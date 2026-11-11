<?php
// Club settings. Each section needs its own permission:
// Club profile and Club images  -> "Edit club profile"
// Membership                    -> "Manage sign-ups and payments"
// Payment details               -> president only
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$db = get_db_connection();

$club_id = (int) ($_GET['club_id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM clubs WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $club_id]);
$club = $stmt->fetch();
if (!$club) {
    render_error_page(404);
}

$can_edit_club      = can_manage_club($club_id, $user['id'], 'club');
$can_manage_signups = can_manage_club($club_id, $user['id'], 'signups');
if (!$can_edit_club && !$can_manage_signups) {
    render_error_page(403);   // needs at least one of the two permissions to open this page
}
$is_president = is_club_president($club_id, $user['id']);

$error = null;
$saved = $_GET['saved'] ?? '';

// Checks an uploaded image and saves it in assets/images/clubs/.
// Returns [the saved path, null] on success, or [null, an error message].
function save_club_image(array $file, int $club_id, string $kind, int $max_mb): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, "The $kind could not be uploaded. Try again."];
    }
    if ($file['size'] > $max_mb * 1024 * 1024) {
        return [null, "The $kind must be $max_mb MB or smaller."];
    }
    // Check what the file really is from its contents, not from its name.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        return [null, "The $kind must be a JPG, PNG or WebP image."];
    }
    $folder = __DIR__ . '/../assets/images/clubs';
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
    // We choose the file name ourselves (random, with an image extension),
    // so an uploaded file can never overwrite another or run as code.
    $filename = 'club' . $club_id . '_' . $kind . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $filename)) {
        return [null, "The $kind could not be saved. Try again."];
    }
    return ['assets/images/clubs/' . $filename, null];
}

