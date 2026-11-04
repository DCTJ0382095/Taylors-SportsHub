<?php

require_once __DIR__ . '/db.php';

// Call these functions only after checking the actor's event-management
// permission for this club. Pass validated form data to saveClubEvent().

function listClubEvents(int $clubId): array
{
    $statement = db()->prepare(
        'SELECT * FROM events WHERE club_id = ? ORDER BY starts_at DESC, id DESC'
    );
    $statement->execute([$clubId]);

    return $statement->fetchAll();
}

function saveClubEvent(int $clubId, int $actorId, array $event, ?int $eventId = null): int
{
    $fields = [
        'title', 'description', 'sport', 'location', 'cover_image_path',
        'event_type', 'audience', 'allow_guests', 'starts_at', 'ends_at', 'capacity',
        'min_team_size', 'max_team_size', 'max_teams', 'team_fee',
        'registration_closes_at', 'member_price', 'student_price', 'guest_price', 'status',
    ];

    if (!in_array($event['status'], ['draft', 'published'], true)) {
        throw new InvalidArgumentException('Choose draft or published.');
    }

    $values = [];
    foreach ($fields as $field) {
        $values[] = $event[$field];
    }

    $pdo = db();
    if ($eventId === null) {
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_fill(0, count($fields) + 2, '?'));
        $statement = $pdo->prepare(
            "INSERT INTO events (club_id, host_user_id, $columns) VALUES ($placeholders)"
        );
        $statement->execute([$clubId, $actorId, ...$values]);

        return (int) $pdo->lastInsertId();
    }

    $editableStatus = $event['status'] === 'draft'
        ? "status = 'draft'"
        : "status IN ('draft', 'published')";
    $assignments = implode(' = ?, ', $fields) . ' = ?';
    $statement = $pdo->prepare(
        "UPDATE events SET $assignments
         WHERE id = ? AND club_id = ? AND $editableStatus"
    );
    $statement->execute([...$values, $eventId, $clubId]);

    // MySQL can report zero changed rows when the submitted values are identical.
    if ($statement->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM events WHERE id = ? AND club_id = ? AND $editableStatus");
        $check->execute([$eventId, $clubId]);
        if ($check->fetchColumn() === false) {
            throw new DomainException('Event is unavailable for this edit.');
        }
    }

    return $eventId;
}

function cancelClubEvent(int $clubId, int $eventId): bool
{
    $statement = db()->prepare(
        "UPDATE events SET status = 'cancelled'
         WHERE club_id = ? AND id = ? AND status IN ('draft', 'published')"
    );
    $statement->execute([$clubId, $eventId]);

    return $statement->rowCount() === 1;
}

function repeatClubEventNextWeek(int $clubId, int $eventId, int $actorId): ?int
{
    $pdo = db();
    $statement = $pdo->prepare(
        "INSERT INTO events (
             club_id, host_user_id, title, description, sport, location, cover_image_path,
             event_type, audience, allow_guests, starts_at, ends_at, capacity,
             min_team_size, max_team_size, max_teams, team_fee, registration_closes_at,
             member_price, student_price, guest_price, status
         )
         SELECT club_id, ?, title, description, sport, location, cover_image_path,
             event_type, audience, allow_guests,
             DATE_ADD(starts_at, INTERVAL 7 DAY), DATE_ADD(ends_at, INTERVAL 7 DAY), capacity,
             min_team_size, max_team_size, max_teams, team_fee,
             DATE_ADD(registration_closes_at, INTERVAL 7 DAY),
             member_price, student_price, guest_price, 'draft'
         FROM events WHERE club_id = ? AND id = ? AND status = 'published'"
    );
    $statement->execute([$actorId, $clubId, $eventId]);

    return $statement->rowCount() === 1 ? (int) $pdo->lastInsertId() : null;
}
