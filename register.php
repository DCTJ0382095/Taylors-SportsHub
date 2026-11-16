<?php
// New student accounts are created through the registration form.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Users who are already logged in are sent to Explore Sessions instead.
if (is_logged_in()) {
    header("Location: " . app_url('/sessions.php'));
    exit;
}

// Page state. The typed values are kept so the form can be refilled after an error.
$page_title = "Create an account";
$errors = [];
$name_val = trim($_POST['name'] ?? '');
$email_val = trim($_POST['email'] ?? '');
$phone_val = trim($_POST['phone'] ?? '');
$duplicate_email = false;
$success_user = null;

// Form submission: the CSRF token is checked first, then every field is validated.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Invalid CSRF tokens are reported before account creation.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['general'] = "Session expired. Please try again.";
    } /* Account fields are validated after CSRF verification. */ else {
        // Both confirm-field names (confirm_password and password_confirm) are accepted.
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? $_POST['password_confirm'] ?? '';
        $terms = !empty($_POST['terms']);

        // A non-empty name is required.
        if (empty($name_val)) {
            $errors['name'] = "Full name is required.";
        }

        // A Taylor's student-domain email address is required.
        if (empty($email_val)) {
            $errors['email'] = "Taylor's email is required.";
        } /* Email addresses outside the student-domain pattern are rejected. */ elseif (!is_valid_student_email($email_val)) {
            $errors['email'] = "Use your @sd.taylors.edu.my student email.";
        }

        // A password of at least eight bytes with a letter and a number is required.
        if (strlen($password) < 8 || !preg_match('/[0-9]/', $password) || !preg_match('/[a-zA-Z]/', $password)) {
            $errors['password'] = "Use at least 8 characters, including a letter and a number.";
        }

        // Matching password confirmation is required.
        if ($password !== $confirm_password) {
            $errors['confirm_password'] = "Your passwords don't match.";
        }

        // Acceptance of the terms is required.
        if (!$terms) {
            $errors['terms'] = "You must agree to the sports terms and cancellation policy.";
        }

        // With no errors, the email is checked for an existing account before anything is saved.
        if (empty($errors)) {
            $db = get_db_connection();
            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email_val]);
            // Existing email accounts are reported without another account insertion.
            if ($stmt->fetch()) {
                $duplicate_email = true;
            } /* A new active student account is saved with a password hash. */ else {
                // The password is hashed with PHP's default algorithm, and the account is saved as an active student.
                // An empty phone number is stored as NULL.
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, status, phone)
                                      VALUES (:name, :email, :password_hash, 'student', 'active', :phone)");
                $stmt->execute([
                    ':name' => $name_val,
                    ':email' => $email_val,
                    ':password_hash' => $password_hash,
                    ':phone' => $phone_val ?: null,
                ]);

                // The success card is shown with the new account's name and email.
                $success_user = [
                    'name' => $name_val,
                    'email' => $email_val
                ];
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- The app container markup is rendered. --><div class="app-container">
    <!-- The auth split layout markup is rendered. --><div class="auth-split-layout">
        <!-- Welcome text and a poster are displayed. -->
        <div class="auth-hero-col">
            <span class="section-label">YOUR CAMPUS EXPERIENCE STARTS HERE</span>
            <h1 class="auth-hero-title">
                A new connection<br>A new rally
            </h1>
            <p class="auth-hero-desc">
                One account for the moments that matter. Join university sports clubs, book your court places, and track your active journey at Taylor's.
            </p>

            <!-- The auth hero poster markup is rendered. --><div class="auth-hero-poster">
                <img src="<?= APP_BASE_PATH ?>/assets/images/sports/badminton-footwork.webp?v=<?= filemtime(__DIR__ . '/assets/images/sports/badminton-footwork.webp') ?>" alt="Taylor's Badminton Training" class="poster-uploaded-img">
            </div>
            <!-- The auth hero tagline markup is rendered. --><div class="auth-hero-tagline">
                Made for the Taylor's sports community. Made for you.
            </div>
        </div>

        <!-- Account creation feedback or the registration form is displayed. -->
        <div class="auth-form-card">
            <?php /* Successful registration is displayed with the account summary. */ if ($success_user): ?>
                <!-- Account creation is confirmed and login is offered. -->
                <div>
                    <!-- The auth success circle markup is rendered. --><div class="auth-success-circle">
                        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>

                    <h2 class="auth-card-title">You're part of it</h2>

                    <!-- The status alert box markup is rendered. --><div class="status-alert-box success" role="alert">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                        <!-- The content group markup is rendered. --><div>
                            <!-- The auth status title markup is rendered. --><div class="auth-status-title">Your account is ready</div>
                            <!-- The content group markup is rendered. --><div>Welcome to Taylor's SportsHub, <?= e($success_user['name']) ?>.</div>
                        </div>
                    </div>

                    <p class="auth-success-desc">
                        Log in with <strong><?= e($success_user['email']) ?></strong> and start exploring club sparring and training sessions.
                    </p>

                    <a href="<?= APP_BASE_PATH ?>/login.php" class="btn-primary btn-auth-submit">
                        <span>Continue to login</span>
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </a>
                </div>

            <?php /* An existing-email notice is displayed. */ elseif ($duplicate_email): ?>
                <!-- Existing email registration is explained. -->
                <div>
                    <h2 class="auth-card-title">Already one of us?</h2>

                    <!-- The status alert box markup is rendered. --><div class="status-alert-box danger" role="alert">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                        <!-- The content group markup is rendered. --><div>
                            <!-- The auth status title markup is rendered. --><div class="auth-status-title">This email is already registered</div>
                            <!-- The content group markup is rendered. --><div>Log in or reset your password to access your account.</div>
                        </div>
                    </div>

                    <!-- The form field markup is rendered. --><div class="form-field">
                        <label class="form-label">Taylor's email</label>
                        <input type="email" value="<?= e($email_val) ?>" class="form-input input-error" readonly>
                    </div>

                    <!-- The auth action col markup is rendered. --><div class="auth-action-col">
                        <a href="<?= APP_BASE_PATH ?>/login.php" class="btn-primary btn-auth-submit">
                            <span>Go to login</span>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        </a>
                        <a href="<?= APP_BASE_PATH ?>/login.php" class="auth-forgot-link">
                            Return to sign in
                        </a>
                    </div>
                </div>

            <?php /* The registration form is displayed before successful account creation. */ else: ?>
                <!-- Registration fields are displayed with associated validation messages. -->
                <span class="auth-card-eyebrow">TAYLOR'S SPORTS HUB</span>
                <h2 class="auth-card-title">Create your account</h2>
                <p class="auth-card-sub">
                    Already part of the community? <a href="<?= APP_BASE_PATH ?>/login.php" class="auth-switch-link">Log in</a>
                </p>

                <!-- General form errors are displayed. -->
                <?php /* General registration errors are displayed. */ if (!empty($errors['general'])): ?>
                    <div class="status-alert-box danger" role="alert">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                        <strong><?= e($errors['general']) ?></strong>
                    </div>
                <?php endif; ?>

                <!-- The form controls markup is rendered. --><form method="POST" action="<?= APP_BASE_PATH ?>/register.php">
                    <?= csrf_field() ?>

                    <!-- The form field markup is rendered. --><div class="form-field">
                        <label class="form-label">Full name</label>
                        <input type="text" name="name" value="<?= e($name_val) ?>"
                               class="form-input <?= !empty($errors['name']) ? 'input-error' : '' ?>" required placeholder="Aisha Tan">
                        <?php /* Name validation errors are displayed next to the field. */ if (!empty($errors['name'])): ?>
                            <!-- The field error msg markup is rendered. --><div class="field-error-msg"><?= e($errors['name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- The form field markup is rendered. --><div class="form-field">
                        <label class="form-label">Taylor's email</label>
                        <input type="email" name="email" value="<?= e($email_val) ?>"
                               class="form-input <?= !empty($errors['email']) ? 'input-error' : '' ?>" required placeholder="name@sd.taylors.edu.my">
                        <?php /* Email validation errors are displayed next to the field. */ if (!empty($errors['email'])): ?>
                            <!-- The field error msg markup is rendered. --><div class="field-error-msg"><?= e($errors['email']) ?></div>
                        <?php /* Student-domain guidance is displayed when no email error is present. */ else: ?>
                            <!-- The field help msg markup is rendered. --><div class="field-help-msg">Use your @sd.taylors.edu.my student email</div>
                        <?php endif; ?>
                    </div>

                    <!-- The form field markup is rendered. --><div class="form-field">
                        <label class="form-label">Phone number <span class="auth-label-opt">(optional)</span></label>
                        <input type="tel" name="phone" value="<?= e($phone_val) ?>"
                               class="form-input" placeholder="+6012-345 6789">
                    </div>

                    <!-- The form field markup is rendered. --><div class="form-field">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-input <?= !empty($errors['password']) ? 'input-error' : '' ?>"
                               required placeholder="••••••••••••">
                        <?php /* Password validation errors are displayed next to the field. */ if (!empty($errors['password'])): ?>
                            <!-- The field error msg markup is rendered. --><div class="field-error-msg"><?= e($errors['password']) ?></div>
                        <?php /* Password requirements are displayed when no password error is present. */ else: ?>
                            <!-- The field help msg markup is rendered. --><div class="field-help-msg">At least 8 characters, including a letter and a number</div>
                        <?php endif; ?>
                    </div>

                    <!-- The form field markup is rendered. --><div class="form-field">
                        <label class="form-label">Confirm password</label>
                        <input type="password" name="confirm_password" class="form-input <?= !empty($errors['confirm_password']) ? 'input-error' : '' ?>"
                               required placeholder="••••••••••••">
                        <?php /* Confirmation mismatch errors are displayed next to the field. */ if (!empty($errors['confirm_password'])): ?>
                            <!-- The field error msg markup is rendered. --><div class="field-error-msg"><?= e($errors['confirm_password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- The form field markup is rendered. --><div class="form-field">
                        <label class="auth-checkbox-label">
                            <input type="checkbox" name="terms" value="1" checked required class="auth-checkbox">
                            <span>I agree to the Taylor's SportsHub rules, fair play guidelines and cancellation terms.</span>
                        </label>
                        <?php /* Missing terms acceptance is displayed next to the checkbox. */ if (!empty($errors['terms'])): ?>
                            <!-- The field error msg markup is rendered. --><div class="field-error-msg"><?= e($errors['terms']) ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn-primary btn-auth-submit">
                        <span>Create account</span>
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </button>

                    <!-- The auth switch prompt markup is rendered. --><div class="auth-switch-prompt">
                        Already have an account? <a href="<?= APP_BASE_PATH ?>/login.php" class="auth-switch-link">Log in</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