// Deletes an old club image, but only if it was uploaded through this page.
function delete_uploaded_club_image(?string $path): void {
    if (is_string($path) && str_starts_with($path, 'assets/images/clubs/')) {
        $full_path = __DIR__ . '/../' . $path;
        if (is_file($full_path)) {
            unlink($full_path);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';

    } elseif ($form === 'profile') {
        if (!$can_edit_club) {
            render_error_page(403);
        }
        $description = trim($_POST['description'] ?? '');
        $sport_type  = trim($_POST['sport_type'] ?? '');
        $schedule    = trim($_POST['training_schedule'] ?? '');
        $email       = trim($_POST['contact_email'] ?? '');
        $instagram   = trim($_POST['instagram'] ?? '');

        if ($description === '' || $sport_type === '') {
            $error = 'Description and sport are required.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid contact email, or leave it empty.';
        } elseif (mb_strlen($sport_type) > 50 || mb_strlen($schedule) > 150
                  || mb_strlen($email) > 150 || mb_strlen($instagram) > 100) {
            $error = 'One of the fields is too long.';
        } else {
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
            header('Location: ' . app_url('/manager/club-settings.php?club_id=' . $club_id . '&saved=profile'), true, 303);
            exit;
        }

    } elseif ($form === 'images') {
        if (!$can_edit_club) {
            render_error_page(403);
        }
        $logo  = $_FILES['logo']  ?? null;
        $cover = $_FILES['cover'] ?? null;
        // An empty "choose file" box is reported as UPLOAD_ERR_NO_FILE.
        $has_logo  = is_array($logo)  && $logo['error']  !== UPLOAD_ERR_NO_FILE;
        $has_cover = is_array($cover) && $cover['error'] !== UPLOAD_ERR_NO_FILE;

        if (!$has_logo && !$has_cover) {
            $error = 'Choose a logo or a cover image to upload.';
        }
        if (!$error && $has_logo) {
            [$path, $error] = save_club_image($logo, $club_id, 'logo', 2);
            if ($path) {
                delete_uploaded_club_image($club['logo_path']);
                $stmt = $db->prepare("UPDATE clubs SET logo_path = :path WHERE id = :id");
                $stmt->execute([':path' => $path, ':id' => $club_id]);
            }
        }
        if (!$error && $has_cover) {
            [$path, $error] = save_club_image($cover, $club_id, 'cover', 5);
            if ($path) {
                delete_uploaded_club_image($club['banner_path']);
                $stmt = $db->prepare("UPDATE clubs SET banner_path = :path WHERE id = :id");
                $stmt->execute([':path' => $path, ':id' => $club_id]);
            }
        }
        if (!$error) {
            header('Location: ' . app_url('/manager/club-settings.php?club_id=' . $club_id . '&saved=images'), true, 303);
            exit;
        }

    } elseif ($form === 'membership') {
        if (!$can_manage_signups) {
            render_error_page(403);   // membership prices go with "Manage sign-ups and payments"
        }
        $open     = isset($_POST['membership_open']) ? 1 : 0;
        $price    = trim($_POST['membership_price'] ?? '');
        $months   = (int) ($_POST['membership_months'] ?? 0);
        $benefits = trim($_POST['membership_benefits'] ?? '');

        if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $price)) {
            $error = 'Enter a price in ringgit, like 30 or 30.50. Enter 0 for a free membership.';
        } elseif (!in_array($months, MEMBERSHIP_LENGTH_OPTIONS, true)) {
            $error = 'Choose a membership length from the list.';
        } elseif (mb_strlen($benefits) > 1000) {
            $error = 'The benefits list is too long (1,000 characters at most).';
        } else {
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

    } elseif ($form === 'payment') {
        if (!$is_president) {
            render_error_page(403);   // only the president decides where members' money goes
        }
        $bank    = trim($_POST['bank_name'] ?? '');
        $holder  = trim($_POST['account_holder'] ?? '');
        $account = trim($_POST['account_number'] ?? '');

        if ($bank === '' || $holder === '' || $account === '') {
            $error = 'Bank, account holder and account number are all required.';
        } elseif (!preg_match('/^[0-9 -]{6,30}$/', $account)) {
            $error = 'The account number can only contain digits, spaces and dashes.';
        } elseif (mb_strlen($bank) > 100 || mb_strlen($holder) > 150) {
            $error = 'The bank or account holder name is too long.';
        } else {
            $stmt = $db->prepare("UPDATE clubs
                                  SET bank_name = :bank, account_holder = :holder, account_number = :account
                                  WHERE id = :id");
            $stmt->execute([':bank' => $bank, ':holder' => $holder, ':account' => $account, ':id' => $club_id]);
            header('Location: ' . app_url('/manager/club-settings.php?club_id=' . $club_id . '&saved=payment'), true, 303);
            exit;
        }
    }
}

$page_title = 'Club settings · ' . $club['name'];
require_once __DIR__ . '/../includes/header.php';

// If saving failed, show what the user typed; otherwise show what's stored in the database.
$val = fn(string $key) => is_string($_POST[$key] ?? null) ? $_POST[$key] : (string) ($club[$key] ?? '');
?>

<div class="session-editor">
    <a href="<?= app_url('/manager/sessions.php') ?>" class="settings-back">← Back to Manager Workbench</a>
    <h1 class="settings-title"><?= e($club['name']) ?> settings</h1>

    <?php if ($error): ?>
        <div class="manager-alert-error"><?= e($error) ?></div>
    <?php elseif ($saved === 'profile'): ?>
        <div class="settings-alert-success">Club profile saved.</div>
    <?php elseif ($saved === 'payment'): ?>
        <div class="settings-alert-success">Payment details saved.</div>
    <?php elseif ($saved === 'images'): ?>
        <div class="settings-alert-success">Club images updated.</div>
    <?php elseif ($saved === 'membership'): ?>
        <div class="settings-alert-success">Membership settings saved.</div>
    <?php endif; ?>

    <?php if ($can_edit_club): ?>
    <!-- Club profile: anyone with the "edit club profile" permission -->
    <form method="POST" class="settings-card">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="profile">
        <h2>Club profile</h2>
        <p class="settings-hint">Shown on the public club page.</p>

        <div class="settings-field">
            <label>Club name</label>
            <input type="text" value="<?= e($club['name']) ?>" readonly>
            <small>Set by the SportsHub admin when the club was created.</small>
        </div>
        <div class="settings-field">
            <label for="sport_type">Sport</label>
            <input type="text" id="sport_type" name="sport_type" maxlength="50" required value="<?= e($val('sport_type')) ?>">
        </div>
        <div class="settings-field">
            <label for="description">About the club</label>
            <textarea id="description" name="description" rows="5" required><?= e($val('description')) ?></textarea>
        </div>
        <div class="settings-field">
            <label for="training_schedule">Training schedule</label>
            <input type="text" id="training_schedule" name="training_schedule" maxlength="150" placeholder="e.g. Training every Tuesday, 8–10 PM" value="<?= e($val('training_schedule')) ?>">
        </div>
        <div class="settings-field">
            <label for="contact_email">Contact email</label>
            <input type="email" id="contact_email" name="contact_email" maxlength="150" value="<?= e($val('contact_email')) ?>">
        </div>
        <div class="settings-field">
            <label for="instagram">Instagram</label>
            <input type="text" id="instagram" name="instagram" maxlength="100" placeholder="@yourclub" value="<?= e($val('instagram')) ?>">
        </div>
        <button type="submit" class="manager-btn manager-btn-primary">Save club profile</button>
    </form>

    <!-- Club images: logo and cover image -->
    <form method="POST" enctype="multipart/form-data" class="settings-card">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="images">
        <h2>Club images</h2>
        <p class="settings-hint">The logo appears on the club page and the Manager Workbench. The cover image is the banner on the club page and the picture on the Sports clubs list.</p>

        <div class="settings-images">
            <div class="settings-image-field">
                <span class="settings-image-label">Logo</span>
                <?php $logo_url = get_club_logo_url($club); ?>
                <?php if ($logo_url): ?>
                    <img src="<?= e($logo_url) ?>" alt="Current logo" class="settings-logo-preview">
                <?php else: ?>
                    <div class="settings-logo-preview club-logo-fallback"><?= e(get_club_initials($club['name'])) ?></div>
                <?php endif; ?>
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                <small>Square image · JPG, PNG or WebP · up to 2 MB</small>
            </div>
            <div class="settings-image-field">
                <span class="settings-image-label">Cover image</span>
                <img src="<?= e(get_club_banner_url($club)) ?>" alt="Current cover image" class="settings-cover-preview">
                <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">
                <small>Wide image (about 3:1) · JPG, PNG or WebP · up to 5 MB</small>
            </div>
        </div>
        <button type="submit" class="manager-btn manager-btn-primary">Upload images</button>
    </form>
    <?php endif; ?>

    <!-- Membership: anyone with the "manage sign-ups and payments" permission -->
    <?php if ($can_manage_signups): ?>
        <?php
            // A tickbox that isn't ticked isn't sent at all, so $val() can't be used for it.
            // After a failed save, show what was ticked; otherwise show what's stored.
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
            <div class="settings-field">
                <label for="membership_price">Price (RM)</label>
                <input type="number" id="membership_price" name="membership_price" min="0" max="9999.99" step="0.01" required value="<?= e($val('membership_price')) ?>">
                <small>Enter 0 for a free membership. Free memberships start straight away.</small>
            </div>
            <div class="settings-field">
                <label for="membership_months">Length</label>
                <select id="membership_months" name="membership_months">
                    <?php foreach (MEMBERSHIP_LENGTH_OPTIONS as $months): ?>
                        <option value="<?= $months ?>" <?= (int) $val('membership_months') === $months ? 'selected' : '' ?>>
                            <?= e(format_membership_length($months)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-field">
                <label for="membership_benefits">Benefits</label>
                <textarea id="membership_benefits" name="membership_benefits" rows="4" maxlength="1000" placeholder="One benefit per line"><?= e($val('membership_benefits')) ?></textarea>
                <small>One benefit per line. Each line shows with a ✓ on the club page.</small>
            </div>
            <button type="submit" class="manager-btn manager-btn-primary">Save membership settings</button>
        </form>
    <?php endif; ?>

    <!-- Payment details: president only -->
    <div class="settings-card">
        <h2>Payment details</h2>
        <?php if ($is_president): ?>
            <p class="settings-hint">Where students send payment for this club's sessions. Only the president can change this.</p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="payment">
                <div class="settings-field">
                    <label for="bank_name">Bank</label>
                    <input type="text" id="bank_name" name="bank_name" maxlength="100" required value="<?= e($val('bank_name')) ?>">
                </div>
                <div class="settings-field">
                    <label for="account_holder">Account holder</label>
                    <input type="text" id="account_holder" name="account_holder" maxlength="150" required value="<?= e($val('account_holder')) ?>">
                </div>
                <div class="settings-field">
                    <label for="account_number">Account number</label>
                    <input type="text" id="account_number" name="account_number" maxlength="30" required value="<?= e($val('account_number')) ?>">
                </div>
                <button type="submit" class="manager-btn manager-btn-primary">Save payment details</button>
            </form>
        <?php else: ?>
            <p class="settings-hint">Only the club president can change the payment details.</p>
            <p><?= e($club['bank_name']) ?> · <?= e($club['account_holder']) ?> · <?= e($club['account_number']) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>