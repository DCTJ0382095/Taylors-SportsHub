<?php
// The manager workspace is assembled from authorized club records, sessions and membership
// applications.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$managed_clubs = get_user_managed_clubs($user['id']);
$is_admin = ($user['role'] === 'admin');

// Everyone else is refused with the 403 page.
if (empty($managed_clubs) && !$is_admin) {
    render_error_page(403);
}

// The Create button is shown when the sessions permission is held in at least one managed club.
$can_create_sessions = $is_admin || !empty(array_filter(
    $managed_clubs,
    fn($club) => can_manage_club((int) $club['id'], $user['id'], 'sessions')
));

// Overdue reservations are expired first, so the counts are current.
$db = get_db_connection();
expire_overdue_reservations();

// Every club is included for admins. Other accounts are restricted to managed club IDs.
if ($is_admin) {
    $club_ids = $db->query("SELECT id FROM clubs")->fetchAll(PDO::FETCH_COLUMN);
} /* Managed club IDs are used for non-admin workspace queries. */ else {
    $club_ids = array_column($managed_clubs, 'id');
}

// The club IDs are cast to int before being joined into the SQL IN (...) list, so the list is safe.
$in_clause = implode(',', array_map('intval', $club_ids));

// Membership applications are loaded only for clubs with sign-ups permission.
$signup_club_ids = $is_admin ? $club_ids : array_column(array_filter(
    $managed_clubs,
    fn($club) => can_manage_club((int) $club['id'], $user['id'], 'signups')
), 'id');
$can_review_memberships = !empty($signup_club_ids);
$membership_signups = [];
// Membership review records are loaded when sign-ups access is available.
if ($can_review_memberships) {
    $signup_in_clause = implode(',', array_map('intval', $signup_club_ids));
    // Applications awaiting receipt review are ordered before applications awaiting payment.
    $membership_signups = $db->query("SELECT m.*, u.name AS member_name, u.email AS member_email, c.name AS club_name
                                      FROM club_memberships m
                                      INNER JOIN users u ON u.id = m.user_id
                                      INNER JOIN clubs c ON c.id = m.club_id
                                      WHERE m.club_id IN ($signup_in_clause)
                                        AND m.status IN ('awaiting_approval', 'awaiting_payment', 'payment_rejected')
                                      ORDER BY m.status = 'awaiting_approval' DESC, m.updated_at DESC")->fetchAll();
}
// Only pending receipt reviews are included in the membership tab count.
$membership_receipts_waiting = count(array_filter($membership_signups, fn($m) => $m['status'] === 'awaiting_approval'));

// The current tab is read from ?status=. The memberships tab needs the sign-ups permission.
$filter_status = trim($_GET['status'] ?? '');
// Unauthorized membership-tab requests are replaced with the default session tab.
if ($filter_status === 'memberships' && !$can_review_memberships) {
    render_error_page(403);
}
// Sessions of the visible clubs are loaded, with a count per registration state for each card.
$sql = "SELECT s.*, c.name AS club_name, 
        (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id AND r.status = 'confirmed') AS confirmed_count,
        (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id AND r.status = 'awaiting_approval') AS pending_review_count,
        (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id AND r.status IN ('awaiting_payment', 'payment_rejected')) AS awaiting_payment_count,
        (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id AND r.status = 'cancellation_pending') AS pending_refund_count
        FROM sessions s 
        INNER JOIN clubs c ON s.club_id = c.id 
        WHERE s.club_id IN ($in_clause)";

// The status filter is quoted by PDO before being added to the SQL.
if ($filter_status !== '') {
    $sql .= " AND s.status = " . $db->quote($filter_status);
}
$sql .= " ORDER BY s.session_date DESC, s.start_time DESC";

$sessions = $db->query($sql)->fetchAll();

// Session metrics are calculated across all visible clubs regardless of the selected tab.
$all_mgr_sql = "SELECT s.status,
                (SELECT COUNT(*) FROM registrations r WHERE r.session_id = s.id AND r.status = 'awaiting_approval') AS pending_review_count
                FROM sessions s WHERE s.club_id IN ($in_clause)";
$all_mgr_rows = $db->query($all_mgr_sql)->fetchAll();

$metric_total = count($all_mgr_rows);
$metric_published = count(array_filter($all_mgr_rows, fn($r) => $r['status'] === 'published'));
$metric_draft = count(array_filter($all_mgr_rows, fn($r) => $r['status'] === 'draft'));
$metric_pending_receipts = array_sum(array_column($all_mgr_rows, 'pending_review_count'));

$page_title = "Manager Workspace · Sessions";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- The manager container markup is rendered. --><div class="manager-container">
    <!-- Workspace identity and authorized session creation are displayed. -->
    <div class="manager-header-row">
        <!-- The manager title group markup is rendered. --><div class="manager-title-group">
            <span class="manager-eyebrow">Club Operations</span>
            <h1 class="manager-title">Manager Workspace</h1>
            <p class="manager-desc">
                Manage club sessions, review payment receipts, and record refunds.
            </p>
        </div>

        <?php /* Session creation is offered to accounts with sessions permission. */ if ($can_create_sessions): ?>
        <a href="<?= app_url('/manager/session-edit.php') ?>" class="manager-btn-create">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Create new session</span>
        </a>
        <?php endif; ?>
        </div>

    <?php
    // Active clubs are loaded for admins, and managed clubs are loaded for other accounts.
    $card_clubs = $is_admin
        ? $db->query("SELECT * FROM clubs WHERE status = 'active' ORDER BY name ASC")->fetchAll()
        : $managed_clubs;
    ?>
    <!-- Authorized club identities, roles and settings links are displayed. -->
    <?php /* Club management cards are displayed when authorized clubs are available. */ if (!empty($card_clubs)): ?>
        <h2 class="manager-section-title">Your clubs</h2>
        <!-- The manager club grid markup is rendered. --><div class="manager-club-grid">
            <?php /* Each authorized club is rendered as a management card. */ foreach ($card_clubs as $mc): ?>
                <?php
                    $mc_id    = (int) $mc['id'];
                    $logo_url = get_club_logo_url($mc);
                    // The admin role label is used for site admins.
                    if ($is_admin) {
                        $role_label = 'Admin';
                    } /* The club committee title is used for other managers. */ else {
                        // The president label, custom committee title or default committee label is selected.
                        $role_label = committee_title(get_committee_role($mc_id, $user['id']));
                    }
                ?>
                <!-- The manager club card markup is rendered. --><div class="manager-club-card">
                    <!-- The manager club logo markup is rendered. --><div class="manager-club-logo">
                        <?php /* An uploaded club logo is displayed. */ if ($logo_url): ?>
                            <img src="<?= e($logo_url) ?>" alt="<?= e($mc['name']) ?> logo">
                        <?php /* Club initials are displayed when no uploaded logo is available. */ else: ?>
                            <span><?= e(get_club_initials($mc['name'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <!-- The manager club name markup is rendered. --><div class="manager-club-name"><?= e($mc['name']) ?></div>
                    <!-- The manager club role markup is rendered. --><div class="manager-club-role"><?= e($role_label) ?></div>
                    <?php /* Incomplete club payment setup is highlighted. */ if (!club_payment_ready($mc)): ?>
                        <!-- The manager club warning markup is rendered. --><div class="manager-club-warning" title="No sessions or paid memberships until the president adds the DuitNow QR and bank details in Settings.">Payment details missing</div>
                    <?php endif; ?>
                    <!-- The manager club actions markup is rendered. --><div class="manager-club-actions">
                        <?php /* Club settings are linked when profile or sign-ups permission is granted. */ if (can_manage_club($mc_id, $user['id'], 'club') || can_manage_club($mc_id, $user['id'], 'signups')): ?>
                            <a href="<?= app_url('/manager/club-settings.php?club_id=' . $mc_id) ?>" class="manager-club-btn">Settings</a>
                        <?php endif; ?>
                        <a href="<?= app_url('/manager/members.php?club_id=' . $mc_id) ?>" class="manager-club-btn">Members</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Session and pending-review metrics are displayed. -->
    <div class="manager-metrics-grid">
        <!-- The manager metric card markup is rendered. --><div class="manager-metric-card">
            <!-- The manager metric val markup is rendered. --><div class="manager-metric-val"><?= $metric_total ?></div>
            <!-- The manager metric label markup is rendered. --><div class="manager-metric-label">All sessions</div>
        </div>
        <!-- The manager metric card markup is rendered. --><div class="manager-metric-card">
            <!-- The manager metric val markup is rendered. --><div class="manager-metric-val"><?= $metric_published ?></div>
            <!-- The manager metric label markup is rendered. --><div class="manager-metric-label">Published</div>
        </div>
        <!-- The manager metric card markup is rendered. --><div class="manager-metric-card">
            <!-- The manager metric val markup is rendered. --><div class="manager-metric-val <?= $metric_pending_receipts > 0 ? 'alert' : '' ?>"><?= $metric_pending_receipts ?></div>
            <!-- The manager metric label markup is rendered. --><div class="manager-metric-label">Receipts to review</div>
        </div>
        <!-- The manager metric card markup is rendered. --><div class="manager-metric-card">
            <!-- The manager metric val markup is rendered. --><div class="manager-metric-val"><?= $metric_draft ?></div>
            <!-- The manager metric label markup is rendered. --><div class="manager-metric-label">Draft sessions</div>
        </div>
    </div>

    <!-- Status tabs and authorized membership review counts are displayed. -->
    <div class="manager-filter-tabs">
        <a href="<?= app_url('/manager/sessions.php') ?>" class="manager-tab <?= $filter_status === '' ? 'active' : '' ?>">
            All (<?= $metric_total ?>)
        </a>
        <?php /* The membership review tab is displayed for authorized sign-ups managers. */ if ($can_review_memberships): ?>
            <a href="<?= app_url('/manager/sessions.php?status=memberships') ?>" class="manager-tab <?= $filter_status === 'memberships' ? 'active' : '' ?>">
                Membership Sign-ups<?= $membership_receipts_waiting > 0 ? ' (' . $membership_receipts_waiting . ')' : '' ?>
            </a>
        <?php endif; ?>
        <a href="<?= app_url('/manager/sessions.php?status=published') ?>" class="manager-tab <?= $filter_status === 'published' ? 'active' : '' ?>">
            Published
        </a>
        <a href="<?= app_url('/manager/sessions.php?status=draft') ?>" class="manager-tab <?= $filter_status === 'draft' ? 'active' : '' ?>">
            Drafts
        </a>
        <a href="<?= app_url('/manager/sessions.php?status=closed') ?>" class="manager-tab <?= $filter_status === 'closed' ? 'active' : '' ?>">
            Closed
        </a>
        <a href="<?= app_url('/manager/sessions.php?status=cancelled') ?>" class="manager-tab <?= $filter_status === 'cancelled' ? 'active' : '' ?>">
            Cancelled
        </a>
    </div>

    <!-- Membership application feedback and cards are displayed. -->
    <?php /* Membership applications are displayed on the membership tab. */ if ($filter_status === 'memberships'): ?>
        <?php
            // Only allowlisted receipt-approval and rejection messages are displayed.
            $done_messages = [
                'approved' => 'Payment approved. The membership is now active.',
                'rejected' => 'Receipt rejected. The student can read your reason and upload a new one.',
            ];
            $done = $_GET['done'] ?? '';
        ?>
        <?php /* An allowlisted review-result message is displayed. */ if (isset($done_messages[$done])): ?>
            <div class="settings-alert-success"><?= e($done_messages[$done]) ?></div>
        <?php endif; ?>

        <?php /* An empty membership queue message is displayed. */ if (empty($membership_signups)): ?>
            <!-- The manager empty state markup is rendered. --><div class="manager-empty-state">
                <h3 class="manager-empty-title">No membership sign-ups right now</h3>
                <p class="manager-empty-desc">When a student applies to join one of your clubs, their application shows up here.</p>
            </div>
        <?php /* Pending membership applications are displayed as cards. */ else: ?>
            <!-- The manager card list markup is rendered. --><div class="manager-card-list">
                <?php /* Each membership application is rendered with payment status and review actions. */ foreach ($membership_signups as $m): ?>
                    <!-- The manager card markup is rendered. --><div class="manager-card">
                        <!-- The manager card main markup is rendered. --><div class="manager-card-main">
                            <!-- The manager card badge row markup is rendered. --><div class="manager-card-badge-row">
                                <?= get_status_badge($m['status']) ?>
                                <!-- Previous membership expiry is used to label renewal applications. -->
                                <span class="manager-card-club-tag"><?= e($m['club_name']) ?> · <?= $m['expires_at'] ? 'Renewal' : 'New member' ?></span>
                            </div>
                            <h3 class="manager-card-title"><?= e($m['member_name']) ?></h3>
                            <!-- The manager meta row markup is rendered. --><div class="manager-meta-row">
                                <span class="manager-meta-item"><?= e($m['member_email']) ?></span>
                                <span class="manager-meta-item"><?= format_price($m['price_snapshot']) ?> · <?= e(format_membership_length((int) $m['months_snapshot'])) ?></span>
                                <span class="manager-meta-item">
                                    <?= /* Membership payment guidance is selected from the application status. */ match ($m['status']) {
                                        'awaiting_approval' => 'Receipt sent ' . e(format_deadline($m['receipt_uploaded_at'])),
                                        'payment_rejected'  => 'Receipt rejected, waiting for a new one',
                                        // Payment completion is indicated as outstanding by default.
                                        default             => 'Waiting for the student to pay',
                                    } ?>
                                </span>
                            </div>
                        </div>
                        <!-- The manager card aside markup is rendered. --><div class="manager-card-aside">
                            <!-- The manager btn group markup is rendered. --><div class="manager-btn-group">
                                <?php /* Pending membership receipts are linked to review. */ if ($m['status'] === 'awaiting_approval'): ?>
                                    <a href="<?= app_url('/manager/review-membership.php?id=' . $m['id']) ?>" class="manager-btn manager-btn-primary">Review receipt</a>
                                <?php /* Other membership applications are linked to payment details. */ else: ?>
                                    <a href="<?= app_url('/membership-payment.php?id=' . $m['id']) ?>" class="manager-btn manager-btn-secondary">View</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <!-- Matching sessions or an empty state are displayed. -->
    <?php /* An empty session message is displayed for the current filter. */ elseif (empty($sessions)): ?>
        <div class="manager-empty-state">
            <svg class="manager-empty-icon" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/></svg>
            <h3 class="manager-empty-title">
                <?= $filter_status !== '' ? 'No ' . e($filter_status) . ' sessions found' : 'No club sessions created yet' ?>
            </h3>
            <p class="manager-empty-desc">
                <?= $filter_status !== '' ? 'Try selecting another status tab or view all club sessions.' : 'Get started by creating your club\'s first training session or social open play.' ?>
            </p>
            <!-- The manager empty actions markup is rendered. --><div class="manager-empty-actions">
                <?php /* Session creation is offered in the empty state when authorized. */ if ($can_create_sessions): ?>
                <a href="<?= app_url('/manager/session-edit.php') ?>" class="manager-btn-create">
                    + Create new session
                </a>
                <?php endif; ?>
                <?php /* Filter reset is offered when a status filter is active. */ if ($filter_status !== ''): ?>
                    <a href="<?= app_url('/manager/sessions.php') ?>" class="manager-empty-btn-all">
                        Show all sessions
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php /* Matching sessions are displayed in the manager list. */ else: ?>
        <!-- The manager card list markup is rendered. --><div class="manager-card-list">
            <?php /* Each session is rendered with registration counts and authorized actions. */ foreach ($sessions as $s): ?>
                <!-- Session identity, status, schedule and pricing are displayed. -->
                <div class="manager-card">
                    <!-- The manager card main markup is rendered. --><div class="manager-card-main">
                        <!-- The manager card badge row markup is rendered. --><div class="manager-card-badge-row">
                            <?= get_status_badge($s['status']) ?>
                            <span class="manager-card-club-tag"><?= e($s['club_name']) ?> · <?= e($s['sport_type']) ?></span>
                        </div>

                        <!-- The manager card title row markup is rendered. --><div class="manager-card-title-row">
                            <h3 class="manager-card-title"><?= e($s['title']) ?></h3>
                            <?php /* Published-session actions are offered to authorized session managers. */ if ($s['status'] === 'published' && can_manage_club((int) $s['club_id'], $user['id'], 'sessions')): ?>
                                <a href="<?= app_url('/manager/session-edit.php?id=' . $s['id']) ?>" class="manager-card-edit">Edit</a>
                            <?php endif; ?>
                        </div>

                        <!-- The manager meta row markup is rendered. --><div class="manager-meta-row">
                            <span class="manager-meta-item">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                                <span><?= format_session_date($s['session_date']) ?></span>
                            </span>
                            <span class="manager-meta-item">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span><?= format_session_time_range($s['start_time'], $s['end_time']) ?></span>
                            </span>
                            <span class="manager-meta-item">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                <span><?= e(format_session_price($s)) ?></span>
                            </span>
                            <span class="manager-meta-item">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                <span><?= e($s['venue']) ?></span>
                            </span>
                        </div>
                    </div>

                    <!-- Participant statistics and management actions are grouped. -->
                    <div class="manager-card-aside">
                        <!-- The manager stat stack markup is rendered. --><div class="manager-stat-stack">
                            <!-- The manager stat badge markup is rendered. --><div class="manager-stat-badge">
                                <!-- The manager stat val markup is rendered. --><div class="manager-stat-val">
                                    <?= $s['confirmed_count'] ?> / <?= $s['capacity'] ?>
                                </div>
                                <!-- The manager stat label markup is rendered. --><div class="manager-stat-label">Confirmed</div>
                            </div>

                            <?php /* Pending receipt-review counts are highlighted. */ if ($s['pending_review_count'] > 0): ?>
                                <!-- The manager stat badge markup is rendered. --><div class="manager-stat-badge alert">
                                    <!-- The manager stat val markup is rendered. --><div class="manager-stat-val">
                                        <?= $s['pending_review_count'] ?>
                                    </div>
                                    <!-- The manager stat label markup is rendered. --><div class="manager-stat-label alert-text">Receipts to review</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Management actions are displayed according to the required permissions. -->
                        <div class="manager-btn-group">
                            <?php /* Participant management is linked for authorized sign-ups managers. */ if (can_manage_club((int) $s['club_id'], $user['id'], 'signups')): ?>
                            <a href="<?= app_url('/manager/participants.php?session_id=' . $s['id']) ?>" class="manager-btn manager-btn-primary">
                                Participants (<?= $s['confirmed_count'] + $s['pending_review_count'] + $s['awaiting_payment_count'] ?>)
                            </a>
                            <?php endif; ?>
                            <?php /* Session-editing actions are displayed for authorized session managers. */ if (can_manage_club((int) $s['club_id'], $user['id'], 'sessions')): ?>
                            <?php /* Draft sessions are linked to the full editor. */ if ($s['status'] === 'draft'): ?>
                                <a href="<?= app_url('/manager/session-edit.php?id=' . $s['id']) ?>" class="manager-btn manager-btn-secondary">
                                    Edit draft
                                </a>
                            <?php /* Published sessions are offered the corresponding management actions. */ elseif ($s['status'] === 'published'): ?>
                                <a href="<?= app_url('/manager/close-signup.php?id=' . $s['id']) ?>" class="manager-btn manager-btn-secondary">
                                    Close signup
                                </a>
                                <a href="<?= app_url('/manager/cancel-session.php?id=' . $s['id']) ?>" class="manager-btn manager-btn-danger">
                                    Cancel session
                                </a>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
