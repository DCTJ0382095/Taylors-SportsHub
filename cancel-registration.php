<?php
// Registration cancellation is handled here. Unpaid and free places are released immediately. Paid
// places are retained for payment review or refund processing.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
$reg_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();
// Overdue reservations are expired first, so the status checked below is current.
expire_overdue_reservations();

// Only the current student's registration is loaded. Other registrations are hidden behind a 404
// response.
$stmt = $db->prepare("SELECT r.*, s.title, s.sport_type, s.session_date, s.start_time, s.end_time, s.venue, s.status AS session_status, c.name AS club_name
                      FROM registrations r
                      INNER JOIN sessions s ON r.session_id = s.id
                      INNER JOIN clubs c ON s.club_id = c.id
                      WHERE r.id = :id AND r.user_id = :uid LIMIT 1");
$stmt->execute([':id' => $reg_id, ':uid' => $user['id']]);
$reg = $stmt->fetch();

// Unknown registrations are handled with a 404 response.
if (!$reg) {
    render_error_page(404);
}

// Self-service cancellation is blocked from the session start time onwards.
$session_start = strtotime($reg['session_date'] . ' ' . $reg['start_time']);
// Cancellation is refused once the session has started.
if ($session_start <= time()) {
    header("Location: " . app_url('/my-activities.php?msg=cannot_cancel_past_start'));
    exit;
}
// Registrations in any other non-cancellable state are sent back to the registration page.
if (!can_cancel_registration($reg)) {
    header('Location: ' . app_url('/payment.php?id=' . $reg_id . '&msg=invalid_status'));
    exit;
}

// Refund details are required for paid places with confirmed payments or receipts under review.
$is_paid_hold = ((float)$reg['price_snapshot'] > 0.001 && in_array($reg['status'], ['confirmed', 'awaiting_approval']));
$error = null;

// The submitted CSRF token is checked before cancellation details are processed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Invalid CSRF tokens are reported before cancellation is attempted.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Session expired. Please try again.";
    } /* Validated requests are processed inside a transaction. */ else {
        // The session and the registration are locked and re-read, and the cancellation rules are checked again,
        // so a status change made by the committee in the meantime is respected.
        $db->beginTransaction();
        $lock = $db->prepare('SELECT status AS session_status, session_date, start_time FROM sessions WHERE id=? FOR UPDATE');
        $lock->execute([$reg['session_id']]);
        $reg = array_merge($reg, $lock->fetch());
        $lock = $db->prepare('SELECT * FROM registrations WHERE id=? FOR UPDATE');
        $lock->execute([$reg_id]);
        $fresh = $lock->fetch();
        // Cancellation eligibility is checked again against the locked registration.
        if (!can_cancel_registration(array_merge($reg, $fresh))) {
            $db->rollBack();
            header('Location: ' . app_url('/payment.php?id=' . $reg_id . '&msg=invalid_status'));
            exit;
        }
        $reg = array_merge($reg, $fresh);
        $is_paid_hold = ((float)$reg['price_snapshot'] > .001 && in_array($reg['status'], ['confirmed','awaiting_approval']));
        // Refund recipient details are validated for paid holds.
        if ($is_paid_hold) {
            // The refund recipient's bank details are required and checked against the column length limits.
            $bank_name = trim($_POST['recipient_bank_name'] ?? '');
            $account_name = trim($_POST['recipient_account_name'] ?? '');
            $account_number = trim($_POST['recipient_account_number'] ?? '');

            // Missing bank details are reported before a refund request is saved.
            if (empty($bank_name) || empty($account_name) || empty($account_number)) {
                $error = "Please provide your bank name, account holder name, and account number to receive your full refund.";
            } /* Oversized bank details are rejected against storage limits. */ elseif (mb_strlen($bank_name)>100 || mb_strlen($account_name)>150 || mb_strlen($account_number)>50) {
                $error = 'Use at most 100 characters for bank name, 150 for account holder and 50 for account number.';
            } /* Valid refund recipient details are saved with the cancellation request. */ else {
                // The cancellation request and recipient details are saved. Confirmed paid places are marked
                // cancellation_pending. Receipts under review are retained as awaiting_approval until a payment
                // decision is recorded.
                $stmt = $db->prepare("UPDATE registrations
                                      SET status = :next_status, cancellation_requested_at = NOW(),
                                          cancellation_reason = 'Participant voluntary withdrawal',
                                          recipient_bank_name = :bank, recipient_account_name = :acc_name,
                                          recipient_account_number = :acc_no, updated_at = NOW()
                                      WHERE id = :id");
                $stmt->execute([
                    ':next_status' => $reg['status'] === 'awaiting_approval' ? 'awaiting_approval' : 'cancellation_pending',
                    ':bank' => $bank_name,
                    ':acc_name' => $account_name,
                    ':acc_no' => $account_number,
                    ':id' => $reg_id,
                ]);
                $db->commit();
                header("Location: " . app_url('/my-activities.php?msg=cancellation_pending'));
                exit;
            }
        } /* Unpaid or free registrations are cancelled without refund processing. */ else {
            // Unpaid or free registrations are cancelled immediately, and the held places are released.
            $stmt = $db->prepare("UPDATE registrations
                                  SET status = 'cancelled', cancellation_requested_at = NOW(),
                                      cancellation_reason = 'Participant cancelled prior to payment/commencement',
                                      updated_at = NOW()
                                  WHERE id = :id");
            $stmt->execute([':id' => $reg_id]);
            $db->commit();
            header("Location: " . app_url('/my-activities.php?msg=cancelled'));
            exit;
        }
        // The registration is left unchanged after a validation error, and the form is redisplayed with the
        // message.
        if ($db->inTransaction()) $db->rollBack();
    }
}

$page_title = "Cancel registration · " . $reg['title'];
require_once __DIR__ . '/includes/header.php';
?>

<!-- The journey page markup is rendered. --><div class="journey-page narrow">
    <!-- Cancellation details and the applicable form are displayed. -->
    <div class="journey-card">
        <span style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #c81e27; background: #fff2f2; padding: 0.35rem 0.75rem; border-radius: 980px; display: inline-block; margin-bottom: 0.75rem;">
            Withdrawal Request
        </span>

        <h1 style="font-size: 1.85rem; font-weight: 800; color: #1d1d1f; margin: 0 0 0.5rem; letter-spacing: -0.02em;">
            Cancel registration?
        </h1>
        <p style="color: #6e6e73; font-size: 0.95rem; line-height: 1.5; margin: 0 0 1.5rem;">
            You are requesting to withdraw from <strong><?= e($reg['title']) ?></strong> on <?= format_session_date($reg['session_date']) ?>.
        </p>

        <!-- Cancellation errors are displayed and marked for focus. -->
        <?php /* Cancellation errors are displayed above the form. */ if ($error): ?>
            <div class="journey-notice notice-error" role="alert" tabindex="-1" data-focus-error>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Refund recipient details are requested for paid holds. -->
        <?php /* Refund details are requested for paid holds. */ if ($is_paid_hold): ?>
            <div style="background: #fafafa; border-radius: 14px; padding: 1.25rem; border: 1px solid #e5e5ea; margin-bottom: 1.75rem;">
                <!-- The content group markup is rendered. --><div style="font-size: 0.95rem; font-weight: 700; color: #1d1d1f; margin-bottom: 0.35rem;"><?= $reg['status']==='awaiting_approval'?'Settlement review before refund':'Full refund entitlement' ?> (<?= format_price($reg['price_snapshot']) ?>)</div>
                <p style="font-size: 0.85rem; color: #6e6e73; margin: 0;">
                    <?= $reg['status']==='awaiting_approval'?'Your receipt is still under review. The committee must check whether money arrived before resolving cancellation. If payment is confirmed, a full external refund is due.':'Requesting cancellation before session start entitles you to a full external refund of your approved payment.' ?> Your place stays held until resolution. Recipient details are private to you and authorised club managers.
                </p>
            </div>

            <!-- The form controls markup is rendered. --><form method="POST" autocomplete="off">
                <?= csrf_field() ?>

                <!-- The content group markup is rendered. --><div style="margin-bottom: 1.25rem;">
                    <label for="recipient_bank_name" style="display: block; font-size: 0.88rem; font-weight: 600; color: #1d1d1f; margin-bottom: 0.4rem;">
                        Your bank name
                    </label>
                    <input type="text" id="recipient_bank_name" name="recipient_bank_name" maxlength="100" value="<?= e($_POST['recipient_bank_name']??'') ?>" placeholder="Bank name…" required style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
                </div>

                <!-- The content group markup is rendered. --><div style="margin-bottom: 1.25rem;">
                    <label for="recipient_account_name" style="display: block; font-size: 0.88rem; font-weight: 600; color: #1d1d1f; margin-bottom: 0.4rem;">
                        Account holder full name
                    </label>
                    <input type="text" id="recipient_account_name" name="recipient_account_name" maxlength="150" value="<?= e($_POST['recipient_account_name']??$user['name']) ?>" required style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
                </div>

                <!-- The content group markup is rendered. --><div style="margin-bottom: 2rem;">
                    <label for="recipient_account_number" style="display: block; font-size: 0.88rem; font-weight: 600; color: #1d1d1f; margin-bottom: 0.4rem;">
                        Account / Phone Number
                    </label>
                    <input type="text" id="recipient_account_number" name="recipient_account_number" maxlength="50" value="<?= e($_POST['recipient_account_number']??'') ?>" placeholder="Recipient account number…" required style="width: 100%; padding: 0.8rem 1rem; border-radius: 12px; border: 1px solid #d2d2d7; font-size: 0.95rem; box-sizing: border-box;">
                </div>

                <!-- The content group markup is rendered. --><div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <a href="<?= app_url('/my-activities.php') ?>" class="journey-button">
                        Keep registration
                    </a>
                    <button type="submit" class="journey-button primary registration-cancel-button">
                        Request cancellation
                    </button>
                </div>
            </form>
        <!-- Cancellation confirmation is requested for unpaid or free places. -->
        <?php /* Immediate place release is explained for unpaid or free registrations. */ else: ?>
            <form method="POST" autocomplete="off">
                <?= csrf_field() ?>
                <p style="font-size: 0.9rem; color: #424245; margin-bottom: 2rem;">
                    Cancelling will immediately release your reserved spot so another student can participate.
                </p>
                <!-- The content group markup is rendered. --><div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <a href="<?= app_url('/my-activities.php') ?>" class="journey-button">
                        Keep registration
                    </a>
                    <button type="submit" class="journey-button primary registration-cancel-button">
                        Yes, cancel my place
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
