<?php
// Shared database connection for the whole app.
// PDO with real prepared statements protects every query against SQL injection.
function db(): PDO
{
    static $pdo = null;               // reuse one connection per request
    if ($pdo === null) {
        $config = require __DIR__ . '/../config/config.php';
        $pdo = new PDO(
            "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
            $config['db_user'],
            $config['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // fail loudly on SQL errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,                  // use real prepared statements
            ]
        );
    }
    return $pdo;
}