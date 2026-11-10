<?php
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    render_error_page(403);
}

logout_user();
header('Location: ' . app_url('/index.php'), true, 303);
exit;
