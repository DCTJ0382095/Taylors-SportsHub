<?php
// Helper functions for club memberships: students pay a club to become a member for a set time.

// The membership lengths a committee can choose from, in months.
const MEMBERSHIP_LENGTH_OPTIONS = [1, 3, 6, 12];

// This student's membership of a club, or null if they have never applied to it.
function get_user_club_membership(int $club_id, int $user_id): ?array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM club_memberships WHERE club_id = :club_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

// Where a membership stands right now:
// 'awaiting_payment', 'awaiting_approval', 'payment_rejected', 'active' or 'expired'.
// "Expired" is never stored. An approved membership counts as expired once its expiry date has passed.
function membership_state(array $membership): string {
    if ($membership['status'] === 'active' && strtotime($membership['expires_at'] ?? '') <= time()) {
        return 'expired';
    }
    return $membership['status'];
}

// A membership length for people to read: "1 month", "6 months", "1 year".
function format_membership_length(int $months): string {
    if ($months === 12) {
        return '1 year';
    }
    return $months . ($months === 1 ? ' month' : ' months');
}

// A membership date for people to read: "11 May 2027".
function format_membership_date(?string $value): string {
    return $value ? date('j M Y', strtotime($value)) : '—';
}