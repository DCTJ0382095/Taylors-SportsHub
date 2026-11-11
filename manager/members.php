<?php
// Club members page: everyone who counts as a member of the club, and the committee.
// Every committee member can open it. Only the president can change the committee
// (add or remove people, set permissions, hand over the presidency).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$db = get_db_connection();

$club_id = (int) ($_GET['club_id'] ?? 0);
$stmt = $db->prepare("SELECT id, name FROM clubs WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $club_id]);
$club = $stmt->fetch();
if (!$club) {
    render_error_page(404);
}

// Anyone on this club's committee (or the admin) may view the page.
if (!is_club_manager($club_id, $user['id'])) {
    render_error_page(403);
}
$is_president = is_club_president($club_id, $user['id']);

$error = null;
$saved = $_GET['saved'] ?? '';

// Turns the three tickboxes into 1 (ticked) or 0 (not ticked).
// An unticked box is not sent with the form at all, so we check whether it exists.
function permissions_from_form(): array {
    return [
        ':sessions' => isset($_POST['can_manage_sessions']) ? 1 : 0,
        ':signups'  => isset($_POST['can_manage_signups']) ? 1 : 0,
        ':club'     => isset($_POST['can_edit_club']) ? 1 : 0,
    ];
}

// Finds a committee row by its id, but ONLY if it belongs to this club.
function find_member(PDO $db, int $member_id, int $club_id): ?array {
    $stmt = $db->prepare("SELECT cm.*, u.name FROM club_managers cm
                          INNER JOIN users u ON u.id = cm.user_id
                          WHERE cm.id = :id AND cm.club_id = :club_id LIMIT 1");
    $stmt->execute([':id' => $member_id, ':club_id' => $club_id]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_president) {
        render_error_page(403);   // only the president changes the committee
    }
    $action    = $_POST['action'] ?? '';
    $member_id = (int) ($_POST['member_id'] ?? 0);
    $back      = app_url('/manager/members.php?club_id=' . $club_id);

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';

    } elseif ($action === 'add') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!is_valid_student_email($email)) {
            $error = "Enter a Taylor's student email ending in @sd.taylors.edu.my.";
        } else {
            $stmt = $db->prepare("SELECT id, status FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $new_user = $stmt->fetch();

            if (!$new_user || $new_user['status'] !== 'active') {
                $error = 'No active SportsHub account uses that email. Ask them to register first.';
            } elseif (get_committee_role($club_id, (int) $new_user['id'])) {
                $error = 'That student is already on the committee.';
            } else {
                $stmt = $db->prepare("INSERT INTO club_managers
                        (club_id, user_id, appointed_by, role, can_manage_sessions, can_manage_signups, can_edit_club)
                    VALUES (:club_id, :user_id, :appointed_by, 'committee', :sessions, :signups, :club)");
                $stmt->execute(
                    [':club_id' => $club_id, ':user_id' => $new_user['id'], ':appointed_by' => $user['id']]
                    + permissions_from_form()
                );
                header('Location: ' . $back . '&saved=added', true, 303);
                exit;
            }
        }

    } elseif (in_array($action, ['update', 'remove', 'handover'], true)) {
        $member = find_member($db, $member_id, $club_id);

        if (!$member || $member['role'] !== 'committee') {
            // Not in this club, or it's the president (who can't be edited or removed here).
            $error = 'That committee member could not be found.';

        } elseif ($action === 'update') {
            $stmt = $db->prepare("UPDATE club_managers
                                  SET can_manage_sessions = :sessions, can_manage_signups = :signups, can_edit_club = :club
                                  WHERE id = :id");
            $stmt->execute([':id' => $member_id] + permissions_from_form());
            header('Location: ' . $back . '&saved=updated', true, 303);
            exit;

        } elseif ($action === 'remove') {
            $stmt = $db->prepare("DELETE FROM club_managers WHERE id = :id");
            $stmt->execute([':id' => $member_id]);
            header('Location: ' . $back . '&saved=removed', true, 303);
            exit;

        } else { // handover
            $db->beginTransaction();
            try {
                // 1. The current president becomes a committee member (keeping every permission).
                $stmt = $db->prepare("UPDATE club_managers
                                      SET role = 'committee', can_manage_sessions = 1, can_manage_signups = 1, can_edit_club = 1
                                      WHERE club_id = :club_id AND role = 'president'");
                $stmt->execute([':club_id' => $club_id]);

                // 2. The chosen member becomes president.
                $stmt = $db->prepare("UPDATE club_managers
                                      SET role = 'president', can_manage_sessions = 1, can_manage_signups = 1, can_edit_club = 1
                                      WHERE id = :id");
                $stmt->execute([':id' => $member_id]);

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            header('Location: ' . $back . '&saved=handed_over', true, 303);
            exit;
        }

    } else {
        $error = 'Unknown action.';
    }
}

// The current committee, president first.
$stmt = $db->prepare("SELECT cm.id, cm.role, cm.can_manage_sessions, cm.can_manage_signups, cm.can_edit_club,
                             u.name, u.email
                      FROM club_managers cm
                      INNER JOIN users u ON u.id = cm.user_id
                      WHERE cm.club_id = :club_id
                      ORDER BY cm.role = 'president' DESC, cm.created_at ASC");
$stmt->execute([':club_id' => $club_id]);
$members = $stmt->fetchAll();

// Students with a paid membership who aren't on the committee.
// (Committee members already count as members, so they're listed once, from $members above.)
$stmt = $db->prepare("SELECT m.id, m.status, m.expires_at, u.name, u.email
                      FROM club_memberships m
                      INNER JOIN users u ON u.id = m.user_id
                      WHERE m.club_id = :club_id AND m.status = 'active'
                        AND m.user_id NOT IN (SELECT user_id FROM club_managers WHERE club_id = :club_id_again)
                      ORDER BY u.name ASC");
$stmt->execute([':club_id' => $club_id, ':club_id_again' => $club_id]);
$paid_members = [];     // membership still running
$expired_members = [];  // membership has run out
foreach ($stmt->fetchAll() as $row) {
    if (membership_state($row) === 'active') {
        $paid_members[] = $row;
    } else {
        $expired_members[] = $row;
    }
}
// Only sign-up managers may open someone's payment page.
$can_view_payments = can_manage_club($club_id, $user['id'], 'signups');

$messages = [
    'added'       => 'Committee member added.',
    'updated'     => 'Permissions saved.',
    'removed'     => 'Committee member removed.',
    'handed_over' => 'The presidency has been handed over.',
];
$permission_labels = [
    'can_manage_sessions' => 'Manage sessions',
    'can_manage_signups'  => 'Manage sign-ups and payments',
    'can_edit_club'       => 'Edit club profile',
];

$page_title = 'Members · ' . $club['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="session-editor">
    <a href="<?= app_url('/manager/sessions.php') ?>" class="settings-back">← Back to Manager Workbench</a>
    <h1 class="settings-title"><?= e($club['name']) ?> members</h1>

    <?php if ($error): ?>
        <div class="manager-alert-error"><?= e($error) ?></div>
    <?php elseif (isset($messages[$saved])): ?>
        <div class="settings-alert-success"><?= e($messages[$saved]) ?></div>
    <?php endif; ?>

    <!-- Everyone who counts as a member: the committee first, then students with a paid membership -->
    <div class="settings-card">
        <h2>Members (<?= count($members) + count($paid_members) ?>)</h2>
        <p class="settings-hint">Committee members always count as members. Students become members when the committee approves their payment.</p>
        <ul class="member-list">
            <?php foreach ($members as $m): ?>
                <li class="member-list-row">
                    <div class="member-list-person"><strong><?= e($m['name']) ?></strong><span><?= e($m['email']) ?></span></div>
                    <span class="member-tag member-tag--committee"><?= $m['role'] === 'president' ? 'President' : 'Committee' ?></span>
                </li>
            <?php endforeach; ?>
            <?php foreach ($paid_members as $p): ?>
                <li class="member-list-row">
                    <div class="member-list-person"><strong><?= e($p['name']) ?></strong><span><?= e($p['email']) ?></span></div>
                    <span class="member-tag">Until <?= e(format_membership_date($p['expires_at'])) ?></span>
                    <?php if ($can_view_payments): ?>
                        <a href="<?= app_url('/membership-payment.php?id=' . $p['id']) ?>" class="member-list-link">View</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="settings-card">
        <h2>Committee</h2>
        <p class="settings-hint">
            <?= $is_president ? 'Tick what each member may do, then save their row.' : 'Only the president can change the committee.' ?>
        </p>

        <?php foreach ($members as $m): ?>
            <div class="committee-row">
                <div class="committee-row-person">
                    <strong><?= e($m['name']) ?></strong>
                    <span><?= e($m['email']) ?></span>
                    <span class="committee-row-role"><?= $m['role'] === 'president' ? 'President' : 'Committee member' ?></span>
                </div>

                <?php if ($m['role'] === 'president'): ?>
                    <p class="settings-hint">Has every permission.</p>

                <?php elseif ($is_president): ?>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                        <?php foreach ($permission_labels as $column => $label): ?>
                            <label class="committee-check">
                                <input type="checkbox" name="<?= $column ?>" <?= $m[$column] ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                        <div class="committee-row-actions">
                            <button type="submit" name="action" value="update" class="manager-btn manager-btn-secondary">Save</button>
                            <button type="submit" name="action" value="remove" class="manager-btn manager-btn-danger"
                                    onclick="return confirm('Remove this member from the committee?')">Remove</button>
                            <button type="submit" name="action" value="handover" class="manager-btn manager-btn-secondary"
                                    onclick="return confirm('Make this member the president? You will become a committee member.')">Make president</button>
                        </div>
                    </form>

                <?php else: ?>
                    <ul class="committee-perms">
                        <?php foreach ($permission_labels as $column => $label): ?>
                            <li><?= $m[$column] ? '✓' : '✗' ?> <?= e($label) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($is_president): ?>
        <form method="POST" class="settings-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <h2>Add a committee member</h2>
            <p class="settings-hint">They must already have a SportsHub student account.</p>
            <div class="settings-field">
                <label for="email">Student email</label>
                <input type="email" id="email" name="email" required placeholder="name@sd.taylors.edu.my">
            </div>
            <?php foreach ($permission_labels as $column => $label): ?>
                <label class="committee-check">
                    <input type="checkbox" name="<?= $column ?>"> <?= e($label) ?>
                </label>
            <?php endforeach; ?>
            <button type="submit" class="manager-btn manager-btn-primary">Add to committee</button>
        </form>
    <?php endif; ?>

    <!-- Students whose paid membership has run out -->
    <?php if (!empty($expired_members)): ?>
        <div class="settings-card">
            <h2>Expired memberships (<?= count($expired_members) ?>)</h2>
            <p class="settings-hint">These students were members. They can renew from the club page.</p>
            <ul class="member-list">
                <?php foreach ($expired_members as $p): ?>
                    <li class="member-list-row">
                        <div class="member-list-person"><strong><?= e($p['name']) ?></strong><span><?= e($p['email']) ?></span></div>
                        <span class="member-tag member-tag--expired">Ended <?= e(format_membership_date($p['expires_at'])) ?></span>
                        <?php if ($can_view_payments): ?>
                            <a href="<?= app_url('/membership-payment.php?id=' . $p['id']) ?>" class="member-list-link">View</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>