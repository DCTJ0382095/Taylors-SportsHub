<?php
// Login, logout and permission checks shared by every page.
// The database connection and the CSRF helpers are loaded here so that pages only need to include auth.php.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/csrf.php';

// Student email addresses are checked against the Taylor's student-domain pattern.
function is_valid_student_email(string $email): bool {
    // The address is trimmed and lowercased before the domain pattern is matched.
    $email = strtolower(trim($email));
    return (bool) preg_match('/^[a-zA-Z0-9._%+-]+@sd\.taylors\.edu\.my$/', $email);
}

// A visitor is treated as logged in when a user ID is stored in the session.
function is_logged_in(): bool {
    start_session_safe();
    return !empty($_SESSION['user_id']);
}

// The account record is cached for the request. A fresh database read is performed when reload is
// requested.
function current_user(bool $reload = false): ?array {
    // Guests are given null straight away.
    if (!is_logged_in()) {
        return null;
    }

    // The cached copy is reused unless a reload is requested or the session now points at a different user.
    static $cached_user = null;
    // The cached account is returned when no reload or account change is required.
    if (!$reload && $cached_user !== null && $cached_user['id'] === (int)$_SESSION['user_id']) {
        return $cached_user;
    }

    // The user's row is read from the database (the password hash is deliberately left out).
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, name, preferred_name, email, role, status, phone, faculty, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // Deleted or suspended accounts are logged out at once, so access is cut off on the next page load.
    if (!$user || $user['status'] === 'suspended') {
        logout_user();
        return null;
    }

    // The row is kept for any later calls in the same request.
    $cached_user = $user;
    return $user;
}

// The account ID is stored after the session ID is regenerated. Password verification is performed by
// the caller before login is requested.
function login_user(array $user): void {
    start_session_safe();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

// The user is logged out: session data is cleared, the session cookie is expired and the session is destroyed.
function logout_user(): void {
    start_session_safe();
    $_SESSION = [];
    // The browser's session cookie is replaced with an expired cookie.
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// Authentication is required, and the current account record is returned to the calling page.
function require_login(?string $redirect = null): array {
    // Guests are redirected to login, with the current page retained as the requested return path.
    if (!is_logged_in()) {
        $target = $redirect ?? $_SERVER['REQUEST_URI'] ?? '/';
        header("Location: " . app_url("/login.php?redirect=") . urlencode($target));
        exit;
    }
    // Accounts that were suspended or deleted mid-session are sent back to login with a message.
    $user = current_user();
    // Missing or inactive accounts are logged out and removed from the cache.
    if (!$user) {
        header("Location: " . app_url("/login.php?msg=") . urlencode("Account inactive or not found"));
        exit;
    }
    return $user;
}

// Every club where the user holds a committee seat (any committee role) is returned.
function get_user_managed_clubs(int $user_id): array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT c.* FROM clubs c INNER JOIN club_managers cm ON c.id = cm.club_id WHERE cm.user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

// The user is checked for any committee seat in the club.
function is_club_manager(int $club_id, int $user_id): bool {
    // Admins are let through without needing a committee row.
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    // Club access is granted immediately to site admins.
    if ($stmt->fetchColumn() === 'admin') return true;

    // A matching club_managers row is required for non-admin accounts.
    $stmt = $db->prepare("SELECT 1 FROM club_managers WHERE club_id = :club_id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    return (bool) $stmt->fetchColumn();
}

// Committee roles, titles and permissions are resolved through the following helpers.

// The user is checked for the site-wide admin role. The admin is given full control of every club.
function is_admin(int $user_id): bool {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $user_id]);
    return $stmt->fetchColumn() === 'admin';
}

// The user's committee row for a club (role, title and permission flags) is returned,
// or null when the user is not on that club's committee.
function get_committee_role(int $club_id, int $user_id): ?array {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT role, title, can_manage_sessions, can_manage_signups, can_edit_club
                          FROM club_managers
                          WHERE club_id = :club_id AND user_id = :user_id
                          LIMIT 1");
    $stmt->execute([':club_id' => $club_id, ':user_id' => $user_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// The user is checked for the president role in this club. The admin is treated as president of every club.
function is_club_president(int $club_id, int $user_id): bool {
    // President-level access is granted to site admins.
    if (is_admin($user_id)) {
        return true;
    }
    $row = get_committee_role($club_id, $user_id);
    return $row !== null && $row['role'] === 'president';
}

// The display title for a committee member is chosen here: "President", the custom title set by the president, or "Committee member".
function committee_title(array $member): string {
    // The fixed president title is returned for president records.
    if ($member['role'] === 'president') {
        return 'President';
    }
    return $member['title'] ?: 'Committee member';
}

// The user is checked for one committee permission in this club.
// $permission is expected to be one of: 'sessions', 'signups', 'club'.
function can_manage_club(int $club_id, int $user_id, string $permission): bool {
    // Each permission name is mapped to exactly one column in club_managers.
    // An unknown name is treated as a programming error, so an exception is thrown instead of quietly returning false.
    $columns = [
        'sessions' => 'can_manage_sessions',
        'signups'  => 'can_manage_signups',
        'club'     => 'can_edit_club',
    ];
    // Unknown permission names are rejected as programming errors.
    if (!isset($columns[$permission])) {
        throw new InvalidArgumentException("Unknown club permission: $permission");
    }

    // Permissions are resolved from the top of the hierarchy down.
    if (is_admin($user_id)) {
        return true;                                   // Full control is granted to admins.
    }
    $row = get_committee_role($club_id, $user_id);
    // Permission is refused when no club committee record is found.
    if ($row === null) {
        return false;                                  // Access is refused when no committee record is found.
    }
    // Every club permission is granted to the president.
    if ($row['role'] === 'president') {
        return true;                                   // Every committee permission is granted to the president.
    }
    return (int) $row[$columns[$permission]] === 1;    // Only explicitly enabled permissions are granted to other committee members.
}