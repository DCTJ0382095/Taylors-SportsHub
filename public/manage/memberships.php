<?php

require_once __DIR__ . '/../../includes/club_memberships.php';

$securityFile = __DIR__ . '/../../includes/part_b_security.php';
if (!is_file($securityFile)) {
    http_response_code(503);
    exit('Membership review is awaiting the shared account integration.');
}
$security = require $securityFile;
$user = $security['require_login']();

$idOptions = ['options' => ['min_range' => 1]];
$clubId = filter_input(INPUT_GET, 'club_id', FILTER_VALIDATE_INT, $idOptions);
if (!$clubId) {
    http_response_code(400);
    exit('Choose a valid club.');
}
$security['require_club_permission']($user['id'], $clubId, 'can_manage_memberships');

$statement = db()->prepare('SELECT name FROM clubs WHERE id = ?');
$statement->execute([$clubId]);
$club = $statement->fetch();
if (!$club) {
    http_response_code(404);
    exit('Club not found.');
}

$reviewUrl = 'memberships.php?club_id=' . $clubId;
$receiptUrl = static function (int $id) use ($clubId): string {
    return 'membership-receipt.php?club_id=' . $clubId . '&membership_id=' . $id;
};
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security['verify_csrf']($_POST['csrf_token'] ?? null);
    $membershipId = filter_input(INPUT_POST, 'membership_id', FILTER_VALIDATE_INT, $idOptions);
    $action = $_POST['action'] ?? '';
    if (!$membershipId || !in_array($action, ['approve', 'reject'], true)) {
        http_response_code(400);
        exit('Choose a valid membership action.');
    }
    if (reviewClubMembership($clubId, $membershipId, $user['id'], $action === 'approve')) {
        header('Location: ' . $reviewUrl, true, 303);
        exit;
    }
    http_response_code(409);
    $error = 'This application is no longer available for review.';
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$csrfToken = $security['csrf_token']();
$memberships = listClubMemberships($clubId);
require __DIR__ . '/../../includes/views/club_memberships.php';
