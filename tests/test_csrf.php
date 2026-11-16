<?php
// CSRF helpers are tested without a database dependency and can be checked independently of the runner.

// Web requests are rejected with a 404 response.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Assertions must be switched on, otherwise every assert() would be skipped silently.
if ((int)ini_get('zend.assertions') !== 1 || !(bool)ini_get('assert.exception') || !(bool)ini_get('assert.active')) {
    throw new RuntimeException('Run php -d zend.assertions=1 -d assert.exception=1 -d assert.active=1 tests/test_csrf.php');
}
// Database and authentication dependencies are checked to remain unloaded.
require_once __DIR__ . '/../includes/csrf.php';
start_session_safe();
// Existing session data is preserved and restored after the test assertions.
$previous = $_SESSION;
// CSRF behavior is checked with existing session data preserved.
try {
    // Missing, malformed and wrong tokens are rejected while no token exists yet.
    unset($_SESSION['csrf_token']);
    assert(!verify_csrf_token(null));
    assert(!verify_csrf_token(['invalid']));
    assert(!verify_csrf_token(''));
    assert(!verify_csrf_token('invalid'));
    // A 64-character hexadecimal token is generated, retained within the session and accepted by
    // validation.
    $token = csrf_token();
    assert(strlen($token) === 64 && ctype_xdigit($token));
    assert(csrf_token() === $token, 'Token remains stable within the session');
    assert(verify_csrf_token($token));
    assert(!verify_csrf_token('invalid'));
    // The generated hidden field is checked for the same session token.
    assert(csrf_field() === '<input type="hidden" name="csrf_token" value="' . $token . '">');
    // After the session token changes, the old token is no longer accepted.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    assert(!verify_csrf_token($token), 'A token from another session is rejected');
    assert(verify_csrf_token($_SESSION['csrf_token']));
} /* Original session data is restored after the assertions. */ finally {
    $_SESSION = $previous;
}
echo "PASS CSRF: standalone loading, token generation/reuse, hidden field, missing/invalid/foreign token rejection\n";
