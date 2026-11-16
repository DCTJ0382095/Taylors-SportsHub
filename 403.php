<?php
// The standalone access-denied page is rendered with HTTP status 403.
require_once __DIR__ . '/config/app.php';
render_error_page(403);
