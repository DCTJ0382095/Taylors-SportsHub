<?php
// Applies for (or renews) a club membership, then sends the student to the payment page.
// Only the Apply / Renew buttons use this page, and they always send a POST form.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . app_url('/clubs.php'));
    exit;
}

$club_id = (int) ($_POST['club_id'] ?? 0);
$club_page = app_url('/club.php?id=' . $club_id);
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: ' . $club_page . '&msg=csrf_error#membership', true, 303);
    exit;
}

$db = get_db_connection();
$db->beginTransaction();
try {
    // FOR UPDATE locks this club's row until we commit. If the student double-clicks,
    // the second click waits here, then sees the application the first click made.
    $stmt = $db->prepare("SELECT * FROM clubs WHERE id = :id AND status = 'active' FOR UPDATE");
    $stmt->execute([':id' => $club_id]);
    $club = $stmt->fetch();
    if (!$club) {
        $db->rollBack();
        render_error_page(404);
    }

    // Reasons the student can't apply right now.
    $member_status = get_member_status($club_id, $user['id']);
    $stop = null;
    if ($member_status === 'committee') {
        $stop = 'committee_member';     // committee members already count as members
    } elseif ($member_status === 'active') {
        $stop = 'already_member';       // renewing only opens once a membership has expired
    } elseif (!$club['membership_open']) {
        $stop = 'memberships_closed';
    }
    if ($stop) {
        $db->rollBack();
        header('Location: ' . $club_page . '&msg=' . $stop . '#membership', true, 303);
        exit;
    }

    // Already applied and waiting for a payment or a review: carry on with that application.
    $membership = get_user_club_membership($club_id, $user['id']);
    if ($membership && $member_status !== 'expired') {
        $db->rollBack();
        header('Location: ' . app_url('/membership-payment.php?id=' . $membership['id']), true, 303);
        exit;
    }

    // A new member gets a new row. A renewal (expired membership) reuses the row they already have.
    if (!$membership) {
        $stmt = $db->prepare("INSERT INTO club_memberships (club_id, user_id) VALUES (:club_id, :user_id)");
        $stmt->execute([':club_id' => $club_id, ':user_id' => $user['id']]);
        $membership_id = (int) $db->lastInsertId();
    } else {
        $membership_id = (int) $membership['id'];
    }

    $price  = (float) $club['membership_price'];
    $months = (int) $club['membership_months'];
    if ($price <= 0) {
        // Free membership: it starts right now.
        $new_status = 'active';
        $starts     = date('Y-m-d H:i:s');
        $expires    = date('Y-m-d H:i:s', strtotime('+' . $months . ' months'));
    } else {
        // Paid membership: it starts when the committee approves the receipt.
        // Until then keep the old dates, so a renewal still shows when the last membership ended.
        $new_status = 'awaiting_payment';
        $starts     = $membership['starts_at'] ?? null;
        $expires    = $membership['expires_at'] ?? null;
    }

    // Fill in this application: today's price, length and bank details (the "snapshots"),
    // and clear anything left over from the previous membership's payment.
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
        ':instructions' => "Bank: {$club['bank_name']}\nAccount Name: {$club['account_holder']}\nAccount Number: {$club['account_number']}",
        ':starts'       => $starts,
        ':expires'      => $expires,
        ':id'           => $membership_id,
    ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

$message = $new_status === 'active' ? 'free_member' : 'applied';
header('Location: ' . app_url('/membership-payment.php?id=' . $membership_id . '&msg=' . $message), true, 303);
exit;