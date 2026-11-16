<?php
// The standalone missing-page response is rendered with HTTP status 404. Missing paths are routed here
// by Apache.
require_once __DIR__ . '/config/app.php';
render_error_page(404);
