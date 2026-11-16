<?php
// Shared branding is generated without a database dependency for normal pages and standalone error
// responses.

// The logo HTML is built once per request and then reused by the header and footer.
function get_site_logo_html(): string {
    static $logo_html = null;
    // Cached logo markup is returned after initial generation.
    if ($logo_html !== null) {
        return $logo_html;
    }
    // The logo image is used when available. Otherwise, a red letter badge is rendered.
    $logo_path = '/assets/images/logo/logo.webp';
    // The available logo image is rendered.
    if (file_exists(__DIR__ . '/..' . $logo_path)) {
        $logo_html = '<img src="' . htmlspecialchars(app_url($logo_path), ENT_QUOTES, 'UTF-8') . '" alt="Taylor\'s University" class="brand-logo-img">';
    } /* A text badge is rendered when the logo file is absent. */ else {
        $logo_html = '<div class="brand-logo-icon" aria-hidden="true">t</div>';
    }
    return $logo_html;
}
