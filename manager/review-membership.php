<?php
// Review a membership receipt: approve it (the membership starts now) or reject it with a reason.
// Needs the "Manage sign-ups and payments" permission for the membership's club.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$db = get_db_connection();

$membership_id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT m.*, u.name AS member_name, u.email AS member_email, c.name AS club_name
                      FROM club_memberships m
                      INNER JOIN users u ON u.id = m.user_id
                      INNER JOIN clubs c ON c.id = m.club_id
                      WHERE m.id = :id LIMIT 1");
$stmt->execute([':id' => $membership_id]);
$membership = $stmt->fetch();
if (!$membership) {
    render_error_page(404);
}
if (!can_manage_club((int) $membership['club_id'], $user['id'], 'signups')) {
    render_error_page(403);
}
$is_self = (int) $membership['user_id'] === (int) $user['id'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'] ?? '';
    $reason = trim($_POST['rejection_reason'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';
    } elseif ($is_self) {
        $error = 'Someone else on the committee must review your own payment.';
    } elseif (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Choose Approve or Reject.';
    } elseif ($decision === 'reject' && ($reason === '' || mb_strlen($reason) > 255)) {
        $error = 'Write a reason (up to 255 characters) so the student knows what to fix.';
    } else {
        // A transaction: the membership and its receipt history change together, or not at all.
        $db->beginTransaction();
        if ($decision === 'approve') {
            // The membership starts today and lasts the length the student was quoted when they applied.
            $stmt = $db->prepare("UPDATE club_memberships
                                  SET status = 'active', starts_at = NOW(),
                                      expires_at = DATE_ADD(NOW(), INTERVAL months_snapshot MONTH),
                                      rejection_reason = NULL, reviewed_by = :reviewer, reviewed_at = NOW()
                                  WHERE id = :id AND status = 'awaiting_approval'");
            $stmt->execute([':reviewer' => $user['id'], ':id' => $membership_id]);
        } else {
            $stmt = $db->prepare("UPDATE club_memberships
                                  SET status = 'payment_rejected', rejection_reason = :reason,
                                      reviewed_by = :reviewer, reviewed_at = NOW()
                                  WHERE id = :id AND status = 'awaiting_approval'");
            $stmt->execute([':reason' => $reason, ':reviewer' => $user['id'], ':id' => $membership_id]);
        }

        // rowCount() is 0 if someone else already decided while this page was open.
        if ($stmt->rowCount() === 1) {
            // Write the same decision into the receipt history.
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
        // Reload so the page shows the decision that was made.
        $stmt = $db->prepare("SELECT status FROM club_memberships WHERE id = :id");
        $stmt->execute([':id' => $membership_id]);
        $membership['status'] = $stmt->fetchColumn();
    }
}

$can_review = $membership['status'] === 'awaiting_approval' && !$is_self;
$receipt_url = app_url('/membership-receipt.php?id=' . $membership_id);
$is_pdf = str_ends_with(strtolower((string) $membership['receipt_path']), '.pdf');

$page_title = 'Review membership payment';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="journey-page">
  <header class="journey-header">
    <nav class="journey-breadcrumb" aria-label="Breadcrumb">
      <a href="<?= app_url('/manager/sessions.php') ?>">Manager Workspace</a> /
      <a href="<?= app_url('/manager/sessions.php?status=memberships') ?>">Membership Sign-ups</a> /
      <span class="breadcrumb-current"><?= e($membership['member_name']) ?></span>
    </nav>
    <h1>Review membership payment</h1>
    <p class="journey-subtitle"><?= e($membership['member_name']) ?> · <?= e($membership['club_name']) ?></p>
  </header>

  <?php if ($error): ?>
    <div class="journey-notice notice-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="journey-columns review">
    <section class="journey-card">
      <h2>Receipt</h2>
      <?= get_status_badge(membership_state($membership)) ?>
      <?php if ($membership['receipt_path']): ?>
        <?php if (!$is_pdf): ?>
          <img class="receipt-inline" src="<?= $receipt_url ?>" alt="Receipt uploaded by <?= e($membership['member_name']) ?>">
        <?php endif; ?>
        <a class="journey-button" href="<?= $receipt_url ?>" target="_blank" rel="noopener">Open full receipt ↗</a>
      <?php else: ?>
        <div class="journey-callout"><strong>No receipt</strong><p>The student hasn't uploaded a receipt for this membership.</p></div>
      <?php endif; ?>
    </section>

    <section class="journey-card">
      <h2>Payment details</h2>
      <dl class="journey-facts">
        <dt>Student</dt><dd><?= e($membership['member_name']) ?></dd>
        <dt>Email</dt><dd><?= e($membership['member_email']) ?></dd>
        <dt>Club</dt><dd><?= e($membership['club_name']) ?></dd>
        <dt>Amount</dt><dd><?= format_price($membership['price_snapshot']) ?></dd>
        <dt>Length</dt><dd><?= e(format_membership_length((int) $membership['months_snapshot'])) ?></dd>
        <?php if ($membership['receipt_uploaded_at']): ?>
          <dt>Receipt sent</dt><dd><?= e(format_deadline($membership['receipt_uploaded_at'])) ?></dd>
        <?php endif; ?>
        <?php if ($membership['expires_at'] && $membership['status'] === 'awaiting_approval'): ?>
          <dt>Renewal</dt><dd>Previous membership ended <?= e(format_membership_date($membership['expires_at'])) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ($can_review): ?>
        <div class="journey-callout"><strong>Check the club's bank account first</strong><p>A receipt is only a picture. Approve once you can see the money arrived. The membership starts the moment you approve.</p></div>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="journey-field">
            <label for="rejection_reason">Reason (needed if you reject)</label>
            <textarea id="rejection_reason" name="rejection_reason" rows="3" maxlength="255" placeholder="e.g. The amount on the receipt is RM 25, not RM 35."><?= e($_POST['rejection_reason'] ?? '') ?></textarea>
          </div>
          <div class="journey-actions">
            <button class="journey-button primary" type="submit" name="decision" value="approve">Approve payment</button>
            <button class="journey-button" type="submit" name="decision" value="reject">Reject with reason</button>
          </div>
        </form>
      <?php elseif ($is_self): ?>
        <div class="journey-callout"><strong>Someone else must review this</strong><p>You can't approve your own payment. Ask another committee member who manages sign-ups.</p></div>
      <?php else: ?>
        <div class="journey-callout"><strong>Nothing to review</strong><p>This membership isn't waiting for a payment decision. Its current status is shown on the left.</p></div>
      <?php endif; ?>

      <div class="journey-actions">
        <a class="journey-button" href="<?= app_url('/manager/sessions.php?status=memberships') ?>">Back to Membership Sign-ups</a>
      </div>
    </section>
  </div>

  <?php render_membership_receipt_history($membership_id); ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>