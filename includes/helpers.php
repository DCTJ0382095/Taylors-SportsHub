<?php
// Escaping, formatting, registration, image and payment helpers are provided. Database, branding and
// membership helpers are loaded.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/memberships.php';

// Values are escaped for HTML output, and null values are converted to empty strings.
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Receipt and review history is rendered in submission order. Ownership or club sign-ups access must be
// checked by the caller first.
function render_receipt_history(int $registration_id): void {
    // Receipt records are loaded with optional reviewer names through a left join.
    $q = get_db_connection()->prepare('SELECT p.*, u.name AS reviewer_name FROM payment_receipts p LEFT JOIN users u ON u.id=p.reviewed_by WHERE p.registration_id=? ORDER BY p.id');
    $q->execute([$registration_id]);
    $receipts = $q->fetchAll();
    // Nothing is printed when no receipt has been sent yet.
    if (!$receipts) return;
    echo '<section class="journey-card"><h2>Receipt and review history</h2>';
    // Each receipt is rendered as a chronological history entry.
    foreach ($receipts as $receipt) {
        // The stored decision is turned into a readable label. Older rows without a recorded decision are labelled as legacy evidence.
        $label = /* A readable label is assigned to the recorded receipt decision. */ match ($receipt['decision']) {
            'awaiting_review' => 'Awaiting review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            // Legacy evidence is labelled without an invented decision.
            default => 'Legacy evidence — decision unavailable',
        };
        echo '<div class="journey-callout"><strong>' . e($label) . '</strong><p>Submitted: ' . e(format_deadline($receipt['submitted_at'])) . '</p>';
        // Reviewer details and review times are displayed when recorded. Legacy evidence is labelled without an
        // invented review record.
        if ($receipt['reviewed_at']) {
            echo '<p>Reviewed by ' . e($receipt['reviewer_name'] ?? 'Former reviewer') . ' · ' . e(format_deadline($receipt['reviewed_at'])) . '</p>';
        } /* Missing review metadata is indicated for non-pending legacy decisions. */ elseif ($receipt['decision'] !== 'awaiting_review') {
            echo '<p>Legacy record: reviewer and decision time were not recorded.</p>';
        }
        // A saved rejection reason is escaped and displayed.
        if ($receipt['rejection_reason']) echo '<p>' . e($receipt['rejection_reason']) . '</p>';
        echo '<a href="' . e(app_url('/evidence.php?registration_id=' . $registration_id . '&receipt_id=' . $receipt['id'])) . '" target="_blank" rel="noopener">Open submitted receipt ↗</a></div>';
    }
    echo '</section>';
}

// A price is formatted as "RM 12.00", or "Free" for zero. A tiny tolerance is used so rounding leftovers still count as free.
function format_price($price): string {
    $val = (float) $price;
    // Near-zero prices are formatted as free.
    if ($val <= 0.001) {
        return 'Free';
    }
    return 'RM ' . number_format($val, 2);
}

// Malaysia date and time values are formatted by the following helpers.

// A session date is formatted as e.g. "Sat, 14 Nov 2026".
function format_session_date(string $date_str): string {
    return date('D, j M Y', strtotime($date_str));
}

// A session time is formatted as e.g. "7:30 PM".
function format_session_time(string $time_str): string {
    return date('g:i A', strtotime($time_str));
}

// A start and end time are formatted as e.g. "7:30 PM – 9:30 PM MYT".
function format_session_time_range(string $start_time, string $end_time): string {
    return format_session_time($start_time) . ' – ' . format_session_time($end_time) . ' MYT';
}

// A deadline or timestamp is formatted with date and time, e.g. "14 Nov 2026 · 7:30 PM MYT".
// "Not applicable" is shown when no value is set.
function format_deadline(?string $value): string {
    return $value ? date('j M Y · g:i A', strtotime($value)) . ' MYT' : 'Not applicable';
}

