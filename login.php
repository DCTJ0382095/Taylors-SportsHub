<?php
// A shared login form is provided for student and committee accounts.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Users who are already logged in are sent straight on to the requested page (or Explore Sessions).
if (is_logged_in()) {
    $redirect = $_GET['redirect'] ?? app_url('/sessions.php');
    header("Location: " . $redirect);
    exit;
}

// Page state. The typed email is kept so the form can be refilled after a failed attempt.
$page_title = "Log in";
$error = null;
$email_val = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
// For signed-out requests, the return target is validated as a local application path. Invalid targets
// are replaced with the session directory.
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';
$path = is_string($redirect) ? explode('?', $redirect, 2)[0] : '';
// The path is decoded first, so encoded slashes, backslashes and traversal segments are caught too.
$decoded_path = rawurldecode($path);
// Unsafe or out-of-application return paths are replaced with the session directory.
if (!str_starts_with($decoded_path, APP_BASE_PATH . '/') ||
    str_contains($decoded_path, '//') ||
    str_contains($decoded_path, '\\') ||
    preg_match('#(?:^|/)\.{1,2}(?:/|$)|[\x00-\x20\x7f%?\#]#D', $decoded_path) ||
    preg_match('/[\x00-\x20\x7f]/', $redirect)) {
    $redirect = app_url('/sessions.php');
}

// Form submission: the CSRF token is checked first, then the email and password.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Invalid CSRF tokens are reported before credential checks.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Session expired. Please try again.";
    } /* Submitted credentials are validated after CSRF verification. */ else {
        $password = $_POST['password'] ?? '';

        // Empty fields are given the same message as a wrong password, so no hint is given about which part was wrong.
        if ($email_val === '' || !is_string($password) || $password === '') {
            $error = "Email or password is incorrect. Check your details and try again.";
        } /* Non-empty credentials are checked against the account record. */ else {
            // The account is looked up by email, and the password is checked against the stored hash.
            $db = get_db_connection();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email_val]);
            $user = $stmt->fetch();

            // Matching credentials are checked for an active account before login.
            if ($user && password_verify($password, $user['password_hash'])) {
                // Suspended accounts are refused. Other accounts with valid passwords are logged in and redirected.
                if ($user['status'] === 'suspended') {
                    $error = "Your account has been suspended. Please contact the administrator.";
                } /* An active account is logged in and redirected. */ else {
                    login_user($user);
                    header("Location: " . $redirect);
                    exit;
                }
            } /* A generic credential error is displayed after failed authentication. */ else {
                // The same failure message is displayed for unknown emails and incorrect passwords.
                $error = "Email or password is incorrect. Check your details and try again.";
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- The app container markup is rendered. --><div class="app-container">
    <!-- The auth split layout markup is rendered. --><div class="auth-split-layout">
        <!-- Welcome text and a poster are displayed in the introductory column. -->
        <div class="auth-hero-col">
            <span class="section-label">WELCOME BACK TO CAMPUS LIFE</span>
            <h1 class="auth-hero-title">
                Your next great<br>moment is waiting
            </h1>
            <p class="auth-hero-desc">
                Pick up where you left off. Your sports sessions, your community, all in one place.
            </p>

            <!-- The auth hero poster markup is rendered. --><div class="auth-hero-poster">
                <img src="<?= APP_BASE_PATH ?>/assets/images/sports/badminton-open-play.webp?v=<?= filemtime(__DIR__ . '/assets/images/sports/badminton-open-play.webp') ?>" alt="Taylor's Badminton Club Open Play" class="poster-uploaded-img">
            </div>
            <!-- The auth hero tagline markup is rendered. --><div class="auth-hero-tagline">
                Made for the Taylor's sports community. Made for you.
            </div>
        </div>

        <!-- The login form is displayed in the second column. -->
        <div class="auth-form-card">
            <span class="auth-card-eyebrow">TAYLOR'S SPORTS HUB</span>
            <h2 class="auth-card-title">Good to see you again</h2>
            <p class="auth-card-sub">Log in with your Taylor's email to save your seat.</p>

            <!-- Login errors are displayed. -->
            <?php /* Login feedback is displayed above the form. */ if ($error): ?>
                <div class="status-alert-box danger" role="alert">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12.01" y1="16" y2="16"/></svg>
                    <!-- The content group markup is rendered. --><div>
                        <strong><?= e($error) ?></strong>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Demo credentials are prefilled and the return path is retained in a hidden field. -->
            <form method="POST" action="<?= APP_BASE_PATH ?>/login.php">
                <?= csrf_field() ?>
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

                <!-- The form field markup is rendered. --><div class="form-field">
                    <label class="form-label">Taylor's email</label>
                    <input type="email" name="email" value="<?= e($email_val ?: 'alex@sd.taylors.edu.my') ?>"
                           class="form-input <?= $error ? 'input-error' : '' ?>" required placeholder="name@sd.taylors.edu.my">
                </div>

                <!-- The form field markup is rendered. --><div class="form-field">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input <?= $error ? 'input-error' : '' ?>"
                           required value="Password123!" placeholder="••••••••••••">
                </div>

                <!-- Remember-login and password-recovery controls are displayed without server-side handling. -->
                <div class="auth-checkbox-row">
                    <label class="auth-checkbox-label">
                        <input type="checkbox" name="remember" checked class="auth-checkbox">
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="auth-forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary btn-auth-submit">
                    <span>Log in</span>
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>

                <!-- Registration and shared manager-login guidance are displayed. -->
                <div class="auth-switch-prompt">
                    New to Sports Hub? <a href="<?= APP_BASE_PATH ?>/register.php" class="auth-switch-link">Create an account</a>
                </div>

                <!-- The auth disclaimer markup is rendered. --><div class="auth-disclaimer">
                    Students and club managers use the same login. Manager workspace access is assigned by the university.
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
