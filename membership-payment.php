<?php
// Membership payment instructions, receipt upload and status are displayed. Uploads are restricted to
// the owner, and read-only access is granted through sign-ups permission.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
$db = get_db_connection();

// Membership, club and account details are loaded. Unknown IDs are handled with a 404 response.
$membership_id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT m.*, c.name AS club_name, c.duitnow_qr_path, u.name AS member_name
                      FROM club_memberships m
                      INNER JOIN clubs c ON c.id = m.club_id
                      INNER JOIN users u ON u.id = m.user_id
                      WHERE m.id = :id LIMIT 1");
$stmt->execute([':id' => $membership_id]);
$membership = $stmt->fetch();
// Unknown memberships are handled with a 404 response.
if (!$membership) {
    render_error_page(404);
}

$is_owner = (int) $membership['user_id'] === (int) $user['id'];
// Membership access is refused without ownership or club sign-ups permission.
if (!$is_owner && !can_manage_club((int) $membership['club_id'], $user['id'], 'signups')) {
    render_error_page(403);   // Access is refused when neither ownership nor club sign-ups permission is established.
}

// Uploads are open only to the owner, while payment is due or after a rejection.
$state = membership_state($membership);
$can_upload = $is_owner && in_array($state, ['awaiting_payment', 'payment_rejected'], true);
$error = null;

// An uploaded receipt is checked and saved in assets/uploads/membership-receipts/.
// [saved path, null] is returned on success, or [null, error message] on failure.
function save_membership_receipt(?array $file, int $membership_id): array {
    // Failed uploads and files over 5 MB are rejected first.
    if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The receipt was not uploaded. Choose the file again and retry.'];
    }
    // Receipt files above five megabytes are rejected.
    if ($file['size'] > 5 * 1024 * 1024) {
        return [null, 'The receipt must be 5 MB or smaller.'];
    }
    // The real file type is determined from contents, and only allowed image formats and PDFs are accepted.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    // Unsupported receipt content types are rejected.
    if (!isset($extensions[$mime])) {
        return [null, 'The receipt must be a JPG, PNG, WebP or PDF file.'];
    }
    // Direct web access to the upload directory is blocked by Apache configuration.
    $folder = __DIR__ . '/assets/uploads/membership-receipts';
    // The membership receipt directory is created when absent.
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
    // A random filename is generated with an extension selected from the detected content type.
    $filename = 'membership_' . $membership_id . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    // Failed receipt moves are returned as upload errors.
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $filename)) {
        return [null, 'The receipt could not be saved. Try again.'];
    }
    return ['assets/uploads/membership-receipts/' . $filename, null];
}