// Self-service cancellation is allowed only when all of these are true: no cancellation request is already under review,
// the session has not been cancelled, the registration still holds (or is waiting for) a place, and the session has not started.
function can_cancel_registration(array $reg): bool {
    return !($reg['status'] === 'awaiting_approval' && !empty($reg['cancellation_requested_at']))
        && ($reg['session_status'] ?? '') !== 'cancelled'
        && in_array($reg['status'], ['confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected'], true)
        && strtotime($reg['session_date'] . ' ' . $reg['start_time']) > time();
}

// A one-sentence "what happens next" hint is returned for each registration status.
function registration_next_step(array $reg): string {
    // Cancellation requests awaiting payment review are handled before ordinary receipt-review statuses.
    if ($reg['status'] === 'awaiting_approval' && !empty($reg['cancellation_requested_at'])) {
        return 'Cancellation requested. The committee must check external settlement before resolving any refund. Your place stays held during review.';
    }
    return /* A next-step hint is selected from the registration status. */ match ($reg['status']) {
        'awaiting_payment' => 'Your place is held. Transfer the quoted amount, then submit a receipt before the deadline.',
        'awaiting_approval' => 'Your place is held during review, including after the session starts. Receipt submission does not confirm payment. Wait for the committee’s decision.',
        'payment_rejected' => 'Check the rejection reason and resubmit evidence before the deadline. A rejected receipt does not prove that no money arrived.',
        'confirmed' => (float)$reg['price_snapshot'] > 0 ? 'Payment approved. Your registration is confirmed.' : 'Your place is confirmed. No payment is required.',
        'cancellation_pending' => 'Your cancellation is pending a full external refund. Your place stays occupied until the refund is fully recorded.',
        'expired' => 'The receipt deadline passed. Your place was released. Check session availability before reserving again.',
        'cancelled' => 'This registration is cancelled and no longer holds a place. Check the cancellation and refund details below.',
        // A general registration-details hint is used for unknown statuses.
        default => 'View your current registration details.'
    };
}

// Allowlisted feedback for the booking journey. A message is picked by ?msg= from this fixed list,
// so arbitrary text from the query string is never printed on the page.
function journey_feedback(): void {
    $messages = [
        'uploaded' => ['success', 'Receipt submitted. Your place is held while the committee checks payment.'],
        'confirmed' => ['success', 'Your place is confirmed. No payment is required for this session.'],
        'approved' => ['success', 'Payment decision saved. The participant’s current registration and refund status are shown below.'],
        'rejected' => ['success', 'Decision saved. The participant can see the rejection reason and deadline.'],
        'refund_logged' => ['success', 'Refund record saved. Check the remaining balance before recording another transfer.'],
        'cancelled' => ['success', 'Registration cancelled. Your place has been released.'],
        'cancellation_pending' => ['success', 'Cancellation requested. View your registration for payment review or refund progress.'],
        'upload_failed' => ['error', 'Receipt was not uploaded. Choose your file again, then retry.'],
        'file_too_large' => ['error', 'File exceeds 5 MB. Choose a smaller JPG, PNG, WebP or PDF, then retry.'],
        'invalid_file_type' => ['error', 'File type not supported. Choose a JPG, PNG, WebP or PDF, then retry.'],
        'save_failed' => ['error', 'Receipt could not be saved. Your registration is unchanged. Choose the file again and retry.'],
        'deadline_passed' => ['error', 'Receipt deadline passed. Uploads are closed; check your registration status.'],
        'invalid_status' => ['error', 'This action is no longer available. Your current registration status is shown below.'],
        'history_retained' => ['error', 'Previous payment or refund evidence is retained for this session. Contact the committee before making another reservation or transfer.'],
        'session_cancelled' => ['error', 'The session is cancelled. New reservations and receipt uploads are closed.'],
        'session_full' => ['error', 'The last place was reserved before your request completed. Check another session or try again later.'],
        'session_started' => ['error', 'This session has started. New reservations are closed.'],
        'not_published' => ['error', 'Signup is closed. Existing payment deadlines remain valid unless the session is cancelled.'],
        'closed' => ['success', 'Signup closed. Existing receipt deadlines remain valid; new reservations are blocked.'],
        'csrf_error' => ['error', 'Your form session expired. Reload this page and try again.'],
        'cannot_cancel_past_start' => ['error', 'Self-service cancellation closes at session start. Contact your club committee for an exceptional withdrawal.'],
    ];
    // Unknown keys are ignored. Errors are announced to screen readers as alerts, and successes as status updates.
    $key = $_GET['msg'] ?? '';
    // Unknown feedback keys are ignored.
    if (!isset($messages[$key])) return;
    [$type, $text] = $messages[$key];
    echo '<div class="journey-notice notice-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . e($text) . '</div>';
}

