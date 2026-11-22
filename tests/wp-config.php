<?php
/**
 * Test configuration for the WordPress PHPUnit test library.
 *
 * Uses wp-env credentials with an isolated table prefix.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

define('DB_NAME', getenv('WORDPRESS_DB_NAME') ?: 'wordpress');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'password');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'mysql');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the test library.

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'ZuidWest Slug Sniper tests');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
define('WPLANG', '');

if (!defined('ABSPATH')) {
    define('ABSPATH', getenv('ZW_SLUG_SNIPER_TESTS_ABSPATH') ?: '/var/www/html/');
}
