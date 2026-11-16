<?php
// Session details, availability and booking actions are displayed. Draft visibility is restricted to
// authorized club managers.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$session_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

// Overdue reservations for this session are expired first, so the place counts are current.
expire_overdue_reservations($session_id);

// The session is loaded with club and payment details. Unknown IDs are handled with a 404 response.
$stmt = $db->prepare("SELECT s.*, c.name AS club_name, c.slug AS club_slug, c.bank_name, c.account_holder, c.account_number 
                      FROM sessions s 
                      INNER JOIN clubs c ON s.club_id = c.id 
                      WHERE s.id = :id LIMIT 1");
$stmt->execute([':id' => $session_id]);
$session = $stmt->fetch();

// Unknown sessions are handled with a 404 response.
if (!$session) {
    render_error_page(404);
}

// Seat numbers, the visitor's own registration (if any) and committee access are worked out.
$seat_info = get_session_seat_info($session_id);
$current_user = current_user();
$existing_reg = $current_user ? get_user_session_registration($session_id, $current_user['id']) : null;
$is_mgr = $current_user ? is_club_manager($session['club_id'], $current_user['id']) : false;
// Drafts are hidden behind a 404 from everyone outside the club's committee.
if ($session['status'] === 'draft' && !$is_mgr) render_error_page(404);
// The price for this visitor is worked out, along with whether the member price applies.
$viewer_id = $current_user ? (int) $current_user['id'] : null;
$your_price = session_price_for($session, $viewer_id);
$gets_member_price = member_price_applies($session, $viewer_id);
// Published status is replaced with started or full for the displayed availability badge when
// applicable.
$signup_status = $session['status'];
// Published sessions are labelled started after the start time.
if ($signup_status === 'published' && $seat_info['is_started']) $signup_status = 'started';
// Published sessions are labelled full when capacity is occupied.
elseif ($signup_status === 'published' && $seat_info['is_full']) $signup_status = 'full';

$page_title = $session['title'];
require_once __DIR__ . '/includes/header.php';
?>

<!-- The session detail page markup is rendered. --><div class="session-detail-page">
    <!-- Registration-action feedback is displayed. -->
    <?php journey_feedback(); ?>

    <!-- Back navigation to session discovery is provided. -->
    <nav class="session-detail-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= app_url('/sessions.php') ?>">Explore Sessions</a> /
        <span class="breadcrumb-current"><?= e($session['title']) ?></span>
    </nav>

    <!-- Session details and registration controls are arranged in two columns. -->
    <div class="session-detail-layout">
        <!-- Session imagery, identity and description are displayed. -->
        <div class="session-detail-main">
            <!-- The session media frame markup is rendered. --><div class="session-media-frame">
                <img src="<?= e(get_session_cover_url($session)) ?>" alt="<?= e($session['title']) ?>">
            </div>

            <!-- The session eyebrow markup is rendered. --><div class="session-eyebrow"><?= strtoupper(e($session['sport_type'])) ?> · <?= strtoupper(e($session['club_name'])) ?></div>
            <h1 class="session-detail-title"><?= e($session['title']) ?></h1>
            <!-- The session detail subtitle markup is rendered. --><div class="session-detail-subtitle">
                <?= e($session['club_name']) ?> · Campus sports session
            </div>

            <!-- The session info card markup is rendered. --><div class="session-info-card">
                <!-- Stored description text is escaped and line breaks are preserved. -->
                <div class="session-info-section">
                    <h2>About this session</h2>
                    <p><?= nl2br(e($session['description'])) ?></p>
                </div>

                <!-- Session cancellation and the recorded reason are displayed. -->
                <?php /* Cancelled-session information is displayed. */ if ($session['status'] === 'cancelled'): ?>
                    <div class="session-state-banner alert">
                        <strong>Session cancelled by the committee</strong>
                        <p style="margin: 4px 0 0;"><?= e($session['cancellation_reason'] ?: 'No reason provided.') ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- A sticky registration summary is displayed in the sidebar. -->
        <aside class="session-detail-sidebar">
            <!-- The session summary card markup is rendered. --><div class="session-summary-card">
                <!-- The summary card header markup is rendered. --><div class="summary-card-header">
                    <h3 class="summary-card-title">Session details</h3>
                    <span class="session-status-badge <?= e($signup_status) ?>">
                        <?= ucfirst(e($signup_status)) ?>
                    </span>
                </div>

                <!-- Session schedule, venue, prices and availability are displayed. -->
                <div class="session-facts-table">
                    <!-- The fact row markup is rendered. --><div class="fact-row">
                        <span class="fact-label">Date</span>
                        <span class="fact-value"><?= format_session_date($session['session_date']) ?></span>
                    </div>
                    <!-- The fact row markup is rendered. --><div class="fact-row">
                        <span class="fact-label">Time</span>
                        <span class="fact-value"><?= format_session_time_range($session['start_time'], $session['end_time']) ?></span>
                    </div>
                    <!-- The fact row markup is rendered. --><div class="fact-row">
                        <span class="fact-label">Venue</span>
                        <span class="fact-value"><?= e($session['venue']) ?></span>
                    </div>
                    <!-- The fact row markup is rendered. --><div class="fact-row">
                        <span class="fact-label">Price</span>
                        <span class="fact-value price"><?= format_price($session['price']) ?></span>
                    </div>
                    <?php /* A cheaper member price is displayed when configured. */ if (format_member_price($session)): ?>
                        <!-- The fact row markup is rendered. --><div class="fact-row">
                            <span class="fact-label">Members</span>
                            <span class="fact-value price"><?= e((float) $session['member_price'] <= 0 ? 'Free' : format_price($session['member_price'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <!-- The fact row markup is rendered. --><div class="fact-row">
                        <span class="fact-label">Places</span>
                        <span class="fact-value"><?= $seat_info['occupied'] ?> / <?= $seat_info['capacity'] ?> occupied</span>
                    </div>
                    <!-- The fact row markup is rendered. --><div class="fact-row">
                        <span class="fact-label">Available</span>
                        <span class="fact-value"><?= $seat_info['seats_left'] ?> places</span>
                    </div>
                </div>

                <!-- Paid-session rules, free confirmation and applicable membership savings are explained. -->
                <div class="session-callout-box">
                    <?php /* Payment guidance is displayed for the applicable paid price. */ if ($your_price > 0): ?>
                        <h4>Payment required</h4>
                        <p>
                            Reserve a place, pay externally and upload your receipt within 24 hours or before start, whichever is earlier.
                            <?php /* Membership savings are highlighted when the normal price applies. */ if (!$gets_member_price && format_member_price($session)): ?>
                                Members of <?= e($session['club_name']) ?> pay less.
                                <a href="<?= app_url('/club.php?id=' . $session['club_id']) ?>">See membership</a>
                            <?php endif; ?>
                        </p>
                    <?php /* Immediate confirmation is explained for a free place. */ else: ?>
                        <h4><?= $gets_member_price ? 'Free for members' : 'Free session' ?></h4>
                        <p>Instant confirmation upon reservation. No payment or receipt required.</p>
                    <?php endif; ?>
                    <?php /* Applied membership pricing is acknowledged. */ if ($gets_member_price): ?>
                        <p class="member-price-note">You're a member of <?= e($session['club_name']) ?>, so you pay the member price.</p>
                    <?php endif; ?>
                </div>

                <!-- Booking controls are selected according to authentication, registration and session availability. -->
                <div class="session-action-area">
                    <?php /* Guests are directed to login before reserving an available session. */ if (!$current_user && $session['status'] === 'published' && !$seat_info['is_started'] && !$seat_info['is_full']): ?>
                        <a href="<?= app_url('/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/sessions.php')) ?>" class="btn-reserve">
                            Sign in to reserve
                        </a>
                    <?php /* Existing held registrations are displayed instead of another reservation form. */ elseif ($existing_reg && in_array($existing_reg['status'], ['confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected', 'cancellation_pending'])): ?>
                        <!-- The existing registration is summarized and linked. -->
                        <div class="session-registered-box">
                            <!-- The session registered header markup is rendered. --><div class="session-registered-header">
                                <span style="font-size: 12.5px; color: var(--text-muted);">Your registration:</span>
                                <!-- The content group markup is rendered. --><div><?= get_status_badge($existing_reg['status']) ?></div>
                            </div>
                            <?php /* Payment completion is offered for an unpaid reservation. */ if ($existing_reg['status'] === 'awaiting_payment'): ?>
                                <p style="font-size: 12.5px; color: var(--text-secondary); margin: 6px 0 12px;">
                                    Place held! Please submit receipt under 24 hours.
                                </p>
                            <?php /* Pending receipt review is explained. */ elseif ($existing_reg['status'] === 'awaiting_approval'): ?>
                                <p style="font-size: 12.5px; color: var(--text-secondary); margin: 6px 0 12px;">
                                    Receipt uploaded. Your place is held during committee review.
                                </p>
                            <?php /* Receipt replacement is offered after rejection. */ elseif ($existing_reg['status'] === 'payment_rejected'): ?>
                                <p style="font-size: 12.5px; color: #d70015; margin: 6px 0 12px;">
                                    Receipt rejected: <?= e($existing_reg['rejection_reason']) ?>.
                                </p>
                            <?php endif; ?>
                            <a href="<?= app_url('/payment.php?id=' . $existing_reg['id']) ?>" class="btn-reserve">
                                View registration
                            </a>
                        </div>
                    <?php /* Unavailable signup is explained for unpublished or started sessions. */ elseif ($session['status'] !== 'published' || $seat_info['is_started']): ?>
                        <!-- The session state banner markup is rendered. --><div class="session-state-banner">
                            <?php /* Closed signup is explained. */ if ($session['status'] === 'closed'): ?>
                                Registration for this session has been closed by the committee.
                            <?php /* Session cancellation is explained. */ elseif ($session['status'] === 'cancelled'): ?>
                                This session is cancelled.
                            <?php /* Started-session signup restrictions are explained. */ elseif ($seat_info['is_started']): ?>
                                This session has already commenced. Registration is closed.
                            <?php /* Other unpublished signup restrictions are explained. */ else: ?>
                                This session is currently in draft mode.
                            <?php endif; ?>
                        </div>
                    <?php /* Full capacity is displayed without a reservation action. */ elseif ($seat_info['is_full']): ?>
                        <!-- The session state banner markup is rendered. --><div class="session-state-banner alert">
                            This session is currently full (<?= $seat_info['capacity'] ?>/<?= $seat_info['capacity'] ?>).<br>
                            Check back later if a spot opens up.
                        </div>
                    <?php /* A reservation form is rendered for an available session. */ else: ?>
                        <!-- The applicable account price is displayed on the reservation control. -->
                        <form method="POST" action="<?= app_url('/reserve.php') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                            <button type="submit" class="btn-reserve">
                                <?= $your_price <= 0 ? 'Reserve place · Free' : 'Reserve place · ' . format_price($your_price) ?>                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Authorized session and participant management links are displayed. -->
                <?php
                    $can_signups  = $is_mgr && can_manage_club((int) $session['club_id'], $current_user['id'], 'signups');
                    $can_sessions = $is_mgr && can_manage_club((int) $session['club_id'], $current_user['id'], 'sessions');
                ?>
                <?php /* Manager links are displayed when session or sign-ups access is granted. */ if ($can_signups || $can_sessions): ?>
                    <div class="manager-quick-box">
                        <span class="manager-quick-title">Manager Actions</span>
                        <!-- The manager quick links markup is rendered. --><div class="manager-quick-links">
                            <?php /* Participant management is linked for authorized sign-ups managers. */ if ($can_signups): ?>
                                <a href="<?= app_url('/manager/participants.php?session_id=' . $session['id']) ?>" class="manager-pill-btn primary">
                                    Participants (<?= $seat_info['occupied'] ?>)
                                </a>
                            <?php endif; ?>
                            <?php /* Session editing is linked for authorized session managers. */ if ($can_sessions): ?>
                                <a href="<?= app_url('/manager/session-edit.php?id=' . $session['id']) ?>" class="manager-pill-btn">
                                    Edit settings
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
