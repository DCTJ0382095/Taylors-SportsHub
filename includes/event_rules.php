<?php

require_once __DIR__ . '/db.php';

function hasActiveMembership(int $userId, int $clubId, DateTimeImmutable $now): bool
{
    $statement = db()->prepare(
        "SELECT 1 FROM memberships
         WHERE user_id = ? AND club_id = ? AND status = 'approved'
           AND starts_at <= ? AND expires_at > ?
         LIMIT 1"
    );
    $timestamp = $now->format('Y-m-d H:i:s');
    $statement->execute([$userId, $clubId, $timestamp, $timestamp]);

    return $statement->fetchColumn() !== false;
}

/**
 * Check the audience and signup window for an authenticated user.
 * Check capacity and duplicate signups separately in the registration transaction.
 * Use the same local time zone as the event dates stored in MySQL.
 */
function canJoinEvent(
    array $event,
    array $user,
    bool $hasActiveMembership,
    DateTimeImmutable $now
): bool {
    $timestamp = $now->format('Y-m-d H:i:s');
    if ($event['status'] !== 'published' || $timestamp >= $event['starts_at']) {
        return false;
    }

    if ($event['registration_closes_at'] !== null
        && $timestamp >= $event['registration_closes_at']) {
        return false;
    }

    if ($user['account_type'] === 'guest' && !(bool) $event['allow_guests']) {
        return false;
    }

    return $event['audience'] !== 'members_only' || $hasActiveMembership;
}

/**
 * Return an exact MYR decimal string. For teams, this is the full fee
 * charged once to the captain. Call canJoinEvent() before accepting a signup.
 */
function calculatePrice(array $event, array $user, bool $hasActiveMembership): string
{
    if ($event['club_id'] === null) {
        return '0.00';
    }

    if ($event['team_fee'] !== null) {
        return (string) $event['team_fee'];
    }

    if ($hasActiveMembership) {
        return (string) $event['member_price'];
    }

    return (string) $event[$user['account_type'] === 'student' ? 'student_price' : 'guest_price'];
}
