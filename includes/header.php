<?php
// Shared page navigation and stylesheets are prepared before the header markup is rendered.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

// The current user and page are looked up so the right navigation link can be highlighted.
$current_user = current_user();
$current_page = basename($_SERVER['PHP_SELF']);
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$is_manager_route = str_contains($script_name, '/manager/');
// The Manager Workspace link is shown to admins and to anyone holding a committee seat.
$managed_clubs = $current_user ? get_user_managed_clubs($current_user['id']) : [];
$is_any_manager = !empty($managed_clubs) || ($current_user && $current_user['role'] === 'admin');

// Page-specific stylesheets are mapped by filename and loaded after style.css.
$css_map = [
    'index.php' => 'home.css',
    'sessions.php' => 'sessions.css',
    'session-detail.php' => 'session-detail.css',
    'clubs.php' => 'clubs.css',
    'club.php' => 'club.css',
    'my-activities.php' => 'my-activities.css',
    'payment.php' => 'payment.css',
    'membership-payment.php' => 'payment.css',
    'cancel-registration.php' => 'payment.css',
    'profile.php' => 'profile.css',
    'memberships.php' => 'memberships.css',
    'login.php' => 'auth.css',
    'register.php' => 'auth.css',
    'privacy.php' => 'legal.css',
    'terms.php' => 'legal.css',
];
// Pages inside manager/ are matched separately, because some manager file names (such as sessions.php)
// are the same as public pages that use a different stylesheet.
if ($is_manager_route) {
    $page_css = /* A manager-page stylesheet is selected from the current filename. */ match ($current_page) {
        'participants.php' => 'participants.css',
        'review-receipt.php', 'record-refund.php', 'review-membership.php' => 'journey.css',
        // The shared manager stylesheet is selected by default.
        default => 'manager.css',
    };
} /* The public-page stylesheet is selected outside the manager directory. */ else {
    $page_css = $css_map[$current_page] ?? null;
}
// Shared stylesheet dependencies are linked separately, and each asset URL is versioned with the file
// modification time.
$page_css_files = /* Shared stylesheet dependencies are selected for the current page. */ match ($page_css) {
    'payment.css' => ['journey.css', 'registration-actions.css', 'payment.css'],
    'session-detail.css' => ['registration-actions.css', 'session-detail.css'],
    null => [],
    // Only the page stylesheet is requested when no shared dependency is needed.
    default => [$page_css],
};
?>
<!DOCTYPE html>
<!-- The page document markup is rendered. --><html lang="en">
<!-- The document metadata markup is rendered. --><head>
    <!-- Page metadata and versioned stylesheet links are rendered. -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? e($page_title) . " · Taylor's SportsHub" : "Taylor's SportsHub · Student sports & club sessions" ?></title>
    <link rel="icon" type="image/x-icon" href="<?= APP_BASE_PATH ?>/favicon.ico">
    <link rel="icon" type="image/webp" href="<?= APP_BASE_PATH ?>/assets/images/logo/logo.webp">
    <link rel="apple-touch-icon" href="<?= APP_BASE_PATH ?>/assets/images/logo/logo.webp">
    <link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <!-- Available page-specific stylesheets are linked. -->
    <?php /* Each selected stylesheet is processed for inclusion. */ foreach ($page_css_files as $css_file): ?>
        <?php /* Existing stylesheet files are linked with a modification-time version. */ if (file_exists(__DIR__ . '/../assets/css/' . $css_file)): ?>
            <link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/css/<?= e($css_file) ?>?v=<?= filemtime(__DIR__ . '/../assets/css/' . $css_file) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
    <!-- Shared form and alignment styles are linked after page styles. -->
    <link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/css/form-controls.css?v=<?= filemtime(__DIR__ . '/../assets/css/form-controls.css') ?>">
    <link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/css/alignment.css?v=<?= filemtime(__DIR__ . '/../assets/css/alignment.css') ?>">
</head>
<!-- The site form controls markup is rendered. --><body class="site-form-controls">
<!-- A direct keyboard navigation link to main content is provided. -->
<a class="skip-link" href="#main-content">Skip to main content</a>

