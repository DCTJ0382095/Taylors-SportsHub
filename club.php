<?php
// The club profile is assembled from club details, upcoming sessions, committee records and membership
// information.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$club_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

// The club is loaded by ID. Unknown IDs are shown the 404 page.
$stmt = $db->prepare("SELECT * FROM clubs WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $club_id]);
$club = $stmt->fetch();

// Unknown clubs are handled with a 404 response.
if (!$club) {
    render_error_page(404);
}

// Upcoming published sessions for the selected club are loaded in start-time order.
$stmt = $db->prepare("SELECT * FROM sessions WHERE club_id = :id AND status = 'published' AND CONCAT(session_date, ' ', start_time) >= NOW() ORDER BY session_date ASC, start_time ASC");
$stmt->execute([':id' => $club_id]);
$sessions = $stmt->fetchAll();

// The club's committee is listed with the president first, then members in the order of joining.
$stmt = $db->prepare("SELECT u.name, cm.role, cm.title
                      FROM club_managers cm
                      INNER JOIN users u ON u.id = cm.user_id
                      WHERE cm.club_id = :id
                      ORDER BY cm.role = 'president' DESC, cm.created_at ASC");
$stmt->execute([':id' => $club_id]);
$committee = $stmt->fetchAll();

// Membership settings and the current account's membership standing are loaded.
$viewer = current_user();                                  // A null value is used for guests.
$member_status = $viewer ? get_member_status($club_id, $viewer['id']) : null;
$my_membership = $viewer ? get_user_club_membership($club_id, $viewer['id']) : null;
// The Edit button is shown when club-profile or membership-management permission is granted.
$can_edit_club = $viewer && (can_manage_club($club_id, $viewer['id'], 'club') || can_manage_club($club_id, $viewer['id'], 'signups'));
// Committee members and active memberships are counted without duplicate students.
$member_count = count_club_members($club_id);
// Benefit lines are trimmed, and empty lines are removed.
$benefits = array_filter(array_map('trim', explode("\n", $club['membership_benefits'] ?? '')));

// Fixed badge initials and gradients are assigned to the three named demo clubs.
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

// The first two letters and a grey gradient are used for other clubs.
$meta = $club_metadata[$club_id] ?? [
    'initials' => strtoupper(substr($club['name'], 0, 2)),
    'badge_bg' => 'linear-gradient(135deg, #374151 0%, #111827 100%)',
];

$page_title = $club['name'];
require_once __DIR__ . '/includes/header.php';
?>

<!-- The club page container markup is rendered. --><div class="club-page-container">
    <!-- Navigation back to the club directory is provided. -->
    <nav class="club-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= app_url('/clubs.php') ?>">Sports Clubs</a> /
        <span class="breadcrumb-current"><?= e($club['name']) ?></span>
    </nav>

    <!-- The club banner is displayed. -->
    <div class="club-banner-frame">
        <img src="<?= e(get_club_banner_url($club)) ?>" alt="<?= e($club['name']) ?> Banner" loading="eager">
    </div>

    <!-- Club identity, editing access, sport and membership count are displayed. -->
    <div class="club-identity-card">
        <!-- The club identity left markup is rendered. --><div class="club-identity-left">
            <?php $logo_url = get_club_logo_url($club); ?>
            <?php /* The uploaded club logo is displayed. */ if ($logo_url): ?>
                <!-- The club squircle badge markup is rendered. --><div class="club-squircle-badge club-squircle-badge--image">
                    <img src="<?= e($logo_url) ?>" alt="<?= e($club['name']) ?> logo">
                </div>
            <?php /* Initials are displayed when a club logo is unavailable. */ else: ?>
                <!-- The club squircle badge markup is rendered. --><div class="club-squircle-badge" style="background: <?= $meta['badge_bg'] ?>;">
                    <span><?= e($meta['initials']) ?></span>
                </div>
            <?php endif; ?>
            <!-- The club identity details markup is rendered. --><div class="club-identity-details">
                <!-- The club title row markup is rendered. --><div class="club-title-row">
                    <h1 class="club-profile-title"><?= e($club['name']) ?></h1>
                    <?php /* Club editing is offered to authorized accounts. */ if ($can_edit_club): ?>
                        <a href="<?= app_url('/manager/club-settings.php?club_id=' . $club_id) ?>" class="club-edit-btn">Edit</a>
                    <?php endif; ?>
                </div>
                <!-- The club profile meta row markup is rendered. --><div class="club-profile-meta-row">
                    <span class="club-meta-text"><?= e($club['sport_type']) ?> · <?= $member_count ?> <?= $member_count === 1 ? 'member' : 'members' ?></span>
                </div>
            </div>
        </div>

        <!-- Membership navigation and link sharing are offered. -->
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

    <!-- Club content is arranged in two columns. -->
    <div class="club-profile-grid">
        <!-- Club details, sessions and committee records are grouped in the main column. -->
        <div class="club-grid-main">
            <!-- The club description is displayed. -->
            <section class="club-content-card">
                <h2 class="club-card-heading">About the club</h2>
                <p class="club-about-text">
                    <?= nl2br(e($club['description'])) ?>
                </p>
            </section>

            <!-- Upcoming sessions are listed with dates, venues and prices. -->
            <section class="club-content-card">
                <!-- The club card header split markup is rendered. --><div class="club-card-header-split">
                    <h2 class="club-card-heading">Upcoming sessions</h2>
                    <span class="club-sessions-count-badge"><?= count($sessions) ?> upcoming</span>
                </div>

                <?php /* An empty-session message is displayed when no upcoming sessions are found. */ if (empty($sessions)): ?>
                    <!-- The club empty sessions markup is rendered. --><div class="club-empty-sessions">
                        <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 14.14 14.14"/></svg>
                        <p>No upcoming sessions published for this club right now.</p>
                        <a href="<?= app_url('/sessions.php') ?>" class="btn-secondary" style="padding: 6px 16px; border-radius: 980px; font-size: 13px;">Browse all sessions</a>
                    </div>
                <?php /* Upcoming sessions are displayed as club session cards. */ else: ?>
                    <!-- The club sessions list markup is rendered. --><div class="club-sessions-list">
                        <?php /* One session card is rendered for each upcoming session. */ foreach ($sessions as $s): 
                            $date_obj = new DateTime($s['session_date']);
                            $day_num = $date_obj->format('j');
                            $month_abbr = strtoupper($date_obj->format('M'));
                            $day_name = $date_obj->format('D');
                            $seat_info = get_session_seat_info($s['id']);
                        ?>
                            <a href="<?= app_url('/session-detail.php?id=' . $s['id']) ?>" class="club-session-row-item">
                                <!-- The club session date tile markup is rendered. --><div class="club-session-date-tile">
                                    <span class="date-day"><?= e($day_num) ?></span>
                                    <span class="date-month"><?= e($month_abbr) ?></span>
                                </div>
                                <!-- The club session info col markup is rendered. --><div class="club-session-info-col">
                                    <!-- The club session title line markup is rendered. --><div class="club-session-title-line">
                                        <h3 class="club-session-title"><?= e($s['title']) ?></h3>
                                    </div>
                                    <!-- The club session subline markup is rendered. --><div class="club-session-subline">
                                        <span><?= e($day_name) ?> · <?= date('g:i A', strtotime($s['start_time'])) ?> · <?= e($s['venue']) ?></span>
                                    </div>
                                </div>
                                <!-- The club session price action markup is rendered. --><div class="club-session-price-action">
                                    <span class="club-session-price">
                                        <?= e(format_price($s['price'])) ?>
                                        <?php /* A cheaper member price is displayed when configured. */ if (format_member_price($s)): ?>
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

            <!-- Committee records are displayed with distinct president styling. -->
            <section class="club-content-card">
                <h2 class="club-card-heading">Committee</h2>
                <!-- The club committee grid markup is rendered. --><div class="club-committee-grid">
                    <?php /* An empty committee message is displayed when no committee records are found. */ if (empty($committee)): ?>
                        <p class="committee-role">No committee members yet.</p>
                    <?php endif; ?>
                    <?php /* Each committee member is rendered with a display title. */ foreach ($committee as $member): ?>
                        <?php $is_pres = $member['role'] === 'president'; ?>
                        <!-- The committee member item markup is rendered. --><div class="committee-member-item">
                            <!-- The committee avatar markup is rendered. --><div class="committee-avatar" style="background: <?= $is_pres ? '#fecdd3' : '#dbeafe' ?>; color: <?= $is_pres ? '#9f1239' : '#1e40af' ?>;">
                                <?= e(get_initials($member['name'])) ?>
                            </div>
                            <!-- The committee details markup is rendered. --><div class="committee-details">
                                <!-- The committee name markup is rendered. --><div class="committee-name"><?= e($member['name']) ?></div>
                                <!-- The committee role markup is rendered. --><div class="committee-role"><?= e(committee_title($member)) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
            </section>
        </div>

        <!-- Membership and contact details are grouped in the sidebar. -->
        <aside class="club-grid-sidebar">
            <!-- Membership terms, benefits and account status are displayed. -->
            <div class="club-sidebar-card membership-card" id="membership">
                <!-- The membership card top markup is rendered. --><div class="membership-card-top">
                    <!-- The sidebar card label markup is rendered. --><div class="sidebar-card-label">MEMBERSHIP</div>
                </div>

                <!-- Membership-action feedback is displayed. -->
                <?php membership_feedback(); ?>

                <?php /* Open membership availability is displayed. */ if ($club['membership_open']): ?>
                    <div class="membership-price-row">
                        <span class="membership-currency-amount"><?= e(format_price($club['membership_price'])) ?></span>
                        <span class="membership-period">/ <?= e(format_membership_length((int) $club['membership_months'])) ?></span>
                    </div>
                <?php /* Closed membership availability is displayed. */ else: ?>
                    <p class="membership-note">This club isn't taking new members right now.</p>
                <?php endif; ?>

                <?php /* Membership benefits are displayed when configured. */ if ($benefits): ?>
                    <!-- The membership perks list markup is rendered. --><ul class="membership-perks-list">
                        <?php /* Each membership benefit is rendered as a list item. */ foreach ($benefits as $benefit): ?>
                            <!-- The list entry markup is rendered. --><li>
                                <svg class="perk-check-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                <span><?= e($benefit) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php [$status_text, $status_tone] = membership_status_label($member_status); ?>
                
                <!-- The membership status row markup is rendered. --><div class="membership-status-row">
                    <span class="status-label">Your status</span>
                    <span class="member-status-pill tone-<?= $status_tone ?>"><?= $status_text ?></span>
                </div>

                <!-- Membership actions are selected according to authentication and membership state. -->
                <?php /* Guests are directed to authentication before membership application. */ if (!$viewer): ?>
                    <a href="<?= app_url('/login.php') . '?redirect=' . urlencode(app_url('/club.php?id=' . $club_id)) ?>" class="btn-sidebar-apply">Log in to apply</a>
                <?php /* Committee membership is acknowledged without a paid application. */ elseif ($member_status === 'committee'): ?>
                    <p class="membership-note">You're on the committee, so you count as a member without paying.</p>
                <?php /* Active membership details are displayed. */ elseif ($member_status === 'active'): ?>
                    <button type="button" class="btn-sidebar-apply" disabled>Member until <?= e(format_membership_date($my_membership['expires_at'])) ?></button>
                <?php /* Pending membership applications are linked to the payment page. */ elseif (in_array($member_status, ['awaiting_payment', 'payment_rejected', 'awaiting_approval'], true)): ?>
                    <a href="<?= app_url('/membership-payment.php?id=' . $my_membership['id']) ?>" class="btn-sidebar-apply">
                        <?= /* A membership action label is selected from the pending state. */ match ($member_status) {
                            'awaiting_approval' => 'View your application',
                            'payment_rejected'  => 'Resubmit your receipt',
                            // The payment-completion label is used as the fallback.
                            default             => 'Complete your payment',
                        } ?>
                    </a>
                <?php /* A membership application or renewal action is offered while applications are open. */ elseif ($club['membership_open']): ?>
                    <!-- The form controls markup is rendered. --><form method="POST" action="<?= app_url('/membership-join.php') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="club_id" value="<?= $club_id ?>">
                        <button type="submit" class="btn-sidebar-apply"><?= $member_status === 'expired' ? 'Renew membership' : 'Apply for membership' ?></button>
                    </form>
                <?php /* Closed membership applications are explained. */ else: ?>
                    <button type="button" class="btn-sidebar-apply" disabled>Memberships closed</button>
                <?php endif; ?>
            </div>

            <!-- Configured training, email and social details are displayed. -->
            <div class="club-sidebar-card club-info-card">
                <!-- The sidebar card label markup is rendered. --><div class="sidebar-card-label">CLUB INFO</div>
                <!-- The club info rows markup is rendered. --><div class="club-info-rows">
                    <?php /* The club training schedule is displayed when configured. */ if (!empty($club['training_schedule'])): ?>
                    <!-- The club info row markup is rendered. --><div class="club-info-row">
                        <svg class="info-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                        <span class="info-text"><?= e($club['training_schedule']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php /* The club contact email is displayed when configured. */ if (!empty($club['contact_email'])): ?>
                    <!-- The club info row markup is rendered. --><div class="club-info-row">
                        <svg class="info-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <a href="mailto:<?= e($club['contact_email']) ?>" class="info-text info-link"><?= e($club['contact_email']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php /* The club Instagram link is displayed when configured. */ if (!empty($club['instagram'])): ?>
                    <!-- The club info row markup is rendered. --><div class="club-info-row">
                        <svg class="info-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        <span class="info-text"><?= e($club['instagram']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- Club-link sharing is handled by the following script. -->
<script>
// The page URL is copied to the clipboard and a confirmation is displayed.
// The page URL is displayed in an alert when the Clipboard API is unavailable.
function shareClubLink() {
    // The current club URL is shared through the available browser feedback controls.
    if (navigator.clipboard) {
        // The Clipboard API is used when exposed by the browser.
        navigator.clipboard.writeText(window.location.href).then(() => {
            // Successful copying is followed by a confirmation message.
            // The shared toast is used when available, with a browser alert used as the fallback.
            if (window.showToast) {
                // Copy confirmation is displayed through the shared toast helper.
                showToast('Club link copied to clipboard!', 'success');
            } else {
                // Copy confirmation is displayed through a browser alert when the toast helper is unavailable.
                alert('Club link copied to clipboard!');
            }
        });
    } else {
        // The current club URL is displayed in an alert when the Clipboard API is unavailable.
        alert('Club link: ' + window.location.href);
    }
}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
