<?php
// Session cancellation is recorded with a reason. Eligible registrations are updated according to
// payment and review status.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$session_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

// The session and club name are loaded. Unknown IDs are handled with a 404 response.
$stmt = $db->prepare("SELECT s.*, c.name AS club_name FROM sessions s INNER JOIN clubs c ON s.club_id = c.id WHERE s.id = :id LIMIT 1");
$stmt->execute([':id' => $session_id]);
$session = $stmt->fetch();

// Unknown sessions are handled with a 404 response.
if (!$session) {
    render_error_page(404);
}

// Session cancellation is restricted to accounts with the club's sessions permission.
if (!can_manage_club($session['club_id'], $user['id'], 'sessions')) {
    render_error_page(403);
}

$error = null;

// Form submission: the CSRF token is checked first, then the reason.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Invalid CSRF tokens are reported before session cancellation.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Session expired. Please try again.";
    } /* A valid request is checked for a cancellation reason. */ else {
        // A cancellation reason is required and limited to 223 characters before the stored prefix is added.
        $reason = trim($_POST['cancellation_reason'] ?? '');
        $registration_reason = 'Session cancelled by the committee: ' . $reason;
        // Missing or oversized cancellation reasons are rejected.
        if ($reason === '' || mb_strlen($registration_reason) > 255) {
            $error = 'Enter a cancellation reason of 1–223 characters.';
        } /* Validated cancellation changes are enclosed in a transaction. */ else {
            // All updates are enclosed in a transaction. The session row is locked, and already-cancelled sessions
            // are left unchanged.
            $db->beginTransaction();
            // Session and registration cancellation changes are committed together.
            try {
                $lock = $db->prepare('SELECT status FROM sessions WHERE id=? FOR UPDATE');
                $lock->execute([$session_id]);
                // Already-cancelled sessions are redirected without repeat updates.
                if ($lock->fetchColumn() === 'cancelled') {
                    $db->rollBack();
                    header('Location: ' . app_url('/manager/sessions.php?msg=session_cancelled'));
                    exit;
                }
                // The session is marked as cancelled with the submitted reason.
                $stmt = $db->prepare("UPDATE sessions SET status = 'cancelled', cancellation_reason = :reason, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':reason' => $reason, ':id' => $session_id]);

                // Paid confirmed registrations are moved to cancellation_pending for refund recording.
                $stmt = $db->prepare("UPDATE registrations
                                      SET status = 'cancellation_pending', cancellation_requested_at = NOW(),
                                          cancellation_reason = :reason, updated_at = NOW()
                                      WHERE session_id = :sid AND status = 'confirmed' AND price_snapshot > 0");
                $stmt->execute([':reason' => $registration_reason, ':sid' => $session_id]);

                // Paid receipts under review are retained as awaiting_approval and flagged for cancellation processing.
                $stmt = $db->prepare("UPDATE registrations SET cancellation_requested_at=COALESCE(cancellation_requested_at,NOW()), cancellation_reason=:reason WHERE session_id=:sid AND status='awaiting_approval' AND price_snapshot>0");
                $stmt->execute([':reason'=>$registration_reason, ':sid'=>$session_id]);
                // Reservations that were never paid (payment due or rejected) are cancelled outright.
                $stmt = $db->prepare("UPDATE registrations SET status='cancelled', cancellation_reason=:reason, updated_at=NOW() WHERE session_id=:sid AND status IN ('awaiting_payment','payment_rejected')");
                $stmt->execute([':reason'=>$registration_reason, ':sid'=>$session_id]);

                // Free registrations in any active state are cancelled, since no refund is involved.
                $stmt = $db->prepare("UPDATE registrations
                                      SET status = 'cancelled', cancellation_reason = :reason, updated_at = NOW()
                                      WHERE session_id = :sid AND status IN ('confirmed', 'awaiting_payment', 'awaiting_approval', 'payment_rejected') AND price_snapshot <= 0");
                $stmt->execute([':reason' => $registration_reason, ':sid' => $session_id]);

                $db->commit();
                header("Location: " . app_url('/manager/sessions.php?msg=session_cancelled'));
                exit;
            } /* Failed cancellation transactions are rolled back and rethrown. */ catch (Throwable $e) {
                // On any error every change is rolled back, and the error is passed on to the 500 page.
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }
    }
}

$page_title = "Cancel session · " . $session['title'];
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Cancellation warnings and the reason form are displayed. -->
<div class="manager-confirm-box">
    <!-- The manager confirm card markup is rendered. --><div class="manager-confirm-card">
        <span class="manager-eyebrow">
            Emergency Action
        </span>
        <h1 class="manager-confirm-title">
            Cancel session?
        </h1>
        <p class="manager-confirm-desc">
            Cancelling <strong><?= e($session['title']) ?></strong> will close registrations permanently. Any paid participants will have their registrations queued for a full external refund.
        </p>

        <?php /* Cancellation errors are displayed above the form. */ if ($error): ?>
            <!-- The manager alert error markup is rendered. --><div class="manager-alert-error" role="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- The manager cancellation form markup is rendered. --><form method="POST" action="<?= $_SERVER['REQUEST_URI'] ?>" class="manager-cancellation-form">
            <?= csrf_field() ?>

            <!-- The form field markup is rendered. --><div class="form-field">
                <label for="cancellation_reason" class="auth-label">
                    Reason for cancellation
                </label>
                <textarea id="cancellation_reason" name="cancellation_reason" rows="3" maxlength="223" required placeholder="e.g. Venue maintenance, adverse weather, or emergency coach unavailability..." class="form-input"></textarea>
            </div>

            <!-- The manager confirm actions markup is rendered. --><div class="manager-confirm-actions">
                <a href="<?= app_url('/manager/sessions.php') ?>" class="manager-btn manager-btn-secondary">
                    Keep session
                </a>
                <button type="submit" class="manager-btn manager-btn-danger">
                    Confirm & cancel session
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
