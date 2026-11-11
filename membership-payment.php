<?php
// One club membership: pay for it, upload the receipt, and see where it stands.
// The student who owns it can upload. The club's sign-up managers can only look.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
$db = get_db_connection();

$membership_id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT m.*, c.name AS club_name, c.duitnow_qr_path, u.name AS member_name
                      FROM club_memberships m
                      INNER JOIN clubs c ON c.id = m.club_id
                      INNER JOIN users u ON u.id = m.user_id
                      WHERE m.id = :id LIMIT 1");
$stmt->execute([':id' => $membership_id]);
$membership = $stmt->fetch();
if (!$membership) {
    render_error_page(404);
}

$is_owner = (int) $membership['user_id'] === (int) $user['id'];
if (!$is_owner && !can_manage_club((int) $membership['club_id'], $user['id'], 'signups')) {
    render_error_page(403);   // not your membership, and you don't manage this club's sign-ups
}

$state = membership_state($membership);
$can_upload = $is_owner && in_array($state, ['awaiting_payment', 'payment_rejected'], true);
$error = null;

// Checks an uploaded receipt and saves it in assets/uploads/membership-receipts/.
// Returns [the saved path, null] on success, or [null, an error message].
function save_membership_receipt(?array $file, int $membership_id): array {
    if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The receipt was not uploaded. Choose the file again and retry.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return [null, 'The receipt must be 5 MB or smaller.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        return [null, 'The receipt must be a JPG, PNG, WebP or PDF file.'];
    }
    // assets/uploads/ is blocked from the browser, so receipts stay private.
    $folder = __DIR__ . '/assets/uploads/membership-receipts';
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
    $filename = 'membership_' . $membership_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $filename)) {
        return [null, 'The receipt could not be saved. Try again.'];
    }
    return ['assets/uploads/membership-receipts/' . $filename, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';
    } elseif (!$can_upload) {
        $error = 'This membership is not waiting for a receipt.';
    } else {
        [$path, $error] = save_membership_receipt($_FILES['receipt'] ?? null, $membership_id);
        if ($path) {
            // "AND status IN (...)" only lets this work while it's still waiting for a receipt,
            // so uploading twice from two tabs can't skip a step.
            $stmt = $db->prepare("UPDATE club_memberships
                                  SET status = 'awaiting_approval', receipt_path = :path,
                                      receipt_uploaded_at = NOW(), rejection_reason = NULL
                                  WHERE id = :id AND status IN ('awaiting_payment', 'payment_rejected')");
            $stmt->execute([':path' => $path, ':id' => $membership_id]);
            if ($stmt->rowCount() === 1) {
                header('Location: ' . app_url('/membership-payment.php?id=' . $membership_id . '&msg=uploaded'), true, 303);
                exit;
            }
            unlink(__DIR__ . '/' . $path);   // nothing was updated, so don't keep the file
            $error = 'This membership is not waiting for a receipt.';
        }
    }
}

$length = format_membership_length((int) $membership['months_snapshot']);
$headings = [
    'awaiting_payment'  => 'Pay for your membership.',
    'payment_rejected'  => 'Resubmit your receipt.',
    'awaiting_approval' => 'Receipt submitted.',
    'active'            => "You're a member.",
    'expired'           => 'This membership has expired.',
];
$next_step = membership_next_step($membership);
// The expiry date means something different depending on the state.
$expiry_label = match ($state) {
    'active'  => 'Expires',
    'expired' => 'Expired on',
    default   => 'Previous membership ended',   // a renewal that hasn't been approved yet
};

