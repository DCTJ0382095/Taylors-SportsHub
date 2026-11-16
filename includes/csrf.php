<?php
// Session initialization and CSRF protection are provided without a database connection.

// The session is started at most once per request, with hardened cookie settings.
function start_session_safe(): void {
    // Cookie settings are configured before an inactive session is started.
    if (session_status() === PHP_SESSION_NONE) {
        // The session cookie is hidden from JavaScript, only accepted from cookies (never from the URL),
        // and session IDs that were not issued by the server are rejected.
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        // The Secure cookie flag is enabled for HTTPS requests.
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        // The session cookie is assigned SameSite=Lax for cross-site request restrictions.
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

// A random session token is generated and checked by the form-processing helpers.

// The session's token is returned, and created on first use.
function csrf_token(): string {
    start_session_safe();
    // A new random CSRF token is generated when no token is stored.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// A hidden input carrying the token is returned, to be printed inside every POST form.
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Submitted tokens are checked with the timing-safe hash_equals comparison.
function verify_csrf_token(mixed $token): bool {
    start_session_safe();
    // Missing, empty or non-string tokens are rejected before any comparison is made.
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
