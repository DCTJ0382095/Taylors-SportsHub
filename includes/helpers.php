<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/memberships.php';

// Safe HTML escaping
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Call only after the page's registration ownership / club-scope check.
function render_receipt_history(int $registration_id): void {
    $q = get_db_connection()->prepare('SELECT p.*, u.name AS reviewer_name FROM payment_receipts p LEFT JOIN users u ON u.id=p.reviewed_by WHERE p.registration_id=? ORDER BY p.id');
    $q->execute([$registration_id]);
    $receipts = $q->fetchAll();
    if (!$receipts) return;
    echo '<section class="journey-card"><h2>Receipt and review history</h2>';
    foreach ($receipts as $receipt) {
        $label = match ($receipt['decision']) {
            'awaiting_review' => 'Awaiting review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => 'Legacy evidence — decision unavailable',
        };
        echo '<div class="journey-callout"><strong>' . e($label) . '</strong><p>Submitted: ' . e(format_deadline($receipt['submitted_at'])) . '</p>';
        if ($receipt['reviewed_at']) {
            echo '<p>Reviewed by ' . e($receipt['reviewer_name'] ?? 'Former reviewer') . ' · ' . e(format_deadline($receipt['reviewed_at'])) . '</p>';
        } elseif ($receipt['decision'] !== 'awaiting_review') {
            echo '<p>Legacy record: reviewer and decision time were not recorded.</p>';
        }
        if ($receipt['rejection_reason']) echo '<p>' . e($receipt['rejection_reason']) . '</p>';
        echo '<a href="' . e(app_url('/evidence.php?registration_id=' . $registration_id . '&receipt_id=' . $receipt['id'])) . '" target="_blank" rel="noopener">Open submitted receipt ↗</a></div>';
    }
    echo '</section>';
}

// Format price (RM or Free)
function format_price($price): string {
    $val = (float) $price;
    if ($val <= 0.001) {
        return 'Free';
    }
    return 'RM ' . number_format($val, 2);
}

// Date and time formatters (Malaysia standard)
function format_session_date(string $date_str): string {
    return date('D, j M Y', strtotime($date_str));
}

function format_session_time(string $time_str): string {
    return date('g:i A', strtotime($time_str));
}

function format_session_time_range(string $start_time, string $end_time): string {
    return format_session_time($start_time) . ' – ' . format_session_time($end_time) . ' MYT';
}

function format_deadline(?string $value): string {
    return $value ? date('j M Y · g:i A', strtotime($value)) . ' MYT' : 'Not applicable';
}

function can_cancel_registration(array $reg): bool {
    return !($reg['status'] === 'awaiting_approval' && !empty($reg['cancellation_requested_at']))
        && ($reg['session_status'] ?? '') !== 'cancelled'
        && in_array($reg['status'], ['confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected'], true)
        && strtotime($reg['session_date'] . ' ' . $reg['start_time']) > time();
}

function registration_next_step(array $reg): string {
    if ($reg['status'] === 'awaiting_approval' && !empty($reg['cancellation_requested_at'])) {
        return 'Cancellation requested. The committee must check external settlement before resolving any refund. Your place stays held during review.';
    }
    return match ($reg['status']) {
        'awaiting_payment' => 'Your place is held. Transfer the quoted amount, then submit a receipt before the deadline.',
        'awaiting_approval' => 'Your place is held during review, including after the session starts. Receipt submission does not confirm payment. Wait for the committee’s decision.',
        'payment_rejected' => 'Check the rejection reason and resubmit evidence before the deadline. A rejected receipt does not prove that no money arrived.',
        'confirmed' => (float)$reg['price_snapshot'] > 0 ? 'Payment approved. Your registration is confirmed.' : 'Your place is confirmed. No payment is required.',
        'cancellation_pending' => 'Your cancellation is pending a full external refund. Your place stays occupied until the refund is fully recorded.',
        'expired' => 'The receipt deadline passed. Your place was released. Check session availability before reserving again.',
        'cancelled' => 'This registration is cancelled and no longer holds a place. Check the cancellation and refund details below.',
        default => 'View your current registration details.'
    };
}

// Allowlisted feedback: never echo arbitrary query-string messages as status.
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
    $key = $_GET['msg'] ?? '';
    if (!isset($messages[$key])) return;
    [$type, $text] = $messages[$key];
    echo '<div class="journey-notice notice-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">' . e($text) . '</div>';
}

function get_initials(string $name): string {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

// Expire overdue reservations (older than deadline, or after session start)
function expire_overdue_reservations(?int $session_id = null): void {
    $db = get_db_connection();
    $sql = "UPDATE registrations r
            INNER JOIN sessions s ON r.session_id = s.id
            SET r.status = 'expired'
            WHERE r.status IN ('awaiting_payment', 'payment_rejected')
              AND (r.payment_deadline <= NOW() OR CONCAT(s.session_date, ' ', s.start_time) <= NOW())";
    if ($session_id !== null) {
        $sql .= " AND r.session_id = " . (int)$session_id;
    }
    $db->exec($sql);
}

// Calculate seat occupancy adhering strictly to approved MVP rules
function get_session_seat_info(int $session_id): array {
    expire_overdue_reservations($session_id);

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, capacity, status, session_date, start_time, end_time, price FROM sessions WHERE id = :id");
    $stmt->execute([':id' => $session_id]);
    $session = $stmt->fetch();
    if (!$session) {
        return ['capacity' => 0, 'occupied' => 0, 'seats_left' => 0, 'is_full' => true, 'is_started' => true, 'status' => 'cancelled'];
    }

    $capacity = (int) $session['capacity'];
    $session_start = strtotime($session['session_date'] . ' ' . $session['start_time']);
    $is_started = ($session_start <= time());

    // Occupying states: confirmed, awaiting_payment, awaiting_approval, payment_rejected, cancellation_pending
    $stmt = $db->prepare("SELECT COUNT(*) FROM registrations
                          WHERE session_id = :id
                            AND status IN ('confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected', 'cancellation_pending')");
    $stmt->execute([':id' => $session_id]);
    $occupied = (int) $stmt->fetchColumn();

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

// Get user registration for a session
function get_user_session_registration(int $session_id, int $user_id): ?array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM registrations WHERE session_id = :session_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':session_id' => $session_id, ':user_id' => $user_id]);
    $reg = $stmt->fetch();
    return $reg ?: null;
}

