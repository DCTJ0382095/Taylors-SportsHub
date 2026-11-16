<?php
// Club settings are divided by permission. Profile and image updates are guarded by club permission,
// memberships by sign-ups permission, and payment details by president or admin access.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$db = get_db_connection();

// The club is loaded by ID. Unknown IDs are shown the 404 page.
$club_id = (int) ($_GET['club_id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM clubs WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $club_id]);
$club = $stmt->fetch();
// Unknown clubs are handled with a 404 response.
if (!$club) {
    render_error_page(404);
}

$can_edit_club      = can_manage_club($club_id, $user['id'], 'club');
$can_manage_signups = can_manage_club($club_id, $user['id'], 'signups');
// Settings access is refused without either supported club permission.
if (!$can_edit_club && !$can_manage_signups) {
    render_error_page(403);   // at least one of the two permissions is needed to open this page
}
// Payment-detail updates are restricted to presidents and admins.
$is_president = is_club_president($club_id, $user['id']);

// The success message is selected through the saved query parameter.
$error = null;
$saved = $_GET['saved'] ?? '';

// Submitted forms are dispatched by section, and the required permission is checked again for each
// update.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';

    // Invalid CSRF tokens are reported before settings updates.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';

    } /* Profile updates are dispatched to the profile-validation branch. */ elseif ($form === 'profile') {
        // Profile updates are refused without club-editing permission.
        if (!$can_edit_club) {
            render_error_page(403);
        }
        // Required profile values, contact-email format and field lengths are validated. Empty optional fields
        // are stored as NULL.
        $description = trim($_POST['description'] ?? '');
        $sport_type  = trim($_POST['sport_type'] ?? '');
        $schedule    = trim($_POST['training_schedule'] ?? '');
        $email       = trim($_POST['contact_email'] ?? '');
        $instagram   = trim($_POST['instagram'] ?? '');

        // Empty descriptions and sport types are rejected.
        if ($description === '' || $sport_type === '') {
            $error = 'Description and sport are required.';
        } /* Invalid optional contact email addresses are rejected. */ elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid contact email, or leave it empty.';
        } /* Profile values beyond column length limits are rejected. */ elseif (mb_strlen($sport_type) > 50 || mb_strlen($schedule) > 150
                  || mb_strlen($email) > 150 || mb_strlen($instagram) > 100) {
            $error = 'One of the fields is too long.';
        } /* Validated profile fields are saved with empty optional values converted to NULL. */ else {
            $stmt = $db->prepare("UPDATE clubs
                                  SET description = :description, sport_type = :sport_type,
                                      training_schedule = :schedule, contact_email = :email,
                                      instagram = :instagram
                                  WHERE id = :id");
            $stmt->execute([
                ':description' => $description,
                ':sport_type'  => $sport_type,
                ':schedule'    => $schedule !== '' ? $schedule : null,
                ':email'       => $email !== '' ? $email : null,
                ':instagram'   => $instagram !== '' ? $instagram : null,
                ':id'          => $club_id,
            ]);
            // After each save the page is reloaded with a GET (303), so refreshing never re-submits the form.
            header('Location: ' . app_url('/manager/club-settings.php?club_id=' . $club_id . '&saved=profile'), true, 303);
            exit;
        }

    } /* Image updates are dispatched to the image-upload branch. */ elseif ($form === 'images') {
        // Image changes are refused without club-editing permission.
        if (!$can_edit_club) {
            render_error_page(403);
        }
        // Club images: the logo (up to 2 MB) and the cover (up to 5 MB) are each optional, but at least one is needed.
        // A replaced upload is deleted once the new file has been saved.
        $logo  = $_FILES['logo']  ?? null;
        $cover = $_FILES['cover'] ?? null;
        // An empty "choose file" box is reported as UPLOAD_ERR_NO_FILE.
        $has_logo  = is_array($logo)  && $logo['error']  !== UPLOAD_ERR_NO_FILE;
        $has_cover = is_array($cover) && $cover['error'] !== UPLOAD_ERR_NO_FILE;

        // An image request without a logo or cover is rejected.
        if (!$has_logo && !$has_cover) {
            $error = 'Choose a logo or a cover image to upload.';
        }
        // A submitted logo is validated when no earlier error was recorded.
        if (!$error && $has_logo) {
            [$path, $error] = save_club_image($logo, $club_id, 'logo', 2);
            // The new logo path is saved after successful upload.
            if ($path) {
                delete_uploaded_club_image($club['logo_path']);
                $stmt = $db->prepare("UPDATE clubs SET logo_path = :path WHERE id = :id");
                $stmt->execute([':path' => $path, ':id' => $club_id]);
            }
        }
        // A submitted cover is validated when no earlier error was recorded.
        if (!$error && $has_cover) {
            [$path, $error] = save_club_image($cover, $club_id, 'cover', 5);
            // The new cover path is saved after successful upload.
            if ($path) {
                delete_uploaded_club_image($club['banner_path']);
                $stmt = $db->prepare("UPDATE clubs SET banner_path = :path WHERE id = :id");
                $stmt->execute([':path' => $path, ':id' => $club_id]);
            }
        }
        // Successful image updates are followed by a redirect.
        if (!$error) {
            header('Location: ' . app_url('/manager/club-settings.php?club_id=' . $club_id . '&saved=images'), true, 303);
            exit;
        }

    } /* Membership settings are dispatched to the membership-validation branch. */ elseif ($form === 'membership') {
        // Membership changes are refused without sign-ups permission.
        if (!$can_manage_signups) {
            render_error_page(403);   // Membership-price updates are guarded by the sign-ups permission.
        }
        // Membership settings: the price must be a ringgit amount (0 for free), the length must be one of the offered options,
        // and paid memberships can only be opened once the club's payment details exist.
        $open     = isset($_POST['membership_open']) ? 1 : 0;
        $price    = trim($_POST['membership_price'] ?? '');
        $months   = (int) ($_POST['membership_months'] ?? 0);
        $benefits = trim($_POST['membership_benefits'] ?? '');

        // Invalid ringgit price formats are rejected.
        if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $price)) {
            $error = 'Enter a price in ringgit, like 30 or 30.50. Enter 0 for a free membership.';
        } /* Unsupported membership periods are rejected. */ elseif (!in_array($months, MEMBERSHIP_LENGTH_OPTIONS, true)) {
            $error = 'Choose a membership length from the list.';
        } /* Oversized membership benefits are rejected. */ elseif (mb_strlen($benefits) > 1000) {
            $error = 'The benefits list is too long (1,000 characters at most).';
        } /* Paid memberships are blocked from opening without payment readiness. */ elseif ($open && (float) $price > 0 && !club_payment_ready($club)) {
            $error = "Add the club's payment details (DuitNow QR and bank details) before opening paid memberships.";
        } /* Validated membership settings are saved. */ else {
            $stmt = $db->prepare("UPDATE clubs
                                  SET membership_open = :open, membership_price = :price,
                                      membership_months = :months, membership_benefits = :benefits
                                  WHERE id = :id");
            $stmt->execute([
                ':open'     => $open,
                ':price'    => $price,
                ':months'   => $months,
                ':benefits' => $benefits !== '' ? $benefits : null,
                ':id'       => $club_id,
            ]);
            header('Location: ' . app_url('/manager/club-settings.php?club_id=' . $club_id . '&saved=membership'), true, 303);
            exit;
        }

    } /* Payment settings are dispatched to the bank-detail branch. */ elseif ($form === 'payment') {
        // Payment changes are refused without president-level access.
        if (!$is_president) {
            render_error_page(403);   // Payment destinations are managed by presidents and admins.
        }
        // Payment details: bank, holder and account number are required, the account number may contain only digits,
        // spaces and dashes, and a DuitNow QR must exist (a new upload or the one already saved).
        $bank    = trim($_POST['bank_name'] ?? '');
        $holder  = trim($_POST['account_holder'] ?? '');
        $account = trim($_POST['account_number'] ?? '');
        $note    = trim($_POST['payment_note'] ?? '');
        $qr      = $_FILES['duitnow_qr'] ?? null;
        $has_new_qr = is_array($qr) && $qr['error'] !== UPLOAD_ERR_NO_FILE;
        $has_old_qr = club_qr_url($club['duitnow_qr_path']) !== null;

        // Missing required bank fields are rejected.
        if ($bank === '' || $holder === '' || $account === '') {
            $error = 'Bank, account holder and account number are all required.';
        } /* Account numbers outside the allowed format are rejected. */ elseif (!preg_match('/^[0-9 -]{6,30}$/', $account)) {
            $error = 'The account number can only contain digits, spaces and dashes.';
        } /* Bank names and account-holder names beyond column limits are rejected. */ elseif (mb_strlen($bank) > 100 || mb_strlen($holder) > 150) {
            $error = 'The bank or account holder name is too long.';
        } /* Payment notes above the length limit are rejected. */ elseif (mb_strlen($note) > 500) {
            $error = 'The payment note is too long (500 characters at most).';
        } /* A QR image is required from an existing file or a new upload. */ elseif (!$has_new_qr && !$has_old_qr) {
            $error = "Upload the club's DuitNow QR. Students scan it to pay.";
        } /* Validated payment details are prepared for saving. */ else {
            $qr_path = $club['duitnow_qr_path'];
            // A replacement QR image is processed when submitted.
            if ($has_new_qr) {
                // The QR image is saved after the other payment-detail checks have passed.
                [$qr_path, $error] = save_club_image($qr, $club_id, 'QR', 2);
            }
            // Payment details are saved after successful validation and optional upload.
            if (!$error) {
                $stmt = $db->prepare("UPDATE clubs
                                      SET bank_name = :bank, account_holder = :holder, account_number = :account,
                                          payment_note = :note, duitnow_qr_path = :qr
                                      WHERE id = :id");
                $stmt->execute([
                    ':bank'    => $bank,
                    ':holder'  => $holder,
                    ':account' => $account,
                    ':note'    => $note !== '' ? $note : null,
                    ':qr'      => $qr_path,
                    ':id'      => $club_id,
                ]);
                // The replaced QR upload is removed after saving.
                if ($has_new_qr) {
                    delete_uploaded_club_image($club['duitnow_qr_path']);   // The previous uploaded QR image is removed after replacement.
                }
                header('Location: ' . app_url('/manager/club-settings.php?club_id=' . $club_id . '&saved=payment'), true, 303);
                exit;
            }
        }
    }
}

