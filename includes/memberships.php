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

// How this person belongs to a club right now:
// 'committee' (committee members always count as members), one of the states from
// membership_state(), or null if they have never applied.
function get_member_status(int $club_id, int $user_id): ?string {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT 1 FROM club_managers WHERE club_id = :club_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    if ($stmt->fetchColumn()) {
        return 'committee';
    }
    $membership = get_user_club_membership($club_id, $user_id);
    return $membership ? membership_state($membership) : null;
}

// Does this person count as a member of the club right now?
// Yes if they're on the committee, or if they have a paid membership that hasn't expired.
function is_club_member(int $club_id, int $user_id): bool {
    return in_array(get_member_status($club_id, $user_id), ['committee', 'active'], true);
}

// Shows a message after a membership action, picked by the ?msg= in the address.
// Only messages in this list can ever be shown, so nobody can put their own text on the page.
function membership_feedback(): void {
    $messages = [
        'applied'            => ['success', 'Application saved. Transfer the membership fee to the club, then upload your receipt below.'],
        'free_member'        => ['success', "This club's membership is free, so you're a member straight away."],
        'uploaded'           => ['success', 'Receipt submitted. Your membership starts when the committee approves the payment.'],
        'already_member'     => ['error', "You're already a member of this club."],
        'committee_member'   => ['error', "You're on this club's committee, so you already count as a member."],
        'memberships_closed' => ['error', "This club isn't accepting memberships right now."],
        'csrf_error'         => ['error', 'Your form expired. Reload the page and try again.'],
    ];
    $key = $_GET['msg'] ?? '';
    if (!isset($messages[$key])) {
        return;
    }
    [$type, $text] = $messages[$key];
    echo '<div class="journey-notice notice-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . e($text) . '</div>';
}

// How many members a club has: committee members plus students whose paid membership hasn't expired.
// UNION puts the two lists together and removes duplicates, so someone who is both is counted once.
function count_club_members(int $club_id): int {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT COUNT(*) FROM (
                              SELECT user_id FROM club_managers WHERE club_id = :club_a
                              UNION
                              SELECT user_id FROM club_memberships
                              WHERE club_id = :club_b AND status = 'active' AND expires_at > NOW()
                          ) AS members");
    $stmt->execute([':club_a' => $club_id, ':club_b' => $club_id]);
    return (int) $stmt->fetchColumn();
}