$page_title = 'Membership · ' . $membership['club_name'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="journey-page">
  <header class="journey-header">
    <nav class="journey-breadcrumb" aria-label="Breadcrumb">
      <a href="<?= app_url('/club.php?id=' . $membership['club_id']) ?>"><?= e($membership['club_name']) ?></a> / Membership
    </nav>
    <h1><?= e($headings[$state]) ?></h1>
    <p class="journey-subtitle"><?= e($membership['club_name']) ?> · <?= e($length) ?> membership</p>
  </header>

  <?php membership_feedback(); ?>
  <?php if ($error): ?>
    <div class="journey-notice notice-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($state === 'payment_rejected' && $membership['rejection_reason']): ?>
    <div class="journey-notice notice-error"><strong>Why the receipt was rejected</strong><p><?= e($membership['rejection_reason']) ?></p></div>
  <?php endif; ?>

  <div class="journey-columns">
    <div>
      <?php if ($can_upload): ?>
        <section class="journey-card">
          <h2>Pay <?= format_price($membership['price_snapshot']) ?></h2>
          <div class="payment-transfer-split">
            <div class="payment-qr-col">
              <div class="duitnow-qr-card">
                <div class="duitnow-qr-image-wrapper">
                  <?php
                    $qr_path = '/' . ltrim($membership['duitnow_qr_path'] ?: 'assets/images/payment/duitnow-qr.webp', '/');
                    $qr_v = file_exists(__DIR__ . $qr_path) ? filemtime(__DIR__ . $qr_path) : '1';
                  ?>
                  <img src="<?= app_url($qr_path) ?>?v=<?= $qr_v ?>" alt="DuitNow QR code" class="duitnow-qr-img" width="150" height="206">
                </div>
                <div class="duitnow-qr-caption">
                  <strong>Scan with your bank or e-wallet</strong>
                  <p>Works with any DuitNow app.</p>
                </div>
              </div>
            </div>
            <div class="payment-instructions-col">
              <div class="journey-callout payment-callout">
                <strong>Bank transfer details</strong>
                <p style="white-space:pre-line; margin-top: 6px;"><?= e($membership['payment_instructions_snapshot'] ?: 'Details unavailable. Contact the club before transferring.') ?></p>
              </div>
              <p class="journey-help">These details were saved when you applied. Keep your transfer receipt: transferring alone does not tell the committee you paid.</p>
            </div>
          </div>

          <h2>Upload your receipt</h2>
          <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="journey-field">
              <label for="receipt">Receipt file</label>
              <p class="journey-help">JPG, PNG, WebP or PDF · up to 5 MB</p>
              <input type="file" id="receipt" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required>
            </div>
            <button type="submit" class="journey-button primary">Submit receipt</button>
          </form>
        </section>
      <?php endif; ?>

      <?php if ($membership['receipt_path']): ?>
        <section class="journey-card">
          <h2>Submitted receipt</h2>
          <p class="journey-help">Only the member and the club's sign-up managers can open it.</p>
          <a class="journey-button" href="<?= app_url('/membership-receipt.php?id=' . $membership_id) ?>" target="_blank" rel="noopener">Open receipt ↗</a>
        </section>
      <?php elseif (!$can_upload): ?>
        <section class="journey-card">
          <h2>Membership details</h2>
          <p><?= e($next_step) ?></p>
        </section>
      <?php endif; ?>
    </div>

    <section class="journey-card">
      <h2><?= $is_owner ? 'Your membership' : e($membership['member_name']) . "'s membership" ?></h2>
      <?= get_status_badge($state) ?>
      <dl class="journey-facts">
        <dt>Club</dt><dd><?= e($membership['club_name']) ?></dd>
        <dt>Price</dt><dd><?= format_price($membership['price_snapshot']) ?></dd>
        <dt>Length</dt><dd><?= e($length) ?></dd>
        <?php if ($membership['receipt_uploaded_at']): ?>
          <dt>Receipt submitted</dt><dd><?= e(format_deadline($membership['receipt_uploaded_at'])) ?></dd>
        <?php endif; ?>
        <?php if ($membership['expires_at']): ?>
          <dt><?= $expiry_label ?></dt><dd><?= e(format_membership_date($membership['expires_at'])) ?></dd>
        <?php endif; ?>
      </dl>
      <div class="journey-callout"><strong>Next step</strong><p><?= e($next_step) ?></p></div>
      <div class="journey-actions">
        <a class="journey-button" href="<?= app_url('/club.php?id=' . $membership['club_id']) ?>">View club</a>
      </div>
    </section>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>