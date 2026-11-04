<?php

require_once __DIR__ . '/../includes/student_activities.php';

$securityFile = __DIR__ . '/../includes/part_b_security.php';
if (!is_file($securityFile)) {
    http_response_code(503);
    exit('Student event hosting is awaiting the shared account integration.');
}
$security = require $securityFile;
$user = $security['require_login']();
if ($user['account_type'] !== 'student') {
    http_response_code(403);
    exit('Only students can host a student event.');
}

$formUrl = 'host-event.php';
$input = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security['verify_csrf']($_POST['csrf_token'] ?? null);
    $input = $_POST;
    unset($input['csrf_token']);
    $cover = null;
    try {
        $upload = $_FILES['cover_image'] ?? null;
        if ($upload !== null && ($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
            if (!isset($security['upload_event_cover'], $security['discard_event_cover'])) {
                throw new DomainException('Cover uploads are awaiting the shared upload integration.');
            }
            $cover = $security['upload_event_cover']($upload);
        }

        hostStudentEvent($user['id'], $input, $cover);
        header('Location: my-activities.php', true, 303);
        exit;
    } catch (Throwable $failure) {
        if ($cover !== null) {
            $security['discard_event_cover']($cover);
        }
        if (!$failure instanceof InvalidArgumentException && !$failure instanceof DomainException) {
            throw $failure;
        }

        http_response_code(422);
        $error = $failure->getMessage();
        $input = array_filter($input, 'is_string');
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$csrfToken = $security['csrf_token']();
require __DIR__ . '/../includes/views/student_event_form.php';
