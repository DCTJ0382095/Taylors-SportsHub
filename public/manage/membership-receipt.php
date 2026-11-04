<?php

require_once __DIR__ . '/../../includes/db.php';

$securityFile = __DIR__ . '/../../includes/part_b_security.php';
if (!is_file($securityFile)) {
    http_response_code(503);
    exit('Membership receipts are awaiting the shared account integration.');
}
$security = require $securityFile;
$user = $security['require_login']();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$idOptions = ['options' => ['min_range' => 1]];
$clubId = filter_input(INPUT_GET, 'club_id', FILTER_VALIDATE_INT, $idOptions);
$membershipId = filter_input(INPUT_GET, 'membership_id', FILTER_VALIDATE_INT, $idOptions);
if (!$clubId || !$membershipId) {
    http_response_code(400);
    exit('Choose a valid club and application.');
}
$security['require_club_permission']($user['id'], $clubId, 'can_manage_memberships');

$statement = db()->prepare('SELECT receipt_path FROM memberships WHERE club_id = ? AND id = ?');
$statement->execute([$clubId, $membershipId]);
$path = $statement->fetchColumn();
if (!$path) {
    http_response_code(404);
    exit('Receipt not found.');
}
if (!isset($security['stream_membership_receipt'])) {
    http_response_code(503);
    exit('Receipt delivery is awaiting the shared upload integration.');
}

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$security['stream_membership_receipt']($path);
