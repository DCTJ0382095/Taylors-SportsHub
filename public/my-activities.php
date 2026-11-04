<?php

require_once __DIR__ . '/../includes/student_activities.php';

$securityFile = __DIR__ . '/../includes/part_b_security.php';
if (!is_file($securityFile)) {
    http_response_code(503);
    exit('My Activities is awaiting the shared account integration.');
}
$security = require $securityFile;
$user = $security['require_login']();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$activities = getMyActivities($user['id']);
require __DIR__ . '/../includes/views/my_activities.php';