// Initials are generated from the first and last name parts, or from the first two letters of a
// single-part name.
function get_initials(string $name): string {
    $parts = explode(' ', trim($name));
    // Initials are taken from the first and last name parts.
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

// Unpaid reservations are expired once the receipt deadline passes or the session starts, so the held places are freed.
function expire_overdue_reservations(?int $session_id = null): void {
    $db = get_db_connection();
    $sql = "UPDATE registrations r
            INNER JOIN sessions s ON r.session_id = s.id
            SET r.status = 'expired'
            WHERE r.status IN ('awaiting_payment', 'payment_rejected')
              AND (r.payment_deadline <= NOW() OR CONCAT(s.session_date, ' ', s.start_time) <= NOW())";
    // Expiry can be restricted to one session. The session ID is converted to an integer before SQL
    // interpolation.
    if ($session_id !== null) {
        $sql .= " AND r.session_id = " . (int)$session_id;
    }
    $db->exec($sql);
}

// Expired reservations are released before occupancy and remaining capacity are calculated.
function get_session_seat_info(int $session_id): array {
    expire_overdue_reservations($session_id);

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, capacity, status, session_date, start_time, end_time, price FROM sessions WHERE id = :id");
    $stmt->execute([':id' => $session_id]);
    $session = $stmt->fetch();
    // Missing sessions are represented as full, started and cancelled to prevent booking.
    if (!$session) {
        return ['capacity' => 0, 'occupied' => 0, 'seats_left' => 0, 'is_full' => true, 'is_started' => true, 'status' => 'cancelled'];
    }

    // A session is marked as started at or after the stored start time.
    $capacity = (int) $session['capacity'];
    $session_start = strtotime($session['session_date'] . ' ' . $session['start_time']);
    $is_started = ($session_start <= time());

    // Places are held by these states: confirmed, awaiting_payment, awaiting_approval, payment_rejected, cancellation_pending.
    $stmt = $db->prepare("SELECT COUNT(*) FROM registrations
                          WHERE session_id = :id
                            AND status IN ('confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected', 'cancellation_pending')");
    $stmt->execute([':id' => $session_id]);
    $occupied = (int) $stmt->fetchColumn();

    // Seats left is never allowed to drop below zero.
    $seats_left = max(0, $capacity - $occupied);
    $is_full = ($occupied >= $capacity);

    return [
        'capacity' => $capacity,
        'occupied' => $occupied,
        'seats_left' => $seats_left,
        'is_full' => $is_full,
        'is_started' => $is_started,
        'status' => $session['status'],
        'price' => (float)$session['price'],
    ];
}

// The user's registration for a session is returned, or null when none exists.
function get_user_session_registration(int $session_id, int $user_id): ?array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM registrations WHERE session_id = :session_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':session_id' => $session_id, ':user_id' => $user_id]);
    $reg = $stmt->fetch();
    return $reg ?: null;
}

