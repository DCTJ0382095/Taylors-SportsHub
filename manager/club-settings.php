<?php
// Club settings: committee members with the "edit club profile" permission can update
// the club's public details. Only the president can change the payment details.
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

if (!can_manage_club($club_id, $user['id'], 'club')) {
    render_error_page(403);
}
$is_president = is_club_president($club_id, $user['id']);

$error = null;
$saved = $_GET['saved'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';

    } elseif ($form === 'profile') {
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
    <?php endif; ?>

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