<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/csrf.php';

// Student email validation - student accounts must use @sd.taylors.edu.my
function is_valid_student_email(string $email): bool {
    $email = strtolower(trim($email));
    return (bool) preg_match('/^[a-zA-Z0-9._%+-]+@sd\.taylors\.edu\.my$/', $email);
}

function is_logged_in(): bool {
    start_session_safe();
    return !empty($_SESSION['user_id']);
}

function current_user(bool $reload = false): ?array {
    if (!is_logged_in()) {
        return null;
    }

    static $cached_user = null;
    if (!$reload && $cached_user !== null && $cached_user['id'] === (int)$_SESSION['user_id']) {
        return $cached_user;
    }

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, name, preferred_name, email, role, status, phone, faculty, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] === 'suspended') {
        logout_user();
        return null;
    }

    $cached_user = $user;
    return $user;
}

function login_user(array $user): void {
    start_session_safe();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

function logout_user(): void {
    start_session_safe();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

function require_login(?string $redirect = null): array {
    if (!is_logged_in()) {
        $target = $redirect ?? $_SERVER['REQUEST_URI'] ?? '/';
        header("Location: " . app_url("/login.php?redirect=") . urlencode($target));
        exit;
    }
    $user = current_user();
    if (!$user) {
        header("Location: " . app_url("/login.php?msg=") . urlencode("Account inactive or not found"));
        exit;
    }
    return $user;
}

function get_user_managed_clubs(int $user_id): array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT c.* FROM clubs c INNER JOIN club_managers cm ON c.id = cm.club_id WHERE cm.user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function is_club_manager(int $club_id, int $user_id): bool {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    if ($stmt->fetchColumn() === 'admin') return true;

    $stmt = $db->prepare("SELECT 1 FROM club_managers WHERE club_id = :club_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    return (bool) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Committee hierarchy: president, committee members and their permissions.
// ---------------------------------------------------------------------------

// Is this user the site administrator? The admin has full control of every club.
function is_admin(int $user_id): bool {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    return $stmt->fetchColumn() === 'admin';
}

// Returns this user's committee row for a club (role + permissions),
// or null if they are not on that club's committee.
function get_committee_role(int $club_id, int $user_id): ?array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT role, can_manage_sessions, can_manage_signups, can_edit_club
                          FROM club_managers
                          WHERE club_id = :club_id AND user_id = :user_id
                          LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Is this user the president of this club? (The admin counts as president everywhere.)
function is_club_president(int $club_id, int $user_id): bool {
    if (is_admin($user_id)) {
        return true;
    }
    $row = get_committee_role($club_id, $user_id);
    return $row !== null && $row['role'] === 'president';
}

// Can this user do a particular job in this club?
// $permission is one of: 'sessions', 'signups', 'club'.
function can_manage_club(int $club_id, int $user_id, string $permission): bool {
    // Each permission name maps to exactly one column in club_managers.
    $columns = [
        'sessions' => 'can_manage_sessions',
        'signups'  => 'can_manage_signups',
        'club'     => 'can_edit_club',
    ];
    if (!isset($columns[$permission])) {
        throw new InvalidArgumentException("Unknown club permission: $permission");
    }

    if (is_admin($user_id)) {
        return true;                                   // admin: full control
    }
    $row = get_committee_role($club_id, $user_id);
    if ($row === null) {
        return false;                                  // not on this club's committee
    }
    if ($row['role'] === 'president') {
        return true;                                   // president: always everything
    }
    return (int) $row[$columns[$permission]] === 1;    // committee: only what was ticked
}