// Statuses are rendered as coloured badges. Unknown values are humanized and displayed with muted
// styling.
function get_status_badge(string $status): string {
    $labels = [
        'confirmed'            => ['Confirmed', 'badge-confirmed'],
        'active'               => ['Active', 'badge-confirmed'],
        'awaiting_payment'     => ['Awaiting Payment', 'badge-warning'],
        'awaiting_approval'    => ['Awaiting Approval', 'badge-info'],
        'payment_rejected'     => ['Payment Rejected', 'badge-danger'],
        'cancellation_pending' => ['Cancellation Pending', 'badge-warning'],
        'cancelled'            => ['Cancelled', 'badge-muted'],
        'expired'              => ['Expired', 'badge-muted'],
        'draft'                => ['Draft', 'badge-muted'],
        'published'            => ['Open', 'badge-confirmed'],
        'closed'               => ['Closed', 'badge-danger'],
        'full'                 => ['Full', 'badge-warning'],
        'started'              => ['Started', 'badge-muted'],
    ];
    [$label, $class] = $labels[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'badge-muted'];
    return '<span class="status-badge ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

// The session cover URL is returned. A selected image is used when the file is available.
function get_session_cover_url(array $session): string {
    $p = '';
    // A configured session cover path is checked before fallback selection.
    if (!empty($session['image_path'])) {
        $candidate = '/' . ltrim($session['image_path'], '/');
        // An existing selected cover file is used.
        if (file_exists(__DIR__ . '/..' . $candidate)) {
            $p = $candidate;
        }
    }
    // A sport-specific fallback is selected when no usable cover was found.
    if ($p === '') {
        // A built-in sport image is used when the selected cover is absent or unavailable.
        $p = /* The default session image is selected by sport type. */ match (strtolower($session['sport_type'] ?? '')) {
            'basketball' => '/assets/images/sports/basketball-run.webp',
            'pickleball' => '/assets/images/sports/pickleball-rally.webp',
            'badminton'  => '/assets/images/sports/badminton-open-play.webp',
            // A general student-athletes image is used for other sports.
            default      => '/assets/images/sports/student-athletes-banner.webp',
        };
    }
    // ?v= is set to the file's last-modified time, so a replaced picture is not served from an old cache.
    $full_path = __DIR__ . '/..' . $p;
    $v = file_exists($full_path) ? filemtime($full_path) : '1';
    return app_url($p) . '?v=' . $v;
}

// The club banner URL is returned. A selected banner is used when the file is available.
function get_club_banner_url(array $club): string {
    $p = '';
    // A configured club banner is checked before fallback selection.
    if (!empty($club['banner_path'])) {
        $candidate = '/' . ltrim($club['banner_path'], '/');
        // An existing selected banner file is used.
        if (file_exists(__DIR__ . '/..' . $candidate)) {
            $p = $candidate;
        }
    }
    // Otherwise a built-in banner for the sport is used (the badminton banner for any other sport).
    if ($p === '') {
        $sport = strtolower($club['sport_type'] ?? '');
        // The Basketball banner is selected for Basketball clubs.
        if ($sport === 'basketball') {
            $p = '/assets/images/sports/basketball-banner.webp';
        } /* The Pickleball banner is selected for Pickleball clubs. */ elseif ($sport === 'pickleball') {
            $p = '/assets/images/sports/pickleball-banner.webp';
        } /* The Badminton banner is used as the default club image. */ else {
            $p = '/assets/images/sports/badminton-banner.webp';
        }
    }
    // The file modification time is appended as the asset version.
    $full_path = __DIR__ . '/..' . $p;
    $v = file_exists($full_path) ? filemtime($full_path) : '1';
        return app_url($p) . '?v=' . $v;
}

// The URL of a club's uploaded logo is returned, or null when no logo has been uploaded.
// Only files uploaded through Club settings (assets/images/clubs/) are accepted as a club logo.
function get_club_logo_url(array $club): ?string {
    $path = $club['logo_path'] ?? '';
    // Logo paths outside the club-upload directory are rejected.
    if (!is_string($path) || !str_starts_with($path, 'assets/images/clubs/')) {
        return null;
    }
    $full_path = __DIR__ . '/../' . $path;
    // Missing logo files are reported as unavailable.
    if (!file_exists($full_path)) {
        return null;
    }
    return app_url('/' . $path) . '?v=' . filemtime($full_path);
}

// Club initials are generated after a leading Taylor's prefix is removed when present.
function get_club_initials(string $name): string {
    $name = preg_replace("/^Taylor['’]?s\s+/iu", '', trim($name));
    $initials = '';
    // The first letters of up to two club-name words are combined.
    foreach (array_slice(preg_split('/\s+/', $name), 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    return $initials;
}

// An uploaded image (club logo, cover or session picture) is checked and saved in assets/images/clubs/.
// [saved path, null] is returned on success, or [null, error message] on failure.
function save_club_image(array $file, int $club_id, string $kind, int $max_mb): array {
    // Failed uploads and files over the size limit are rejected first.
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, "The $kind could not be uploaded. Try again."];
    }
    // Images above the supplied size limit are rejected.
    if ($file['size'] > $max_mb * 1024 * 1024) {
        return [null, "The $kind must be $max_mb MB or smaller."];
    }
    // The file type is identified from file contents rather than the supplied filename.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    // Unsupported image content types are rejected.
    if (!isset($extensions[$mime])) {
        return [null, "The $kind must be a JPG, PNG or WebP image."];
    }
    // The upload folder is created on first use.
    $folder = __DIR__ . '/../assets/images/clubs';
    // The club-image directory is created when absent.
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
    // A random server-generated filename is combined with an allowed image extension.
    $filename = 'club' . $club_id . '_' . $kind . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    // Failed upload moves are returned as validation errors.
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $filename)) {
        return [null, "The $kind could not be saved. Try again."];
    }
    return ['assets/images/clubs/' . $filename, null];
}

