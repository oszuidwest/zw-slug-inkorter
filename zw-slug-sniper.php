<?php
/**
 * Plugin Name:       ZuidWest Slug Sniper
 * Plugin URI:        https://github.com/oszuidwest/zw-slug-sniper
 * Description:       Gives new posts a short, readable slug made by OpenAI when they are first published or scheduled.
 * Version:           0.0.1
 * Requires at least: 7.1
 * Requires PHP:      8.4
 * Author:            Streekomroep ZuidWest
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zw-slug-sniper
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'ZWSlugSniper\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

add_filter('wp_insert_post_data', [\ZWSlugSniper\Shortener::class, 'filter'], 99, 2);
add_action('init', [\ZWSlugSniper\Region::class, 'listenToRest'], 99);
add_filter('site_status_tests', [\ZWSlugSniper\Health::class, 'register']);
