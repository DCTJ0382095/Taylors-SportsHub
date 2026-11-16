<?php
// Club members and committee records are listed. Committee changes are restricted to presidents and
// admins.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$user = require_login();
$db = get_db_connection();

// The club is loaded by ID. Unknown IDs are shown the 404 page.
$club_id = (int) ($_GET['club_id'] ?? 0);
$stmt = $db->prepare("SELECT id, name FROM clubs WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $club_id]);
$club = $stmt->fetch();
// Unknown clubs are handled with a 404 response.
if (!$club) {
    render_error_page(404);
}

// Page access is granted to club committee members and admins.
if (!is_club_manager($club_id, $user['id'])) {
    render_error_page(403);
}
// Committee changes are restricted to presidents and admins.
$is_president = is_club_president($club_id, $user['id']);

// The success message is selected through the saved query parameter.
$error = null;
$saved = $_GET['saved'] ?? '';

// Each permission checkbox is converted to 1 or 0 according to submitted field presence.
function permissions_from_form(): array {
    return [
        ':sessions' => isset($_POST['can_manage_sessions']) ? 1 : 0,
        ':signups'  => isset($_POST['can_manage_signups']) ? 1 : 0,
        ':club'     => isset($_POST['can_edit_club']) ? 1 : 0,
    ];
}

// The submitted committee title is trimmed and limited to 50 characters.
function title_from_form(): ?string {
    $title = trim($_POST['title'] ?? '');
    return $title === '' ? null : mb_substr($title, 0, 50);
}

// A committee record is selected by ID and restricted to the current club.
function find_member(PDO $db, int $member_id, int $club_id): ?array {
    $stmt = $db->prepare("SELECT cm.*, u.name FROM club_managers cm
                          INNER JOIN users u ON u.id = cm.user_id
                          WHERE cm.id = :id AND cm.club_id = :club_id LIMIT 1");
    $stmt->execute([':id' => $member_id, ':club_id' => $club_id]);
    return $stmt->fetch() ?: null;
}

// Submitted committee changes are processed.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Committee mutations are refused without president-level access.
    if (!$is_president) {
        render_error_page(403);   // Committee changes are restricted to presidents and admins.
    }
    // The CSRF token is checked first, then the chosen action is carried out.
    $action    = $_POST['action'] ?? '';
    $member_id = (int) ($_POST['member_id'] ?? 0);
    $back      = app_url('/manager/members.php?club_id=' . $club_id);

    // Invalid CSRF tokens are reported before committee changes.
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Your form expired. Reload the page and try again.';

    } /* A new committee appointment is validated. */ elseif ($action === 'add') {
        // Active student accounts are checked for existing committee membership before a committee role, title
        // and permissions are assigned.
        $email = strtolower(trim($_POST['email'] ?? ''));
        // Non-student-domain email addresses are rejected.
        if (!is_valid_student_email($email)) {
            $error = "Enter a Taylor's student email ending in @sd.taylors.edu.my.";
        } /* A student account is looked up by the validated email address. */ else {
            $stmt = $db->prepare("SELECT id, status FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $new_user = $stmt->fetch();

            // Missing or inactive student accounts are rejected.
            if (!$new_user || $new_user['status'] !== 'active') {
                $error = 'No active SportsHub account uses that email. Ask them to register first.';
            } /* Duplicate committee appointments are rejected. */ elseif (get_committee_role($club_id, (int) $new_user['id'])) {
                $error = 'That student is already on the committee.';
            } /* A validated student is appointed with the selected title and permissions. */ else {
                $stmt = $db->prepare("INSERT INTO club_managers
                        (club_id, user_id, appointed_by, role, title, can_manage_sessions, can_manage_signups, can_edit_club)
                    VALUES (:club_id, :user_id, :appointed_by, 'committee', :title, :sessions, :signups, :club)");
                $stmt->execute(
                    [':club_id' => $club_id, ':user_id' => $new_user['id'], ':appointed_by' => $user['id'], ':title' => title_from_form()]
                    + permissions_from_form()
                );
                header('Location: ' . $back . '&saved=added', true, 303);
                exit;
            }
        }

    } /* Existing committee records are selected for editing, removal or presidency transfer. */ elseif (in_array($action, ['update', 'remove', 'handover'], true)) {
        // Existing club committee records are selected for update, removal or presidency transfer.
        $member = find_member($db, $member_id, $club_id);

        // Missing records and non-committee roles are rejected for these actions.
        if (!$member || $member['role'] !== 'committee') {
            // Missing records and the current president's record are rejected for these actions.
            $error = 'That committee member could not be found.';

        } /* The selected committee member's title and permissions are updated. */ elseif ($action === 'update') {
            // Update: the title and the permission ticks are saved.
            $stmt = $db->prepare("UPDATE club_managers
                                  SET title = :title, can_manage_sessions = :sessions, can_manage_signups = :signups, can_edit_club = :club
                                  WHERE id = :id");
            $stmt->execute([':id' => $member_id, ':title' => title_from_form()] + permissions_from_form());
            header('Location: ' . $back . '&saved=updated', true, 303);
            exit;

        } /* The selected committee appointment is removed. */ elseif ($action === 'remove') {
            // Remove: the committee row is deleted.
            $stmt = $db->prepare("DELETE FROM club_managers WHERE id = :id");
            $stmt->execute([':id' => $member_id]);
            header('Location: ' . $back . '&saved=removed', true, 303);
            exit;

        } /* Presidency is transferred to the selected committee member. */ else { // Presidency changes are grouped in one transaction.
            $db->beginTransaction();
            // Both presidency role changes are committed together.
            try {
                // 1. The current president is made a committee member (keeping every permission).
                $stmt = $db->prepare("UPDATE club_managers
                                      SET role = 'committee', title = NULL, can_manage_sessions = 1, can_manage_signups = 1, can_edit_club = 1
                                      WHERE club_id = :club_id AND role = 'president'");
                $stmt->execute([':club_id' => $club_id]);

                // 2. The chosen member is made president.
                $stmt = $db->prepare("UPDATE club_managers
                                      SET role = 'president', title = NULL, can_manage_sessions = 1, can_manage_signups = 1, can_edit_club = 1
                                      WHERE id = :id");
                $stmt->execute([':id' => $member_id]);

                $db->commit();
            } /* Failed presidency changes are rolled back and rethrown. */ catch (Throwable $e) {
                // On any error both changes are rolled back.
                $db->rollBack();
                throw $e;
            }
            header('Location: ' . $back . '&saved=handed_over', true, 303);
            exit;
        }

    } /* Unsupported committee actions are rejected. */ else {
        $error = 'Unknown action.';
    }
}

// The current committee is loaded, president first.
$stmt = $db->prepare("SELECT cm.id, cm.role, cm.title, cm.can_manage_sessions, cm.can_manage_signups, cm.can_edit_club,
                             u.name, u.email
                      FROM club_managers cm
                      INNER JOIN users u ON u.id = cm.user_id
                      WHERE cm.club_id = :club_id
                      ORDER BY cm.role = 'president' DESC, cm.created_at ASC");
$stmt->execute([':club_id' => $club_id]);
$members = $stmt->fetchAll();

// Non-committee membership records are loaded separately, so committee members are not listed twice.
$stmt = $db->prepare("SELECT m.id, m.status, m.expires_at, u.name, u.email
                      FROM club_memberships m
                      INNER JOIN users u ON u.id = m.user_id
                      WHERE m.club_id = :club_id AND m.status = 'active'
                        AND m.user_id NOT IN (SELECT user_id FROM club_managers WHERE club_id = :club_id_again)
                      ORDER BY u.name ASC");
$stmt->execute([':club_id' => $club_id, ':club_id_again' => $club_id]);
$paid_members = [];     // Unexpired approved memberships are classified as active.
$expired_members = [];  // Expired approved memberships are classified as expired.
foreach ($stmt->fetchAll() as $row) {
    // Unexpired approved memberships are added to the active member list.
    if (membership_state($row) === 'active') {
        $paid_members[] = $row;
    } /* Expired memberships are added to the expired member list. */ else {
        $expired_members[] = $row;
    }
}
// Payment pages can be opened by sign-up managers only.
$can_view_payments = can_manage_club($club_id, $user['id'], 'signups');

// Success messages and the labels for three committee permissions are defined.
$messages = [
    'added'       => 'Committee member added.',
        'updated'     => 'Changes saved.',
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

<!-- The session editor markup is rendered. --><div class="session-editor">
    <!-- Back navigation and the member-directory title are displayed. -->
    <a href="<?= app_url('/manager/sessions.php') ?>" class="settings-back">← Back to Manager Workspace</a>
    <h1 class="settings-title"><?= e($club['name']) ?> members</h1>

    <!-- Member-management feedback is displayed. -->
    <?php /* Committee-editing errors are displayed. */ if ($error): ?>
        <div class="manager-alert-error"><?= e($error) ?></div>
    <?php /* An allowlisted success message is displayed after a redirect. */ elseif (isset($messages[$saved])): ?>
        <!-- The settings alert success markup is rendered. --><div class="settings-alert-success"><?= e($messages[$saved]) ?></div>
    <?php endif; ?>

    <!-- Committee members and active approved memberships are listed. -->
    <div class="settings-card">
        <h2>Members (<?= count($members) + count($paid_members) ?>)</h2>
        <p class="settings-hint">Committee members always count as members. Students become members when the committee approves their payment.</p>
        <!-- Member search is connected to the filtering script below. -->
        <div class="settings-field">
            <input type="search" id="member-search" placeholder="Search by name or email" aria-label="Search members" autocomplete="off">
        </div>
        <!-- The member list markup is rendered. --><ul class="member-list" id="member-list">
            <?php /* Each committee member is included in the member directory. */ foreach ($members as $m): ?>
                <!-- The member list row markup is rendered. --><li class="member-list-row">
                    <!-- The member list person markup is rendered. --><div class="member-list-person"><strong><?= e($m['name']) ?></strong><span><?= e($m['email']) ?></span></div>
                    <span class="member-tag member-tag--committee"><?= e(committee_title($m)) ?></span>
                </li>
            <?php endforeach; ?>
            <?php /* Each active membership is included in the member directory. */ foreach ($paid_members as $p): ?>
                <!-- The member list row markup is rendered. --><li class="member-list-row">
                    <!-- The member list person markup is rendered. --><div class="member-list-person"><strong><?= e($p['name']) ?></strong><span><?= e($p['email']) ?></span></div>
                    <span class="member-tag">Active</span>
                    <?php /* Membership payment links are shown to authorized sign-ups managers. */ if ($can_view_payments): ?>
                        <a href="<?= app_url('/membership-payment.php?id=' . $p['id']) ?>" class="member-list-link">View</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="settings-hint" id="member-search-empty" hidden>No members match your search.</p>
    </div>

    <!-- Expired membership records are listed separately. -->
    <?php /* The expired-member list is displayed when records are available. */ if (!empty($expired_members)): ?>
        <div class="settings-card">
            <h2>Expired memberships (<?= count($expired_members) ?>)</h2>
            <p class="settings-hint">These students were members. They can renew from the club page.</p>
            <!-- The member list markup is rendered. --><ul class="member-list">
                <?php /* Each expired membership is rendered in the expired-member list. */ foreach ($expired_members as $p): ?>
                    <!-- The member list row markup is rendered. --><li class="member-list-row">
                        <!-- The member list person markup is rendered. --><div class="member-list-person"><strong><?= e($p['name']) ?></strong><span><?= e($p['email']) ?></span></div>
                        <span class="member-tag member-tag--expired">Expired <?= e(format_membership_date($p['expires_at'])) ?></span>
                        <?php /* Expired membership payment details are linked for authorized sign-ups managers. */ if ($can_view_payments): ?>
                            <a href="<?= app_url('/membership-payment.php?id=' . $p['id']) ?>" class="member-list-link">View</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Committee permissions are editable for president-level accounts and otherwise displayed read-only. -->
    <div class="settings-card">
        <?php /* Read-only committee access is explained for non-presidents. */ if (!$is_president): ?>
            <p class="settings-hint">Only the president can change the committee.</p>
        <?php endif; ?>

        <?php /* Each committee appointment is rendered with role and permission details. */ foreach ($members as $m): ?>
            <!-- The committee row markup is rendered. --><div class="committee-row">
                <!-- The committee row person markup is rendered. --><div class="committee-row-person">
                    <strong><?= e($m['name']) ?></strong>
                    <span><?= e($m['email']) ?></span>
                    <span class="committee-row-role"><?= e(committee_title($m)) ?></span>
                </div>

                <?php /* The president role is displayed without ordinary committee-editing actions. */ if ($m['role'] === 'president'): ?>
                    <p class="settings-hint">Has every permission.</p>

                <?php /* Committee-editing controls are displayed for president-level accounts. */ elseif ($is_president): ?>
                    <!-- The form controls markup is rendered. --><form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                        <!-- The settings field markup is rendered. --><div class="settings-field">
                            <label for="title-<?= (int) $m['id'] ?>">Title</label>
                            <input type="text" id="title-<?= (int) $m['id'] ?>" name="title" maxlength="50" value="<?= e($m['title'] ?? '') ?>" placeholder="e.g. Vice President, Secretary, Treasurer">
                        </div>
                        <?php /* Each committee permission is rendered as an editable checkbox. */ foreach ($permission_labels as $column => $label): ?>
                            <label class="committee-check">
                                <input type="checkbox" name="<?= $column ?>" <?= $m[$column] ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                        <!-- The committee row actions markup is rendered. --><div class="committee-row-actions">
                            <button type="submit" name="action" value="update" class="manager-btn manager-btn-secondary">Save</button>
                            <button type="submit" name="action" value="remove" class="manager-btn manager-btn-danger"
                                    onclick="return confirm('Remove this member from the committee?')">Remove</button>
                            <button type="submit" name="action" value="handover" class="manager-btn manager-btn-secondary"
                                    onclick="return confirm('Make this member the president? You will become a committee member.')">Make president</button>
                        </div>
                    </form>

                <?php /* Assigned permissions are displayed without editing controls. */ else: ?>
                    <!-- The committee perms markup is rendered. --><ul class="committee-perms">
                        <?php /* Each permission label is rendered with the recorded permission state. */ foreach ($permission_labels as $column => $label): ?>
                            <!-- The list entry markup is rendered. --><li><?= $m[$column] ? '✓' : '✗' ?> <?= e($label) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Committee appointment controls are displayed for president-level accounts. -->
    <?php /* The committee appointment form is displayed for president-level accounts. */ if ($is_president): ?>
        <form method="POST" class="settings-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <h2>Add a committee member</h2>
            <p class="settings-hint">They must already have a SportsHub student account.</p>
            <!-- The settings field markup is rendered. --><div class="settings-field">
                <label for="email">Student email</label>
                <input type="email" id="email" name="email" required placeholder="name@sd.taylors.edu.my">
            </div>
            <!-- The settings field markup is rendered. --><div class="settings-field">
                <label for="new-title">Title (optional)</label>
                <input type="text" id="new-title" name="title" maxlength="50" placeholder="e.g. Vice President, Secretary, Treasurer">
            </div>
            <?php /* Each available permission is offered on the appointment form. */ foreach ($permission_labels as $column => $label): ?>
                <label class="committee-check">
                    <input type="checkbox" name="<?= $column ?>"> <?= e($label) ?>
                </label>
            <?php endforeach; ?>
            <button type="submit" class="manager-btn manager-btn-primary">Add to committee</button>
        </form>
    <?php endif; ?>

    <script>
    // Member rows are filtered by the normalized search query.
    const memberSearch = document.getElementById('member-search');
    const memberRows = document.querySelectorAll('#member-list .member-list-row');
    const noMatches = document.getElementById('member-search-empty');
    memberSearch.addEventListener('input', () => {
        // Member rows are filtered after the search field is edited.
        const query = memberSearch.value.trim().toLowerCase();
        let shown = 0;
        memberRows.forEach(row => {
            // Each row is matched against the normalized search query and hidden when unmatched.
            const match = row.textContent.toLowerCase().includes(query);
            row.hidden = !match;
            // The visible member count is increased for matched rows.
            if (match) shown++;
        });
        // The no-matches message is displayed when every row has been hidden.
        noMatches.hidden = shown > 0;
    });
    </script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>