// Status badge helper with Apple-inspired clean styling
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

// Session cover image resolver
function get_session_cover_url(array $session): string {
    $p = '';
    if (!empty($session['image_path'])) {
        $candidate = '/' . ltrim($session['image_path'], '/');
        if (file_exists(__DIR__ . '/..' . $candidate)) {
            $p = $candidate;
        }
    }
    if ($p === '') {
        // No picture chosen, or its file is missing: use a built-in one for the sport.
        $p = match (strtolower($session['sport_type'] ?? '')) {
            'basketball' => '/assets/images/sports/basketball-run.webp',
            'pickleball' => '/assets/images/sports/pickleball-rally.webp',
            'badminton'  => '/assets/images/sports/badminton-open-play.webp',
            default      => '/assets/images/sports/student-athletes-banner.webp',
        };
    }
    $full_path = __DIR__ . '/..' . $p;
    $v = file_exists($full_path) ? filemtime($full_path) : '1';
    return app_url($p) . '?v=' . $v;
}

// Club banner image resolver
function get_club_banner_url(array $club): string {
    $p = '';
    if (!empty($club['banner_path'])) {
        $candidate = '/' . ltrim($club['banner_path'], '/');
        if (file_exists(__DIR__ . '/..' . $candidate)) {
            $p = $candidate;
        }
    }
    if ($p === '') {
        $sport = strtolower($club['sport_type'] ?? '');
        if ($sport === 'basketball') {
            $p = '/assets/images/sports/basketball-banner.webp';
        } elseif ($sport === 'pickleball') {
            $p = '/assets/images/sports/pickleball-banner.webp';
        } else {
            $p = '/assets/images/sports/badminton-banner.webp';
        }
    }
    $full_path = __DIR__ . '/..' . $p;
    $v = file_exists($full_path) ? filemtime($full_path) : '1';
        return app_url($p) . '?v=' . $v;
}

// URL of a club's uploaded logo, or null if the club hasn't uploaded one.
// Only files uploaded through Club settings (assets/images/clubs/) count as a club logo.
function get_club_logo_url(array $club): ?string {
    $path = $club['logo_path'] ?? '';
    if (!is_string($path) || !str_starts_with($path, 'assets/images/clubs/')) {
        return null;
    }
    $full_path = __DIR__ . '/../' . $path;
    if (!file_exists($full_path)) {
        return null;
    }
    return app_url('/' . $path) . '?v=' . filemtime($full_path);
}

// Two-letter initials for a club without a logo, e.g. "Taylor's Badminton Club" → "BC".
function get_club_initials(string $name): string {
    $name = preg_replace("/^Taylor['’]?s\s+/iu", '', trim($name));
    $initials = '';
    foreach (array_slice(preg_split('/\s+/', $name), 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    return $initials;
}

// Checks an uploaded image and saves it in assets/images/clubs/ (club logos, covers, session pictures).
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

// Deletes an old uploaded picture, but only one from assets/images/clubs/ (never a built-in picture).
function delete_uploaded_club_image(?string $path): void {
    if (is_string($path) && str_starts_with($path, 'assets/images/clubs/')) {
        $full_path = __DIR__ . '/../' . $path;
        if (is_file($full_path)) {
            unlink($full_path);
        }
    }
}

// The web address of a club's DuitNow QR picture, or null if it has none (or the file is missing).
function club_qr_url(?string $path): ?string {
    if (!$path) {
        return null;
    }
    $file = __DIR__ . '/../' . ltrim($path, '/');
    if (!is_file($file)) {
        return null;
    }
    return app_url('/' . ltrim($path, '/')) . '?v=' . filemtime($file);
}

// The payment instructions saved with a booking or a membership:
// the club's bank details, then the president's payment note (if there is one).
function club_payment_instructions(array $club): string {
    $text = "Bank: {$club['bank_name']}\nAccount Name: {$club['account_holder']}\nAccount Number: {$club['account_number']}";
    $note = trim((string) ($club['payment_note'] ?? ''));
    if ($note !== '') {
        $text .= "\n\n" . $note;
    }
    return $text;
}

// Can this club take payments? It needs a DuitNow QR picture and all three bank details.
// Clubs that can't are not allowed to run sessions or paid memberships.
function club_payment_ready(array $club): bool {
    foreach (['bank_name', 'account_holder', 'account_number'] as $field) {
        if (trim((string) ($club[$field] ?? '')) === '') {
            return false;
        }
    }
    return club_qr_url($club['duitnow_qr_path'] ?? null) !== null;
}