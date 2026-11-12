<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$club_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

$stmt = $db->prepare("SELECT * FROM clubs WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $club_id]);
$club = $stmt->fetch();

if (!$club) {
    render_error_page(404);
}

// Fetch sessions
$stmt = $db->prepare("SELECT * FROM sessions WHERE club_id = :id AND status = 'published' AND CONCAT(session_date, ' ', start_time) >= NOW() ORDER BY session_date ASC, start_time ASC");
$stmt->execute([':id' => $club_id]);
$sessions = $stmt->fetchAll();

// The club's real committee: the president first, then members in the order they joined.
$stmt = $db->prepare("SELECT u.name, cm.role, cm.title
                      FROM club_managers cm
                      INNER JOIN users u ON u.id = cm.user_id
                      WHERE cm.club_id = :id
                      ORDER BY cm.role = 'president' DESC, cm.created_at ASC");
$stmt->execute([':id' => $club_id]);
$committee = $stmt->fetchAll();

// Membership: the club's settings, and where the person looking at the page stands.
$viewer = current_user();                                  // null if nobody is logged in
$member_status = $viewer ? get_member_status($club_id, $viewer['id']) : null;
$my_membership = $viewer ? get_user_club_membership($club_id, $viewer['id']) : null;
// Same people who get the Settings button in the Manager Workspace (club details or memberships).
$can_edit_club = $viewer && (can_manage_club($club_id, $viewer['id'], 'club') || can_manage_club($club_id, $viewer['id'], 'signups'));
$member_count = count_club_members($club_id);
// One benefit per line. trim() removes spaces (and Windows' hidden "\r"), array_filter() drops empty lines.
$benefits = array_filter(array_map('trim', explode("\n", $club['membership_benefits'] ?? '')));

// Club metadata dictionary for Figma exact match
$club_metadata = [
    1 => [
        'initials' => 'BC',
        'badge_bg' => 'linear-gradient(135deg, #1b4931 0%, #0d281a 100%)',
    ],
    2 => [
        'initials' => 'BB',
        'badge_bg' => 'linear-gradient(135deg, #7c2d12 0%, #431407 100%)',
    ],
    3 => [
        'initials' => 'PB',
        'badge_bg' => 'linear-gradient(135deg, #0369a1 0%, #0c4a6e 100%)',
    ]
];

$meta = $club_metadata[$club_id] ?? [
    'initials' => strtoupper(substr($club['name'], 0, 2)),
    'badge_bg' => 'linear-gradient(135deg, #374151 0%, #111827 100%)',
];

$page_title = $club['name'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="club-page-container">
    <!-- Breadcrumb -->
    <nav class="club-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= app_url('/clubs.php') ?>">Sports Clubs</a> /
        <span class="breadcrumb-current"><?= e($club['name']) ?></span>
    </nav>

    <!-- Top Banner Frame -->
    <div class="club-banner-frame">
        <img src="<?= e(get_club_banner_url($club)) ?>" alt="<?= e($club['name']) ?> Banner" loading="eager">
    </div>

    <!-- Identity Bar / Club Header Card (Matching Figma) -->
    <div class="club-identity-card">
        <div class="club-identity-left">
            <?php $logo_url = get_club_logo_url($club); ?>
            <?php if ($logo_url): ?>
                <div class="club-squircle-badge club-squircle-badge--image">
                    <img src="<?= e($logo_url) ?>" alt="<?= e($club['name']) ?> logo">
                </div>
            <?php else: ?>
                <div class="club-squircle-badge" style="background: <?= $meta['badge_bg'] ?>;">
                    <span><?= e($meta['initials']) ?></span>
                </div>
            <?php endif; ?>
            <div class="club-identity-details">
                <div class="club-title-row">
                    <h1 class="club-profile-title"><?= e($club['name']) ?></h1>
                    <?php if ($can_edit_club): ?>
                        <a href="<?= app_url('/manager/club-settings.php?club_id=' . $club_id) ?>" class="club-edit-btn">Edit</a>
                    <?php endif; ?>
                </div>
                <div class="club-profile-meta-row">
                    <span class="club-meta-text"><?= e($club['sport_type']) ?> · <?= $member_count ?> <?= $member_count === 1 ? 'member' : 'members' ?></span>
                </div>
            </div>
        </div>

        <div class="club-identity-actions">
            <a href="#membership" class="btn-club-apply">
                <span><?= in_array($member_status, ['committee', 'active'], true) ? 'View membership' : 'Apply for membership' ?></span>
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
            <button type="button" class="btn-club-share" onclick="shareClubLink()">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                <span>Share</span>
            </button>
        </div>
    </div>

    <!-- Two-Column Profile Layout (Matching 03-club-profile.png) -->
    <div class="club-profile-grid">
        <!-- Left Main Column -->
        <div class="club-grid-main">
            <!-- Card 1: About the club -->
            <section class="club-content-card">
                <h2 class="club-card-heading">About the club</h2>
                <p class="club-about-text">
                    <?= nl2br(e($club['description'])) ?>
                </p>
            </section>

            <!-- Card 2: Upcoming sessions -->
            <section class="club-content-card">
                <div class="club-card-header-split">
                    <h2 class="club-card-heading">Upcoming sessions</h2>
                    <span class="club-sessions-count-badge"><?= count($sessions) ?> upcoming</span>
                </div>

                <?php if (empty($sessions)): ?>
                    <div class="club-empty-sessions">
                        <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 14.14 14.14"/></svg>
                        <p>No upcoming sessions published for this club right now.</p>
                        <a href="<?= app_url('/sessions.php') ?>" class="btn-secondary" style="padding: 6px 16px; border-radius: 980px; font-size: 13px;">Browse all sessions</a>
                    </div>
                <?php else: ?>
                    <div class="club-sessions-list">
                        <?php foreach ($sessions as $s): 
                            $date_obj = new DateTime($s['session_date']);
                            $day_num = $date_obj->format('j');
                            $month_abbr = strtoupper($date_obj->format('M'));
                            $day_name = $date_obj->format('D');
                            $seat_info = get_session_seat_info($s['id']);
                        ?>
                            <a href="<?= app_url('/session-detail.php?id=' . $s['id']) ?>" class="club-session-row-item">
                                <div class="club-session-date-tile">
                                    <span class="date-day"><?= e($day_num) ?></span>
                                    <span class="date-month"><?= e($month_abbr) ?></span>
                                </div>
                                <div class="club-session-info-col">
                                    <div class="club-session-title-line">
                                        <h3 class="club-session-title"><?= e($s['title']) ?></h3>
                                    </div>
                                    <div class="club-session-subline">
                                        <span><?= e($day_name) ?> · <?= date('g:i A', strtotime($s['start_time'])) ?> · <?= e($s['venue']) ?></span>
                                    </div>
                                </div>
                                <div class="club-session-price-action">
                                    <span class="club-session-price">
                                        <?= e(format_price($s['price'])) ?>
                                        <?php if (format_member_price($s)): ?>
                                            <br><?= e(format_price($s['member_price'])) ?>
                                        <?php endif; ?>
                                    </span>
                                    <svg class="club-session-arrow" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Card 3: Committee -->
            <section class="club-content-card">
                <h2 class="club-card-heading">Committee</h2>
                <div class="club-committee-grid">
                    <?php if (empty($committee)): ?>
                        <p class="committee-role">No committee members yet.</p>
                    <?php endif; ?>
                    <?php foreach ($committee as $member): ?>
                        <?php $is_pres = $member['role'] === 'president'; ?>
                        <div class="committee-member-item">
                            <div class="committee-avatar" style="background: <?= $is_pres ? '#fecdd3' : '#dbeafe' ?>; color: <?= $is_pres ? '#9f1239' : '#1e40af' ?>;">
                                <?= e(get_initials($member['name'])) ?>
                            </div>
                            <div class="committee-details">
                                <div class="committee-name"><?= e($member['name']) ?></div>
                                <div class="committee-role"><?= e(committee_title($member)) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
            </section>
        </div>

        <!-- Right Sidebar Column -->
        <aside class="club-grid-sidebar">
            <!-- Membership Card -->
            <div class="club-sidebar-card membership-card" id="membership">
                <div class="membership-card-top">
                    <div class="sidebar-card-label">MEMBERSHIP</div>
                </div>

                <?php membership_feedback(); ?>

                <?php if ($club['membership_open']): ?>
                    <div class="membership-price-row">
                        <span class="membership-currency-amount"><?= e(format_price($club['membership_price'])) ?></span>
                        <span class="membership-period">/ <?= e(format_membership_length((int) $club['membership_months'])) ?></span>
                    </div>
                <?php else: ?>
                    <p class="membership-note">This club isn't taking new members right now.</p>
                <?php endif; ?>

                <?php if ($benefits): ?>
                    <ul class="membership-perks-list">
                        <?php foreach ($benefits as $benefit): ?>
                            <li>
                                <svg class="perk-check-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                <span><?= e($benefit) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php [$status_text, $status_tone] = membership_status_label($member_status); ?>
                
                <div class="membership-status-row">
                    <span class="status-label">Your status</span>
                    <span class="member-status-pill tone-<?= $status_tone ?>"><?= $status_text ?></span>
                </div>

                <?php if (!$viewer): ?>
                    <a href="<?= app_url('/login.php') . '?redirect=' . urlencode(app_url('/club.php?id=' . $club_id)) ?>" class="btn-sidebar-apply">Log in to apply</a>
                <?php elseif ($member_status === 'committee'): ?>
                    <p class="membership-note">You're on the committee, so you count as a member without paying.</p>
                <?php elseif ($member_status === 'active'): ?>
                    <button type="button" class="btn-sidebar-apply" disabled>Member until <?= e(format_membership_date($my_membership['expires_at'])) ?></button>
                <?php elseif (in_array($member_status, ['awaiting_payment', 'payment_rejected', 'awaiting_approval'], true)): ?>
                    <a href="<?= app_url('/membership-payment.php?id=' . $my_membership['id']) ?>" class="btn-sidebar-apply">
                        <?= match ($member_status) {
                            'awaiting_approval' => 'View your application',
                            'payment_rejected'  => 'Resubmit your receipt',
                            default             => 'Complete your payment',
                        } ?>
                    </a>
                <?php elseif ($club['membership_open']): ?>
                    <form method="POST" action="<?= app_url('/membership-join.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="club_id" value="<?= $club_id ?>">
                        <button type="submit" class="btn-sidebar-apply"><?= $member_status === 'expired' ? 'Renew membership' : 'Apply for membership' ?></button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn-sidebar-apply" disabled>Memberships closed</button>
                <?php endif; ?>
            </div>

            <!-- Club Info Card -->
            <div class="club-sidebar-card club-info-card">
                <div class="sidebar-card-label">CLUB INFO</div>
                <div class="club-info-rows">
                    <?php if (!empty($club['training_schedule'])): ?>
                    <div class="club-info-row">
                        <svg class="info-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        <span class="info-text"><?= e($club['training_schedule']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($club['contact_email'])): ?>
                    <div class="club-info-row">
                        <svg class="info-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <a href="mailto:<?= e($club['contact_email']) ?>" class="info-text info-link"><?= e($club['contact_email']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($club['instagram'])): ?>
                    <div class="club-info-row">
                        <svg class="info-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        <span class="info-text"><?= e($club['instagram']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
function shareClubLink() {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(window.location.href).then(() => {
            if (window.showToast) {
                showToast('Club link copied to clipboard!', 'success');
            } else {
                alert('Club link copied to clipboard!');
            }
        });
    } else {
        alert('Club link: ' + window.location.href);
    }
}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
