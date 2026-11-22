<?php
/**
 * PHPUnit bootstrap: WordPress test library with the plugin loaded as an mu-plugin.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Prefer wp-env's matching test library and fall back to wp-phpunit.
$zw_slug_sniper_tests_dir = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR');
define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-config.php');

require_once $zw_slug_sniper_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    // The OpenAI provider from wp-env, with the key the Connectors screen would store.
    require WP_PLUGIN_DIR . '/ai-provider-for-openai/plugin.php';
    require dirname(__DIR__) . '/zw-slug-sniper.php';
});
tests_add_filter('pre_option_connectors_ai_openai_api_key', static fn (): string => 'test-key');

// The theme registers the region taxonomy on init, before the plugin looks for it.
tests_add_filter('init', static function (): void {
    register_taxonomy('regio', ['post'], ['hierarchical' => true, 'show_in_rest' => true, 'rest_base' => 'regio']);
});

require $zw_slug_sniper_tests_dir . '/includes/bootstrap.php';
