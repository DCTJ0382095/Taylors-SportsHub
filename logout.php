<?php
// Log-out endpoint. Only POST requests with a valid CSRF token are accepted,
// so a link or image on another site cannot sign a user out.
require_once __DIR__ . '/includes/auth.php';

// Any other request method is refused with 405 Method Not Allowed.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
// A missing or wrong CSRF token is refused with the 403 page.
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    render_error_page(403);
}

// The session is ended, and a 303 redirect to the home page is issued.
logout_user();
header('Location: ' . app_url('/index.php'), true, 303);
exit;
