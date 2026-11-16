<?php
// A session receipt is approved or rejected under sign-ups permission. Cancellation-related decisions
// are applied to the corresponding refund state.
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/helpers.php";
$user = require_login();
$db = get_db_connection();
// The registration is loaded with student, session and club details. The same SQL is reused below with FOR UPDATE.
$reg_id = (int) ($_GET["id"] ?? 0);
$sql =
    "SELECT r.*,u.name AS student_name,u.email AS student_email,s.title AS session_title,s.club_id,s.session_date,s.start_time,s.status AS session_status,c.name AS club_name FROM registrations r JOIN users u ON u.id=r.user_id JOIN sessions s ON s.id=r.session_id JOIN clubs c ON c.id=s.club_id WHERE r.id=?";
$q = $db->prepare($sql);
$q->execute([$reg_id]);
$reg = $q->fetch();
// Unknown registrations are handled with a 404 response, and sign-ups permission is required.
if (!$reg) {
    render_error_page(404);
}
// Session receipt review is refused without club sign-ups permission.
if (!can_manage_club($reg["club_id"], $user["id"], 'signups')) {
    render_error_page(403);
}
// Review of the current account's own registration payment is prohibited.
$is_self = $reg["user_id"] === $user["id"];
$error = null;
// Form submission: CSRF and self-review are checked first, then the decision is validated inside a locked transaction.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Invalid CSRF tokens are reported before session receipt review.
    if (!verify_csrf_token($_POST["csrf_token"] ?? "")) {
        $error = "Your form session expired. Reload and try again.";
    } /* Self-review of session payment is rejected. */ elseif ($is_self) {
        $error = "Another authorised manager must review your own payment.";
    } /* A validated reviewer request is processed under transaction locks. */ else {
        $db->beginTransaction();
        // Session and registration records are locked before the decision is validated.
        try {
            // The same lock order as reservation and committee cancellation is used: session first, then registration.
            // The row is re-read under the lock, so the checks below use current data.
            $q = $db->prepare("SELECT id FROM sessions WHERE id=? FOR UPDATE");
            $q->execute([$reg["session_id"]]);
            $q = $db->prepare($sql . " FOR UPDATE");
            $q->execute([$reg_id]);
            $reg = $q->fetch();
            // Cancellation processing is selected for a student cancellation request or a cancelled session.
            $cancelling = !empty($reg["cancellation_requested_at"]) || $reg["session_status"] === "cancelled";
            $decision = $_POST["decision"] ?? "";
            $reason = trim($_POST["rejection_reason"] ?? "");
            // The current receipt's history row is looked up, so a decision made on an older receipt can be detected.
            $receipt_query = $db->prepare('SELECT id FROM payment_receipts WHERE registration_id=? AND receipt_path=?');
            $receipt_query->execute([$reg_id, $reg['receipt_path']]);
            $current_receipt_id = (int)$receipt_query->fetchColumn();
            // Decisions are rejected for non-pending or replaced receipts, missing decision values, or rejection
            // without a reason.
            if ($reg["status"] !== "awaiting_approval" || !$reg["receipt_path"]) {
                $error =
                    "This receipt is no longer awaiting a decision. Return to participants for its current status.";
            } /* Missing or replaced receipt IDs are rejected as stale review submissions. */ elseif (!$current_receipt_id || (int)($_POST['receipt_id'] ?? 0) !== $current_receipt_id) {
                $error = 'The receipt changed since you opened this form. Inspect the current receipt before deciding.';
            } /* Unsupported session receipt decisions are rejected. */ elseif (!in_array($decision, ["approve", "reject"], true)) {
                $error = "Choose a payment decision.";
            } /* A rejection reason within the storage limit is required. */ elseif ($decision === "reject" && ($reason === "" || mb_strlen($reason) > 255)) {
                $error = "Enter a rejection reason of 1–255 characters.";
            } /* The pending receipt-history row is updated before registration state. */ else {
                // The receipt history row is marked first. Exactly one row must change, or the whole decision is rolled back.
                $q = $db->prepare("UPDATE payment_receipts SET decision=?, rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE registration_id=? AND receipt_path=? AND decision='awaiting_review'");
                $q->execute([
                    $decision === 'approve' ? 'approved' : 'rejected',
                    $decision === 'reject' ? $reason : null,
                    $user['id'], $reg_id, $reg['receipt_path'],
                ]);
                // The decision is aborted unless exactly one pending receipt-history row was updated.
                if ($q->rowCount() !== 1) throw new RuntimeException('Receipt history is unavailable or already reviewed. Reload before retrying.');
                // An approved payment is confirmed or moved to refund processing after cancellation.
                if ($decision === "approve") {
                    // Approve: the place is confirmed, or moved to cancellation_pending when a cancellation is in progress
                    // (a full refund is then due).
                    $next = $cancelling ? "cancellation_pending" : "confirmed";
                    $q = $db->prepare(
                        "UPDATE registrations SET status=?,rejection_reason=NULL,updated_at=NOW() WHERE id=?",
                    );
                    $q->execute([$next, $reg_id]);
                } /* A rejected payment is cancelled or assigned a new upload deadline. */ else {
                    // Reject: a new 24-hour resubmission deadline is set, capped at the session start.
                    // During a cancellation the registration is cancelled outright instead (no transfer arrived).
                    $deadline = date(
                        "Y-m-d H:i:s",
                        min(time() + 86400, strtotime($reg["session_date"] . " " . $reg["start_time"])),
                    );
                    $next = $cancelling ? "cancelled" : "payment_rejected";
                    $q = $db->prepare(
                        "UPDATE registrations SET status=?,rejection_reason=?,payment_deadline=?,updated_at=NOW() WHERE id=?",
                    );
                    $q->execute([$next, $reason, $deadline, $reg_id]);
                }
                // The reviewer is sent back to the participants list with the result.
                $db->commit();
                header(
                    "Location: " .
                        app_url(
                            "/manager/participants.php?session_id=" .
                                $reg["session_id"] .
                                "&msg=" .
                                ($decision === "approve" ? "approved" : "rejected"),
                        ),
                );
                exit();
            }
            // The transaction is rolled back after validation errors, and the form is redisplayed with the message.
            $db->rollBack();
        } /* Failed review transactions are rolled back and rethrown. */ catch (Throwable $e) {
            // On any error the transaction is rolled back, and the error is passed on to the 500 page.
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
// Review availability and the receipt ID submitted with the decision are prepared.
$cancelling = !empty($reg["cancellation_requested_at"]) || $reg["session_status"] === "cancelled";
$can_review = $reg["status"] === "awaiting_approval" && !empty($reg["receipt_path"]) && !$is_self;
$receipt_query = $db->prepare('SELECT id FROM payment_receipts WHERE registration_id=? AND receipt_path=?');
$receipt_query->execute([$reg_id, $reg['receipt_path']]);
$displayed_receipt_id = (int)$receipt_query->fetchColumn();
$page_title = "Review payment evidence";
require_once __DIR__ . "/../includes/header.php";
?>
<!-- The journey page markup is rendered. --><div class="journey-page">
 <!-- Session-review navigation and account context are displayed. -->
 <header class="journey-header">
    <!-- The journey breadcrumb markup is rendered. --><nav class="journey-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= app_url("/manager/sessions.php") ?>">Manager Workspace</a> /
        <a href="<?= app_url("/manager/participants.php?session_id=" . $reg["session_id"]) ?>">Participants</a> /
        <span class="breadcrumb-current"><?= e($reg["student_name"]) ?></span>
    </nav>
    <h1>Review payment evidence</h1>
    <p class="journey-subtitle"><?= e($reg["student_name"]) ?> · <?= e($reg["session_title"]) ?></p>
 </header>
 <!-- Review errors are displayed and marked for focus. -->
 <?php /* Session-review errors are displayed above the form. */ if ($error): ?><div class="journey-notice notice-error" role="alert" tabindex="-1" data-focus-error><?= e(
    $error,
) ?></div><?php endif; ?>
 <!-- The journey columns markup is rendered. --><div class="journey-columns review">
  <!-- Image receipts are previewed and PDFs are linked. -->
  <section class="journey-card"><h2>Receipt submission</h2><?= get_status_badge($reg["status"]) ?>
   <?php /* Existing receipt evidence is displayed for review. */ if ($reg["receipt_path"] && is_file(__DIR__ . "/../" . $reg["receipt_path"])): ?>
    <?php $evidence_url = app_url("/evidence.php?registration_id=" . $reg_id); ?>
    <?php /* Image receipts are displayed in the receipt preview. */ if (
        !str_ends_with(strtolower($reg["receipt_path"]), ".pdf")
    ): ?><img class="receipt-inline" src="<?= $evidence_url ?>" alt="Payment receipt submitted by <?= e(
    $reg["student_name"],
) ?>"><?php endif; ?>
    <a class="journey-button" href="<?= $evidence_url ?>" target="_blank" rel="noopener">Open full receipt ↗</a>
   <?php /* Missing receipt evidence is explained. */ else: ?><!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Receipt file unavailable</strong><p>No readable receipt is attached. Check with the participant through your existing contact channel.</p></div><?php endif; ?>
   <p class="journey-help">Evidence is private to this participant and authorised club managers.</p>
  </section>
  <!-- Payment facts, cancellation context and available review decisions are displayed. -->
  <section class="journey-card"><h2>Payment decision</h2><dl class="journey-facts"><dt>Payer</dt><dd><?= e(
      $reg["student_name"],
  ) ?></dd><dt>Email</dt><dd><?= e($reg["student_email"]) ?></dd><dt>Quoted amount</dt><dd><?= format_price(
    $reg["price_snapshot"],
) ?></dd><dt>Reviewer</dt><dd><?= e($user["name"]) ?></dd><dt>Club</dt><dd><?= e(
    $reg["club_name"],
) ?></dd><dt>Submitted</dt><dd><?= format_deadline($reg["receipt_uploaded_at"]) ?></dd></dl>
   <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Check external settlement first</strong><p>A receipt alone does not verify payment. Check the club’s bank records before deciding. You cannot review your own payment.</p></div>
   <?php /* Cancellation-related payment review is explained. */ if (
       $cancelling
   ): ?><!-- The journey notice markup is rendered. --><div class="journey-notice"><strong>Cancellation requested</strong><p>If money arrived, approve settlement to queue a full refund. Complete cancellation without refund only after checking that no transfer arrived.</p></div><?php endif; ?>
   <?php /* Decision controls are displayed for the current reviewable receipt. */ if ($can_review): ?><!-- The form controls markup is rendered. --><form method="POST" autocomplete="off">
     <?= csrf_field() ?>
     <input type="hidden" name="receipt_id" value="<?= $displayed_receipt_id ?>">
     <!-- The journey field markup is rendered. --><div class="journey-field"><label for="rejection_reason"><?= $cancelling
         ? "Settlement check notes (required to complete without refund)"
         : "Rejection reason (required when rejecting)" ?></label><textarea id="rejection_reason" name="rejection_reason" rows="3" maxlength="255" placeholder="Explain what needs correcting…"><?= e(
    $_POST["rejection_reason"] ?? "",
) ?></textarea><p class="journey-help"><?= $cancelling
    ? "Record the external settlement check. A rejected receipt is not proof that no money arrived."
    : "Rejection allows up to 24 hours to resubmit, capped at session start. Rejection at or after start allows no further upload." ?></p></div>
     <!-- The journey actions markup is rendered. --><div class="journey-actions"><button class="journey-button primary" type="submit" name="decision" value="approve" data-pending-label="Saving decision…"><?= $cancelling
         ? "Payment received — queue refund"
         : "Approve payment" ?></button><button class="journey-button" type="submit" name="decision" value="reject" data-pending-label="Saving decision…"><?= $cancelling
    ? "No transfer received — complete cancellation"
    : "Reject with reason" ?></button></div>
   </form><?php /* Review unavailability is explained when no decision can be submitted. */ else: ?><!-- The journey callout markup is rendered. --><div class="journey-callout"><strong><?= $is_self
    ? "Another reviewer is needed"
    : "No decision available" ?></strong><p><?= $is_self
    ? "Another authorised manager or setup admin must review this payment."
    : "This record is no longer awaiting payment review. Its current status is shown above." ?></p></div><?php endif; ?>
   <!-- The journey actions markup is rendered. --><div class="journey-actions"><a class="journey-button" href="<?= app_url(
       "/manager/participants.php?session_id=" . $reg["session_id"],
   ) ?>">Back to participants</a></div>
  </section>
 </div>
 <!-- Session receipt and review history is rendered. -->
 <?php render_receipt_history($reg_id); ?>
</div>
<?php require_once __DIR__ . "/../includes/footer.php"; ?>
