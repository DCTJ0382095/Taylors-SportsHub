<?php

require_once __DIR__ . '/../../includes/club_events.php';

// A's integration file adapts the shared security helpers to this contract.
$securityFile = __DIR__ . '/../../includes/part_b_security.php';
if (!is_file($securityFile)) {
    http_response_code(503);
    exit('Club event management is awaiting the shared account integration.');
}
$security = require $securityFile;

$clubId = filter_input(INPUT_GET, 'club_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$clubId) {
    http_response_code(400);
    exit('Choose a valid club.');
}

$user = $security['require_login']();
$security['require_club_permission']($user['id'], $clubId, 'can_manage_events');

$statement = db()->prepare('SELECT id, name FROM clubs WHERE id = ?');
$statement->execute([$clubId]);
$club = $statement->fetch();
if (!$club) {
    http_response_code(404);
    exit('Club not found.');
}

$dashboardUrl = 'events.php?club_id=' . $clubId;
$editorUrl = 'event.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security['verify_csrf']($_POST['csrf_token'] ?? null);
    $eventId = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = $_POST['action'] ?? '';
    if (!$eventId || !in_array($action, ['repeat', 'cancel'], true)) {
        http_response_code(400);
        exit('Choose a valid event action.');
    }

    $changed = $action === 'repeat'
        ? repeatClubEventNextWeek($clubId, $eventId, $user['id']) !== null
        : cancelClubEvent($clubId, $eventId);
    if ($changed) {
        header('Location: ' . $dashboardUrl, true, 303);
        exit;
    }

    http_response_code(409);
    $error = 'This event is unavailable for that action.';
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$csrfToken = $security['csrf_token']();
$events = listClubEvents($clubId);
require __DIR__ . '/../../includes/views/club_events_dashboard.php';
