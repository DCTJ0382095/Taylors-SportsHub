<?php
// The standalone server-error page is rendered with HTTP status 500.
require_once __DIR__ . '/config/app.php';
render_error_page(500);
