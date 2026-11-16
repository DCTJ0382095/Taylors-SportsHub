<?php
// Registration metrics and participant records are presented with payment-review and refund actions.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$session_id = (int)($_GET['session_id'] ?? 0);
$db = get_db_connection();

// Overdue reservations for this session are expired first, so the list and metrics are current.
expire_overdue_reservations($session_id);

// The session and club name are loaded. Unknown IDs are handled with a 404 response.
$stmt = $db->prepare("SELECT s.*, c.name AS club_name FROM sessions s INNER JOIN clubs c ON s.club_id = c.id WHERE s.id = :id LIMIT 1");
$stmt->execute([':id' => $session_id]);
$session = $stmt->fetch();

// Unknown sessions are handled with a 404 response.
if (!$session) {
    render_error_page(404);
}

// Participant access is restricted to accounts with the club's sign-ups permission.
if (!can_manage_club($session['club_id'], $user['id'], 'signups')) {
    render_error_page(403);
}

// Every registration for the session is loaded with the student's contact details, oldest first.
$stmt = $db->prepare("SELECT r.*, u.name, u.email, u.phone 
                      FROM registrations r 
                      INNER JOIN users u ON r.user_id = u.id 
                      WHERE r.session_id = :sid 
                      ORDER BY r.created_at ASC");
$stmt->execute([':sid' => $session_id]);
$participants = $stmt->fetchAll();

$seat_info = get_session_seat_info($session_id);

$page_title = "Participants · " . $session['title'];
require_once __DIR__ . '/../includes/header.php';
?>

<!-- The participants container markup is rendered. --><div class="participants-container">
    <!-- Registration-action feedback is displayed. -->
    <?php journey_feedback(); ?>

    <!-- Back navigation and session identity are displayed. -->
    <a href="<?= app_url('/manager/sessions.php') ?>" class="participants-back-link">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        <span>Back to Manager Workspace</span>
    </a>

    <!-- The participants header row markup is rendered. --><div class="participants-header-row">
        <!-- The content group markup is rendered. --><div>
            <span class="participants-badge">
                <?= e($session['sport_type']) ?> · <?= e($session['club_name']) ?>
            </span>
            <h1 class="participants-title">
                <?= e($session['title']) ?>
            </h1>
            <!-- The participants meta markup is rendered. --><div class="participants-meta">
                <?= format_session_date($session['session_date']) ?> · <?= format_session_time_range($session['start_time'], $session['end_time']) ?> · <?= e($session['venue']) ?>
            </div>
        </div>

        <!-- Closure and cancellation actions are shown for published sessions. -->
        <div class="participants-actions">
            <?php /* Signup closure is offered for published sessions. */ if ($session['status'] === 'published'): ?>
                <a href="<?= app_url('/manager/close-signup.php?id=' . $session['id']) ?>" class="btn-participants-control secondary">
                    Close signup
                </a>
                <a href="<?= app_url('/manager/cancel-session.php?id=' . $session['id']) ?>" class="btn-participants-control danger">
                    Cancel session
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Session capacity, occupancy and pricing are summarized. -->
    <div class="participants-metrics-grid">
        <!-- The participants metric card markup is rendered. --><div class="participants-metric-card">
            <span class="metric-card-label">Capacity</span>
            <!-- The metric card val markup is rendered. --><div class="metric-card-val"><?= $seat_info['capacity'] ?></div>
        </div>
        <!-- The participants metric card markup is rendered. --><div class="participants-metric-card">
            <span class="metric-card-label">Places Remaining</span>
            <!-- The metric card val markup is rendered. --><div class="metric-card-val <?= $seat_info['seats_left'] === 0 ? 'color-red' : 'color-green' ?>">
                <?= $seat_info['seats_left'] ?>
            </div>
        </div>
        <!-- The participants metric card markup is rendered. --><div class="participants-metric-card">
            <span class="metric-card-label">Active Holds</span>
            <!-- The metric card val markup is rendered. --><div class="metric-card-val"><?= $seat_info['occupied'] ?></div>
        </div>
        <!-- The participants metric card markup is rendered. --><div class="participants-metric-card">
            <span class="metric-card-label">Price</span>
            <!-- The metric card val markup is rendered. --><div class="metric-card-val color-primary">
                <?= format_price($session['price']) ?>
                <?php /* A configured member discount is displayed in the session summary. */ if (format_member_price($session)): ?>
                    <br><?= format_price($session['member_price']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Participants are listed or an empty state is displayed. -->
    <div class="participants-card">
        <!-- The participants card header markup is rendered. --><div class="participants-card-header">
            <h2 class="participants-card-title">
                Registered students (<?= count($participants) ?>)
            </h2>
        </div>

        <?php /* An empty participant message is displayed when no registrations are found. */ if (empty($participants)): ?>
            <!-- The participants empty markup is rendered. --><div class="participants-empty">
                <svg class="participants-empty-icon" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <h3 class="participants-empty-title">No registrations yet</h3>
                <p class="participants-empty-desc">
                    Students haven't registered for this session yet. Share your session link with students or promote it during club practice!
                </p>
                <!-- The participants empty actions markup is rendered. --><div class="participants-empty-actions">
                    <a href="<?= app_url('/session-detail.php?id=' . $session['id']) ?>" target="_blank" class="btn-table-action primary">
                        <span>View public session</span>
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M7 17L17 7M7 7h10v10"/></svg>
                    </a>
                    <button type="button" data-copy-link="<?= app_url('/session-detail.php?id=' . $session['id']) ?>" class="btn-table-action secondary">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        <span>Copy link</span>
                    </button>
                    <a href="<?= app_url('/manager/sessions.php') ?>" class="btn-table-action secondary">
                        Back to Manager Workspace
                    </a>
                </div>
            </div>
        <?php /* Participant records are displayed in a table. */ else: ?>
            <!-- The participant table is placed in a keyboard-focusable scroll region. -->
            <div class="participants-table-wrap" tabindex="0" role="region" aria-label="Participant records; scroll horizontally for actions">
                <!-- The participants table markup is rendered. --><table class="participants-table">
                    <!-- The thead content markup is rendered. --><thead>
                        <!-- The table row markup is rendered. --><tr>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Price</th>
                            <th>Registered at</th>
                            <th class="col-action">Action</th>
                        </tr>
                    </thead>
                    <!-- The tbody content markup is rendered. --><tbody>
                        <?php /* Each participant is rendered with registration and payment details. */ foreach ($participants as $p): ?>
                            <!-- The table row markup is rendered. --><tr>
                                <td>
                                    <strong class="student-name"><?= e($p['name']) ?></strong>
                                    <span class="student-email"><?= e($p['email']) ?></span>
                                    <?php /* A recorded participant phone number is displayed. */ if (!empty($p['phone'])): ?>
                                        <span class="student-phone"> · <?= e($p['phone']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= get_status_badge($p['status']) ?>
                                    <?php /* Receipt deadlines are displayed for unpaid or rejected registrations. */ if (in_array($p['status'], ['awaiting_payment','payment_rejected'])): ?>
                                        <!-- The deadline note markup is rendered. --><div class="deadline-note">
                                            Due: <?= format_deadline($p['payment_deadline']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php /* Pending cancellation is labelled while receipt review is outstanding. */ if ($p['cancellation_requested_at'] && $p['status'] === 'awaiting_approval'): ?>
                                        <!-- The cancellation note markup is rendered. --><div class="cancellation-note">Cancellation requested; check settlement</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="price-text"><?= format_price($p['price_snapshot']) ?></span>
                                </td>
                                <td>
                                    <span class="date-text"><?= date('j M Y, g:i A', strtotime($p['created_at'])) ?></span>
                                </td>
                                <!-- Receipt, review and refund actions are selected by registration state. -->
                                <td class="col-action-cell">
                                    <?php /* Pending receipts are linked to the review page. */ if ($p['status'] === 'awaiting_approval'): ?>
                                        <a href="<?= app_url('/manager/review-receipt.php?id=' . $p['id']) ?>" class="btn-table-action primary">
                                            <span>Review receipt</span>
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                        </a>
                                    <?php /* Pending refunds are linked to refund recording. */ elseif ($p['status'] === 'cancellation_pending'): ?>
                                        <a href="<?= app_url('/manager/record-refund.php?id=' . $p['id']) ?>" class="btn-table-action dark">
                                            <span>Record refund</span>
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                        </a>
                                    <?php /* Existing evidence is offered for registrations without pending review or refund actions. */ elseif (!empty($p['receipt_path'])): ?>
                                        <?php /* PDF evidence is opened through the protected evidence endpoint. */ if (str_ends_with(strtolower($p['receipt_path']), '.pdf')): ?>
                                            <a href="<?= app_url('/evidence.php?registration_id=' . $p['id']) ?>" target="_blank" rel="noopener" class="btn-table-action link">
                                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                <span>View PDF</span>
                                            </a>
                                        <?php /* Image evidence is linked to the receipt preview. */ else: ?>
                                            <button type="button" class="btn-table-action secondary" data-receipt-zoom="<?= app_url('/evidence.php?registration_id=' . $p['id']) ?>" data-receipt-title="Receipt · <?= e($p['name']) ?> (<?= format_price($p['price_snapshot']) ?>)">
                                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                                <span>View receipt</span>
                                            </button>
                                        <?php endif; ?>
                                    <?php /* An unavailable-action marker is displayed when no evidence action applies. */ else: ?>
                                        <span class="student-phone">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
