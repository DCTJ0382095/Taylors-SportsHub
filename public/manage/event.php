<?php

require_once __DIR__ . '/../../includes/club_events.php';
require_once __DIR__ . '/../../includes/club_event_form.php';

$securityFile = __DIR__ . '/../../includes/part_b_security.php';
if (!is_file($securityFile)) {
    http_response_code(503);
    exit('Club event management is awaiting the shared account integration.');
}
$security = require $securityFile;

$idOptions = ['options' => ['min_range' => 1]];
$clubId = filter_input(INPUT_GET, 'club_id', FILTER_VALIDATE_INT, $idOptions);
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT, $idOptions);
if (!$clubId || $eventId === false) {
    http_response_code(400);
    exit('Choose a valid club and event.');
}

$user = $security['require_login']();
$security['require_club_permission']($user['id'], $clubId, 'can_manage_events');

$event = [];
if ($eventId !== null) {
    $statement = db()->prepare("SELECT * FROM events WHERE club_id = ? AND id = ? AND status <> 'cancelled'");
    $statement->execute([$clubId, $eventId]);
    $event = $statement->fetch();
    if (!$event) {
        http_response_code(404);
        exit('Event not found.');
    }
} else {
    $statement = db()->prepare('SELECT 1 FROM clubs WHERE id = ?');
    $statement->execute([$clubId]);
    if ($statement->fetchColumn() === false) {
        http_response_code(404);
        exit('Club not found.');
    }
}

$dashboardUrl = 'events.php?club_id=' . $clubId;
$editorUrl = 'event.php?club_id=' . $clubId;
if ($eventId !== null) {
    $editorUrl .= '&event_id=' . $eventId;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security['verify_csrf']($_POST['csrf_token'] ?? null);
    $input = $_POST;
    unset($input['csrf_token']);
    $newCover = null;
    try {
        $data = clubEventFormData($input, $event['cover_image_path'] ?? null);
        $upload = $_FILES['cover_image'] ?? null;
        if ($upload !== null && ($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
            if (!isset($security['upload_event_cover'], $security['discard_event_cover'])) {
                throw new DomainException('Cover uploads are awaiting the shared upload integration.');
            }
            $newCover = $security['upload_event_cover']($upload);
            $data['cover_image_path'] = $newCover;
        }

        saveClubEvent($clubId, $user['id'], $data, $eventId);
        header('Location: ' . $dashboardUrl, true, 303);
        exit;
    } catch (Throwable $failure) {
        if ($newCover !== null) {
            $security['discard_event_cover']($newCover);
        }
        if (!$failure instanceof InvalidArgumentException && !$failure instanceof DomainException) {
            throw $failure;
        }

        http_response_code(422);
        $error = $failure->getMessage();
        $originalStatus = $event['status'] ?? 'draft';
        $event = array_replace($event, array_filter($input, 'is_string'));
        $event['status'] = $originalStatus;
        $event['allow_guests'] = ($input['allow_guests'] ?? null) === '1';
        if ($eventId === null) {
            unset($event['id']);
        } else {
            $event['id'] = $eventId;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$csrfToken = $security['csrf_token']();
require __DIR__ . '/../../includes/views/club_event_editor.php';
