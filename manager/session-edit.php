<?php
// Sessions are created and edited under sessions permission. For non-draft sessions, updates are
// restricted to the description and cover image.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$managed_clubs = get_user_managed_clubs($user['id']);
$is_admin = ($user['role'] === 'admin');

// Editor access is restricted to admins and committee members.
if (empty($managed_clubs) && !$is_admin) {
    render_error_page(403);
}

$db = get_db_connection();
$session_id = (int)($_GET['id'] ?? 0);
$session = null;

// An existing session is loaded from the requested ID, and club access is checked.
if ($session_id > 0) {
    $stmt = $db->prepare("SELECT * FROM sessions WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $session_id]);
    $session = $stmt->fetch();
    // Unknown sessions are handled with a 404 response.
    if (!$session) {
        render_error_page(404);
    }
    // Session editing is refused without sessions permission.
    if (!can_manage_club($session['club_id'], $user['id'], 'sessions')) {
        render_error_page(403);
    }
}

// Eligible clubs are loaded for the club selector.
if ($is_admin) {
    $clubs = $db->query("SELECT * FROM clubs WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} /* Non-admin club choices are restricted to managed clubs with sessions permission. */ else {
    // Clubs are restricted to the current account's sessions permissions.
    $clubs = array_values(array_filter(
        $managed_clubs,
        fn($club) => can_manage_club((int) $club['id'], $user['id'], 'sessions')
    ));
}
// Editor access is refused when no authorized clubs remain.
if (empty($clubs)) {
    render_error_page(403);   // Editor access is refused when sessions permission is absent from every managed club.
}

// Clubs are checked for a QR image and complete bank details before session creation is offered.
$club_ready = [];
// Payment readiness is calculated for each selectable club.
foreach ($clubs as $c) {
    $club_ready[(int) $c['id']] = club_payment_ready($c);
}
$first_ready_club = array_search(true, $club_ready, true);   // The first payment-ready club ID is selected, or false is returned.

// Form values are initialized from saved session values, submitted values and defaults.
$error = null;
$title_val = $session['title'] ?? ($_POST['title'] ?? '');
$club_id_val = $session['club_id'] ?? ($_POST['club_id'] ?? ($first_ready_club ?: ($clubs[0]['id'] ?? 0)));
$sport_val = $session['sport_type'] ?? ($_POST['sport_type'] ?? ($clubs[0]['sport_type'] ?? 'Badminton'));
$venue_val = $session['venue'] ?? ($_POST['venue'] ?? '');
$date_val = $session['session_date'] ?? ($_POST['session_date'] ?? date('Y-m-d', strtotime('+3 days')));
$start_val = $session['start_time'] ?? ($_POST['start_time'] ?? '18:00');
$end_val = $session['end_time'] ?? ($_POST['end_time'] ?? '20:00');
$price_val = isset($session['price']) ? (float)$session['price'] : (float)($_POST['price'] ?? 0.00);
// The member price is retained as text. An empty value is interpreted as no separate member price.
$member_price_val = (string) ($session['member_price'] ?? '');
$capacity_val = $session['capacity'] ?? (int)($_POST['capacity'] ?? 16);
$desc_val = $session['description'] ?? ($_POST['description'] ?? '');

// Built-in session cover choices are defined.
$cover_presets = [
    ['path' => 'assets/images/sports/badminton-open-play.webp',   'title' => 'Badminton Open Play',        'badge' => 'Badminton'],
    ['path' => 'assets/images/sports/badminton-footwork.webp',    'title' => 'Footwork & Training Drills', 'badge' => 'Training'],
    ['path' => 'assets/images/sports/basketball-run.webp',        'title' => 'Basketball Open Run',        'badge' => 'Basketball'],
    ['path' => 'assets/images/sports/pickleball-rally.webp',      'title' => 'Pickleball Social Rally',    'badge' => 'Pickleball'],
    ['path' => 'assets/images/sports/pickleball-clinic.webp',     'title' => 'Pickleball Coaching Clinic', 'badge' => 'Coaching'],
    ['path' => 'assets/images/sports/pickleball-ladder.webp',     'title' => 'Pickleball Evening Doubles', 'badge' => 'Pickleball'],
    ['path' => 'assets/images/sports/sports-complex-banner.webp', 'title' => "Taylor's Sports Complex",    'badge' => 'Venue'],
    ['path' => 'assets/images/sports/student-athletes-banner.webp', 'title' => 'Campus Sports',            'badge' => 'General'],
];
$image_path_val = $session['image_path'] ?? $cover_presets[0]['path'];
// Allowed cover paths are limited to built-in images and the session's existing image.
$allowed_covers = array_column($cover_presets, 'path');
// The existing cover is added to the allowed image paths.
if ($session && $session['image_path']) {
    $allowed_covers[] = $session['image_path'];
}

// Published terms are retained for every non-draft status, including closed and cancelled sessions.
$is_published = ($session && $session['status'] !== 'draft');

// Form submission: the CSRF token is checked, then everything is validated inside a transaction.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Invalid CSRF tokens are reported before session updates.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Session expired. Please try again.";
    } /* Valid requests are processed inside a transaction. */ else {
        $db->beginTransaction();
        // Session changes are validated and saved under transaction protection.
        try {
            // Existing session rows are locked and reloaded before editing.
            if ($session_id > 0) {
                // The session is re-checked under the same lock used by publication, closure and cancellation.
                $stmt = $db->prepare('SELECT * FROM sessions WHERE id=? FOR UPDATE');
                $stmt->execute([$session_id]);
                $session = $stmt->fetch();
                // Missing or no-longer-authorized sessions are rejected after locking.
                if (!$session || !can_manage_club($session['club_id'], $user['id'], 'sessions')) {
                    $db->rollBack();
                    render_error_page(403);
                }
                $is_published = $session['status'] !== 'draft';
            }
            // The submitted values are read and trimmed. Prices cannot go below zero, and capacity cannot go below one.
            $title_val = trim($_POST['title'] ?? '');
            $club_id_val = (int)($_POST['club_id'] ?? 0);
            $sport_val = trim($_POST['sport_type'] ?? '');
            $venue_val = trim($_POST['venue'] ?? '');
            $date_val = trim($_POST['session_date'] ?? '');
            $start_val = trim($_POST['start_time'] ?? '');
            $end_val = trim($_POST['end_time'] ?? '');
            $price_val = max(0.00, (float)($_POST['price'] ?? 0.00));
            $member_price_val = trim($_POST['member_price'] ?? '');
            $capacity_val = max(1, (int)($_POST['capacity'] ?? 1));
            $desc_val = trim($_POST['description'] ?? '');
            $cover_choice = trim($_POST['image_path'] ?? '');
            $action = $_POST['action'] ?? 'draft';

            // Locked terms are taken from the database, never from disabled inputs or forged POST fields.
            if ($is_published) {
                $title_val = $session['title'];
                $club_id_val = $session['club_id'];
                $sport_val = $session['sport_type'];
                $venue_val = $session['venue'];
                $date_val = $session['session_date'];
                $start_val = $session['start_time'];
                $end_val = $session['end_time'];
                $price_val = $session['price'];
                $member_price_val = (string) ($session['member_price'] ?? '');
                $capacity_val = $session['capacity'];
            }

            // Sessions permission is required for the selected club.
            if (!can_manage_club($club_id_val, $user['id'], 'sessions')) {
                $db->rollBack();
                render_error_page(403);
            }

            // The selected built-in image, existing image or new upload is resolved as the cover.
            $cover_error = null;
            $cover_uploaded = false;
            // Custom cover uploads are validated when upload mode is selected.
            if ($cover_choice === 'upload') {
                $file = $_FILES['cover_upload'] ?? null;
                // A missing required cover upload is reported.
                if (!is_array($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
                    $cover_error = 'Choose a picture to upload, or pick one of the built-in pictures.';
                } /* The uploaded cover is checked and stored through the image helper. */ else {
                    [$image_path_val, $cover_error] = save_club_image($file, (int) $club_id_val, 'cover', 5);
                    $cover_uploaded = $image_path_val !== null;
                }
            } /* An allowed existing cover path is accepted. */ elseif (in_array($cover_choice, $allowed_covers, true)) {
                $image_path_val = $cover_choice;
            } /* Unrecognized cover choices are rejected. */ else {
                $cover_error = 'Choose a cover picture.';   // a path that is not on offer is never trusted
            }

            // The cover, payment readiness, required fields, session times and member price are validated in
            // sequence.
            if ($cover_error) {
                $error = $cover_error;
            } /* New and draft sessions are refused without club payment readiness. */ elseif (!$is_published && empty($club_ready[(int) $club_id_val])) {
                $error = "This club can't run sessions until its president adds a DuitNow QR and bank details in Club settings.";
            } /* Empty required session fields are rejected. */ elseif (empty($title_val) || empty($venue_val) || empty($date_val) || empty($start_val) || empty($end_val) || empty($desc_val)) {
                $error = "Please fill in all required fields.";
            } /* New and draft sessions are required to start in the future. */ elseif (!$is_published && strtotime($date_val . ' ' . $start_val) <= time()) {
                $error = "Session start time must be in the future.";
            } /* Session end times at or before the start are rejected. */ elseif (strtotime($start_val) >= strtotime($end_val)) {
                $error = "End time must be after start time.";
            } /* Non-numeric or negative member prices are rejected. */ elseif ($member_price_val !== '' && (!is_numeric($member_price_val) || (float) $member_price_val < 0)) {
                $error = "The member price must be an amount in ringgit, or left empty.";
            } /* Member prices above the normal price are rejected. */ elseif ($member_price_val !== '' && (float) $member_price_val > (float) $price_val) {
                $error = "The member price can't be more than the normal price.";
            } /* Validated session data is inserted or updated according to session state. */ else {
                // Draft or published status is selected from the submitted action.
                $status = ($action === 'publish') ? 'published' : 'draft';
                // A NULL member price is stored for empty values and free sessions.
                $member_price_db = ($member_price_val === '' || (float) $price_val <= 0) ? null : round((float) $member_price_val, 2);

                // Existing sessions are updated according to publication status.
                if ($session_id > 0) {
                    // Published session updates are restricted to description and cover.
                    if ($is_published) {
                        // Only the description and cover picture are updated for published sessions.
                        $stmt = $db->prepare("UPDATE sessions SET description = :desc, image_path = :image_path, updated_at = NOW() WHERE id = :id");
                        $stmt->execute([':desc' => $desc_val, ':image_path' => $image_path_val, ':id' => $session_id]);
                    } /* Draft sessions are updated with the full editable field set. */ else {
                        // A draft is saved in full, including a status change to published.
                        $stmt = $db->prepare("UPDATE sessions SET club_id = :club_id, title = :title, description = :desc, sport_type = :sport,
                                              venue = :venue, image_path = :image_path, session_date = :sdate, start_time = :stime, end_time = :etime,
                                              price = :price, member_price = :member_price, capacity = :cap, status = :st, updated_at = NOW()
                                              WHERE id = :id");
                        $stmt->execute([
                            ':club_id' => $club_id_val,
                            ':title' => $title_val,
                            ':desc' => $desc_val,
                            ':sport' => $sport_val,
                            ':venue' => $venue_val,
                            ':image_path' => $image_path_val,
                            ':sdate' => $date_val,
                            ':stime' => $start_val,
                            ':etime' => $end_val,
                            ':price' => $price_val,
                            ':member_price' => $member_price_db,
                            ':cap' => $capacity_val,
                            ':st' => $status,
                            ':id' => $session_id,
                        ]);
                    }
                } /* A new session is inserted with creator attribution. */ else {
                    // A new session is inserted with the current account recorded as creator.
                    $stmt = $db->prepare("INSERT INTO sessions (club_id, title, description, sport_type, venue, image_path, session_date, start_time, end_time, price, member_price, capacity, status, created_by)
                                          VALUES (:club_id, :title, :desc, :sport, :venue, :image_path, :sdate, :stime, :etime, :price, :member_price, :cap, :st, :uid)");                    
                                          $stmt->execute([
                        ':club_id' => $club_id_val,
                        ':title' => $title_val,
                        ':desc' => $desc_val,
                        ':sport' => $sport_val,
                        ':venue' => $venue_val,
                        ':image_path' => $image_path_val,
                        ':sdate' => $date_val,
                        ':stime' => $start_val,
                        ':etime' => $end_val,
                        ':price' => $price_val,
                        ':member_price' => $member_price_db,
                        ':cap' => $capacity_val,
                        ':st' => $status,
                        ':uid' => $user['id'],
                    ]);
                }

                $db->commit();
                // The replaced uploaded picture is deleted after the session is saved.
                if ($session && $session['image_path'] !== $image_path_val) {
                    delete_uploaded_club_image($session['image_path']);
                }
                header("Location: " . app_url('/manager/sessions.php'));
                exit;
            }
            // The transaction is rolled back after validation errors, and the form is redisplayed with the message.
            if ($db->inTransaction()) $db->rollBack();
            // A newly uploaded picture is removed when form validation fails.
            if ($cover_uploaded) {
                delete_uploaded_club_image($image_path_val);
            }
            // The stored cover is restored for redisplay after a validation failure.
            if (!in_array($image_path_val, $allowed_covers, true)) {
                $image_path_val = $session['image_path'] ?? $cover_presets[0]['path'];
            }
        } /* Failed session transactions are rolled back and rethrown. */ catch (Throwable $e) {
            // On any error the transaction is rolled back, and the error is passed on to the 500 page.
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}

$page_title = $session ? "Edit session · Manager Workspace" : "Create session · Manager Workspace";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- The session editor markup is rendered. --><div class="session-editor">
    <!-- The editor title is selected for creation, draft editing or published-session management. -->
    <div style="margin-bottom: 2rem;">
        <a href="<?= app_url('/manager/sessions.php') ?>" style="color: #6e6e73; text-decoration: none; font-size: 0.88rem;">← Back to Manager Workspace</a>
        <h1 style="font-size: 2rem; font-weight: 800; color: #1d1d1f; margin: 0.5rem 0 0; letter-spacing: -0.02em;">
            <?= $session ? ($is_published ? "Manage published session" : "Edit draft session") : "Create new session" ?>
        </h1>
    </div>

    <!-- Session validation errors are displayed. -->
    <?php /* Session validation errors are displayed above the form. */ if ($error): ?>
        <div style="background: #fff2f2; border: 1px solid #fecdd3; color: #c81e27; padding: 0.85rem 1rem; border-radius: 12px; margin-bottom: 1.5rem;">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Incomplete payment setup is highlighted for selectable clubs. -->
    <?php /* Incomplete club payment setup is highlighted before publication. */ if (!$is_published && in_array(false, $club_ready, true)): ?>
        <div style="background: #fff8eb; border: 1px solid #f3dca0; color: #805000; padding: 0.85rem 1rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: 0.9rem;">
            <?= $first_ready_club ? "Some of your clubs can't" : 'None of your clubs can' ?> run sessions yet. A club needs a DuitNow QR and bank details first: its president adds them in Club settings → Payment details.
        </div>
    <?php endif; ?>

    <!-- Published-term restrictions are explained. -->
    <?php /* Locked published terms are explained. */ if ($is_published): ?>
        <div style="background: #fafafa; border: 1px solid #e5e5ea; border-radius: 14px; padding: 1.25rem; margin-bottom: 2rem; font-size: 0.9rem; color: #424245;">
            <strong>Note on published sessions:</strong> Per sports hub governance, date, time, venue, prices, and capacity cannot be altered once published to protect registered students. To change session schedule, cancel this session and publish a replacement.
        </div>
    <?php endif; ?>

    <!-- Published terms are rendered read-only or disabled in the editor. -->
    <form method="POST" action="<?= e($_SERVER['REQUEST_URI']) ?>" class="session-editor-form" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <!-- Eligible organizing clubs are offered in the selector. -->
        <div style="margin-bottom: 1.5rem;">
            <label for="club_id" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Organising club</label>
            <select name="club_id" id="club_id" <?= $is_published ? 'disabled' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; background: #fff;">
                <?php /* Each authorized club is rendered as a selector option. */ foreach ($clubs as $c): ?>
                    <?php $ready = $club_ready[(int) $c['id']]; ?>
                    <option value="<?= $c['id'] ?>" <?= (int)$club_id_val === (int)$c['id'] ? 'selected' : '' ?> <?= $ready || $is_published ? '' : 'disabled' ?>>
                        <?= e($c['name']) ?> (<?= e($c['sport_type']) ?>)<?= $ready ? '' : ' (add payment details first)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- The session title field is rendered. -->
        <div style="margin-bottom: 1.5rem;">
            <label for="title" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Session title</label>
            <input type="text" id="title" name="title" value="<?= e($title_val) ?>" placeholder="e.g. Sunday Open Play & Social Smash" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
        </div>

        <!-- Sport and venue fields are rendered. -->
        <div class="editor-fields">
            <!-- The content group markup is rendered. --><div>
                <label for="sport_type" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Sport</label>
                <input type="text" id="sport_type" name="sport_type" value="<?= e($sport_val) ?>" placeholder="e.g. Badminton" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
            </div>
            <!-- The content group markup is rendered. --><div>
                <label for="venue" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Venue / Court</label>
                <input type="text" id="venue" name="venue" value="<?= e($venue_val) ?>" placeholder="e.g. Sports Complex Court 1-3" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
            </div>
        </div>

        <!-- Session date and time fields are rendered. -->
        <div class="editor-fields editor-fields-time">
            <!-- The content group markup is rendered. --><div>
                <label for="session_date" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Date</label>
                <input type="date" id="session_date" name="session_date" value="<?= e($date_val) ?>" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
            </div>
            <!-- The content group markup is rendered. --><div>
                <label for="start_time" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Start</label>
                <input type="time" id="start_time" name="start_time" value="<?= e($start_val) ?>" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
            </div>
            <!-- The content group markup is rendered. --><div>
                <label for="end_time" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">End</label>
                <input type="time" id="end_time" name="end_time" value="<?= e($end_val) ?>" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
            </div>
        </div>

        <!-- Normal and optional member prices are requested. -->
        <div class="editor-fields">
            <!-- The content group markup is rendered. --><div>
                <label for="price" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Price (RM)</label>
                <input type="number" step="0.50" min="0" id="price" name="price" value="<?= e((string)$price_val) ?>" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
                <small style="display: block; margin-top: 0.3rem; color: #6e6e73; font-size: 0.8rem;">What non-members pay. 0 makes it free.</small>
            </div>
            <!-- The content group markup is rendered. --><div>
                <label for="member_price" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Member price (RM, optional)</label>
                <input type="number" step="0.50" min="0" id="member_price" name="member_price" value="<?= e($member_price_val) ?>" placeholder="Same as the price" <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
                <small style="display: block; margin-top: 0.3rem; color: #6e6e73; font-size: 0.8rem;">What club members pay. Leave empty if everyone pays the same, 0 makes it free for members.</small>
            </div>
        </div>

        <!-- Session capacity is requested. -->
        <div class="editor-fields">
            <!-- The content group markup is rendered. --><div>
                <label for="capacity" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;"> Participant limit</label>
                <input type="number" min="1" id="capacity" name="capacity" value="<?= e((string)$capacity_val) ?>" required <?= $is_published ? 'readonly' : '' ?> style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
            </div>
        </div>


        <!-- The session description is kept editable after publication. -->
        <div style="margin-bottom: 2rem;">
            <label for="description" style="display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #1d1d1f;">Session description</label>
            <textarea id="description" name="description" rows="5" required placeholder="Outline format, drills, who should attend, and what equipment is provided..." style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box; font-family: inherit; resize: none;"><?= e($desc_val) ?></textarea>
        </div>

                <!-- Existing, built-in and uploaded session covers are offered. -->
        <div style="margin-bottom: 1.75rem;">
            <!-- The editor cover header markup is rendered. --><div class="editor-cover-header">
                <label style="font-size: 0.88rem; font-weight: 600; color: #1d1d1f;">Session cover picture</label>
                <span style="font-size: 0.8rem; color: #86868b;">Pick a picture, or upload your own</span>
            </div>

            <!-- The image picker grid markup is rendered. --><div class="image-picker-grid">
                <?php $current_cover = $session['image_path'] ?? null; ?>
                <?php /* The existing custom cover is offered alongside the built-in choices. */ if ($current_cover && !in_array($current_cover, array_column($cover_presets, 'path'), true)): ?>
                    <!-- The existing custom cover is offered for retention. -->
                    <label class="image-picker-option">
                        <input type="radio" name="image_path" value="<?= e($current_cover) ?>" <?= $image_path_val === $current_cover ? 'checked' : '' ?>>
                        <img src="<?= e(get_session_cover_url($session)) ?>" alt="Current cover picture" class="image-picker-thumb">
                        <!-- The image picker label markup is rendered. --><div class="image-picker-label">
                            <span class="image-picker-badge">Current</span>
                            This session's picture
                        </div>
                    </label>
                <?php endif; ?>

                <!-- Built-in cover choices are displayed. -->
                <?php /* Each built-in cover is offered as a preview option. */ foreach ($cover_presets as $preset):
                    $preset_v = file_exists(__DIR__ . '/../' . $preset['path']) ? filemtime(__DIR__ . '/../' . $preset['path']) : '1';
                ?>
                    <label class="image-picker-option">
                        <input type="radio" name="image_path" value="<?= e($preset['path']) ?>" <?= $image_path_val === $preset['path'] ? 'checked' : '' ?>>
                        <img src="<?= app_url('/' . $preset['path']) ?>?v=<?= $preset_v ?>" alt="<?= e($preset['title']) ?>" class="image-picker-thumb" loading="lazy">
                        <!-- The image picker label markup is rendered. --><div class="image-picker-label">
                            <span class="image-picker-badge"><?= e($preset['badge']) ?></span>
                            <?= e($preset['title']) ?>
                        </div>
                    </label>
                <?php endforeach; ?>

                <!-- The upload radio choice is selected after a file is chosen. -->
                <label class="image-picker-option image-picker-upload">
                    <input type="radio" name="image_path" value="upload">
                    <span class="image-picker-upload-box">
                        <strong>+ Upload your own</strong>
                        <small>JPG, PNG or WebP · up to 5 MB · a wide picture works best</small>
                    </span>
                    <input type="file" name="cover_upload" accept="image/jpeg,image/png,image/webp"
                           onchange="this.closest('label').querySelector('input[type=radio]').checked = true;">
                </label>
            </div>
        </div>

        <!-- Save and publication actions are selected by session state. -->
        <div class="editor-actions">
            <?php /* Draft saving is offered only before publication. */ if (!$is_published): ?>
                <button type="submit" name="action" value="draft" style="padding: 0.85rem 1.5rem; background: #f5f5f7; color: #1d1d1f; border: 1px solid #d2d2d7; border-radius: 980px; font-weight: 600; font-size: 0.95rem; cursor: pointer;">
                    Save draft
                </button>
            <?php endif; ?>

            <button type="submit" name="action" value="<?= $is_published ? 'save' : 'publish' ?>" class="manager-btn manager-btn-primary">
                <?= $is_published ? 'Update description' : 'Publish session' ?>
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
