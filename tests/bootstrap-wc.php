<?php

/**
 * PHPUnit bootstrap for the WooCommerce-loaded test suite.
 *
 * Unlike tests/bootstrap.php, this one loads WooCommerce and installs its
 * tables so the product-bridge (stock/price/image virtualization, stock
 * reduction interception) can be exercised against real WC_Product objects.
 *
 * Run with: composer test:wc  (or vendor/bin/phpunit -c phpunit-wc.xml)
 */
$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';

if (! file_exists($_tests_dir.'/includes/functions.php')) {
    echo "Could not find WP test suite at {$_tests_dir}.\n";
    echo "Run: bin/install-wp-tests.sh <db-name> <db-user> <db-pass>\n";
    exit(1);
}

require_once dirname(__DIR__).'/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $_tests_dir.'/includes/functions.php';

if (! defined('POLYGLOT_TRANSLATIONS_DIR')) {
    define('POLYGLOT_TRANSLATIONS_DIR', 'polyglot-test-'.uniqid());
}

if (! defined('POLYGLOT_LOCALES')) {
    // The WC suite includes a non-base-currency locale (DKK) to exercise the
    // currency filter and FX price conversion (inc/exchange-rates.php).
    define('POLYGLOT_LOCALES', [
        'master.test' => ['locale' => 'fr_FR', 'hreflang' => 'fr', 'label' => 'Français', 'currency' => 'EUR', 'master' => true],
        'en.test' => ['locale' => 'en_IE', 'hreflang' => 'en', 'label' => 'English',  'currency' => 'EUR'],
        'es.test' => ['locale' => 'es_ES', 'hreflang' => 'es', 'label' => 'Español',  'currency' => 'EUR'],
        'dk.test' => ['locale' => 'da_DK', 'hreflang' => 'da', 'label' => 'Dansk',    'currency' => 'DKK'],
    ]);
}

// Keep WC quiet during the test boot (no admin install redirect, no remote calls).
if (! defined('WP_ADMIN')) {
    define('WP_ADMIN', false);
}

// WooCommerce lives outside this repo, and where it sits depends on the checkout:
// a plugin installed under wp-content/plugins/ finds it as a sibling, but a
// standalone dev clone symlinked into a site does not. Hence the explicit override.
$_wc_candidates = array_values(array_filter([
    getenv('WC_PLUGIN_PATH') ?: null,
    dirname(__DIR__, 2).'/woocommerce/woocommerce.php',
    defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR.'/woocommerce/woocommerce.php' : null,
]));

$_wc_dir = null;
foreach ($_wc_candidates as $_candidate) {
    if (file_exists($_candidate)) {
        $_wc_dir = $_candidate;

        break;
    }
}

if (null === $_wc_dir) {
    echo "WooCommerce not found. Set WC_PLUGIN_PATH to its woocommerce.php. Tried:\n";
    echo '  '.implode("\n  ", $_wc_candidates)."\n";
    exit(1);
}

// Load WooCommerce first, then our plugin (so our woocommerce_* filters attach).
tests_add_filter('muplugins_loaded', function () use ($_wc_dir) {
    require $_wc_dir;
    require dirname(__DIR__).'/wp-ai-polyglot.php';
});

// Install WooCommerce tables once WC is available.
tests_add_filter('setup_theme', static function () {
    if (! class_exists('WC_Install')) {
        return;
    }
    // Avoid Action Scheduler / async cron noise during install.
    remove_action('init', ['WC_Install', 'install_actions']);
    WC_Install::install();

    // Re-initialise roles created by the install.
    $roles = wp_roles();
    if (method_exists($roles, 'init_roles')) {
        $roles->init_roles();
    }
});

require $_tests_dir.'/includes/bootstrap.php';
