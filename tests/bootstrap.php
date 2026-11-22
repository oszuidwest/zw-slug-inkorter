<?php
/**
 * PHPUnit bootstrap: WordPress test library with the plugin loaded as an mu-plugin.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Prefer wp-env's matching test library and fall back to wp-phpunit.
$zw_slug_inkorter_tests_dir = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR');
if (!is_string($zw_slug_inkorter_tests_dir) || !is_dir($zw_slug_inkorter_tests_dir)) {
    fwrite(STDERR, "Neither WP_TESTS_DIR nor WP_PHPUNIT__DIR points to the WordPress test library.\n");
    exit(1);
}

$zw_slug_inkorter_tests_config = getenv('WP_PHPUNIT__TESTS_CONFIG');
if (is_string($zw_slug_inkorter_tests_config) && $zw_slug_inkorter_tests_config !== '') {
    $zw_slug_inkorter_tests_config = str_starts_with($zw_slug_inkorter_tests_config, '/') ? $zw_slug_inkorter_tests_config : dirname(__DIR__) . '/' . $zw_slug_inkorter_tests_config;
    if (is_file($zw_slug_inkorter_tests_config)) {
        define('WP_TESTS_CONFIG_FILE_PATH', $zw_slug_inkorter_tests_config);
    }
}

define('ZW_SLUG_INKORTER_API_KEY', 'sk-test');

require_once $zw_slug_inkorter_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/zw-slug-inkorter.php';
});

// The theme registers the region taxonomy on init, before the plugin looks for it.
tests_add_filter('init', static function (): void {
    register_taxonomy('regio', ['post'], ['hierarchical' => true, 'show_in_rest' => true, 'rest_base' => 'regio']);
});

require $zw_slug_inkorter_tests_dir . '/includes/bootstrap.php';
