<?php
// Membership evidence is served to the owner and accounts with club sign-ups permission. The latest
// receipt or a requested historical receipt is selected.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
$db = get_db_connection();

// Membership ownership and club details are loaded. Unknown IDs are handled with a 404 response.
$membership_id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT user_id, club_id, receipt_path FROM club_memberships WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $membership_id]);
$membership = $stmt->fetch();
// Unknown memberships are handled with a 404 response.
if (!$membership) {
    render_error_page(404);
}
// Access is limited to the owner and to committee members with the sign-ups permission.
if ((int) $membership['user_id'] !== (int) $user['id']
    && !can_manage_club((int) $membership['club_id'], $user['id'], 'signups')) {
    render_error_page(403);
}

// The latest receipt is sent by default.
$path = $membership['receipt_path'];
// A requested historical receipt is selected within the membership.
if (isset($_GET['receipt_id'])) {
    // Historical receipts are restricted to the selected membership.
    $stmt = $db->prepare("SELECT receipt_path FROM membership_receipts WHERE id = :receipt_id AND membership_id = :id");
    $stmt->execute([':receipt_id' => (int) $_GET['receipt_id'], ':id' => $membership_id]);
    $path = $stmt->fetchColumn();
}
// Missing receipt paths are handled with a 404 response.
if (!$path) {
    render_error_page(404);
}

// The resolved receipt path is checked for containment within the membership receipt directory.
$folder = realpath(__DIR__ . '/assets/uploads/membership-receipts');
$file = realpath(__DIR__ . '/' . $path);
// Files outside the membership receipt directory and missing files are rejected.
if (!$folder || !$file || !str_starts_with($file, $folder . DIRECTORY_SEPARATOR) || !is_file($file)) {
    render_error_page(404);
}
// Only images and PDFs are sent, judged by the file's real contents.
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
// Unsupported receipt content types are rejected.
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    render_error_page(404);
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');      // Content-type guessing is disabled through the nosniff response header.
header('Cache-Control: private, no-store');     // Cache storage is discouraged through private, no-store response directives.
readfile($file);