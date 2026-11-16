<?php
// Session payment evidence is checked and saved under a random filename, and the registration is moved
// to awaiting_approval.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();

// Non-POST requests are redirected to the activities page.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . app_url('/my-activities.php'));
    exit;
}

// A missing or wrong CSRF token is sent back with an error message.
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header("Location: " . app_url('/my-activities.php?msg=csrf_error'));
    exit;
}

// Overdue reservations are expired first.
$reg_id = (int)($_POST['registration_id'] ?? 0);
$db = get_db_connection();

expire_overdue_reservations();

// The session row is locked first (the same lock order as reserve.php), then the student's own registration,
// so the status cannot change halfway through the upload.
$db->beginTransaction();
$lock = $db->prepare('SELECT s.id FROM sessions s JOIN registrations r ON r.session_id=s.id WHERE r.id=?');
$lock->execute([$reg_id]);
$session_lock_id = $lock->fetchColumn();
// The related session is locked before the registration when a session ID is found.
if ($session_lock_id) {
    $lock = $db->prepare('SELECT id FROM sessions WHERE id=? FOR UPDATE');
    $lock->execute([$session_lock_id]);
}
$stmt = $db->prepare("SELECT r.*, s.session_date, s.start_time, s.status AS session_status
                      FROM registrations r
                      INNER JOIN sessions s ON r.session_id = s.id
                      WHERE r.id = :id AND r.user_id = :uid LIMIT 1 FOR UPDATE");
$stmt->execute([':id' => $reg_id, ':uid' => $user['id']]);
$reg = $stmt->fetch();

// Updates are restricted to the current student's registration. Other records are handled with a 404
// response.
if (!$reg) {
    $db->rollBack();
    render_error_page(404);
}

// Uploads are closed for cancelled sessions.
if ($reg['session_status'] === 'cancelled') {
    $db->rollBack();
    header('Location: ' . app_url('/payment.php?id=' . $reg_id . '&msg=session_cancelled'));
    exit;
}

// Uploads are accepted only while payment is due or after a rejection.
if (!in_array($reg['status'], ['awaiting_payment', 'payment_rejected'])) {
    $db->rollBack();
    header("Location: " . app_url('/payment.php?id=' . $reg_id . '&msg=invalid_status'));
    exit;
}

// Late uploads are refused, and the reservation is expired to release the held place.
$deadline_ts = strtotime($reg['payment_deadline'] ?? '');
$session_start = strtotime($reg['session_date'] . ' ' . $reg['start_time']);

// Expired receipt windows are rejected and the reservation is released.
if (($deadline_ts && $deadline_ts <= time()) || $session_start <= time()) {
    $stmt = $db->prepare("UPDATE registrations SET status = 'expired' WHERE id = :id");
    $stmt->execute([':id' => $reg_id]);
    $db->commit();
    header("Location: " . app_url('/payment.php?id=' . $reg_id . '&msg=deadline_passed'));
    exit;
}

// Successful upload transfer is required before file validation.
if (empty($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    $db->rollBack();
    header("Location: " . app_url('/payment.php?id=' . $reg_id . '&msg=upload_failed'));
    exit;
}

$file = $_FILES['receipt'];
$max_size = 5 * 1024 * 1024; // A five-megabyte upload limit is enforced.

if ($file['size'] > $max_size) {
    $db->rollBack();
    header("Location: " . app_url('/payment.php?id=' . $reg_id . '&msg=file_too_large'));
    exit;
}

// The real file type is read from the file's contents, and only images and PDFs are accepted.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$allowed_mimes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

// Unsupported receipt content types are rejected.
if (!isset($allowed_mimes[$mime])) {
    $db->rollBack();
    header("Location: " . app_url('/payment.php?id=' . $reg_id . '&msg=invalid_file_type'));
    exit;
}

// The file is saved under a random name in assets/uploads/receipts (blocked from direct web access),
// with an extension chosen by the server.
$ext = $allowed_mimes[$mime];
$filename = 'receipt_' . $reg_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$target_dir = __DIR__ . '/assets/uploads/receipts';
// The session receipt directory is created when absent.
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true);
}

$target_path = $target_dir . '/' . $filename;
$relative_path = 'assets/uploads/receipts/' . $filename;

// The uploaded file is moved into storage. Failed moves are handled without updating the registration.
if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    $db->rollBack();
    header("Location: " . app_url('/payment.php?id=' . $reg_id . '&msg=save_failed'));
    exit;
}

// The receipt is recorded, and the registration is moved to awaiting_approval.
try {
    // Each submission is kept in payment_receipts, separately from the registration's current receipt pointer.
    $stmt = $db->prepare('INSERT INTO payment_receipts (registration_id, receipt_path, submitted_at) VALUES (?, ?, NOW())');
    $stmt->execute([$reg_id, $relative_path]);
    // A payment-due or rejected registration has no active cancellation request,
    // so stale flags from earlier unpaid attempts are cleared.
    $stmt = $db->prepare("UPDATE registrations
                          SET status = 'awaiting_approval', receipt_path = :path, receipt_uploaded_at = NOW(),
                              cancellation_requested_at = NULL, cancellation_reason = NULL, updated_at = NOW()
                          WHERE id = :id");
    $stmt->execute([
        ':path' => $relative_path,
        ':id' => $reg_id,
    ]);
    $db->commit();
} /* Failed receipt transactions are rolled back before file cleanup. */ catch (Throwable $e) {
    // On failure the transaction is rolled back and the saved file is deleted, so no orphan file is left behind.
    if ($db->inTransaction()) $db->rollBack();
    // A receipt saved during the failed transaction is removed.
    if (is_file($target_path)) unlink($target_path);
    throw $e;
}

// The student is sent back to the registration page with a success message.
header("Location: " . app_url('/payment.php?id=' . $reg_id . '&msg=uploaded'));
exit;
