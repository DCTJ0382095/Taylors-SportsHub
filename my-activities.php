<?php
// Session registrations and club memberships are grouped into activity status tabs.
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/includes/helpers.php";

$user = require_login();
$db = get_db_connection();
// Overdue reservations are expired first, so each status is current.
expire_overdue_reservations();

// Every registration of the student is loaded with session and club details, plus the total refunded so far.
$q = $db->prepare(
    "SELECT r.*, s.title, s.sport_type, s.venue, s.session_date, s.start_time, s.end_time, s.status AS session_status, s.cancellation_reason AS session_cancellation_reason, s.image_path, c.name AS club_name, (SELECT COALESCE(SUM(amount),0) FROM refund_records WHERE registration_id=r.id) AS refunded FROM registrations r INNER JOIN sessions s ON r.session_id=s.id INNER JOIN clubs c ON s.club_id=c.id WHERE r.user_id=? ORDER BY s.session_date, s.start_time",
);
$q->execute([$user["id"]]);
$all = $q->fetchAll();

// Activity tab keys and labels are defined. Unknown tab values are replaced with All.
$tabs = [
    "all" => "All",
    "payment" => "Needs receipt",
    "review" => "Under review",
    "refund" => "Refund pending",
    "confirmed" => "Confirmed",
    "history" => "History",
];
$tab = $_GET["tab"] ?? "all";
// Unknown activity tabs are replaced with the All tab.
if (!isset($tabs[$tab])) {
    $tab = "all";
}

// Registrations are assigned to status tabs. Ended confirmed sessions are placed in History.
$groups = array_fill_keys(array_keys($tabs), []);
$groups["all"] = $all;
// Each registration is assigned to the appropriate activity group.
foreach ($all as $r) {
    $group = /* A registration group is selected from status and session timing. */ match ($r["status"]) {
        "awaiting_payment", "payment_rejected" => "payment",
        "awaiting_approval" => "review",
        "cancellation_pending" => "refund",
        "confirmed" => strtotime($r["session_date"] . " " . $r["end_time"]) < time() ? "history" : "confirmed",
        // Other registration states are assigned to History.
        default => "history",
    };
    $groups[$group][] = $r;
}

