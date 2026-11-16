<?php
// Session payment instructions, receipt upload, refund progress and booking details are displayed for
// owners and authorized sign-ups managers.
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/helpers.php";
$user = require_login();
$reg_id = (int) ($_GET["id"] ?? 0);
$db = get_db_connection();
// Overdue reservations are expired first, so the status shown is current.
expire_overdue_reservations();
// Registration, session, club and account details are loaded. Unknown IDs are handled with a 404
// response.
$stmt = $db->prepare(
    "SELECT r.*, s.title,s.sport_type,s.venue,s.session_date,s.start_time,s.end_time,s.status AS session_status,s.cancellation_reason AS session_cancellation_reason,c.id AS club_id,c.name AS club_name,c.duitnow_qr_path,u.name AS student_name FROM registrations r INNER JOIN sessions s ON r.session_id=s.id INNER JOIN clubs c ON s.club_id=c.id INNER JOIN users u ON r.user_id=u.id WHERE r.id=?",
);
$stmt->execute([$reg_id]);
$reg = $stmt->fetch();
// Unknown registrations are handled with a 404 response.
if (!$reg) {
    render_error_page(404);
}
// Registration access is granted to the owner and accounts with club sign-ups permission.
if ($reg["user_id"] !== $user["id"] && !can_manage_club($reg["club_id"], $user["id"], 'signups')) {
    render_error_page(403);
}
// Uploads are allowed only for the owner, while payment is due or was rejected,
// and only before both the receipt deadline and the session start.
$is_owner = $reg["user_id"] === $user["id"];
$can_upload =
    $is_owner &&
    in_array($reg["status"], ["awaiting_payment", "payment_rejected"], true) &&
    $reg["session_status"] !== "cancelled" &&
    strtotime($reg["payment_deadline"] ?? "") > time() &&
    strtotime($reg["session_date"] . " " . $reg["start_time"]) > time();