// Form submission: the CSRF token and the membership's state are checked before the file is saved.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Invalid CSRF tokens are reported before membership receipt uploads.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';
    } /* Uploads are refused without owner access and an eligible payment state. */ elseif (!$can_upload) {
        $error = 'This membership is not waiting for a receipt.';
    } /* Eligible receipt uploads are validated and stored. */ else {
        [$path, $error] = save_membership_receipt($_FILES['receipt'] ?? null, $membership_id);
        // Successfully saved receipts are linked to the membership inside a transaction.
        if ($path) {
            // Membership and receipt-history changes are saved together in a transaction.
            $db->beginTransaction();
            // The update is restricted to receipt-eligible statuses to detect concurrent state changes.
            $stmt = $db->prepare("UPDATE club_memberships
                                  SET status = 'awaiting_approval', receipt_path = :path,
                                      receipt_uploaded_at = NOW(), rejection_reason = NULL
                                  WHERE id = :id AND status IN ('awaiting_payment', 'payment_rejected')");
            $stmt->execute([':path' => $path, ':id' => $membership_id]);
            // Receipt history is inserted only after the pending membership row is updated.
            if ($stmt->rowCount() === 1) {
                // Every receipt is kept in the history, not just the latest one.
                $stmt = $db->prepare("INSERT INTO membership_receipts (membership_id, receipt_path, submitted_at)
                                      VALUES (:id, :path, NOW())");
                $stmt->execute([':id' => $membership_id, ':path' => $path]);
                $db->commit();
                header('Location: ' . app_url('/membership-payment.php?id=' . $membership_id . '&msg=uploaded'), true, 303);
                exit;
            }
            $db->rollBack();
            unlink(__DIR__ . '/' . $path);   // The saved file is removed when no membership row is updated.
            $error = 'This membership is not waiting for a receipt.';
        }
    }
}

// State-specific headings, membership duration and next-step text are prepared.
$length = format_membership_length((int) $membership['months_snapshot']);
$headings = [
    'awaiting_payment'  => 'Pay for your membership',
    'payment_rejected'  => 'Resubmit your receipt',
    'awaiting_approval' => 'Receipt submitted',
    'active'            => "You're a member",
    'expired'           => 'This membership has expired',
];
$next_step = membership_next_step($membership);
// The expiry date is labelled differently depending on the state.
$expiry_label = /* The expiry label is selected from the current membership state. */ match ($state) {
    'active'  => 'Expires',
    'expired' => 'Expired on',
    // The previous-term label is used for pending renewal states.
    default   => 'Previous membership ended',   // The previous expiry date is labelled for a renewal awaiting approval.
};

$page_title = 'Membership · ' . $membership['club_name'];
require_once __DIR__ . '/includes/header.php';
?>
<!-- The journey page markup is rendered. --><div class="journey-page">
  <!-- Membership navigation and the state-specific heading are displayed. -->
  <header class="journey-header">
    <!-- The journey breadcrumb markup is rendered. --><nav class="journey-breadcrumb" aria-label="Breadcrumb">
      <?php /* Owners are linked back to the membership overview. */ if ($is_owner): ?>
        <a href="<?= app_url('/memberships.php') ?>">Memberships</a> /
        <span class="breadcrumb-current"><?= e($membership['club_name']) ?></span>
      <?php /* Sign-ups managers are linked back to the manager workspace. */ else: ?>
        <a href="<?= app_url('/manager/sessions.php') ?>">Manager Workspace</a> /
        <a href="<?= app_url('/manager/sessions.php?status=memberships') ?>">Membership Sign-ups</a> /
        <span class="breadcrumb-current"><?= e($membership['member_name']) ?></span>
      <?php endif; ?>
    </nav>
    <h1><?= e($headings[$state]) ?></h1>
    <p class="journey-subtitle"><?= e($membership['club_name']) ?> · <?= e($length) ?> membership</p>
  </header>

  <!-- Membership feedback and receipt rejection details are displayed. -->
  <?php membership_feedback(); ?>
  <?php /* Membership payment errors are displayed. */ if ($error): ?>
    <div class="journey-notice notice-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <?php /* A recorded payment rejection reason is displayed. */ if ($state === 'payment_rejected' && $membership['rejection_reason']): ?>
    <!-- The journey notice markup is rendered. --><div class="journey-notice notice-error"><strong>Why the receipt was rejected</strong><p><?= e($membership['rejection_reason']) ?></p></div>
  <?php endif; ?>

  <!-- The journey columns markup is rendered. --><div class="journey-columns">
    <!-- Payment, receipt evidence and history are grouped in the main column. -->
    <div>
      <!-- QR and bank instructions are displayed while receipt upload is allowed. -->
      <?php /* Payment instructions and upload controls are displayed while uploads are allowed. */ if ($can_upload): ?>
        <section class="journey-card">
          <h2>Pay <?= format_price($membership['price_snapshot']) ?></h2>
          <!-- The payment transfer split markup is rendered. --><div class="payment-transfer-split">
            <!-- The payment qr col markup is rendered. --><div class="payment-qr-col">
              <!-- The duitnow qr card markup is rendered. --><div class="duitnow-qr-card">
                <!-- The duitnow qr image wrapper markup is rendered. --><div class="duitnow-qr-image-wrapper">
                  <?php $qr_url = club_qr_url($membership['duitnow_qr_path']); ?>
                  <?php /* The club QR image is displayed when available. */ if ($qr_url): ?>
                    <img src="<?= e($qr_url) ?>" alt="DuitNow QR code for <?= e($membership['club_name']) ?>" class="duitnow-qr-img" width="150" height="206">
                  <?php /* Missing QR setup is explained alongside payment instructions. */ else: ?>
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
                <p style="white-space:pre-line; margin-top: 6px;"><?= e($membership['payment_instructions_snapshot'] ?: 'Details unavailable. Contact the club before transferring.') ?></p>
              </div>
              <p class="journey-help">After paying, keep your receipt and upload it below. Paying alone does not confirm your membership.</p>
            </div>
          </div>

          <!-- Receipt upload is enhanced by the shared script and validated again on the server. -->
          <h2>Upload your receipt</h2>
          <!-- The form controls markup is rendered. --><form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <!-- The journey field markup is rendered. --><div class="journey-field upload-dropzone" id="receipt-dropzone">
              <label for="receipt">Receipt file</label>
              <p class="journey-help" id="receipt-hint">JPG, PNG, WebP or PDF · Up to 5 MB. Choose a file or drop it here.</p>
              <input type="file" id="receipt" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required aria-describedby="receipt-hint receipt-error">
              <p id="receipt-error" class="field-error" aria-live="polite"></p>
            </div>
            <!-- The receipt upload preview markup is rendered. --><div id="receipt-upload-preview" hidden class="journey-callout" aria-live="polite"><span class="preview-name"></span><span class="preview-size"></span></div>
            <button type="submit" class="journey-button primary" data-pending-label="Submitting receipt…">Submit receipt</button>
            <p class="journey-help">The committee checks your receipt before your membership starts.</p>
          </form>
        </section>
      <?php endif; ?>

      <!-- The latest receipt is linked or missing evidence is explained. -->
      <?php /* Existing receipt evidence is linked for the membership. */ if ($membership['receipt_path']): ?>
        <section class="journey-card">
          <h2>Submitted receipt</h2>
          <p class="journey-help">Only the member and the club's sign-up managers can open it.</p>
          <a class="journey-button" href="<?= app_url('/membership-receipt.php?id=' . $membership_id) ?>" target="_blank" rel="noopener">Open receipt ↗</a>
        </section>
      <?php /* The current membership state is explained when upload is unavailable and no receipt is recorded. */ elseif (!$can_upload): ?>
        <!-- The journey card markup is rendered. --><section class="journey-card">
          <h2>Awaiting receipt</h2>
          <p><?= e($next_step) ?></p>
        </section>
      <?php endif; ?>

      <!-- Membership receipt history is rendered. -->
      <?php render_membership_receipt_history($membership_id); ?>
    </div>

    <!-- Membership facts, next steps and club navigation are displayed. -->
    <section class="journey-card">
      <!-- The journey card header markup is rendered. --><div class="journey-card-header">
        <h2><?= $is_owner ? 'Your membership' : e($membership['member_name']) . "'s membership" ?></h2>
        <?= get_status_badge($state) ?>
      </div>
      <dl class="journey-facts">
        <dt>Club</dt><dd><?= e($membership['club_name']) ?></dd>
        <dt>Price</dt><dd><?= format_price($membership['price_snapshot']) ?></dd>
        <dt>Length</dt><dd><?= e($length) ?></dd>
        <?php /* The receipt submission time is displayed when recorded. */ if ($membership['receipt_uploaded_at']): ?>
          <dt>Receipt submitted</dt><dd><?= e(format_deadline($membership['receipt_uploaded_at'])) ?></dd>
        <?php endif; ?>
        <?php /* A recorded membership expiry date is displayed with the state-specific label. */ if ($membership['expires_at']): ?>
          <dt><?= $expiry_label ?></dt><dd><?= e(format_membership_date($membership['expires_at'])) ?></dd>
        <?php endif; ?>
      </dl>
      <!-- The journey callout markup is rendered. --><div class="journey-callout"><strong>Next step</strong><p><?= e($next_step) ?></p></div>
      <!-- The journey actions markup is rendered. --><div class="journey-actions">
        <a class="journey-button" href="<?= app_url('/club.php?id=' . $membership['club_id']) ?>">View club</a>
      </div>
    </section>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>