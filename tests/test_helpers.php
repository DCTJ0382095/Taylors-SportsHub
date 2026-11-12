<?php
// Only the CLI runner may load this suite against its disposable database.
if (PHP_SAPI !== 'cli' || !isset($scratch) || !str_starts_with($scratch, 'tu_sportshub_test_')) {
    throw new RuntimeException('Use tests/run.php');
}
require_once __DIR__ . '/../includes/auth.php';

assert(is_valid_student_email('john.doe@sd.taylors.edu.my'));
assert(is_valid_student_email(' JOHN.DOE@SD.TAYLORS.EDU.MY '));
assert(!is_valid_student_email('staff@taylors.edu.my'));
assert(!is_valid_student_email('external@gmail.com'));
assert(is_club_manager(1, 2));
assert(!is_club_manager(2, 2));
assert(is_club_manager(2, 1));
assert(!verify_csrf_token(null));
assert(!verify_csrf_token('invalid'));
assert(verify_csrf_token(csrf_token()));

$free = get_session_seat_info(1);
assert($free['capacity'] === 16 && $free['occupied'] === 1 && $free['seats_left'] === 15);
$paid = get_session_seat_info(2);
assert($paid['capacity'] === 12 && $paid['occupied'] === 2 && $paid['seats_left'] === 10);

// Fixture writes prepare inputs; production helpers must calculate/expire them.
$db->exec("INSERT INTO registrations (session_id,user_id,status,price_snapshot,payment_deadline) VALUES (4,3,'confirmed',10,DATE_ADD(NOW(),INTERVAL 1 HOUR))");
$registration_id = (int)$db->lastInsertId();
$db->exec('UPDATE sessions SET capacity=1 WHERE id=4');
assert(get_session_seat_info(4)['is_full']);
assert(get_session_seat_info(4)['seats_left'] === 0);
assert(get_session_seat_info(2147483647)['is_full'], 'Missing sessions cannot accept reservations');
$update = $db->prepare('UPDATE registrations SET status=? WHERE id=?');
foreach (['confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected', 'cancellation_pending', 'cancelled', 'expired'] as $status) {
    $update->execute([$status, $registration_id]);
    $occupied = in_array($status, ['cancelled', 'expired'], true) ? 0 : 1;
    assert(get_session_seat_info(4)['occupied'] === $occupied, "Occupancy for $status");
}
$read_status = $db->prepare('SELECT status FROM registrations WHERE id=?');
foreach (['awaiting_payment', 'payment_rejected', 'awaiting_approval', 'cancellation_pending'] as $status) {
    $update->execute([$status, $registration_id]);
    $db->exec("UPDATE registrations SET payment_deadline=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=$registration_id");
    expire_overdue_reservations(4);
    $read_status->execute([$registration_id]);
    assert($read_status->fetchColumn() === (in_array($status, ['awaiting_payment', 'payment_rejected'], true) ? 'expired' : $status));
}
$db->exec("UPDATE sessions SET session_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=4");
$db->exec("UPDATE registrations SET status='awaiting_payment',payment_deadline=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=$registration_id");
expire_overdue_reservations(4);
$read_status->execute([$registration_id]);
assert($read_status->fetchColumn() === 'expired', 'Session start caps payment window');
assert(get_session_seat_info(4)['is_started']);

$reg = ['status'=>'confirmed', 'session_status'=>'published', 'session_date'=>date('Y-m-d', strtotime('+7 days')), 'start_time'=>'18:00:00'];
assert(can_cancel_registration($reg));
assert(!can_cancel_registration(array_merge($reg, ['session_status'=>'cancelled'])));
assert(!can_cancel_registration(array_merge($reg, ['session_date'=>date('Y-m-d', strtotime('-1 day'))])));
assert(!can_cancel_registration(array_merge($reg, ['status'=>'awaiting_approval', 'cancellation_requested_at'=>date('Y-m-d H:i:s')])));

// Public rendering smoke checks; output stays buffered by the runner.
// A published session that started an hour ago must no longer be listed.
$db->exec("INSERT INTO sessions (club_id,title,description,sport_type,venue,session_date,start_time,end_time,price,capacity,status,created_by)
           VALUES (1,'Already Started Smash','Started an hour ago','Badminton','Court 1',DATE(DATE_SUB(NOW(),INTERVAL 1 HOUR)),TIME(DATE_SUB(NOW(),INTERVAL 1 HOUR)),TIME(DATE_ADD(NOW(),INTERVAL 1 HOUR)),0,10,'published',2)");
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
// Pages load the shared header only once per test run, so only the first page (Home) has a tab title here.
$pages = [
    // page => [text that must appear, text that must not appear]
    'index.php' => [['Sunday Open Play &amp; Social Smash', "<title>Taylor's SportsHub · Student sports & club sessions</title>"], ['Already Started Smash']],
    'sessions.php' => [['Find your next session', '>View session<'], ['Already Started Smash', 'View Session']],
    'session-detail.php' => [['Sunday Open Play &amp; Social Smash'], []],
];
foreach ($pages as $route => [$must_show, $must_not_show]) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $route;
    $_GET = $route === 'session-detail.php' ? ['id'=>1] : [];
    ob_start();
    require __DIR__ . '/../' . $route;
    $html = ob_get_clean();
    foreach ($must_show as $text) assert(str_contains($html, $text), "$route should show: $text");
    foreach ($must_not_show as $text) assert(!str_contains($html, $text), "$route should not show: $text");
}

// The profile page names the kind of account: user 1 is the admin, user 2 (Alex) a student.
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/profile.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
foreach ([1 => 'Administrator', 2 => 'Student'] as $account_id => $badge) {
    $_SESSION['user_id'] = $account_id;
    ob_start();
    require __DIR__ . '/../profile.php';
    $html = ob_get_clean();
    // The badge sits in the top row, before the name heading
    $top_row = strstr(strstr($html, 'profile-sidebar-top'), '<h3', true);
    assert(str_contains($top_row, $badge), "Profile badge in the top row: $badge");
    assert(str_contains($html, "Verified Taylor's " . strtolower($badge) . " account"), "Profile account line: $badge");
}
unset($_SESSION['user_id']);
echo "PASS helpers: auth, scope, CSRF, occupancy, expiry, cancellation eligibility, public rendering, profile page\n";