$page_title = 'Club settings · ' . $club['name'];
require_once __DIR__ . '/../includes/header.php';

// Submitted values are redisplayed after validation errors. Otherwise, stored values are shown.
$val = fn(string $key) => is_string($_POST[$key] ?? null) ? $_POST[$key] : (string) ($club[$key] ?? '');
?>

<!-- The session editor markup is rendered. --><div class="session-editor">
    <!-- Back navigation and the settings title are displayed. -->
    <a href="<?= app_url('/manager/sessions.php') ?>" class="settings-back">← Back to Manager Workspace</a>
    <h1 class="settings-title"><?= e($club['name']) ?> settings</h1>

    <!-- Incomplete club payment setup is highlighted. -->
    <?php /* Missing payment setup is highlighted above the settings forms. */ if (!club_payment_ready($club)): ?>
        <div class="manager-alert-error">
            This club can't run sessions or paid memberships yet: it needs a DuitNow QR and bank details.
            <?= $is_president ? '<a href="#payment">Add them below.</a>' : 'Ask the club president to add them.' ?>
        </div>
    <?php endif; ?>

    <!-- Settings errors or saved-section feedback are displayed. -->
    <?php /* Settings validation errors are displayed. */ if ($error): ?>
        <div class="manager-alert-error"><?= e($error) ?></div>
    <?php /* Successful profile changes are acknowledged. */ elseif ($saved === 'profile'): ?>
        <!-- The settings alert success markup is rendered. --><div class="settings-alert-success">Club profile saved.</div>
    <?php /* Successful payment-detail changes are acknowledged. */ elseif ($saved === 'payment'): ?>
        <!-- The settings alert success markup is rendered. --><div class="settings-alert-success">Payment details saved.</div>
    <?php /* Successful image changes are acknowledged. */ elseif ($saved === 'images'): ?>
        <!-- The settings alert success markup is rendered. --><div class="settings-alert-success">Club images updated.</div>
    <?php /* Successful membership-setting changes are acknowledged. */ elseif ($saved === 'membership'): ?>
        <!-- The settings alert success markup is rendered. --><div class="settings-alert-success">Membership settings saved.</div>
    <?php endif; ?>

    <?php /* Profile and image forms are displayed for authorized club editors. */ if ($can_edit_club): ?>
    <!-- Club image controls are displayed for authorized editors. -->
    <form method="POST" enctype="multipart/form-data" class="settings-card">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="images">
        <h2>Club images</h2>
        <p class="settings-hint">The logo appears on the club page and the Manager Workspace. The cover image is the banner on the club page and the picture on the Sports clubs list.</p>

        <!-- The settings images markup is rendered. --><div class="settings-images">
            <!-- The settings image field markup is rendered. --><div class="settings-image-field">
                <span class="settings-image-label">Logo</span>
                <?php $logo_url = get_club_logo_url($club); ?>
                <?php /* The current club logo is previewed. */ if ($logo_url): ?>
                    <img src="<?= e($logo_url) ?>" alt="Current logo" class="settings-logo-preview">
                <?php /* Club initials are previewed when no logo is available. */ else: ?>
                    <!-- The settings logo preview markup is rendered. --><div class="settings-logo-preview club-logo-fallback"><?= e(get_club_initials($club['name'])) ?></div>
                <?php endif; ?>
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                <small>Square image · JPG, PNG or WebP · up to 2 MB</small>
            </div>
            <!-- The settings image field markup is rendered. --><div class="settings-image-field">
                <span class="settings-image-label">Cover image</span>
                <img src="<?= e(get_club_banner_url($club)) ?>" alt="Current cover image" class="settings-cover-preview">
                <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">
                <small>Wide image (about 3:1) · JPG, PNG or WebP · up to 5 MB</small>
            </div>
        </div>
        <button type="submit" class="manager-btn manager-btn-primary">Upload images</button>
    </form>
    <?php endif; ?>
    <!-- Club profile fields are displayed with saving guarded by profile-editing permission. -->
    <form method="POST" class="settings-card">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="profile">
        <h2>Club profile</h2>
        <p class="settings-hint">Shown on the public club page.</p>

        <!-- The settings field markup is rendered. --><div class="settings-field">
            <label>Club name</label>
            <input type="text" value="<?= e($club['name']) ?>" readonly>
            <small>Set by the SportsHub admin when the club was created.</small>
        </div>
        <!-- The settings field markup is rendered. --><div class="settings-field">
            <label for="sport_type">Sport</label>
            <input type="text" id="sport_type" name="sport_type" maxlength="50" required value="<?= e($val('sport_type')) ?>">
        </div>
        <!-- The settings field markup is rendered. --><div class="settings-field">
            <label for="description">About the club</label>
            <textarea id="description" name="description" rows="5" required><?= e($val('description')) ?></textarea>
        </div>
        <!-- The settings field markup is rendered. --><div class="settings-field">
            <label for="training_schedule">Training schedule</label>
            <input type="text" id="training_schedule" name="training_schedule" maxlength="150" placeholder="e.g. Training every Tuesday, 8–10 PM" value="<?= e($val('training_schedule')) ?>">
        </div>
        <!-- The settings field markup is rendered. --><div class="settings-field">
            <label for="contact_email">Contact email</label>
            <input type="email" id="contact_email" name="contact_email" maxlength="150" value="<?= e($val('contact_email')) ?>">
        </div>
        <!-- The settings field markup is rendered. --><div class="settings-field">
            <label for="instagram">Instagram</label>
            <input type="text" id="instagram" name="instagram" maxlength="100" placeholder="@yourclub" value="<?= e($val('instagram')) ?>">
        </div>
        <button type="submit" class="manager-btn manager-btn-primary">Save club profile</button>
    </form>


    <!-- Membership settings are displayed with sign-ups permission. -->
    <?php /* Membership settings are displayed for authorized sign-ups managers. */ if ($can_manage_signups): ?>
        <?php
            // Checkbox state is derived from submitted field presence after errors, or from the stored value on
            // initial display.
            $membership_failed = ($_POST['form'] ?? '') === 'membership';
            $open_checked = $membership_failed ? isset($_POST['membership_open']) : (bool) $club['membership_open'];
        ?>
        <form method="POST" class="settings-card" id="membership">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="membership">
            <h2>Membership</h2>
            <p class="settings-hint">Students pay this to become members of the club. A membership starts on the day its payment is approved. Committee members are always counted as members.</p>

            <label class="settings-toggle">
                <input type="checkbox" name="membership_open" <?= $open_checked ? 'checked' : '' ?>>
                Accept new memberships and renewals
            </label>
            <!-- The settings field markup is rendered. --><div class="settings-field">
                <label for="membership_price">Price (RM)</label>
                <input type="number" id="membership_price" name="membership_price" min="0" max="9999.99" step="0.01" required value="<?= e($val('membership_price')) ?>">
                <small>Enter 0 for a free membership. Free memberships start straight away.</small>
            </div>
            <!-- The settings field markup is rendered. --><div class="settings-field">
                <label for="membership_months">Length</label>
                <select id="membership_months" name="membership_months">
                    <?php /* Each allowed membership period is rendered as an option. */ foreach (MEMBERSHIP_LENGTH_OPTIONS as $months): ?>
                        <option value="<?= $months ?>" <?= (int) $val('membership_months') === $months ? 'selected' : '' ?>>
                            <?= e(format_membership_length($months)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- The settings field markup is rendered. --><div class="settings-field">
                <label for="membership_benefits">Benefits</label>
                <textarea id="membership_benefits" name="membership_benefits" rows="4" maxlength="1000" placeholder="One benefit per line"><?= e($val('membership_benefits')) ?></textarea>
                <small>One benefit per line. Each line shows with a ✓ on the club page.</small>
            </div>
            <button type="submit" class="manager-btn manager-btn-primary">Save membership settings</button>
        </form>
    <?php endif; ?>

    <!-- Payment details are editable for presidents and admins and read-only for other authorized accounts. -->
    <div class="settings-card" id="payment">
        <h2>Payment details</h2>
        <?php $qr_url = club_qr_url($club['duitnow_qr_path']); ?>
        <?php /* Editable payment details are displayed for president-level accounts. */ if ($is_president): ?>
            <p class="settings-hint">Students pay the club by scanning its DuitNow QR, or by bank transfer, so both are required. Only the president can change this.</p>
            <!-- The form controls markup is rendered. --><form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="payment">
                <!-- The settings qr markup is rendered. --><div class="settings-qr">
                    <?php /* The saved QR image is previewed. */ if ($qr_url): ?>
                        <img src="<?= e($qr_url) ?>" alt="Current DuitNow QR" class="settings-qr-preview">
                    <?php /* Missing QR setup is explained. */ else: ?>
                        <!-- The settings qr preview markup is rendered. --><div class="settings-qr-preview settings-qr-missing">No QR yet</div>
                    <?php endif; ?>
                    <!-- The settings field markup is rendered. --><div class="settings-field">
                        <label for="duitnow_qr">DuitNow QR<?= $qr_url ? ' (upload a new one to replace it)' : '' ?></label>
                        <input type="file" id="duitnow_qr" name="duitnow_qr" accept="image/jpeg,image/png,image/webp" <?= $qr_url ? '' : 'required' ?>>
                        <small>A screenshot or photo of the club's DuitNow QR · JPG, PNG or WebP · up to 2 MB</small>
                    </div>
                </div>
                <!-- The settings field markup is rendered. --><div class="settings-field">
                    <label for="bank_name">Bank</label>
                    <input type="text" id="bank_name" name="bank_name" maxlength="100" required value="<?= e($val('bank_name')) ?>">
                </div>
                <!-- The settings field markup is rendered. --><div class="settings-field">
                    <label for="account_holder">Account holder</label>
                    <input type="text" id="account_holder" name="account_holder" maxlength="150" required value="<?= e($val('account_holder')) ?>">
                </div>
                <!-- The settings field markup is rendered. --><div class="settings-field">
                    <label for="account_number">Account number</label>
                    <input type="text" id="account_number" name="account_number" maxlength="30" required value="<?= e($val('account_number')) ?>">
                </div>
                <!-- The settings field markup is rendered. --><div class="settings-field">
                    <label for="payment_note">Payment note (optional)</label>
                    <textarea id="payment_note" name="payment_note" rows="3" maxlength="500" placeholder="e.g. Put your full name as the payment reference."><?= e($val('payment_note')) ?></textarea>
                    <small>Shown next to the QR when students pay.</small>
                </div>
                <button type="submit" class="manager-btn manager-btn-primary">Save payment details</button>
            </form>
        <?php /* Payment details are displayed without editing controls for other authorized managers. */ else: ?>
            <p class="settings-hint">Only the club president can change the payment details.</p>
            <?php /* A configured QR image is displayed in the read-only payment section. */ if ($qr_url): ?>
                <img src="<?= e($qr_url) ?>" alt="The club's DuitNow QR" class="settings-qr-preview">
            <?php endif; ?>
            <p><?= e($club['bank_name']) ?> · <?= e($club['account_holder']) ?> · <?= e($club['account_number']) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>