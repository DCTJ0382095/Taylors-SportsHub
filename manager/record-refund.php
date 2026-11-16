<?php
// Record-refund page (committee only, sign-ups permission). A refund made outside SportsHub is recorded here,
// partial refunds included. The registration is cancelled once the full amount has been recorded.
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/helpers.php";
$user = require_login();
$db = get_db_connection();
// The registration is loaded with student and session details. Unknown IDs are shown the 404 page.
$reg_id = (int) ($_GET["id"] ?? 0);
$sql =
    "SELECT r.*,u.name AS student_name,s.title AS session_title,s.club_id FROM registrations r JOIN users u ON r.user_id=u.id JOIN sessions s ON r.session_id=s.id WHERE r.id=?";
$q = $db->prepare($sql);
$q->execute([$reg_id]);
$reg = $q->fetch();
// Unknown registrations are handled with a 404 response.
if (!$reg) {
    render_error_page(404);
}
// Sign-ups permission is required, and refund recording for the current account's own registration is
// refused.
if (!can_manage_club($reg["club_id"], $user["id"], 'signups')) {
    render_error_page(403);
}
$is_self = $reg["user_id"] === $user["id"];
$error = null;
// A one-time submission key is kept in a hidden field. A form submitted twice carries the same key,
// so the second copy is recognised and not recorded again.
$submitted_key = $_POST['submission_key'] ?? null;
$valid_key = is_string($submitted_key) && preg_match('/^[a-f0-9]{32}$/D', $submitted_key);
$submission_key = $valid_key ? $submitted_key : bin2hex(random_bytes(16));
// Recorded refund totals and the outstanding balance are calculated.
$sum = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM refund_records WHERE registration_id=?");
$sum->execute([$reg_id]);
$total_refunded = (float) $sum->fetchColumn();
$remaining = max(0, (float) $reg["price_snapshot"] - $total_refunded);
// Form submission: CSRF, self-recording and the submission key are checked first.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Invalid CSRF tokens are reported before refund recording.
    if (!verify_csrf_token($_POST["csrf_token"] ?? "")) {
        $error = "Your form session expired. Reload and try again.";
    } /* Self-recorded refunds are rejected. */ elseif ($is_self) {
        $error = "Another authorised manager must record your refund.";
    } /* Invalid refund submission keys are rejected. */ elseif (!$valid_key) {
        $error = "Reload the refund form before recording a transfer.";
    } /* A validated refund request is processed in a transaction. */ else {
        $db->beginTransaction();
        $saved_file = null;
        // Refund eligibility and remaining balance are checked under a registration lock.
        try {
            // The registration is locked and re-read, so the checks below use current data.
            $q = $db->prepare("SELECT * FROM registrations WHERE id=? FOR UPDATE");
            $q->execute([$reg_id]);
            $fresh = $q->fetch();
            // A submission key that is already recorded means this form was sent twice, so the first result is reused.
            $duplicate = $db->prepare('SELECT id FROM refund_records WHERE registration_id=? AND submission_key=?');
            $duplicate->execute([$reg_id, $submission_key]);
            // Previously recorded submission keys are redirected without a duplicate refund.
            if ($duplicate->fetchColumn()) {
                $db->rollBack();
                header('Location: ' . app_url('/manager/participants.php?session_id=' . $reg['session_id'] . '&msg=refund_logged'), true, 303);
                exit;
            }
            // The remaining amount is recalculated under the lock.
            $sum->execute([$reg_id]);
            $total_refunded = (float) $sum->fetchColumn();
            $remaining = max(0, (float) $fresh["price_snapshot"] - $total_refunded);
            // Refund amounts are validated as positive values with at most two decimal places and compared in cents
            // with the outstanding balance. A reason or transfer reference is required.
            $amount_text = trim($_POST["amount"] ?? "");
            $amount = round((float) $amount_text, 2);
            $reason = trim($_POST["reason"] ?? "");
            // Refund recording is refused outside cancellation_pending status.
            if ($fresh["status"] !== "cancellation_pending") {
                $error = "This registration is not awaiting a refund. Return to participants for its current status.";
            } /* Invalid or excessive refund amounts are rejected. */ elseif (
                !preg_match('/^\d+(?:\.\d{1,2})?$/', $amount_text) ||
                $amount <= 0 ||
                (int) round($amount * 100) > (int) round($remaining * 100)
            ) {
                $error =
                    "Enter a positive amount up to RM " .
                    number_format($remaining, 2) .
                    ", with at most 2 decimal places.";
            } /* Missing or oversized refund reasons are rejected. */ elseif ($reason === "" || mb_strlen($reason) > 255) {
                $error = "Enter a reason or transfer reference of 1–255 characters.";
            }
            // Optional proof is checked for size and content type and saved under a random filename. A supplied
            // proof file must be saved before the refund is recorded.
            $proof_path = null;
            // Optional proof uploads are validated after the refund fields.
            if (!$error && isset($_FILES["proof"]) && $_FILES["proof"]["error"] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES["proof"];
                // Upload errors and files above five megabytes are rejected.
                if ($file["error"] !== UPLOAD_ERR_OK || $file["size"] > 5 * 1024 * 1024) {
                    $error = "Refund proof was not saved. Choose a file up to 5 MB and retry. No refund was recorded.";
                } /* The proof content type is inspected after transfer and size checks. */ else {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
                    $allowed = [
                        "image/jpeg" => "jpg",
                        "image/png" => "png",
                        "image/webp" => "webp",
                        "application/pdf" => "pdf",
                    ];
                    // Unsupported proof content types are rejected.
                    if (!isset($allowed[$mime])) {
                        $error = "Choose JPG, PNG, WebP or PDF proof. No refund was recorded.";
                    } /* Valid proof is prepared for storage under a random filename. */ else {
                        $filename = "refund_" . $reg_id . "_" . bin2hex(random_bytes(8)) . "." . $allowed[$mime];
                        $dir = __DIR__ . "/../assets/uploads/refunds";
                        // The refund-proof directory is created when absent.
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }
                        $saved_file = $dir . "/" . $filename;
                        // Failed proof moves are reported before refund recording.
                        if (!move_uploaded_file($file["tmp_name"], $saved_file)) {
                            $error = "Refund proof could not be saved. Retry; no refund was recorded.";
                        } /* The saved proof path is retained for the refund record. */ else {
                            $proof_path = "assets/uploads/refunds/" . $filename;
                        }
                    }
                }
            }
            // The transaction is rolled back after validation errors, and the form is redisplayed with the message.
            if ($error) {
                $db->rollBack();
            } /* A validated refund is inserted and registration status is updated if fully repaid. */ else {
                // The refund is recorded. When the total reaches the full price, the registration is cancelled and the place released.
                $q = $db->prepare(
                    "INSERT INTO refund_records (registration_id,amount,reason,proof_path,recorded_by,submission_key) VALUES (?,?,?,?,?,?)",
                );
                $q->execute([$reg_id, $amount, $reason, $proof_path, $user["id"], $submission_key]);
                // Fully refunded registrations are cancelled to release the held place.
                if (
                    (int) round(($total_refunded + $amount) * 100) >= (int) round((float) $reg["price_snapshot"] * 100)
                ) {
                    $q = $db->prepare("UPDATE registrations SET status='cancelled',updated_at=NOW() WHERE id=?");
                    $q->execute([$reg_id]);
                }
                $db->commit();
                header(
                    "Location: " .
                        app_url("/manager/participants.php?session_id=" . $reg["session_id"] . "&msg=refund_logged"),
                );
                exit();
            }
        } /* Failed refund transactions are rolled back before proof cleanup and rethrow. */ catch (Throwable $e) {
            // On any error the transaction is rolled back and any saved proof file is deleted,
            // then the error is passed on to the 500 page.
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // A proof file saved during the failed transaction is removed.
            if ($saved_file && is_file($saved_file)) {
                unlink($saved_file);
            }
            throw $e;
        }
    }
}
$page_title = "Record external refund";
require_once __DIR__ . "/../includes/header.php";
?>
<!-- The journey page markup is rendered. --><div class="journey-page narrow">
 <!-- Refund navigation and account context are displayed. -->
 <header class="journey-header">
    <!-- The journey breadcrumb markup is rendered. --><nav class="journey-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= app_url("/manager/sessions.php") ?>">Manager Workspace</a> /
        <a href="<?= app_url("/manager/participants.php?session_id=" . $reg["session_id"]) ?>">Participants</a> /
        <span class="breadcrumb-current"><?= e($reg["student_name"]) ?></span>
    </nav>
    <h1>Record external refund</h1>
    <p class="journey-subtitle"><?= e($reg["student_name"]) ?> · <?= e($reg["session_title"]) ?></p>
 </header>
 <!-- Refund errors are displayed and marked for focus. -->
 <?php /* Refund-recording errors are displayed above the form. */ if ($error): ?><div class="journey-notice notice-error" role="alert" tabindex="-1" data-focus-error><?= e(
    $error,
) ?></div><?php endif; ?>
 <!-- Refund progress, recipient details and available actions are displayed. -->
 <section class="journey-card"><h2>Refund progress</h2><?= get_status_badge(
     $reg["status"],
 ) ?><dl class="journey-facts"><dt>Approved payment</dt><dd><?= format_price(
    $reg["price_snapshot"],
) ?></dd><dt>Already recorded</dt><dd>RM <?= number_format(
    $total_refunded,
    2,
) ?></dd><dt>Remaining refund</dt><dd>RM <?= number_format($remaining, 2) ?></dd></dl>
  <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Private refund recipient</strong><p><?= e(
      $reg["recipient_bank_name"] ?: "Bank details not provided",
  ) ?><br><?= e($reg["recipient_account_name"] ?: $reg["student_name"]) ?><br><?= e(
    $reg["recipient_account_number"] ?: "Arrange recipient details through your existing private contact channel.",
) ?></p></div>
  <?php /* Refund entry is offered only for another account's pending cancellation with an outstanding balance. */ if (!$is_self && $reg["status"] === "cancellation_pending" && $remaining > 0.001): ?>
   <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Record a transfer already made</strong><p>SportsHub does not send money. Record the actual external transfer, including partial refunds. Cancellation stays pending until the full refund is recorded.</p></div>
   <!-- The refund submission key is retained in a hidden field. -->
   <form method="POST" enctype="multipart/form-data" autocomplete="off"><?= csrf_field() ?>
    <input type="hidden" name="submission_key" value="<?= e($submission_key) ?>">
    <!-- The journey field markup is rendered. --><div class="journey-field"><label for="amount">Amount transferred (MYR)</label><input id="amount" name="amount" type="number" inputmode="decimal" min="0.01" max="<?= number_format(
        $remaining,
        2,
        ".",
        "",
    ) ?>" step="0.01" required value="<?= e($_POST["amount"] ?? number_format($remaining, 2, ".", "")) ?>"></div>
    <!-- The journey field markup is rendered. --><div class="journey-field"><label for="reason">Reason or transfer reference</label><input id="reason" name="reason" maxlength="255" required value="<?= e(
        $_POST["reason"] ?? "",
    ) ?>" placeholder="External transfer reference…"></div>
    <!-- The journey field markup is rendered. --><div class="journey-field"><label for="proof">Transfer proof (optional)</label><input type="file" id="proof" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" aria-describedby="proof-hint"><p id="proof-hint" class="journey-help">JPG, PNG, WebP or PDF · Up to 5 MB. A supplied file must save successfully before the record is accepted.</p></div>
    <!-- The journey actions markup is rendered. --><div class="journey-actions"><button class="journey-button primary" type="submit" data-pending-label="Recording refund…">Record refund transfer</button><a class="journey-button" href="<?= app_url(
        "/manager/participants.php?session_id=" . $reg["session_id"],
    ) ?>">Back to participants</a></div>
   </form>
  <?php /* Unavailable refund entry is explained for the current registration. */ else: ?><!-- The journey callout markup is rendered. --><div class="journey-callout"><strong><?= $is_self
    ? "Another manager must record your refund"
    : "No refund action available" ?></strong><p>Check the current registration status and recorded transfers before proceeding.</p></div><a class="journey-button" href="<?= app_url(
    "/manager/participants.php?session_id=" . $reg["session_id"],
) ?>">Back to participants</a><?php endif; ?>
 </section>
</div>
<?php require_once __DIR__ . "/../includes/footer.php"; ?>
