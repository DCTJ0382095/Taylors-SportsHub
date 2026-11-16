<?php
// Database settings and the shared PDO connection are defined.
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/setup.php';

// Local XAMPP defaults are used unless connection settings are supplied through environment variables.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'tu_sportshub');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') === false ? '' : getenv('DB_PASS'));
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));

// One PDO connection is opened per request and reused by every query.
function get_db_connection(): PDO {
    static $pdo = null;
    // A PDO connection is created only when no connection has been cached.
    if ($pdo === null) {
        // The database name is omitted from the initial connection so a missing database can be created during
        // setup.
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        // Errors are thrown as exceptions, rows are returned as associative arrays, and real prepared statements are used.
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        // MySQL is set to Malaysia time (+08:00) to match PHP, then the database is created or upgraded when needed.
        $connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        $connection->exec("SET time_zone = '+08:00'");
        initialize_database($connection, DB_NAME);
        $pdo = $connection;
    }
    return $pdo;
}
