<?php
// A club membership is applied for (or renewed) here, then the student is sent to the payment page.
// This page is used only by the Apply / Renew buttons, which always send a POST form.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
// Anything other than a POST is sent to the club directory.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . app_url('/clubs.php'));
    exit;
}

$club_id = (int) ($_POST['club_id'] ?? 0);
$club_page = app_url('/club.php?id=' . $club_id);
// A missing or wrong CSRF token is sent back to the club page with an error.
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: ' . $club_page . '&msg=csrf_error#membership', true, 303);
    exit;
}

$db = get_db_connection();
$db->beginTransaction();
// Membership eligibility and application changes are processed in one transaction.
try {
    // The club row is locked to serialize membership applications. Unknown or inactive clubs are handled
    // with a 404 response.
    $stmt = $db->prepare("SELECT * FROM clubs WHERE id = :id AND status = 'active' FOR UPDATE");
    $stmt->execute([':id' => $club_id]);
    $club = $stmt->fetch();
    // Unknown or inactive clubs are handled with a 404 response.
    if (!$club) {
        $db->rollBack();
        render_error_page(404);
    }

    // Applications are blocked when memberships are closed or paid memberships lack payment details.
    $member_status = get_member_status($club_id, $user['id']);
    $stop = null;
    // Paid application is skipped for existing committee members.
    if ($member_status === 'committee') {
        $stop = 'committee_member';     // Committee members are already counted as club members.
    } /* Renewal is blocked while an approved membership remains active. */ elseif ($member_status === 'active') {
        $stop = 'already_member';       // Renewal is permitted only after membership expiry.
    } /* Applications are refused when membership is closed or paid membership setup is incomplete. */ elseif (!$club['membership_open'] || ((float) $club['membership_price'] > 0 && !club_payment_ready($club))) {
        $stop = 'memberships_closed';
    }
    // Refused applications are redirected with the corresponding feedback key.
    if ($stop) {
        $db->rollBack();
        header('Location: ' . $club_page . '&msg=' . $stop . '#membership', true, 303);
        exit;
    }

    // An application already waiting for payment or review is continued instead of starting a new one.
    $membership = get_user_club_membership($club_id, $user['id']);
    // An existing pending application is continued without a new row.
    if ($membership && $member_status !== 'expired') {
        $db->rollBack();
        header('Location: ' . app_url('/membership-payment.php?id=' . $membership['id']), true, 303);
        exit;
    }

    // A new row is inserted for the first application. The existing row is reused for an expired membership
    // renewal.
    if (!$membership) {
        $stmt = $db->prepare("INSERT INTO club_memberships (club_id, user_id) VALUES (:club_id, :user_id)");
        $stmt->execute([':club_id' => $club_id, ':user_id' => $user['id']]);
        $membership_id = (int) $db->lastInsertId();
    } /* A first membership application is inserted before payment details are assigned. */ else {
        $membership_id = (int) $membership['id'];
    }

    $price  = (float) $club['membership_price'];
    $months = (int) $club['membership_months'];
    // Free memberships are activated with start and expiry dates immediately.
    if ($price <= 0) {
        // Free memberships are activated immediately.
        $new_status = 'active';
        $starts     = date('Y-m-d H:i:s');
        $expires    = date('Y-m-d H:i:s', strtotime('+' . $months . ' months'));
    } /* Paid memberships are marked awaiting_payment with previous dates retained for renewals. */ else {
        // Paid memberships are activated after receipt approval. Previous membership dates are retained while
        // renewal payment is pending.
        $new_status = 'awaiting_payment';
        $starts     = $membership['starts_at'] ?? null;
        $expires    = $membership['expires_at'] ?? null;
    }

    // The application is filled in with today's price, length and bank details (the "snapshots"),
    // and anything left over from the previous membership's payment is cleared.
    $stmt = $db->prepare("UPDATE club_memberships
                          SET status = :status, price_snapshot = :price, months_snapshot = :months,
                              payment_instructions_snapshot = :instructions,
                              receipt_path = NULL, receipt_uploaded_at = NULL, rejection_reason = NULL,
                              reviewed_by = NULL, reviewed_at = NULL,
                              starts_at = :starts, expires_at = :expires
                          WHERE id = :id");
    $stmt->execute([
        ':status'       => $new_status,
        ':price'        => $price,
        ':months'       => $months,
        ':instructions' => club_payment_instructions($club),
        ':starts'       => $starts,
        ':expires'      => $expires,
        ':id'           => $membership_id,
    ]);
    $db->commit();
} /* Failed membership transactions are rolled back and rethrown. */ catch (Throwable $e) {
    // On any error the transaction is rolled back, and the error is passed on to the 500 page.
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

// The student is sent to the payment page, with a welcome message for a free membership or the next payment step for a paid one.
$message = $new_status === 'active' ? 'free_member' : 'applied';
header('Location: ' . app_url('/membership-payment.php?id=' . $membership_id . '&msg=' . $message), true, 303);
exit;