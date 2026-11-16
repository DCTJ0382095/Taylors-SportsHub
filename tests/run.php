<?php
// PHP test files are run against a disposable database after the configured connection is initialized.
// Test filenames can be supplied as command-line arguments.

// Web requests are rejected with a 404 response, so the runner is restricted to the command line.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
// Assertions must be switched on, otherwise every assert() would be skipped silently.
if ((int)ini_get('zend.assertions') !== 1 || !(bool)ini_get('assert.exception') || !(bool)ini_get('assert.active')) {
    throw new RuntimeException('Run php -d zend.assertions=1 -d assert.exception=1 -d assert.active=1 tests/run.php');
}
// Test filenames are taken from the command line or the default suite and checked against the
// allowlist.
$names = array_slice($argv, 1) ?: ['test_portability.php', 'test_csrf.php', 'test_helpers.php', 'test_receipt_history.php', 'test_clubs.php'];
// Each requested test filename is checked before execution.
foreach ($names as $name) {
    // Unknown test filenames are rejected.
    if (!in_array($name, ['test_portability.php', 'test_csrf.php', 'test_helpers.php', 'test_receipt_history.php', 'test_clubs.php'], true)) {
        throw new RuntimeException('Unknown test');
    }
}
// A scratch database with a random name is created and filled from schema.sql and seed.sql.
require_once __DIR__ . '/../includes/helpers.php';
$db = get_db_connection();
$scratch = 'tu_sportshub_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4");
// Scratch database creation and suite execution are protected by cleanup.
try {
    $db->exec("USE `$scratch`");
    // Database-selection statements are removed so schema and seed statements are executed in the scratch
    // database.
    foreach (['schema', 'seed'] as $script) {
        $sql = file_get_contents(__DIR__ . '/../database/' . $script . '.sql');
        $sql = preg_replace('/^(?:CREATE DATABASE[^\r\n]*|USE[^\r\n]*);\r?\n/m', '', $sql);
        $db->exec($sql);
    }
    // Only disposable fixture dates are moved into the future. Demo seed files are retained unchanged.
    $db->exec('UPDATE sessions SET session_date=DATE_ADD(CURDATE(),INTERVAL 7 DAY)');
    $db->exec('UPDATE registrations SET payment_deadline=DATE_ADD(NOW(),INTERVAL 1 DAY),receipt_uploaded_at=IF(receipt_uploaded_at IS NULL,NULL,NOW())');
    initialize_receipt_history($db);
    // Test files are executed sequentially, and buffered output is printed after completion or failure.
    ob_start();
    // Test output is buffered until suite completion or failure.
    try {
        // Each allowlisted test file is included sequentially.
        foreach ($names as $name) require __DIR__ . '/' . $name;
    } /* Buffered suite output is flushed after execution. */ finally {
        echo ob_get_clean();
    }
} /* The scratch database is dropped after execution or failure. */ finally {
    // The scratch database is always dropped, even when a test fails.
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('USE `' . DB_NAME . '`');
    $db->exec("DROP DATABASE `$scratch`");
}
