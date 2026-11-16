<?php
// Payment and refund evidence is served after ownership or club-permission checks. The latest receipt,
// a historical receipt or refund proof is selected through request parameters.
require_once __DIR__ . "/includes/auth.php";
$user = require_login();
$db = get_db_connection();
// The registration is loaded with the associated owner and club.
$id = (int) ($_GET["registration_id"] ?? 0);
$q = $db->prepare(
    "SELECT r.user_id,r.receipt_path,s.club_id FROM registrations r INNER JOIN sessions s ON r.session_id=s.id WHERE r.id=?",
);
$q->execute([$id]);
$r = $q->fetch();
// Unknown registrations are shown the 404 page.
if (!$r) {
    render_error_page(404);
}
// Evidence access is granted to the registration owner and accounts with the club's sign-ups
// permission.
if ($r["user_id"] !== $user["id"] && !can_manage_club($r["club_id"], $user["id"], 'signups')) {
    render_error_page(403);
}
// The latest receipt is served by default. An older receipt or a refund proof can be asked for instead,
// and either one must belong to this same registration.
$path = $r["receipt_path"];
// The requested historical payment receipt is selected within the registration.
if (isset($_GET['receipt_id'])) {
    $q = $db->prepare('SELECT receipt_path FROM payment_receipts WHERE id=? AND registration_id=?');
    $q->execute([(int) $_GET['receipt_id'], $id]);
    $path = $q->fetchColumn();
} /* The requested refund proof is selected within the registration. */ elseif (isset($_GET["refund_id"])) {
    $q = $db->prepare("SELECT proof_path FROM refund_records WHERE id=? AND registration_id=?");
    $q->execute([(int) $_GET["refund_id"], $id]);
    $path = $q->fetchColumn();
}
// The resolved file path is checked for existence and containment within assets/uploads.
$file = $path ? realpath(__DIR__ . "/" . $path) : false;
$uploads = realpath(__DIR__ . "/assets/uploads");
// Missing files and paths outside the upload directory are rejected.
if (
    !$file ||
    !$uploads ||
    !str_starts_with(str_replace("\\", "/", $file), str_replace("\\", "/", $uploads) . "/") ||
    !is_file($file)
) {
    render_error_page(404);
}
// Only images and PDFs are served, judged by the file's real contents.
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
// Unsupported evidence content types are rejected.
if (!in_array($mime, ["image/jpeg", "image/png", "image/webp", "application/pdf"], true)) {
    render_error_page(404);
}
// A fixed inline filename and the detected content type are sent. MIME sniffing and storage in caches
// are discouraged through response headers.
header("Content-Type: " . $mime);
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, no-store");
header('Content-Disposition: inline; filename="evidence.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
readfile($file);
