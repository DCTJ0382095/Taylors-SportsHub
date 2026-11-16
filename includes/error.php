<?php
// Standalone error pages are rendered without authentication or database-backed layout dependencies.
require_once __DIR__ . '/branding.php';

// A full error page is printed for the given status code, and the request is ended.
function render_error_page(int $status): void {
    // A title and message are defined for each supported status code.
    $pages = [
        403 => ['Access denied', 'You do not have permission to open this page or complete this action. Return to SportsHub home to continue.'],
        404 => ['Page not found', 'This page may have moved or is no longer available. Find your next session in Explore sessions.'],
        500 => ['Something went wrong', 'Your request could not be completed. Please try again in a moment, or return to the home page.'],
    ];
    // A generic message is used for unsupported status codes.
    [$title, $message] = $pages[$status] ?? ['Error', 'An unexpected error occurred.'];
    // The status code is sent, and the page is marked as not cacheable so a temporary error is not kept by the browser.
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    // The base path is used only when config/app.php has already been loaded.
    $base = htmlspecialchars(defined('APP_BASE_PATH') ? APP_BASE_PATH : '', ENT_QUOTES, 'UTF-8');
    // Standalone error markup is rendered with dedicated stylesheet links.
    ?>
<!DOCTYPE html>
<!-- The page document markup is rendered. --><html lang="en">
<!-- The document metadata markup is rendered. --><head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#c8102e">
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/favicon.ico">
    <title><?= $status ?> · <?= htmlspecialchars($title) ?> · Taylor's SportsHub</title>
    <link rel="stylesheet" href="<?= $base ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/errors.css?v=<?= filemtime(__DIR__ . '/../assets/css/errors.css') ?>">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/alignment.css?v=<?= filemtime(__DIR__ . '/../assets/css/alignment.css') ?>">
</head>
<!-- The error page markup is rendered. --><body class="error-page" data-error-code="<?= $status ?>">
    <!-- Shared branding is displayed without normal navigation. -->
    <header class="error-header">
        <a class="brand-group" href="<?= $base ?>/index.php" aria-label="Taylor's SportsHub home">
            <?= get_site_logo_html() ?>
            <!-- The brand text markup is rendered. --><div class="brand-text">
                <span class="brand-title">Taylor's</span>
                <span class="brand-subtitle">SPORTS HUB</span>
            </div>
        </a>
    </header>
    <!-- Error details and recovery links are displayed. -->
    <main class="error-main">
        <!-- The error content markup is rendered. --><div class="error-content">
            <p class="error-code"><?= $status ?></p>
            <h1><?= htmlspecialchars($title) ?></h1>
            <p class="error-description"><?= htmlspecialchars($message) ?></p>
            <!-- The error actions markup is rendered. --><nav class="error-actions" aria-label="Error recovery">
                <a class="btn-primary" href="<?= $base ?>/sessions.php">Explore sessions</a>
                <a class="btn-secondary" href="<?= $base ?>/index.php">Back to home</a>
            </nav>
        </div>
    </main>
    <!-- The error footer markup is rendered. --><footer class="error-footer">Taylor’s SportsHub · Student sports and club activities.</footer>
</body>
</html>
    <?php
    // The request is ended here so nothing else is printed after the error page.
    exit;
}

// Uncaught exceptions are routed here. The full error is written to the server log
// and the 500 page is shown instead of a PHP error message.
function handle_app_exception(Throwable $error): void {
    error_log((string) $error);
    // Every open output buffer is discarded so a half-printed page is not mixed with the error page.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    render_error_page(500);
}
