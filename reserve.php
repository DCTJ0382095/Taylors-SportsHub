<?php
// POST reservations are processed from the session page. Free places are confirmed immediately, and
// paid places are held until the receipt deadline.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();

// Anything other than a POST is sent back to Explore Sessions.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . app_url('/sessions.php'));
    exit;
}

// A missing or wrong CSRF token is sent back with an error message.
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    header("Location: " . app_url('/sessions.php?msg=csrf_error'));
    exit;
}

// Overdue reservations for this session are expired first, so any freed places can be taken.
$session_id = (int)($_POST['session_id'] ?? 0);
$db = get_db_connection();

expire_overdue_reservations($session_id);

// Capacity checks and reservation writes are enclosed in one transaction.
$db->beginTransaction();
// Reservation checks and writes are enclosed in a transaction.
try {
    // The session row is locked before capacity is checked and a booking is saved.
    $stmt = $db->prepare("SELECT s.*, c.bank_name, c.account_holder, c.account_number, c.payment_note
                          FROM sessions s
                          INNER JOIN clubs c ON s.club_id = c.id
                          WHERE s.id = :id FOR UPDATE");
    $stmt->execute([':id' => $session_id]);
    $session = $stmt->fetch();

    // Unknown sessions are sent back to Explore Sessions.
    if (!$session) {
        $db->rollBack();
        header("Location: " . app_url('/sessions.php?msg=not_found'));
        exit;
    }

    // Only published sessions can be booked.
    if ($session['status'] !== 'published') {
        $db->rollBack();
        header("Location: " . app_url('/session-detail.php?id=' . $session_id . '&msg=not_published'));
        exit;
    }

    // Reservations are blocked at or after the session start time.
    $session_start = strtotime($session['session_date'] . ' ' . $session['start_time']);
    // Reservations are refused once the session has started.
    if ($session_start <= time()) {
        $db->rollBack();
        header("Location: " . app_url('/session-detail.php?id=' . $session_id . '&msg=session_started'));
        exit;
    }

    // Any earlier registration by this student for this session is looked up and locked.
    $stmt = $db->prepare("SELECT * FROM registrations WHERE session_id = :session_id AND user_id = :user_id FOR UPDATE");
    $stmt->execute([':session_id' => $session_id, ':user_id' => $user['id']]);
    $existing = $stmt->fetch();

    // Duplicate occupied registrations are prevented. Payment-due registrations are redirected to payment,
    // and other occupied registrations are redirected to the session page.
    if ($existing && in_array($existing['status'], ['confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected', 'cancellation_pending'])) {
        $db->rollBack();
        // Existing unpaid or rejected reservations are redirected to payment.
        if ($existing['status'] === 'awaiting_payment' || $existing['status'] === 'payment_rejected') {
            header("Location: " . app_url('/payment.php?id=' . $existing['id']));
        } /* Other occupied reservations are redirected to the session page. */ else {
            header("Location: " . app_url('/session-detail.php?id=' . $session_id . '&msg=already_registered'));
        }
        exit;
    }

    // Rebooking is blocked when financial evidence is retained. One registration row is preserved per
    // student and session.
    if ($existing) {
        $history = $db->prepare('SELECT COUNT(*) FROM refund_records WHERE registration_id=?');
        $history->execute([$existing['id']]);
        // Rebooking is refused when receipt or refund history is retained.
        if ($existing['receipt_path'] || (int)$history->fetchColumn() > 0) {
            $db->rollBack();
            header('Location: ' . app_url('/payment.php?id=' . $existing['id'] . '&msg=history_retained'));
            exit;
        }
        // A released registration with no receipt and no refund history is reused, with the old cancellation details cleared.
        $db->prepare('UPDATE registrations SET cancellation_requested_at=NULL,cancellation_reason=NULL,recipient_bank_name=NULL,recipient_account_name=NULL,recipient_account_number=NULL WHERE id=?')->execute([$existing['id']]);
    }

    // Capacity is checked inside the same locked transaction.
    $stmt = $db->prepare("SELECT COUNT(*) FROM registrations
                          WHERE session_id = :id
                            AND status IN ('confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected', 'cancellation_pending') FOR UPDATE");
    $stmt->execute([':id' => $session_id]);
    $occupied = (int) $stmt->fetchColumn();

    // New reservations are refused when held places meet capacity.
    if ($occupied >= (int)$session['capacity']) {
        $db->rollBack();
        header("Location: " . app_url('/session-detail.php?id=' . $session_id . '&msg=session_full'));
        exit;
    }

    // The applicable session price and club payment instructions are copied onto the registration as
    // booking snapshots.
    $price = session_price_for($session, (int) $user['id']);
    $payment_instructions = club_payment_instructions($session);

    // Free reservations are confirmed immediately.
    if ($price <= 0.001) {
        // Free session: the place is confirmed immediately, reusing the old row when one exists.
        if ($existing) {
            $stmt = $db->prepare("UPDATE registrations
                                  SET status = 'confirmed', price_snapshot = 0.00, payment_instructions_snapshot = 'Free session',
                                      updated_at = NOW()
                                  WHERE id = :id");
            $stmt->execute([':id' => $existing['id']]);
            $reg_id = $existing['id'];
        } /* A first free reservation is inserted as confirmed. */ else {
            $stmt = $db->prepare("INSERT INTO registrations (session_id, user_id, status, price_snapshot, payment_instructions_snapshot)
                                  VALUES (:session_id, :user_id, 'confirmed', 0.00, 'Free session')");
            $stmt->execute([':session_id' => $session_id, ':user_id' => $user['id']]);
            $reg_id = (int)$db->lastInsertId();
        }
        $db->commit();
        header("Location: " . app_url('/session-detail.php?id=' . $session_id . '&msg=confirmed'));
        exit;
    } /* Paid reservations are assigned a receipt deadline and payment snapshots. */ else {
        // Paid session: the place is held until a receipt deadline of 24 hours, or the session start if that comes sooner.
        // The old row is reused when one exists, with any earlier receipt details cleared.
        // Afterwards the student is sent to the payment page to upload a receipt.
        $twenty_four_hours_later = time() + (24 * 3600);
        $deadline_ts = min($twenty_four_hours_later, $session_start);
        $deadline_dt = date('Y-m-d H:i:s', $deadline_ts);

        // A released unpaid registration row is reused for the paid reservation.
        if ($existing) {
            $stmt = $db->prepare("UPDATE registrations
                                  SET status = 'awaiting_payment', price_snapshot = :price,
                                      payment_instructions_snapshot = :instr, payment_deadline = :deadline,
                                      receipt_path = NULL, receipt_uploaded_at = NULL, rejection_reason = NULL,
                                      updated_at = NOW()
                                  WHERE id = :id");
            $stmt->execute([
                ':price' => $price,
                ':instr' => $payment_instructions,
                ':deadline' => $deadline_dt,
                ':id' => $existing['id']
            ]);
            $reg_id = $existing['id'];
        } /* A first paid reservation is inserted as awaiting_payment. */ else {
            $stmt = $db->prepare("INSERT INTO registrations (session_id, user_id, status, price_snapshot, payment_instructions_snapshot, payment_deadline)
                                  VALUES (:session_id, :user_id, 'awaiting_payment', :price, :instr, :deadline)");
            $stmt->execute([
                ':session_id' => $session_id,
                ':user_id' => $user['id'],
                ':price' => $price,
                ':instr' => $payment_instructions,
                ':deadline' => $deadline_dt,
            ]);
            $reg_id = (int)$db->lastInsertId();
        }
        $db->commit();
        header("Location: " . app_url('/payment.php?id=' . $reg_id));
        exit;
    }
} /* Failed reservation transactions are rolled back and rethrown. */ catch (Throwable $e) {
    // On any error the transaction is rolled back, and the error is passed on to the 500 page.
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
