<?php
// Free and paid club memberships are represented through fixed membership periods and payment states.

// Supported membership lengths are defined in months.
const MEMBERSHIP_LENGTH_OPTIONS = [1, 3, 6, 12];

// The student's membership row for a club is returned, or null when no application has ever been made.
function get_user_club_membership(int $club_id, int $user_id): ?array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM club_memberships WHERE club_id = :club_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

// Membership state is derived from the stored status and expiry date. Approved memberships are reported
// as expired after the recorded expiry date.
function membership_state(array $membership): string {
    // Approved memberships are treated as expired after the recorded expiry time.
    if ($membership['status'] === 'active' && strtotime($membership['expires_at'] ?? '') <= time()) {
        return 'expired';
    }
    return $membership['status'];
}

// A membership length is formatted for display: "1 month", "6 months", "1 year".
function format_membership_length(int $months): string {
    // A twelve-month period is formatted as one year.
    if ($months === 12) {
        return '1 year';
    }
    return $months . ($months === 1 ? ' month' : ' months');
}

// A membership date is formatted for display, e.g. "11 May 2027". A dash is shown when no date is set.
function format_membership_date(?string $value): string {
    return $value ? date('j M Y', strtotime($value)) : '—';
}

// The person's current standing in a club is returned:
// 'committee' (committee members are always counted as members), one of the states from
// membership_state(), or null when no application has been made.
function get_member_status(int $club_id, int $user_id): ?string {
    // Committee membership is given precedence over a membership application.
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT 1 FROM club_managers WHERE club_id = :club_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    // Committee membership is returned before paid membership state.
    if ($stmt->fetchColumn()) {
        return 'committee';
    }
    // Otherwise, the stored membership state is used when an application is present.
    $membership = get_user_club_membership($club_id, $user_id);
    return $membership ? membership_state($membership) : null;
}

// Membership eligibility is granted through a committee seat or an unexpired approved membership.
function is_club_member(int $club_id, int $user_id): bool {
    return in_array(get_member_status($club_id, $user_id), ['committee', 'active'], true);
}

// A message is shown after a membership action, picked by the ?msg= value in the address.
// Only messages from this list can be shown, so custom text cannot be pushed onto the page through the URL.
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
    // Unknown or missing keys are ignored, and nothing is printed.
    $key = $_GET['msg'] ?? '';
    // Unknown membership feedback keys are ignored.
    if (!isset($messages[$key])) {
        return;
    }
    // Errors are announced to screen readers as alerts, and successes as status updates.
    [$type, $text] = $messages[$key];
    echo '<div class="journey-notice notice-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . e($text) . '</div>';
}

// Committee records and unexpired approved memberships are combined with UNION, so each student is
// counted once.
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

// A display label and colour are assigned to each membership status for use across membership views.
function membership_status_label(?string $state): array {
    return /* A display label and colour are selected for the membership state. */ match ($state) {
        'committee'         => ['Committee Member', 'green'],
        'active'            => ['Member', 'green'],
        'awaiting_payment'  => ['Awaiting Payment', 'amber'],
        'awaiting_approval' => ['Awaiting Approval', 'blue'],
        'payment_rejected'  => ['Payment Rejected', 'red'],
        'expired'           => ['Expired', 'grey'],
        // Non-membership is used as the fallback display state.
        default             => ['Not a Member', 'grey'],
    };
}

// A next-step message is returned for the membership payment and activity views.
function membership_next_step(array $membership): string {
    return /* A next-step hint is selected from the derived membership state. */ match (membership_state($membership)) {
        'awaiting_payment'  => 'Transfer ' . format_price($membership['price_snapshot']) . ' to the club, then upload your receipt.',
        'payment_rejected'  => 'The committee could not confirm the payment. Read the reason, then upload a new receipt.',
        'awaiting_approval' => 'The committee is checking the payment. The membership starts on the day they approve it.',
        'active'            => 'Membership is active until ' . format_membership_date($membership['expires_at']) . '.',
        // Renewal guidance is used for expired membership state.
        default             => 'This membership ended on ' . format_membership_date($membership['expires_at']) . '. You can renew it from My Memberships or the club page.',
    };
}

// Every receipt sent for a membership is listed (oldest first), together with the committee's decision.
// Access to the membership must already have been checked before this is called.
function render_membership_receipt_history(int $membership_id): void {
    // Receipt records are loaded with optional reviewer names through a left join.
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT r.*, u.name AS reviewer_name
                          FROM membership_receipts r
                          LEFT JOIN users u ON u.id = r.reviewed_by
                          WHERE r.membership_id = :id
                          ORDER BY r.id ASC");
    $stmt->execute([':id' => $membership_id]);
    $receipts = $stmt->fetchAll();
    // Empty receipt histories are omitted from output.
    if (!$receipts) {
        return;   // No history markup is rendered when no receipts are recorded.
    }
    // One card is printed per receipt: decision, time sent, reviewer, rejection reason and a link to the file.
    ?>
    <!-- The journey card markup is rendered. --><section class="journey-card">
      <h2>Receipt history</h2>
      <?php /* One history card is rendered for each membership receipt. */ foreach ($receipts as $receipt): ?>
        <!-- The journey callout markup is rendered. --><div class="journey-callout">
          <strong><?= /* A receipt decision label is selected for the history card. */ match ($receipt['decision']) { 'approved' => 'Approved', 'rejected' => 'Rejected', /* Pending review is used as the default receipt label. */ default => 'Waiting for review' } ?></strong>
          <p>Sent <?= e(format_deadline($receipt['submitted_at'])) ?></p>
          <?php /* Recorded review details are displayed when available. */ if ($receipt['reviewed_at']): ?>
            <p>Reviewed by <?= e($receipt['reviewer_name'] ?? 'a former committee member') ?> · <?= e(format_deadline($receipt['reviewed_at'])) ?></p>
          <?php endif; ?>
          <?php /* Recorded rejection reasons are displayed when available. */ if ($receipt['rejection_reason']): ?>
            <p>Reason: <?= e($receipt['rejection_reason']) ?></p>
          <?php endif; ?>
          <a href="<?= e(app_url('/membership-receipt.php?id=' . $membership_id . '&receipt_id=' . $receipt['id'])) ?>" target="_blank" rel="noopener">Open this receipt ↗</a>
        </div>
      <?php endforeach; ?>
    </section>
    <?php
}

// A cheaper member price is applied only to authenticated club members when a member price is
// configured.
function member_price_applies(array $session, ?int $user_id): bool {
    return $user_id !== null
        && ($session['member_price'] ?? null) !== null
        && is_club_member((int) $session['club_id'], $user_id);
}

// The applicable member price is returned, or the normal session price is used.
function session_price_for(array $session, ?int $user_id): float {
    return member_price_applies($session, $user_id) ? (float) $session['member_price'] : (float) $session['price'];
}

// The member price is formatted for labels, e.g. "Members RM 10.00" or "Free for members".
// Null is returned when the session has no member price, or when that price is not cheaper than the normal one.
function format_member_price(array $session): ?string {
    $member = $session['member_price'] ?? null;
    // Non-discounted or absent member prices are omitted from member-price labels.
    if ($member === null || (float) $member >= (float) $session['price']) {
        return null;
    }
    return (float) $member <= 0 ? 'Free for members' : 'Members ' . format_price($member);
}

// Both prices are formatted on one line, e.g. "RM 15.00 · RM 10.00", or just "RM 20.00" / "Free".
function format_session_price(array $session): string {
    $member = format_member_price($session) ? format_price($session['member_price']) : null;
    return format_price($session['price']) . ($member ? ' · ' . $member : '');
}