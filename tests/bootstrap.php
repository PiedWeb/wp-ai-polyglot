<?php
/**
 * PHPUnit bootstrap for WP AI Polyglot.
 *
 * Loads WordPress test suite and the plugin.
 * WooCommerce is NOT loaded — it hangs during PHPUnit bootstrap.
 * Inventory bridge tests use wp-cli integration tests instead.
 */

$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';

if (! file_exists($_tests_dir . '/includes/functions.php')) {
    echo "Could not find WP test suite at {$_tests_dir}.\n";
    echo "Run: bin/install-wp-tests.sh <db-name> <db-user> <db-pass>\n";
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $_tests_dir . '/includes/functions.php';

// Define POLYGLOT_TRANSLATIONS_DIR before plugin loads (unique per run)
if (! defined('POLYGLOT_TRANSLATIONS_DIR')) {
    define('POLYGLOT_TRANSLATIONS_DIR', 'polyglot-test-' . uniqid());
}

// Define POLYGLOT_LOCALES before plugin loads
if (! defined('POLYGLOT_LOCALES')) {
    define('POLYGLOT_LOCALES', [
        'master.test' => ['locale' => 'fr_FR', 'hreflang' => 'fr', 'label' => 'Français', 'currency' => 'EUR', 'master' => true],
        'en.test'     => ['locale' => 'en_IE', 'hreflang' => 'en', 'label' => 'English',  'currency' => 'EUR'],
        'es.test'     => ['locale' => 'es_ES', 'hreflang' => 'es', 'label' => 'Español',  'currency' => 'EUR'],
    ]);
}

// Stub WP_CLI so the CLI class in the plugin is loaded
if (! defined('WP_CLI')) {
    define('WP_CLI', true);
}
if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static function add_command($name, $class)
        {
        }
        public static function error($msg)
        {
            throw new \RuntimeException($msg);
        }
        public static function success($msg)
        {
        }
        public static function warning($msg)
        {
        }
        public static function log($msg)
        {
        }
        public static function line($msg)
        {
            echo $msg;
        }
    }
}

tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/wp-ai-polyglot.php';
});

require $_tests_dir . '/includes/bootstrap.php';
