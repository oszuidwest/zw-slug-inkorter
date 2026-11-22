<?php
/**
 * The Site Health check.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

namespace ZWSlugInkorter\Tests\Integration;

use ZWSlugInkorter\Health;

final class HealthTest extends \WP_UnitTestCase
{
    public function test_is_registered_as_direct_test(): void
    {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);

        $this->assertSame([Health::class, 'test'], $tests['direct']['zw_slug_inkorter']['test']);
    }

    public function test_good_without_failures(): void
    {
        $this->assertSame('good', Health::test()['status']);
    }

    public function test_failure_is_escaped_and_dated(): void
    {
        update_option(Health::OPTION, ['time' => strtotime('2026-04-24 10:00:00 UTC'), 'message' => '<b>kapot</b>'], false);
        $description = Health::test()['description'];

        $this->assertStringContainsString('&lt;b&gt;kapot&lt;/b&gt;', $description);
        $this->assertStringContainsString('2026', $description);
    }

    public function test_failure_option_is_not_autoloaded(): void
    {
        Health::recordFailure('kapot');

        $this->assertArrayNotHasKey(Health::OPTION, wp_load_alloptions());
    }
}
