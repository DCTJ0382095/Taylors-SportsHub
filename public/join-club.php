<?php

require_once __DIR__ . '/../includes/club_memberships.php';

$securityFile = __DIR__ . '/../includes/part_b_security.php';
if (!is_file($securityFile)) {
    http_response_code(503);
    exit('Membership applications are awaiting the shared account integration.');
}
$security = require $securityFile;
$user = $security['require_login']();

$clubId = filter_input(INPUT_GET, 'club_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$clubId) {
    http_response_code(400);
    exit('Choose a valid club.');
}

$statement = db()->prepare(
    'SELECT name, membership_fee, membership_months, allow_guest_members, duitnow_qr_path
     FROM clubs WHERE id = ?'
);
$statement->execute([$clubId]);
$club = $statement->fetch();
if (!$club) {
    http_response_code(404);
    exit('Club not found.');
}
if ($user['account_type'] === 'guest' && !$club['allow_guest_members']) {
    http_response_code(403);
    exit('This club does not accept guest members.');
}

$formUrl = 'join-club.php?club_id=' . $clubId;
$qrUrl = $club['duitnow_qr_path'] && isset($security['public_qr_url'])
    ? $security['public_qr_url']($club['duitnow_qr_path'])
    : null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security['verify_csrf']($_POST['csrf_token'] ?? null);
    $receipt = null;
    try {
        if ((float) $club['membership_fee'] > 0) {
            if ($qrUrl === null || !isset($security['upload_membership_receipt'], $security['discard_membership_receipt'])) {
                throw new DomainException('Membership payments are currently unavailable.');
            }
            if (!isset($_FILES['receipt'])) {
                throw new InvalidArgumentException('Upload your membership payment receipt.');
            }
            $receipt = $security['upload_membership_receipt']($_FILES['receipt']);
        }
        applyForClubMembership($clubId, $user['id'], $receipt);
        header('Location: my-activities.php', true, 303);
        exit;
    } catch (Throwable $failure) {
        if ($receipt !== null) {
            $security['discard_membership_receipt']($receipt);
        }
        if (!$failure instanceof InvalidArgumentException && !$failure instanceof DomainException) {
            throw $failure;
        }
        http_response_code(422);
        $error = $failure->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$csrfToken = $security['csrf_token']();
require __DIR__ . '/../includes/views/membership_application.php';
