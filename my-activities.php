<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/helpers.php";

$user = require_login();
$db = get_db_connection();
expire_overdue_reservations();

$q = $db->prepare(
    "SELECT r.*, s.title, s.sport_type, s.venue, s.session_date, s.start_time, s.end_time, s.status AS session_status, s.cancellation_reason AS session_cancellation_reason, s.image_path, c.name AS club_name, (SELECT COALESCE(SUM(amount),0) FROM refund_records WHERE registration_id=r.id) AS refunded FROM registrations r INNER JOIN sessions s ON r.session_id=s.id INNER JOIN clubs c ON s.club_id=c.id WHERE r.user_id=? ORDER BY s.session_date, s.start_time",
);
$q->execute([$user["id"]]);
$all = $q->fetchAll();

$tabs = [
    "all" => "All",
    "payment" => "Needs receipt",
    "review" => "Under review",
    "refund" => "Refund pending",
    "confirmed" => "Confirmed",
    "history" => "History",
];
$tab = $_GET["tab"] ?? "all";
if (!isset($tabs[$tab])) {
    $tab = "all";
}

$groups = array_fill_keys(array_keys($tabs), []);
$groups["all"] = $all;
foreach ($all as $r) {
    $group = match ($r["status"]) {
        "awaiting_payment", "payment_rejected" => "payment",
        "awaiting_approval" => "review",
        "cancellation_pending" => "refund",
        "confirmed" => strtotime($r["session_date"] . " " . $r["end_time"]) < time() ? "history" : "confirmed",
        default => "history",
    };
    $groups[$group][] = $r;
}

