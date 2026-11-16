<?php
// Shared application settings are loaded, and Malaysia time is selected for PHP date and time
// operations.
date_default_timezone_set('Asia/Kuala_Lumpur');
// The URL prefix is detected for installations at the web root or in a subfolder.
if (!defined('APP_BASE_PATH')) {
    // An explicitly configured APP_BASE_PATH environment value is given priority.
    $configured_base = getenv('APP_BASE_PATH');
    $base = $configured_base === false ? '' : rtrim($configured_base, '/');
    // Otherwise the prefix is worked out by comparing the project folder with the web server's document root.
    if ($configured_base === false && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $resolved_root = realpath($_SERVER['DOCUMENT_ROOT']);
        $docRoot = $resolved_root === false ? false : rtrim(str_replace('\\', '/', $resolved_root), '/');
        $projectRoot = str_replace('\\', '/', realpath(dirname(__DIR__)));
        // The URL prefix is derived from the project path within the document root.
        if ($docRoot !== false && $projectRoot && ($projectRoot === $docRoot || str_starts_with($projectRoot, $docRoot . '/'))) {
            $base = substr($projectRoot, strlen($docRoot));
        }
    // When no document root is available, the running script's folder is used instead, with known sub-folders stripped off.
    } /* The script path is used as a fallback for web requests without an explicit base path. */ elseif ($configured_base === false && PHP_SAPI !== 'cli' && !empty($_SERVER['SCRIPT_NAME'])) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $base = preg_replace('#/(admin|api|config|database|includes|tests)$#', '', $scriptDir);
    }

    define('APP_BASE_PATH', rtrim($base, '/'));
}

// The application prefix is added to local URL paths. Already-prefixed paths are returned unchanged.
function app_url(string $path): string {
    // Already-prefixed paths are returned without another prefix.
    if ($path === APP_BASE_PATH || (APP_BASE_PATH !== '' && str_starts_with($path, APP_BASE_PATH . '/'))) {
        return $path;
    }
    return APP_BASE_PATH . '/' . ltrim($path, '/');
}

// Standalone error handlers are loaded. Web output is buffered, and uncaught exceptions are routed to
// the 500 page.
require_once __DIR__ . '/../includes/error.php';
// Output buffering and exception handling are enabled for web requests.
if (PHP_SAPI !== 'cli') {
    ob_start();
    set_exception_handler('handle_app_exception');
}
