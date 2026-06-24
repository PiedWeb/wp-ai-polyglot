<?php
/**
 * PHPStan-only bootstrap.
 *
 * Declares the configuration constants that the plugin reads from the host's
 * wp-config.php (or the test bootstrap). They are never defined inside the
 * plugin sources, so at high analysis levels PHPStan would otherwise report
 * them as undefined. Values here are placeholders for static analysis only;
 * they are listed under `dynamicConstantNames` in phpstan.dist.neon so PHPStan
 * never narrows control flow on them.
 *
 * This file is dev-only and excluded from the distributed plugin (.distignore).
 */
if (! defined('POLYGLOT_LOCALES')) {
    define('POLYGLOT_LOCALES', [
        'example.test' => ['locale' => 'fr_FR', 'hreflang' => 'fr', 'label' => 'Français', 'currency' => 'EUR', 'master' => true],
    ]);
}
if (! defined('POLYGLOT_BAR')) {
    define('POLYGLOT_BAR', true);
}
if (! defined('POLYGLOT_FOOTER')) {
    define('POLYGLOT_FOOTER', true);
}
if (! defined('POLYGLOT_FEED')) {
    define('POLYGLOT_FEED', true);
}
if (! defined('POLYGLOT_FEED_BRAND')) {
    define('POLYGLOT_FEED_BRAND', '');
}
if (! defined('POLYGLOT_FEED_GPC_DEFAULT')) {
    define('POLYGLOT_FEED_GPC_DEFAULT', '');
}
if (! defined('POLYGLOT_AUTOEXPORT')) {
    define('POLYGLOT_AUTOEXPORT', true);
}
if (! defined('POLYGLOT_WP_BIN')) {
    define('POLYGLOT_WP_BIN', 'wp');
}
if (! defined('POLYGLOT_TRANSLATIONS_DIR')) {
    define('POLYGLOT_TRANSLATIONS_DIR', 'polyglot-flat');
}
if (! defined('WP_HOME')) {
    define('WP_HOME', 'https://example.test');
}

// Plugin-internal constants: defined in wp-ai-polyglot.php after the early
// returns, so PHPStan's constant resolver does not always see them. WP core's
// COOKIEHASH is likewise environment-derived and absent from the core stubs.
if (! defined('POLYGLOT_VERSION')) {
    define('POLYGLOT_VERSION', '2.1.0');
}
if (! defined('POLYGLOT_PLUGIN_FILE')) {
    define('POLYGLOT_PLUGIN_FILE', __DIR__.'/wp-ai-polyglot.php');
}
if (! defined('POLYGLOT_PLUGIN_DIR')) {
    define('POLYGLOT_PLUGIN_DIR', __DIR__.'/');
}
if (! defined('POLYGLOT_WC_SLUGS_OPTION')) {
    define('POLYGLOT_WC_SLUGS_OPTION', 'polyglot_wc_slugs');
}
if (! defined('COOKIEHASH')) {
    define('COOKIEHASH', '');
}
