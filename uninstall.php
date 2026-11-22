<?php
/**
 * Remove plugin-owned data on uninstall.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('zw_slug_sniper_last_error');