// Club memberships go into the same tabs as sessions, depending on where each one stands.
// Clubs where you're on the committee are left out: committee members are members without signing up.
$stmt = $db->prepare("SELECT m.*, c.name AS club_name, c.logo_path, c.membership_open
                      FROM club_memberships m
                      INNER JOIN clubs c ON c.id = m.club_id
                      WHERE m.user_id = :user_id
                        AND m.club_id NOT IN (SELECT club_id FROM club_managers WHERE user_id = :user_id_again)
                      ORDER BY c.name ASC");
$stmt->execute([':user_id' => $user['id'], ':user_id_again' => $user['id']]);
$membership_groups = array_fill_keys(array_keys($tabs), []);
foreach ($stmt->fetchAll() as $m) {
    $m['state'] = membership_state($m);
    $membership_groups['all'][] = $m;
    $membership_tab = match ($m['state']) {
        'awaiting_payment', 'payment_rejected' => 'payment',
        'awaiting_approval' => 'review',
        'active' => 'confirmed',
        default => 'history',            // expired
    };
    $membership_groups[$membership_tab][] = $m;
}

$page_title = "My Activities";
require_once __DIR__ . "/includes/header.php";
?>

<div class="activities-page">
    <header class="activities-header">
        <div class="activities-eyebrow">SPORTSHUB / ACTIVITIES</div>
        <h1 class="activities-title">My Activities</h1>
        <p class="activities-subtitle">Your session registrations and club memberships, where each one stands, and what to do next.</p>
    </header>

    <?php journey_feedback(); ?>

    <nav class="activities-tabs" aria-label="Filter activities">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="?tab=<?= $key ?>" class="activity-tab <?= $key === $tab ? 'active' : '' ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>>
                <?= $label ?> (<?= count($groups[$key]) + count($membership_groups[$key]) ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (empty($groups[$tab]) && empty($membership_groups[$tab])): ?>
        <section class="activities-empty-card">
            <h2>No activities found</h2>
            <p>Your session registrations and club memberships appear here with their current status and next action.</p>
            <div class="activity-actions-row" style="justify-content: center;">
                <a class="btn-activity primary" href="<?= app_url('/sessions.php') ?>">Explore sessions</a>
                <?php if ($tab !== "all"): ?>
                    <a class="btn-activity" href="?tab=all">View all activities</a>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <?php foreach ($membership_groups[$tab] as $m): ?>
            <?php
                $state = $m['state'];
                [$status_text, $status_tone] = membership_status_label($state);
                $logo_url = get_club_logo_url($m);
                $club_url = app_url('/club.php?id=' . $m['club_id']);
                // Waiting on payment while an old expiry date exists means this is a renewal.
                $is_renewal = $m['expires_at'] && in_array($state, ['awaiting_payment', 'awaiting_approval', 'payment_rejected'], true);
            ?>
            <!-- A club membership: same tabs as sessions, but its own look -->
            <article class="activity-card activity-card--membership">
                <div class="activity-card-layout">
                    <div class="activity-thumb activity-thumb--club">
                        <div class="activity-club-logo">
                            <?php if ($logo_url): ?>
                                <img src="<?= e($logo_url) ?>" alt="">
                            <?php else: ?>
                                <?= e(get_club_initials($m['club_name'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="activity-content">
                        <div class="activity-header-row">
                            <div>
                                <span class="activity-kind">Club membership<?= $is_renewal ? ' · Renewal' : '' ?></span>
                                <h2 class="activity-name"><a href="<?= $club_url ?>"><?= e($m['club_name']) ?></a></h2>
                            </div>
                            <span class="member-status-pill tone-<?= $status_tone ?>"><span class="status-dot"></span> <?= $status_text ?></span>
                        </div>

                        <div class="activity-facts-list">
                            <div class="activity-fact-row">
                                <span class="fact-row-label">Price</span>
                                <span class="fact-row-val" style="font-weight: 700;"><?= format_price($m['price_snapshot']) ?> · <?= e(format_membership_length((int) $m['months_snapshot'])) ?></span>
                            </div>
                            <?php if ($state === 'active' || $state === 'expired'): ?>
                                <div class="activity-fact-row">
                                    <span class="fact-row-label"><?= $state === 'active' ? 'Expires' : 'Expired on' ?></span>
                                    <span class="fact-row-val"><?= e(format_membership_date($m['expires_at'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="activity-callout <?= $state === 'payment_rejected' ? 'alert' : '' ?>">
                            <div class="callout-step-title">Next step</div>
                            <p style="margin: 0;"><?= e(membership_next_step($m)) ?></p>
                            <?php if ($state === 'payment_rejected' && $m['rejection_reason']): ?>
                                <p style="margin-top: 4px; margin-bottom: 0;"><strong>Reason:</strong> <?= e($m['rejection_reason']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="activity-actions-row">
                            <?php if ($state === 'expired' && $m['membership_open']): ?>
                                <form method="POST" action="<?= app_url('/membership-join.php') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="club_id" value="<?= (int) $m['club_id'] ?>">
                                    <button type="submit" class="btn-activity primary">Renew membership</button>
                                </form>
                            <?php else: ?>
                                <a class="btn-activity <?= in_array($state, ['awaiting_payment', 'payment_rejected'], true) ? 'primary' : '' ?>" href="<?= app_url('/membership-payment.php?id=' . $m['id']) ?>">
                                    <?= match ($state) {
                                        'awaiting_payment'  => 'Pay & submit receipt',
                                        'payment_rejected'  => 'Resubmit receipt',
                                        'awaiting_approval' => 'View payment review',
                                        default             => 'View membership',
                                    } ?>
                                </a>
                            <?php endif; ?>
                            <a class="btn-activity" href="<?= $club_url ?>">View club</a>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>

        <?php foreach ($groups[$tab] as $r): ?>
            <article class="activity-card">
                <div class="activity-card-layout">
                    <div class="activity-thumb">
                        <img src="<?= e(get_session_cover_url($r)) ?>" alt="" loading="lazy">
                    </div>
                    <div class="activity-content">
                        <div class="activity-header-row">
                            <h2 class="activity-name">
                                <a href="<?= app_url('/session-detail.php?id=' . $r['session_id']) ?>">
                                    <?= e($r['title']) ?>
                                </a>
                            </h2>
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <?= get_status_badge($r['status']) ?>
                                <?php if ($r['session_status'] === 'cancelled'): ?>
                                    <span class="status-badge badge-danger">Session cancelled</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Row by Row Content Alignment -->
                        <div class="activity-facts-list">
                            <div class="activity-fact-row">
                                <span class="fact-row-label">Club</span>
                                <span class="fact-row-val"><?= e($r['club_name']) ?></span>
                            </div>
                            <div class="activity-fact-row">
                                <span class="fact-row-label">When</span>
                                <span class="fact-row-val"><?= format_session_date($r['session_date']) ?> · <?= format_session_time_range($r['start_time'], $r['end_time']) ?></span>
                            </div>
                            <div class="activity-fact-row">
                                <span class="fact-row-label">Where</span>
                                <span class="fact-row-val"><?= e($r['venue']) ?></span>
                            </div>
                            <div class="activity-fact-row">
                                <span class="fact-row-label">Price</span>
                                <span class="fact-row-val" style="font-weight: 700;"><?= format_price($r['price_snapshot']) ?></span>
                            </div>
                            <?php if (in_array($r['status'], ['awaiting_payment', 'payment_rejected'])): ?>
                                <div class="activity-fact-row">
                                    <span class="fact-row-label" style="color:#d70015;">Deadline</span>
                                    <span class="fact-row-val" style="color:#d70015; font-weight: 700;"><?= format_deadline($r['payment_deadline']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Step Callout -->
                        <div class="activity-callout <?= $r['status'] === 'payment_rejected' ? 'alert' : '' ?>">
                            <div class="callout-step-title">Next step</div>
                            <p style="margin: 0;"><?= e(registration_next_step($r)) ?></p>
                            <?php if ($r['status'] === 'payment_rejected'): ?>
                                <p style="margin-top: 4px; margin-bottom: 0;"><strong>Reason:</strong> <?= e($r['rejection_reason']) ?></p>
                            <?php endif; ?>
                            <?php if ($r['status'] === 'cancellation_pending'): ?>
                                <p style="margin-top: 4px; margin-bottom: 0;">Recorded: RM <?= number_format((float)$r['refunded'], 2) ?> · Remaining: RM <?= number_format(max(0, (float)$r['price_snapshot'] - (float)$r['refunded']), 2) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($r['session_cancellation_reason']): ?>
                            <p style="font-size: 12.5px; color: #6e6e73; margin: 0 0 10px;">
                                <strong>Organiser’s cancellation reason:</strong> <?= e($r['session_cancellation_reason']) ?>
                            </p>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="activity-actions-row">
                            <a class="btn-activity <?= in_array($r['status'], ['awaiting_payment', 'payment_rejected']) ? 'primary' : '' ?>" href="<?= app_url('/payment.php?id=' . $r['id']) ?>">
                                <?= match ($r['status']) {
                                    'awaiting_payment' => 'Pay & submit receipt',
                                    'payment_rejected' => 'Resubmit receipt',
                                    'awaiting_approval' => 'View payment review',
                                    'cancellation_pending' => 'View cancellation & refund',
                                    default => 'View registration',
                                } ?>
                            </a>
                            <a class="btn-activity" href="<?= app_url('/session-detail.php?id=' . $r['session_id']) ?>">
                                View session
                            </a>
                            <?php if (can_cancel_registration($r)): ?>
                                <a class="btn-activity danger-link" href="<?= app_url('/cancel-registration.php?id=' . $r['id']) ?>">
                                    Cancel registration
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
