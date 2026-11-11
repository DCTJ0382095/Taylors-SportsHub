<?php
// Sends a membership receipt to the browser, but only to the student who uploaded it
// or to committee members who manage this club's sign-ups and payments.
// ?id=5 sends the latest receipt; ?id=5&receipt_id=9 sends an older one from the history.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
$db = get_db_connection();

$membership_id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT user_id, club_id, receipt_path FROM club_memberships WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $membership_id]);
$membership = $stmt->fetch();
if (!$membership) {
    render_error_page(404);
}
if ((int) $membership['user_id'] !== (int) $user['id']
    && !can_manage_club((int) $membership['club_id'], $user['id'], 'signups')) {
    render_error_page(403);
}

$path = $membership['receipt_path'];
if (isset($_GET['receipt_id'])) {
    // An older receipt: it must belong to this same membership, or anyone could guess numbers.
    $stmt = $db->prepare("SELECT receipt_path FROM membership_receipts WHERE id = :receipt_id AND membership_id = :id");
    $stmt->execute([':receipt_id' => (int) $_GET['receipt_id'], ':id' => $membership_id]);
    $path = $stmt->fetchColumn();
}
if (!$path) {
    render_error_page(404);
}

// Only ever send a file that really is inside the membership receipts folder.
// realpath() turns the path into its real, full form, so tricks like "../" can't escape the folder.
$folder = realpath(__DIR__ . '/assets/uploads/membership-receipts');
$file = realpath(__DIR__ . '/' . $path);
if (!$folder || !$file || !str_starts_with($file, $folder . DIRECTORY_SEPARATOR) || !is_file($file)) {
    render_error_page(404);
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    render_error_page(404);
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');      // the browser must trust our Content-Type, not guess
header('Cache-Control: private, no-store');     // don't keep a copy of someone's receipt
readfile($file);