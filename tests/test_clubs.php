<?php
// Clubs: committee titles, membership status, member prices and the payment-details rule.
// Run only through run.php, which supplies a disposable database with the demo data.
if (PHP_SAPI !== 'cli' || !isset($scratch) || !str_starts_with($scratch, 'tu_sportshub_test_')) {
    throw new RuntimeException('Use tests/run.php test_clubs.php');
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/memberships.php';

// ---------- Committee titles ----------
// The president is always "President". Others show the title the president typed, or "Committee member".
assert(committee_title(['role' => 'president', 'title' => null]) === 'President');
assert(committee_title(['role' => 'committee', 'title' => 'Vice President']) === 'Vice President');
assert(committee_title(['role' => 'committee', 'title' => null]) === 'Committee member');
assert(committee_title(['role' => 'committee', 'title' => '']) === 'Committee member');
// Demo data: Alex (user 2) is Badminton's president, Liam (3) its Treasurer, Sara (6) its Secretary.
assert(committee_title(get_committee_role(1, 2)) === 'President');
assert(committee_title(get_committee_role(1, 3)) === 'Treasurer');
assert(committee_title(get_committee_role(1, 6)) === 'Secretary');
assert(get_committee_role(1, 5) === null, 'Danial is a member, not on the committee');

// ---------- Membership status (club 1 is Badminton, club 2 is Basketball) ----------
assert(get_member_status(1, 2) === 'committee');          // Alex: committee members always count as members
assert(get_member_status(1, 5) === 'active');             // Danial: paid and not expired
assert(get_member_status(1, 7) === 'expired');            // Mei: approved, but it ran out last month
assert(get_member_status(1, 8) === 'awaiting_approval');  // Ravi: receipt waiting for review
assert(get_member_status(2, 7) === 'payment_rejected');   // Mei at Basketball: receipt rejected
assert(get_member_status(2, 5) === 'awaiting_payment');   // Danial at Basketball: applied, not paid yet
assert(get_member_status(1, 9) === null);                 // Nur: never applied
assert(is_club_member(1, 2) && is_club_member(1, 5));
assert(!is_club_member(1, 7) && !is_club_member(1, 8) && !is_club_member(1, 9));

// Status tag words: each word starts with a capital letter.
assert(membership_status_label('awaiting_approval') === ['Awaiting Approval', 'blue']);
assert(membership_status_label('payment_rejected') === ['Payment Rejected', 'red']);
assert(membership_status_label(null) === ['Not a Member', 'grey']);
assert(str_contains(get_status_badge('active'), '>Active<'));
assert(str_contains(get_status_badge('confirmed'), '>Confirmed<'));

// ---------- Member prices ----------
$session = ['club_id' => 1, 'price' => 15, 'member_price' => 10];
assert(session_price_for($session, 5) === 10.0);     // Danial: active member
assert(session_price_for($session, 2) === 10.0);     // Alex: committee
assert(session_price_for($session, 7) === 15.0);     // Mei: membership expired
assert(session_price_for($session, null) === 15.0);  // not logged in
assert(session_price_for(['club_id' => 1, 'price' => 15, 'member_price' => null], 5) === 15.0);
// Cards show both prices without the word "Members". No cheaper member price means just one price.
assert(format_session_price($session) === 'RM 15.00 · RM 10.00');
assert(format_session_price(['price' => 15, 'member_price' => 0]) === 'RM 15.00 · Free');
assert(format_session_price(['price' => 15, 'member_price' => 15]) === 'RM 15.00');
assert(format_session_price(['price' => 20, 'member_price' => null]) === 'RM 20.00');
assert(format_session_price(['price' => 0, 'member_price' => null]) === 'Free');

// ---------- Payment details rule ----------
// A club needs a DuitNow QR picture and all three bank details before it can take payments.
$badminton = $db->query('SELECT * FROM clubs WHERE id = 1')->fetch();
$pickleball = $db->query('SELECT * FROM clubs WHERE id = 3')->fetch();
assert(club_payment_ready($badminton));
assert(!club_payment_ready($pickleball), 'Pickleball has no QR or bank details yet');
assert(!club_payment_ready(array_merge($badminton, ['duitnow_qr_path' => null])), 'No QR');
assert(!club_payment_ready(array_merge($badminton, ['account_number' => '  '])), 'Blank bank detail');

// ---------- Club page shows each committee member's title ----------
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/club.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['id' => 1];
ob_start();
require __DIR__ . '/../club.php';
$html = ob_get_clean();
foreach (['President', 'Treasurer', 'Secretary'] as $title) {
    assert(str_contains($html, '<div class="committee-role">' . $title . '</div>'), "Club page shows $title");
}

// ---------- Upgrading an older database ----------
// Databases made before committee titles have no title column. Setup must add it once,
// straight after "role", and existing committee members then show as "Committee member".
$db->exec('ALTER TABLE club_managers DROP COLUMN title');
initialize_workflow_schema($db);
initialize_workflow_schema($db);   // running it again must change nothing
$columns = $db->query('SHOW COLUMNS FROM club_managers')->fetchAll(PDO::FETCH_COLUMN);
assert(array_search('title', $columns) === array_search('role', $columns) + 1, 'title comes right after role');
assert(committee_title(get_committee_role(1, 3)) === 'Committee member');

echo "PASS clubs: committee titles, membership status, member prices, payment details, club page, title upgrade\n";