// Club memberships are sorted into the same tabs as sessions, depending on the state of each one.
// Clubs where the student sits on the committee are left out, since committee members count as members without signing up.
$stmt = $db->prepare("SELECT m.*, c.name AS club_name, c.logo_path, c.membership_open
                      FROM club_memberships m
                      INNER JOIN clubs c ON c.id = m.club_id
                      WHERE m.user_id = :user_id
                        AND m.club_id NOT IN (SELECT club_id FROM club_managers WHERE user_id = :user_id_again)
                      ORDER BY c.name ASC");
$stmt->execute([':user_id' => $user['id'], ':user_id_again' => $user['id']]);
$membership_groups = array_fill_keys(array_keys($tabs), []);
// Each non-committee membership is assigned to an activity group.
foreach ($stmt->fetchAll() as $m) {
    $m['state'] = membership_state($m);
    $membership_groups['all'][] = $m;
    $membership_tab = /* A membership activity group is selected from the derived state. */ match ($m['state']) {
        'awaiting_payment', 'payment_rejected' => 'payment',
        'awaiting_approval' => 'review',
        'active' => 'confirmed',
        // Expired memberships are assigned to History.
        default => 'history',            // Expired memberships are assigned to History.
    };
    $membership_groups[$membership_tab][] = $m;
}

$page_title = "My Activities";
require_once __DIR__ . "/includes/header.php";
?>

<!-- The activities page markup is rendered. --><div class="activities-page">
    <!-- The activity overview heading is displayed. -->
    <header class="activities-header">
        <!-- The activities eyebrow markup is rendered. --><div class="activities-eyebrow">SPORTSHUB / ACTIVITIES</div>
        <h1 class="activities-title">My Activities</h1>
        <p class="activities-subtitle">Your session registrations and club memberships, where each one stands, and what to do next.</p>
    </header>

    <!-- Activity-action feedback is displayed. -->
    <?php journey_feedback(); ?>

    <!-- Status tabs are rendered with registration and membership counts. -->
    <nav class="activities-tabs" aria-label="Filter activities">
        <?php /* Each activity tab is rendered with the corresponding count. */ foreach ($tabs as $key => $label): ?>
            <a href="?tab=<?= $key ?>" class="activity-tab <?= $key === $tab ? 'active' : '' ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>>
                <?= $label ?> (<?= count($groups[$key]) + count($membership_groups[$key]) ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Matching membership and session cards or an empty state are displayed. -->
    <?php /* An empty activity message is displayed when neither registrations nor memberships match. */ if (empty($groups[$tab]) && empty($membership_groups[$tab])): ?>
        <section class="activities-empty-card">
            <h2>No activities found</h2>
            <p>Your session registrations and club memberships appear here with their current status and next action.</p>
            <!-- The activity actions row markup is rendered. --><div class="activity-actions-row" style="justify-content: center;">
                <a class="btn-activity primary" href="<?= app_url('/sessions.php') ?>">Explore sessions</a>
                <?php /* A filtered empty state is displayed for the selected status tab. */ if ($tab !== "all"): ?>
                    <a class="btn-activity" href="?tab=all">View all activities</a>
                <?php endif; ?>
            </div>
        </section>
    <?php /* A general empty state is displayed for the All tab. */ else: ?>
        <?php /* Each matching membership is rendered as an activity card. */ foreach ($membership_groups[$tab] as $m): ?>
            <?php
                $state = $m['state'];
                [$status_text, $status_tone] = membership_status_label($state);
                // Active memberships are labelled Confirmed in the activity list.
                if ($state === 'active') $status_text = 'Confirmed';   // Active memberships are labelled with the same confirmed wording as session bookings.
                $logo_url = get_club_logo_url($m);
                $club_url = app_url('/club.php?id=' . $m['club_id']);
                // A membership waiting on payment while an old expiry date exists is treated as a renewal.
                $is_renewal = $m['expires_at'] && in_array($state, ['awaiting_payment', 'awaiting_approval', 'payment_rejected'], true);
            ?>
            <!-- Club membership activity is displayed in a distinct card. -->
            <article class="activity-card activity-card--membership">
                <!-- The activity card layout markup is rendered. --><div class="activity-card-layout">
                    <!-- The activity thumb markup is rendered. --><div class="activity-thumb activity-thumb--club">
                        <!-- The activity club logo markup is rendered. --><div class="activity-club-logo">
                            <?php /* The uploaded club logo is displayed on the membership activity card. */ if ($logo_url): ?>
                                <img src="<?= e($logo_url) ?>" alt="">
                            <?php /* Club initials are used when no logo is available. */ else: ?>
                                <?= e(get_club_initials($m['club_name'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- The activity content markup is rendered. --><div class="activity-content">
                        <!-- The activity header row markup is rendered. --><div class="activity-header-row">
                            <!-- The content group markup is rendered. --><div>
                                <span class="activity-kind">Club membership<?= $is_renewal ? ' · Renewal' : '' ?></span>
                                <h2 class="activity-name"><a href="<?= $club_url ?>"><?= e($m['club_name']) ?></a></h2>
                            </div>
                            <span class="member-status-pill tone-<?= $status_tone ?>"><?= $status_text ?></span>
                        </div>

                        <!-- Membership pricing, duration and applicable dates are displayed. -->
                        <div class="activity-facts-list">
                            <!-- The activity fact row markup is rendered. --><div class="activity-fact-row">
                                <span class="fact-row-label">Price</span>
                                <span class="fact-row-val" style="font-weight: 700;"><?= format_price($m['price_snapshot']) ?> · <?= e(format_membership_length((int) $m['months_snapshot'])) ?></span>
                            </div>
                            <?php /* Membership dates are displayed for active and expired terms. */ if ($state === 'active' || $state === 'expired'): ?>
                                <!-- The activity fact row markup is rendered. --><div class="activity-fact-row">
                                    <span class="fact-row-label"><?= $state === 'active' ? 'Expires' : 'Expired on' ?></span>
                                    <span class="fact-row-val"><?= e(format_membership_date($m['expires_at'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Next steps and recorded rejection reasons are displayed. -->
                        <div class="activity-callout <?= $state === 'payment_rejected' ? 'alert' : '' ?>">
                            <!-- The callout step title markup is rendered. --><div class="callout-step-title">Next step</div>
                            <p style="margin: 0;"><?= e(membership_next_step($m)) ?></p>
                            <?php /* A saved membership payment rejection reason is displayed. */ if ($state === 'payment_rejected' && $m['rejection_reason']): ?>
                                <p style="margin-top: 4px; margin-bottom: 0;"><strong>Reason:</strong> <?= e($m['rejection_reason']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Membership renewal, payment and club navigation are offered according to state. -->
                        <div class="activity-actions-row">
                            <?php /* Renewal is offered for expired memberships with open applications. */ if ($state === 'expired' && $m['membership_open']): ?>
                                <!-- The form controls markup is rendered. --><form method="POST" action="<?= app_url('/membership-join.php') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="club_id" value="<?= (int) $m['club_id'] ?>">
                                    <button type="submit" class="btn-activity primary">Renew membership</button>
                                </form>
                            <?php /* Other membership actions are linked through the payment page. */ else: ?>
                                <a class="btn-activity <?= in_array($state, ['awaiting_payment', 'payment_rejected'], true) ? 'primary' : '' ?>" href="<?= app_url('/membership-payment.php?id=' . $m['id']) ?>">
                                    <?= /* A membership action label is selected from the current state. */ match ($state) {
                                        'awaiting_payment'  => 'Pay & submit receipt',
                                        'payment_rejected'  => 'Resubmit receipt',
                                        'awaiting_approval' => 'View payment review',
                                        // Membership viewing is offered as the default action.
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

        <?php /* Each matching session registration is rendered as an activity card. */ foreach ($groups[$tab] as $r): ?>
            <!-- Session registration identity, status and actions are displayed. -->
            <article class="activity-card">
                <!-- The activity card layout markup is rendered. --><div class="activity-card-layout">
                    <!-- The activity thumb markup is rendered. --><div class="activity-thumb">
                        <img src="<?= e(get_session_cover_url($r)) ?>" alt="" loading="lazy">
                    </div>
                    <!-- The activity content markup is rendered. --><div class="activity-content">
                        <!-- The activity header row markup is rendered. --><div class="activity-header-row">
                            <h2 class="activity-name">
                                <a href="<?= app_url('/session-detail.php?id=' . $r['session_id']) ?>">
                                    <?= e($r['title']) ?>
                                </a>
                            </h2>
                            <!-- The content group markup is rendered. --><div style="display: flex; gap: 6px; align-items: center;">
                                <?= get_status_badge($r['status']) ?>
                                <?php /* Cancelled sessions are identified on the registration card. */ if ($r['session_status'] === 'cancelled'): ?>
                                    <span class="status-badge badge-danger">Session Cancelled</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Registration facts and applicable payment deadlines are displayed. -->
                        <div class="activity-facts-list">
                            <!-- The activity fact row markup is rendered. --><div class="activity-fact-row">
                                <span class="fact-row-label">Club</span>
                                <span class="fact-row-val"><?= e($r['club_name']) ?></span>
                            </div>
                            <!-- The activity fact row markup is rendered. --><div class="activity-fact-row">
                                <span class="fact-row-label">When</span>
                                <span class="fact-row-val"><?= format_session_date($r['session_date']) ?> · <?= format_session_time_range($r['start_time'], $r['end_time']) ?></span>
                            </div>
                            <!-- The activity fact row markup is rendered. --><div class="activity-fact-row">
                                <span class="fact-row-label">Where</span>
                                <span class="fact-row-val"><?= e($r['venue']) ?></span>
                            </div>
                            <!-- The activity fact row markup is rendered. --><div class="activity-fact-row">
                                <span class="fact-row-label">Price</span>
                                <span class="fact-row-val" style="font-weight: 700;"><?= format_price($r['price_snapshot']) ?></span>
                            </div>
                            <?php /* Receipt deadlines are displayed for payment-due and rejected registrations. */ if (in_array($r['status'], ['awaiting_payment', 'payment_rejected'])): ?>
                                <!-- The activity fact row markup is rendered. --><div class="activity-fact-row">
                                    <span class="fact-row-label" style="color:#d70015;">Deadline</span>
                                    <span class="fact-row-val" style="color:#d70015; font-weight: 700;"><?= format_deadline($r['payment_deadline']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Next steps, rejection details and refund progress are displayed when relevant. -->
                        <div class="activity-callout <?= $r['status'] === 'payment_rejected' ? 'alert' : '' ?>">
                            <!-- The callout step title markup is rendered. --><div class="callout-step-title">Next step</div>
                            <p style="margin: 0;"><?= e(registration_next_step($r)) ?></p>
                            <?php /* Receipt rejection guidance is displayed. */ if ($r['status'] === 'payment_rejected'): ?>
                                <p style="margin-top: 4px; margin-bottom: 0;"><strong>Reason:</strong> <?= e($r['rejection_reason']) ?></p>
                            <?php endif; ?>
                            <?php /* Refund progress is displayed for pending cancellation. */ if ($r['status'] === 'cancellation_pending'): ?>
                                <p style="margin-top: 4px; margin-bottom: 0;">Recorded: RM <?= number_format((float)$r['refunded'], 2) ?> · Remaining: RM <?= number_format(max(0, (float)$r['price_snapshot'] - (float)$r['refunded']), 2) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- The recorded session cancellation reason is displayed. -->
                        <?php /* A recorded session cancellation reason is displayed. */ if ($r['session_cancellation_reason']): ?>
                            <p style="font-size: 12.5px; color: #6e6e73; margin: 0 0 10px;">
                                <strong>Committee’s cancellation reason:</strong> <?= e($r['session_cancellation_reason']) ?>
                            </p>
                        <?php endif; ?>

                        <!-- Registration, session and eligible cancellation actions are offered. -->
                        <div class="activity-actions-row">
                            <a class="btn-activity <?= in_array($r['status'], ['awaiting_payment', 'payment_rejected']) ? 'primary' : '' ?>" href="<?= app_url('/payment.php?id=' . $r['id']) ?>">
                                <?= /* A registration action label is selected from the current status. */ match ($r['status']) {
                                    'awaiting_payment' => 'Pay & submit receipt',
                                    'payment_rejected' => 'Resubmit receipt',
                                    'awaiting_approval' => 'View payment review',
                                    'cancellation_pending' => 'View cancellation & refund',
                                    // Registration viewing is offered as the default action.
                                    default => 'View registration',
                                } ?>
                            </a>
                            <a class="btn-activity" href="<?= app_url('/session-detail.php?id=' . $r['session_id']) ?>">
                                View session
                            </a>
                            <?php /* Cancellation is offered only while self-service cancellation is allowed. */ if (can_cancel_registration($r)): ?>
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
