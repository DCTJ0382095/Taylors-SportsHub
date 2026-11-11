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

// The words and colour for a membership status pill, e.g. ['Awaiting payment', 'amber'].
// The club page, My memberships and My Activities all use this, so they always match.
function membership_status_label(?string $state): array {
    return match ($state) {
        'committee'         => ['Committee member', 'green'],
        'active'            => ['Member', 'green'],
        'awaiting_payment'  => ['Awaiting payment', 'amber'],
        'awaiting_approval' => ['Awaiting approval', 'blue'],
        'payment_rejected'  => ['Payment rejected', 'red'],
        'expired'           => ['Expired', 'grey'],
        default             => ['Not a member', 'grey'],
    };
}

// One sentence telling the student what happens next with this membership.
// The payment page and My Activities both use it.
function membership_next_step(array $membership): string {
    return match (membership_state($membership)) {
        'awaiting_payment'  => 'Transfer ' . format_price($membership['price_snapshot']) . ' to the club, then upload your receipt.',
        'payment_rejected'  => 'The committee could not confirm the payment. Read the reason, then upload a new receipt.',
        'awaiting_approval' => 'The committee is checking the payment. The membership starts on the day they approve it.',
        'active'            => 'Membership is active until ' . format_membership_date($membership['expires_at']) . '.',
        default             => 'This membership ended on ' . format_membership_date($membership['expires_at']) . '. You can renew it from My memberships or the club page.',
    };
}

// Shows every receipt sent for a membership (oldest first) and what the committee decided.
// Only call this after checking that the person may see this membership.
function render_membership_receipt_history(int $membership_id): void {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT r.*, u.name AS reviewer_name
                          FROM membership_receipts r
                          LEFT JOIN users u ON u.id = r.reviewed_by
                          WHERE r.membership_id = :id
                          ORDER BY r.id ASC");
    $stmt->execute([':id' => $membership_id]);
    $receipts = $stmt->fetchAll();
    if (!$receipts) {
        return;   // nothing sent yet, so show nothing
    }
    ?>
    <section class="journey-card">
      <h2>Receipt history</h2>
      <?php foreach ($receipts as $receipt): ?>
        <div class="journey-callout">
          <strong><?= match ($receipt['decision']) { 'approved' => 'Approved', 'rejected' => 'Rejected', default => 'Waiting for review' } ?></strong>
          <p>Sent <?= e(format_deadline($receipt['submitted_at'])) ?></p>
          <?php if ($receipt['reviewed_at']): ?>
            <p>Reviewed by <?= e($receipt['reviewer_name'] ?? 'a former committee member') ?> · <?= e(format_deadline($receipt['reviewed_at'])) ?></p>
          <?php endif; ?>
          <?php if ($receipt['rejection_reason']): ?>
            <p>Reason: <?= e($receipt['rejection_reason']) ?></p>
          <?php endif; ?>
          <a href="<?= e(app_url('/membership-receipt.php?id=' . $membership_id . '&receipt_id=' . $receipt['id'])) ?>" target="_blank" rel="noopener">Open this receipt ↗</a>
        </div>
      <?php endforeach; ?>
    </section>
    <?php
}