<!-- Branding, primary navigation and account controls are displayed. -->
<header class="site-header">
    <!-- The header inner markup is rendered. --><div class="header-inner">
        <a href="<?= APP_BASE_PATH ?>/index.php" class="brand-group" aria-label="Taylor's SportsHub Home">
            <?= get_site_logo_html() ?>
            <!-- The brand text markup is rendered. --><div class="brand-text">
                <span class="brand-title">Taylor's</span>
                <span class="brand-subtitle">SPORTS HUB</span>
            </div>
        </a>

        <!-- Current-page navigation is highlighted and authenticated activity access is offered. -->
        <nav class="nav-links" aria-label="Main Navigation">
            <a href="<?= APP_BASE_PATH ?>/index.php" class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>">Home</a>
            <a href="<?= APP_BASE_PATH ?>/sessions.php" class="nav-link <?= (!$is_manager_route && in_array($current_page, ['sessions.php', 'session-detail.php'])) ? 'active' : '' ?>">Explore Sessions</a>
            <a href="<?= APP_BASE_PATH ?>/clubs.php" class="nav-link <?= in_array($current_page, ['clubs.php', 'club.php']) ? 'active' : '' ?>">Clubs</a>
            <?php /* Authenticated account controls are rendered. */ if ($current_user): ?>
                <a href="<?= APP_BASE_PATH ?>/my-activities.php" class="nav-link <?= in_array($current_page, ['my-activities.php', 'payment.php', 'cancel-registration.php']) ? 'active' : '' ?>">My Activities</a>
            <?php endif; ?>
        </nav>

        <!-- Account or guest actions are selected according to authentication. -->
        <div class="nav-actions">
            <?php /* Account-specific menu links are rendered after authentication. */ if ($current_user): ?>
                <?php /* The manager workspace link is shown to authorized accounts. */ if ($is_any_manager): ?>
                    <a href="<?= APP_BASE_PATH ?>/manager/sessions.php" class="btn-manage-club">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>Manager Workspace</span>
                    </a>
                <?php endif; ?>

                <a href="<?= APP_BASE_PATH ?>/memberships.php" class="btn-manage-club nav-memberships-btn" aria-label="My Memberships">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><path d="M14 10h4M14 14h4"/></svg>
                    <span>Memberships</span>
                </a>

                <!-- The account dropdown is kept inert until opened. -->
                <div class="user-account">
                <button type="button" class="user-menu-pill user-pill" aria-controls="account-menu" aria-expanded="false" aria-label="User menu for <?= e($current_user['name']) ?>">
                    <span class="avatar-initials"><?= get_initials($current_user['name']) ?></span>
                    <span class="user-name-text"><?= e($current_user['name']) ?></span>
                    <svg class="dropdown-arrow" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>

                </button>
                    <!-- The user dropdown markup is rendered. --><div class="user-dropdown" id="account-menu" inert>
                        <!-- The user dropdown header markup is rendered. --><div class="user-dropdown-header">
                            <strong><?= e($current_user['name']) ?></strong>
                            <span><?= e($current_user['email']) ?></span>
                        </div>
                        <a href="<?= APP_BASE_PATH ?>/profile.php" class="dropdown-item">My Profile</a>
                        <a href="<?= APP_BASE_PATH ?>/my-activities.php" class="dropdown-item">My Activities</a>
                        <a href="<?= APP_BASE_PATH ?>/memberships.php" class="dropdown-item">My Memberships</a>

                        <!-- The dropdown divider markup is rendered. --><div class="dropdown-divider"></div>
                        <!-- Logout is submitted through a CSRF-protected POST form. -->
                        <form method="POST" action="<?= APP_BASE_PATH ?>/logout.php" style="margin: 0;">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item text-danger" style="width:100%; border:0; background:none; font:inherit; text-align:left; cursor:pointer;">Sign out</button>
                        </form>
                    </div>
                </div>
            <?php /* Login and registration links are shown to guests. */ else: ?>
                <a href="<?= APP_BASE_PATH ?>/login.php" class="btn-link <?= $current_page === 'login.php' ? 'active' : '' ?>">Log in</a>
                <a href="<?= APP_BASE_PATH ?>/register.php" class="btn-primary <?= $current_page === 'register.php' ? 'active' : '' ?>">Register</a>
            <?php endif; ?>

            <!-- The mobile drawer is controlled through the navigation button. -->
            <button type="button" class="mobile-menu-btn" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobile-navigation">
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
            </button>
        </div>
    </div>