// An old uploaded picture is deleted, but only from assets/images/clubs/, so built-in pictures are never removed.
function delete_uploaded_club_image(?string $path): void {
    // Image deletion is restricted to club-upload paths.
    if (is_string($path) && str_starts_with($path, 'assets/images/clubs/')) {
        $full_path = __DIR__ . '/../' . $path;
        // An existing uploaded image file is removed.
        if (is_file($full_path)) {
            unlink($full_path);
        }
    }
}

// The web address of a club's DuitNow QR picture is returned, or null when none is set or the file is missing.
function club_qr_url(?string $path): ?string {
    // Missing QR paths are reported as unavailable.
    if (!$path) {
        return null;
    }
    $file = __DIR__ . '/../' . ltrim($path, '/');
    // Missing QR files are reported as unavailable.
    if (!is_file($file)) {
        return null;
    }
    return app_url('/' . ltrim($path, '/')) . '?v=' . filemtime($file);
}

// The payment instructions saved with a booking or a membership are built here:
// the club's bank details, followed by the president's payment note when one is set.
function club_payment_instructions(array $club): string {
    $text = "Bank: {$club['bank_name']}\nAccount Name: {$club['account_holder']}\nAccount Number: {$club['account_number']}";
    $note = trim((string) ($club['payment_note'] ?? ''));
    // A configured payment note is appended to the bank instructions.
    if ($note !== '') {
        $text .= "\n\n" . $note;
    }
    return $text;
}

// Payment readiness is checked through the presence of a QR image and three bank-detail fields.
function club_payment_ready(array $club): bool {
    // Each required bank-detail field is checked.
    foreach (['bank_name', 'account_holder', 'account_number'] as $field) {
        // Payment readiness is refused for blank bank details.
        if (trim((string) ($club[$field] ?? '')) === '') {
            return false;
        }
    }
    return club_qr_url($club['duitnow_qr_path'] ?? null) !== null;
}