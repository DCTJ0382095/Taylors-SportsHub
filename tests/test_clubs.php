<?php
// Club tests: committee titles, membership status, member prices and the payment-details rule.
// This file is run only through run.php, which supplies a disposable database with the demo data.
if (PHP_SAPI !== 'cli' || !isset($scratch) || !str_starts_with($scratch, 'tu_sportshub_test_')) {
    throw new RuntimeException('Use tests/run.php test_clubs.php');
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/memberships.php';

// ---------- Committee titles ----------
// The president is always shown as "President". Other members are shown with the title typed by the president, or "Committee member".
assert(committee_title(['role' => 'president', 'title' => null]) === 'President');
assert(committee_title(['role' => 'committee', 'title' => 'Vice President']) === 'Vice President');
assert(committee_title(['role' => 'committee', 'title' => null]) === 'Committee member');
assert(committee_title(['role' => 'committee', 'title' => '']) === 'Committee member');
// Alex, Liam and Sara are checked against the seeded president, treasurer and secretary roles.
assert(committee_title(get_committee_role(1, 2)) === 'President');
assert(committee_title(get_committee_role(1, 3)) === 'Treasurer');
assert(committee_title(get_committee_role(1, 6)) === 'Secretary');
assert(get_committee_role(1, 5) === null, 'Danial is a member, not on the committee');

// Membership states are checked for the seeded Badminton and Basketball clubs.
assert(get_member_status(1, 2) === 'committee');          // Alex is counted as a member through the committee role.
assert(get_member_status(1, 5) === 'active');             // Danial's approved unexpired membership is recognized as active.
assert(get_member_status(1, 7) === 'expired');            // Mei's expired membership is excluded from active membership.
assert(get_member_status(1, 8) === 'awaiting_approval');  // Ravi's submitted receipt is recognized as awaiting review.
assert(get_member_status(2, 7) === 'payment_rejected');   // Mei's Basketball receipt is recognized as rejected.
assert(get_member_status(2, 5) === 'awaiting_payment');   // Danial's Basketball application is recognized as awaiting payment.
assert(get_member_status(1, 9) === null);                 // Nur is recognized as having no membership application.
assert(is_club_member(1, 2) && is_club_member(1, 5));
assert(!is_club_member(1, 7) && !is_club_member(1, 8) && !is_club_member(1, 9));

// Status tag words: each word is capitalised.
assert(membership_status_label('awaiting_approval') === ['Awaiting Approval', 'blue']);
assert(membership_status_label('payment_rejected') === ['Payment Rejected', 'red']);
assert(membership_status_label(null) === ['Not a Member', 'grey']);
assert(str_contains(get_status_badge('active'), '>Active<'));
assert(str_contains(get_status_badge('confirmed'), '>Confirmed<'));

// ---------- Member prices ----------
// The member price is charged only to active members and the committee.
$session = ['club_id' => 1, 'price' => 15, 'member_price' => 10];
assert(session_price_for($session, 5) === 10.0);     // The member price is applied to Danial's active membership.
assert(session_price_for($session, 2) === 10.0);     // The member price is applied to Alex's committee membership.
assert(session_price_for($session, 7) === 15.0);     // The normal price is applied after Mei's membership expiry.
assert(session_price_for($session, null) === 15.0);  // The normal price is applied to a guest.
assert(session_price_for(['club_id' => 1, 'price' => 15, 'member_price' => null], 5) === 15.0);
// Both prices are shown on cards, without the word "Members". When there is no cheaper member price, only one price is shown.
assert(format_session_price($session) === 'RM 15.00 · RM 10.00');
assert(format_session_price(['price' => 15, 'member_price' => 0]) === 'RM 15.00 · Free');
assert(format_session_price(['price' => 15, 'member_price' => 15]) === 'RM 15.00');
assert(format_session_price(['price' => 20, 'member_price' => null]) === 'RM 20.00');
assert(format_session_price(['price' => 0, 'member_price' => null]) === 'Free');

// ---------- Payment details rule ----------
// A DuitNow QR picture and all three bank details are required before a club can take payments.
$badminton = $db->query('SELECT * FROM clubs WHERE id = 1')->fetch();
$pickleball = $db->query('SELECT * FROM clubs WHERE id = 3')->fetch();
assert(club_payment_ready($badminton));
assert(!club_payment_ready($pickleball), 'Pickleball has no QR or bank details yet');
assert(!club_payment_ready(array_merge($badminton, ['duitnow_qr_path' => null])), 'No QR');
assert(!club_payment_ready(array_merge($badminton, ['account_number' => '  '])), 'Blank bank detail');

// ---------- Club page shows each committee member's title ----------
// The club page is rendered as a GET request for club 1, and the output is captured.
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/club.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['id' => 1];
ob_start();
require __DIR__ . '/../club.php';
$html = ob_get_clean();
// Expected committee titles are checked in the rendered club page.
foreach (['President', 'Treasurer', 'Secretary'] as $title) {
    assert(str_contains($html, '<div class="committee-role">' . $title . '</div>'), "Club page shows $title");
}

// The title column is removed from the disposable schema and restored by the upgrade. Existing records
// are checked for the default committee title.
$db->exec('ALTER TABLE club_managers DROP COLUMN title');
initialize_workflow_schema($db);
initialize_workflow_schema($db);   // A repeated upgrade is checked for idempotence.
$columns = $db->query('SHOW COLUMNS FROM club_managers')->fetchAll(PDO::FETCH_COLUMN);
assert(array_search('title', $columns) === array_search('role', $columns) + 1, 'title comes right after role');
assert(committee_title(get_committee_role(1, 3)) === 'Committee member');

echo "PASS clubs: committee titles, membership status, member prices, payment details, club page, title upgrade\n";