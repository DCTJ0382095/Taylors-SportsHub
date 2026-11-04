<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/club_event_form.php';

// Call after authentication and CSRF verification. Use the signed-in user ID
// and a cover path supplied by the secure upload handler.
function hostStudentEvent(int $userId, array $input, ?string $coverImagePath): int
{
    $settings = [
        'event_type' => 'session',
        'audience' => 'open',
        'member_price' => '0',
        'student_price' => '0',
        'guest_price' => '0',
        'status' => 'published',
    ];
    $event = clubEventFormData($settings + $input, $coverImagePath);

    $pdo = db();
    $statement = $pdo->prepare(
        "INSERT INTO events (
            host_user_id, title, description, sport, location, cover_image_path,
            event_type, audience, allow_guests, starts_at, ends_at, capacity, status
         )
         SELECT id, ?, ?, ?, ?, ?, 'session', 'open', ?, ?, ?, ?, 'published'
         FROM users WHERE id = ? AND account_type = 'student'"
    );
    $statement->execute([
        $event['title'], $event['description'], $event['sport'], $event['location'],
        $event['cover_image_path'], $event['allow_guests'], $event['starts_at'],
        $event['ends_at'], $event['capacity'], $userId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new DomainException('Only students can host a student event.');
    }

    return (int) $pdo->lastInsertId();
}

// Use the signed-in user ID.
function getMyActivities(int $userId): array
{
    $pdo = db();
    $signups = $pdo->prepare(
        'SELECT r.id, r.status, r.amount_due, r.event_id, e.title, e.sport,
                e.starts_at, e.status AS event_status, c.name AS club_name, t.name AS team_name
         FROM registrations r JOIN events e ON e.id = r.event_id
         LEFT JOIN clubs c ON c.id = e.club_id
         LEFT JOIN teams t ON t.id = r.team_id
         WHERE r.user_id = ?
         ORDER BY e.starts_at DESC, r.id DESC'
    );
    $signups->execute([$userId]);

    $memberships = $pdo->prepare(
        "SELECT m.id, m.club_id, m.status, m.starts_at, m.expires_at, c.name AS club_name,
                (m.status = 'approved' AND m.starts_at <= NOW() AND m.expires_at > NOW()) AS is_active
         FROM memberships m JOIN clubs c ON c.id = m.club_id
         WHERE m.user_id = ?
         ORDER BY m.applied_at DESC, m.id DESC"
    );
    $memberships->execute([$userId]);

    $hosted = $pdo->prepare(
        'SELECT id, title, sport, starts_at, status
         FROM events WHERE host_user_id = ? AND club_id IS NULL
         ORDER BY starts_at DESC, id DESC'
    );
    $hosted->execute([$userId]);

    $clubs = $pdo->prepare(
        'SELECT c.id, c.name, (c.president_id = ?) AS is_president
         FROM clubs c
         LEFT JOIN club_staff s ON s.club_id = c.id AND s.user_id = ?
         WHERE c.president_id = ? OR s.user_id IS NOT NULL
         ORDER BY c.name, c.id'
    );
    $clubs->execute([$userId, $userId, $userId]);

    return [
        'signups' => $signups->fetchAll(),
        'memberships' => $memberships->fetchAll(),
        'hosted_events' => $hosted->fetchAll(),
        'managed_clubs' => $clubs->fetchAll(),
    ];
}