// Refund records are loaded to work out how much has been refunded so far and how much remains.
$stmt = $db->prepare(
    "SELECT amount,reason,created_at,id,proof_path FROM refund_records WHERE registration_id=? ORDER BY created_at,id",
);
$stmt->execute([$reg_id]);
$refunds = $stmt->fetchAll();
$refunded = array_sum(array_column($refunds, "amount"));
$remaining = max(0, (float) $reg["price_snapshot"] - $refunded);
// The refund card is shown while a cancellation is pending, or once any refund has been recorded.
$is_refund = $reg["status"] === "cancellation_pending" || !empty($refunds);
$page_title = "Registration · " . $reg["title"];
require_once __DIR__ . "/includes/header.php";
?>
<!-- The journey page markup is rendered. --><div class="journey-page">
  <!-- Registration navigation and the state-specific heading are displayed. -->
  <header class="journey-header">
    <!-- The journey breadcrumb markup is rendered. --><nav class="journey-breadcrumb" aria-label="Breadcrumb">
        <?php /* Owners are linked back to the activities overview. */ if ($is_owner): ?>
            <a href="<?= app_url("/my-activities.php") ?>">My Activities</a> /
            <span class="breadcrumb-current"><?= e($reg["title"]) ?></span>
        <?php /* Sign-ups managers are linked back to participants. */ else: ?>
            <a href="<?= app_url("/manager/sessions.php") ?>">Manager Workspace</a> /
            <a href="<?= app_url("/manager/participants.php?session_id=" . $reg["session_id"]) ?>">Participants</a> /
            <span class="breadcrumb-current"><?= e($reg["student_name"]) ?></span>
        <?php endif; ?>
    </nav>
    <h1><?= $can_upload
        ? ($reg["status"] === "payment_rejected"
            ? "Resubmit your receipt"
            : "Complete your payment")
        : ($is_refund
            ? "Cancellation & refund"
            : "Your registration") ?></h1>
    <p class="journey-subtitle"><?= e($reg["title"]) ?> · <?= format_session_date(
     $reg["session_date"],
 ) ?> · <?= format_session_time_range($reg["start_time"], $reg["end_time"]) ?></p>
  </header>
  <!-- Registration-action feedback is displayed. -->
  <?php journey_feedback(); ?>
  <!-- Session cancellation and the recorded reason are displayed. -->
  <?php /* The session cancellation notice is displayed. */ if ($reg["session_status"] === "cancelled"): ?>
    <div class="journey-notice notice-error"><strong>Session cancelled by the committee</strong><p><?= e(
        $reg["session_cancellation_reason"],
    ) ?></p><p>New reservations and receipt uploads are closed. Payment and refund resolution remain separate.</p></div>
  <?php endif; ?>
  <!-- The journey columns markup is rendered. --><div class="journey-columns">
    <!-- Payment, refund and evidence details are grouped in the main column. -->
    <div>
      <!-- Payment instructions are displayed while receipt upload is allowed. -->
      <?php /* Payment instructions and receipt upload are displayed while upload is allowed. */ if ($can_upload): ?>
        <section class="journey-card" aria-labelledby="pay-heading">
          <h2 id="pay-heading">Pay <?= format_price($reg["price_snapshot"]) ?></h2>
          
          <!-- QR and bank transfer instructions are arranged side by side. -->
          <div class="payment-transfer-split">
            <!-- The payment qr col markup is rendered. --><div class="payment-qr-col">
              <!-- The duitnow qr card markup is rendered. --><div class="duitnow-qr-card">
                <!-- The duitnow qr image wrapper markup is rendered. --><div class="duitnow-qr-image-wrapper">
                  <?php $qr_url = club_qr_url($reg['duitnow_qr_path']); ?>
                  <?php /* The club QR image is displayed when available. */ if ($qr_url): ?>
                    <img src="<?= e($qr_url) ?>" alt="DuitNow QR code for <?= e($reg['club_name']) ?>" class="duitnow-qr-img" width="150" height="206">
                  <?php /* Missing QR setup is explained. */ else: ?>
                    <p class="duitnow-qr-missing">This club hasn't added a DuitNow QR. Use the bank details instead.</p>
                  <?php endif; ?>
                </div>
                <!-- The duitnow qr caption markup is rendered. --><div class="duitnow-qr-caption">
                  <strong>Scan to pay with DuitNow</strong>
                  <p>Works with any Malaysian banking or e-wallet app.</p>
                </div>
              </div>
            </div>
            <!-- The payment instructions col markup is rendered. --><div class="payment-instructions-col">
              <!-- The journey callout markup is rendered. --><div class="journey-callout payment-callout">
                <strong>Payment details</strong>
                <p style="white-space:pre-line; margin-top: 6px;"><?= e(
                    $reg["payment_instructions_snapshot"] ?:
                    "Instructions unavailable. Contact your club committee before transferring.",
                ) ?></p>
              </div>
              <p class="journey-help">After paying, keep your receipt and upload it below. Paying alone does not confirm your place.</p>
            </div>
          </div>

          <!-- Receipt selection is enhanced by the shared script and validated on the server. -->
          <h2>Upload your receipt</h2>
          <!-- The form controls markup is rendered. --><form method="POST" action="<?= app_url(
              "/upload-receipt.php",
          ) ?>" enctype="multipart/form-data" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="registration_id" value="<?= $reg_id ?>">
            <!-- The journey field markup is rendered. --><div class="journey-field upload-dropzone" id="receipt-dropzone">
              <label for="receipt">Receipt file</label>
              <p class="journey-help" id="receipt-hint">JPG, PNG, WebP or PDF · Up to 5 MB. Choose a file or drop it here.</p>
              <input type="file" id="receipt" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required aria-describedby="receipt-hint receipt-error">
              <p id="receipt-error" class="field-error" aria-live="polite"></p>
            </div>
            <!-- The receipt upload preview markup is rendered. --><div id="receipt-upload-preview" hidden class="journey-callout" aria-live="polite"><span class="preview-name"></span><span class="preview-size"></span></div>
            <button type="submit" class="journey-button primary" data-pending-label="Submitting receipt…">Submit receipt</button>
            <p class="journey-help">Submit before <?= format_deadline(
                $reg["payment_deadline"],
            ) ?>. Timely submission holds your place while an authorised manager checks external settlement.</p>
          </form>
        </section>
      <?php endif; ?>
      <!-- Refund amounts, recipient details and recorded transfers are displayed. -->
      <?php /* Refund progress and recorded transfers are displayed when applicable. */ if ($is_refund): ?>
        <section class="journey-card" aria-labelledby="refund-heading">
          <h2 id="refund-heading"><?= $remaining < 0.001 ? "Refund recorded in full" : "Refund in progress" ?></h2>
          <dl class="journey-facts"><dt>Refund entitlement</dt><dd><?= format_price(
              $reg["price_snapshot"],
          ) ?></dd><dt>Recorded so far</dt><dd>RM <?= number_format(
    $refunded,
    2,
) ?></dd><dt>Remaining</dt><dd>RM <?= number_format($remaining, 2) ?></dd></dl>
          <p class="journey-help">Refunds happen externally. This page shows transfers recorded by your committee; SportsHub does not move money.</p>
          <?php /* Recorded refund recipient bank details are displayed. */ if ($reg["recipient_account_number"]): ?>
            <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Private refund recipient</strong><p><?= e(
                $reg["recipient_bank_name"],
            ) ?><br><?= e($reg["recipient_account_name"]) ?><br><?= e($reg["recipient_account_number"]) ?></p></div>
          <?php /* Missing bank details are highlighted while a refund balance remains. */ elseif (
              $remaining > 0.001
          ): ?><!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Recipient details needed</strong><p>Contact your club committee through your existing private channel to arrange the refund. No turnaround time has been set.</p></div><?php endif; ?>
          <?php /* Each recorded refund is displayed with amount and reference details. */ foreach ($refunds as $refund): ?><!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>RM <?= number_format(
    (float) $refund["amount"],
    2,
) ?> recorded</strong><p><?= format_deadline($refund["created_at"]) ?><br><?= e($refund["reason"]) ?></p><?php /* Uploaded refund proof is linked through the protected evidence endpoint. */ if (
    $refund["proof_path"]
): ?><a href="<?= app_url(
    "/evidence.php?registration_id=" . $reg_id . "&refund_id=" . $refund["id"],
) ?>" target="_blank" rel="noopener">View refund evidence ↗</a><?php endif; ?></div><?php endforeach; ?>
        </section>
      <?php endif; ?>
      <!-- The latest submitted receipt is linked. -->
      <?php /* Existing payment evidence is linked through the protected endpoint. */ if ($reg["receipt_path"]): ?>
        <section class="journey-card"><h2>Submitted evidence</h2><p class="journey-help">A receipt is evidence of a claimed transfer. Only committee review confirms settlement.</p><a class="journey-button" href="<?= app_url(
            "/evidence.php?registration_id=" . $reg_id,
        ) ?>" target="_blank" rel="noopener">Open receipt ↗</a></section>
      <?php endif; ?>
      <!-- Session receipt and review history is rendered. -->
      <?php render_receipt_history($reg_id); ?>
      <!-- A registration summary is displayed when no payment, refund or receipt action applies. -->
      <?php /* A state summary is displayed when payment, refunds and receipt evidence are unavailable. */ if (!$can_upload && !$is_refund && !$reg["receipt_path"]): ?><section class="journey-card"><h2><?= $reg[
    "status"
] === "expired"
    ? "Your place was released"
    : "Registration details" ?></h2><p><?= e(
    registration_next_step($reg),
) ?></p><a class="journey-button" href="<?= app_url(
    "/my-activities.php",
) ?>">Back to My Activities</a></section><?php endif; ?>
    </div>

    <!-- Reservation facts, next steps and actions are displayed in the sidebar. -->
    <section class="journey-card" aria-labelledby="reservation-heading">
      <!-- The journey card header markup is rendered. --><div class="journey-card-header">
        <h2 id="reservation-heading">Your reservation</h2>
        <?= get_status_badge($reg["status"]) ?>
      </div>
      <dl class="journey-facts">
        <dt><?= $can_upload ? "Amount due" : "Quoted amount" ?></dt><dd><?= format_price($reg["price_snapshot"]) ?></dd>
        <dt>Club</dt><dd><?= e($reg["club_name"]) ?></dd>
        <dt>Where</dt><dd><?= e($reg["venue"]) ?></dd>
        <?php /* The receipt deadline is displayed while upload is allowed. */ if ($can_upload): ?><dt>Receipt deadline</dt><dd><?= format_deadline(
    $reg["payment_deadline"],
) ?></dd><?php endif; ?>
        <dt>Session starts</dt><dd><?= format_deadline($reg["session_date"] . " " . $reg["start_time"]) ?></dd>
        <?php /* The recorded receipt upload time is displayed. */ if ($reg["receipt_uploaded_at"]): ?><dt>Receipt submitted</dt><dd><?= format_deadline(
    $reg["receipt_uploaded_at"],
) ?></dd><?php endif; ?>
      </dl>
      <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Next step</strong><p><?= e(registration_next_step($reg)) ?></p></div>
      <?php /* A saved rejection reason is displayed. */ if (
          $reg["rejection_reason"]
      ): ?><!-- The journey notice markup is rendered. --><div class="journey-notice notice-error"><strong>Rejection reason</strong><p><?= e(
    $reg["rejection_reason"],
) ?></p></div><?php endif; ?>
      <?php /* A saved registration cancellation reason is displayed. */ if ($reg["cancellation_reason"]): ?><p class="journey-help"><strong>Cancellation reason:</strong> <?= e(
    $reg["cancellation_reason"],
) ?></p><?php endif; ?>
      <!-- Session navigation, eligible owner cancellation and manager participant access are offered. -->
      <div class="journey-actions">
        <a class="journey-button" href="<?= app_url("/session-detail.php?id=" . $reg["session_id"]) ?>">View session</a>
        <?php /* Cancellation is offered to the owner when eligible. */ if ($is_owner && can_cancel_registration($reg)): ?><a class="journey-button registration-cancel-button" href="<?= app_url(
    "/cancel-registration.php?id=" . $reg_id,
) ?>">Cancel registration</a><?php endif; ?>
        <?php /* Participant management is linked for authorized non-owner access. */ if (!$is_owner): ?><a class="journey-button" href="<?= app_url(
    "/manager/participants.php?session_id=" . $reg["session_id"],
) ?>">Back to participants</a><?php endif; ?>
      </div>
    </section>
  </div>
  </div>
</div>
<?php require_once __DIR__ . "/includes/footer.php"; ?>