</header>

<!-- The mobile drawer and backdrop are rendered with initial inertness. -->
<div class="mobile-drawer-backdrop" aria-hidden="true"></div>
<!-- The mobile drawer markup is rendered. --><aside class="mobile-drawer" id="mobile-navigation" role="dialog" aria-modal="true" aria-label="Navigation" inert>
    <!-- Branding and the drawer close control are displayed. -->
    <div class="mobile-drawer-header">
        <a href="<?= APP_BASE_PATH ?>/index.php" class="brand-group" aria-label="Taylor's SportsHub Home">
            <?= get_site_logo_html() ?>
            <!-- The brand text markup is rendered. --><div class="brand-text">
                <span class="brand-title">Taylor's</span>
                <span class="brand-subtitle">SPORTS HUB</span>
            </div>
        </a>
        <button type="button" class="mobile-drawer-close" aria-label="Close navigation menu">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>

    <!-- Mobile navigation and authorized account actions are displayed. -->
    <nav class="mobile-drawer-nav">
        <a href="<?= APP_BASE_PATH ?>/index.php" class="mobile-nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>">Home</a>
        <a href="<?= APP_BASE_PATH ?>/sessions.php" class="mobile-nav-link <?= (!$is_manager_route && in_array($current_page, ['sessions.php', 'session-detail.php'])) ? 'active' : '' ?>">Explore Sessions</a>
        <a href="<?= APP_BASE_PATH ?>/clubs.php" class="mobile-nav-link <?= in_array($current_page, ['clubs.php', 'club.php']) ? 'active' : '' ?>">Sports Clubs</a>
        <?php /* Account-specific drawer links are rendered after authentication. */ if ($current_user): ?>
            <a href="<?= APP_BASE_PATH ?>/profile.php" class="mobile-nav-link <?= $current_page === 'profile.php' ? 'active' : '' ?>">My Profile</a>
            <a href="<?= APP_BASE_PATH ?>/my-activities.php" class="mobile-nav-link <?= in_array($current_page, ['my-activities.php', 'payment.php', 'cancel-registration.php']) ? 'active' : '' ?>">My Activities</a>
            <a href="<?= APP_BASE_PATH ?>/memberships.php" class="mobile-nav-link <?= $current_page === 'memberships.php' ? 'active' : '' ?>">My Memberships</a>
            <?php /* The manager workspace link is included in the authorized mobile drawer. */ if ($is_any_manager): ?>
                <a href="<?= APP_BASE_PATH ?>/manager/sessions.php" class="mobile-nav-link manager-nav-link">Manager Workspace</a>
            <?php endif; ?>
            <!-- The mobile drawer footer markup is rendered. --><div class="mobile-drawer-footer">
                <!-- The logout form markup is rendered. --><form method="POST" action="<?= APP_BASE_PATH ?>/logout.php" class="logout-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-secondary btn-full btn-danger-text">Log out</button>
                </form>
            </div>
        <?php /* Guest authentication links are rendered in the mobile drawer. */ else: ?>
            <!-- The mobile drawer auth markup is rendered. --><div class="mobile-drawer-auth">
                <a href="<?= APP_BASE_PATH ?>/login.php" class="btn-secondary btn-full">Log in</a>
                <a href="<?= APP_BASE_PATH ?>/register.php" class="btn-primary btn-full">Register</a>
            </div>
        <?php endif; ?>
    </nav>
</aside>

<!-- Main page content is opened here and closed in the footer. -->
<main id="main-content" tabindex="-1">
