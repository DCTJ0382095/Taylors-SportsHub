<?php
// Membership receipt review: the receipt is approved (the membership starts at that moment) or rejected with a reason.
// The "Manage sign-ups and payments" permission for the membership's club is required.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$db = get_db_connection();

// The membership is loaded with member and club details. Unknown IDs are shown the 404 page.
$membership_id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT m.*, u.name AS member_name, u.email AS member_email, c.name AS club_name
                      FROM club_memberships m
                      INNER JOIN users u ON u.id = m.user_id
                      INNER JOIN clubs c ON c.id = m.club_id
                      WHERE m.id = :id LIMIT 1");
$stmt->execute([':id' => $membership_id]);
$membership = $stmt->fetch();
// Unknown memberships are handled with a 404 response.
if (!$membership) {
    render_error_page(404);
}
// Sign-ups permission is required, and self-review is prohibited.
if (!can_manage_club((int) $membership['club_id'], $user['id'], 'signups')) {
    render_error_page(403);
}
$is_self = (int) $membership['user_id'] === (int) $user['id'];
$error = null;

// Form submission: the CSRF token, self-review, the decision and the reason are checked in turn.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'] ?? '';
    $reason = trim($_POST['rejection_reason'] ?? '');

    // Invalid CSRF tokens are reported before membership review.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';
    } /* Self-review of membership payment is rejected. */ elseif ($is_self) {
        $error = 'Someone else on the committee must review your own payment.';
    } /* Unsupported membership decisions are rejected. */ elseif (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Choose Approve or Reject.';
    } /* A rejection reason within the storage limit is required. */ elseif ($decision === 'reject' && ($reason === '' || mb_strlen($reason) > 255)) {
        $error = 'Write a reason (up to 255 characters) so the student knows what to fix.';
    } /* A validated membership decision is saved with receipt history. */ else {
        // Membership changes and receipt-history changes are saved together in a transaction.
        $db->beginTransaction();
        // Approved membership dates are calculated from the quoted term.
        if ($decision === 'approve') {
            // The approval date is used as the membership start, and the quoted term is added for the expiry date.
            $stmt = $db->prepare("UPDATE club_memberships
                                  SET status = 'active', starts_at = NOW(),
                                      expires_at = DATE_ADD(NOW(), INTERVAL months_snapshot MONTH),
                                      rejection_reason = NULL, reviewed_by = :reviewer, reviewed_at = NOW()
                                  WHERE id = :id AND status = 'awaiting_approval'");
            $stmt->execute([':reviewer' => $user['id'], ':id' => $membership_id]);
        } /* Membership payment is rejected with the supplied reason. */ else {
            // Reject: the reason is saved so the student can fix the receipt and upload again.
            $stmt = $db->prepare("UPDATE club_memberships
                                  SET status = 'payment_rejected', rejection_reason = :reason,
                                      reviewed_by = :reviewer, reviewed_at = NOW()
                                  WHERE id = :id AND status = 'awaiting_approval'");
            $stmt->execute([':reason' => $reason, ':reviewer' => $user['id'], ':id' => $membership_id]);
        }

        // A zero affected-row count is returned when the pending status has already been changed.
        if ($stmt->rowCount() === 1) {
            // The same decision is written into the receipt history.
            $stmt = $db->prepare("UPDATE membership_receipts
                                  SET decision = :decision, rejection_reason = :reason,
                                      reviewed_by = :reviewer, reviewed_at = NOW()
                                  WHERE membership_id = :id AND receipt_path = :path AND decision = 'awaiting_review'");
            $stmt->execute([
                ':decision' => $decision === 'approve' ? 'approved' : 'rejected',
                ':reason'   => $decision === 'reject' ? $reason : null,
                ':reviewer' => $user['id'],
                ':id'       => $membership_id,
                ':path'     => $membership['receipt_path'],
            ]);
            $db->commit();
            $done = $decision === 'approve' ? 'approved' : 'rejected';
            header('Location: ' . app_url('/manager/sessions.php?status=memberships&done=' . $done), true, 303);
            exit;
        }
        $db->rollBack();
        $error = 'This receipt has already been reviewed. Its current status is shown below.';
        // Membership status is reloaded for the resulting decision display.
        $stmt = $db->prepare("SELECT status FROM club_memberships WHERE id = :id");
        $stmt->execute([':id' => $membership_id]);
        $membership['status'] = $stmt->fetchColumn();
    }
}

// Review availability, the receipt URL and the PDF display flag are prepared.
$can_review = $membership['status'] === 'awaiting_approval' && !$is_self;
$receipt_url = app_url('/membership-receipt.php?id=' . $membership_id);
$is_pdf = str_ends_with(strtolower((string) $membership['receipt_path']), '.pdf');

$page_title = 'Review membership payment';
require_once __DIR__ . '/../includes/header.php';
?>
<!-- The journey page markup is rendered. --><div class="journey-page">
  <!-- Membership-review navigation and account context are displayed. -->
  <header class="journey-header">
    <!-- The journey breadcrumb markup is rendered. --><nav class="journey-breadcrumb" aria-label="Breadcrumb">
      <a href="<?= app_url('/manager/sessions.php') ?>">Manager Workspace</a> /
      <a href="<?= app_url('/manager/sessions.php?status=memberships') ?>">Membership Sign-ups</a> /
      <span class="breadcrumb-current"><?= e($membership['member_name']) ?></span>
    </nav>
    <h1>Review membership payment</h1>
    <p class="journey-subtitle"><?= e($membership['member_name']) ?> · <?= e($membership['club_name']) ?></p>
  </header>

  <?php /* Membership-review errors are displayed. */ if ($error): ?>
    <!-- The journey notice markup is rendered. --><div class="journey-notice notice-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <!-- The journey columns markup is rendered. --><div class="journey-columns review">
    <!-- Image receipts are previewed and PDFs are linked. -->
    <section class="journey-card">
      <h2>Receipt</h2>
      <?= get_status_badge(membership_state($membership)) ?>
      <?php /* The recorded membership receipt is presented for review. */ if ($membership['receipt_path']): ?>
        <?php /* Image receipts are displayed in the receipt preview. */ if (!$is_pdf): ?>
          <img class="receipt-inline" src="<?= $receipt_url ?>" alt="Receipt uploaded by <?= e($membership['member_name']) ?>">
        <?php endif; ?>
        <a class="journey-button" href="<?= $receipt_url ?>" target="_blank" rel="noopener">Open full receipt ↗</a>
      <?php /* PDF receipts are offered as protected document links. */ else: ?>
        <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>No receipt</strong><p>The student hasn't uploaded a receipt for this membership.</p></div>
      <?php endif; ?>
    </section>

    <!-- Membership payment facts and available decisions are displayed. -->
    <section class="journey-card">
      <h2>Payment details</h2>
      <dl class="journey-facts">
        <dt>Student</dt><dd><?= e($membership['member_name']) ?></dd>
        <dt>Email</dt><dd><?= e($membership['member_email']) ?></dd>
        <dt>Club</dt><dd><?= e($membership['club_name']) ?></dd>
        <dt>Amount</dt><dd><?= format_price($membership['price_snapshot']) ?></dd>
        <dt>Length</dt><dd><?= e(format_membership_length((int) $membership['months_snapshot'])) ?></dd>
        <?php /* The recorded upload time is displayed. */ if ($membership['receipt_uploaded_at']): ?>
          <dt>Receipt sent</dt><dd><?= e(format_deadline($membership['receipt_uploaded_at'])) ?></dd>
        <?php endif; ?>
        <?php /* The previous expiry date is displayed for a renewal under review. */ if ($membership['expires_at'] && $membership['status'] === 'awaiting_approval'): ?>
          <dt>Renewal</dt><dd>Previous membership ended <?= e(format_membership_date($membership['expires_at'])) ?></dd>
        <?php endif; ?>
      </dl>

      <?php /* Decision controls are displayed for a reviewable membership receipt. */ if ($can_review): ?>
        <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Check the club's bank account first</strong><p>A receipt is only a picture. Approve once you can see the money arrived. The membership starts the moment you approve.</p></div>
        <!-- The form controls markup is rendered. --><form method="POST">
          <?= csrf_field() ?>
          <!-- The journey field markup is rendered. --><div class="journey-field">
            <label for="rejection_reason">Reason (needed if you reject)</label>
            <textarea id="rejection_reason" name="rejection_reason" rows="3" maxlength="255" placeholder="e.g. The amount on the receipt is RM 25, not RM 35."><?= e($_POST['rejection_reason'] ?? '') ?></textarea>
          </div>
          <!-- The journey actions markup is rendered. --><div class="journey-actions">
            <button class="journey-button primary" type="submit" name="decision" value="approve">Approve payment</button>
            <button class="journey-button" type="submit" name="decision" value="reject">Reject with reason</button>
          </div>
        </form>
      <?php /* Self-review restrictions are explained. */ elseif ($is_self): ?>
        <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Someone else must review this</strong><p>You can't approve your own payment. Ask another committee member who manages sign-ups.</p></div>
      <?php /* The existing membership decision is displayed when review is unavailable. */ else: ?>
        <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Nothing to review</strong><p>This membership isn't waiting for a payment decision. Its current status is shown on the left.</p></div>
      <?php endif; ?>

      <!-- The journey actions markup is rendered. --><div class="journey-actions">
        <a class="journey-button" href="<?= app_url('/manager/sessions.php?status=memberships') ?>">Back to Membership Sign-ups</a>
      </div>
    </section>
  </div>

  <!-- Membership receipt history is rendered. -->
  <?php render_membership_receipt_history($membership_id); ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>