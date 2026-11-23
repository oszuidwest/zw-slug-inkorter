<?php
/**
 * The Site Health check.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper\Tests\Integration;

use ZWSlugSniper\Health;

final class HealthTest extends OpenAiTestCase
{
    public function test_is_registered_as_direct_test(): void
    {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);

        $this->assertSame([Health::class, 'test'], $tests['direct']['zw_slug_sniper']['test']);
    }

    public function test_good_without_failures(): void
    {
        $this->assertSame('good', Health::test()['status']);
        $this->assertSame([], $this->openAiUrls);
    }

    public function test_recorded_failure_is_critical_escaped_dated_and_links_to_connectors(): void
    {
        update_option(Health::OPTION, ['time' => strtotime('2026-04-24 10:00:00 UTC'), 'message' => '<b>kapot</b>'], false);
        $test = Health::test();

        $this->assertSame('critical', $test['status']);
        $this->assertStringContainsString('24 April 2026 10:00', $test['description']);
        $this->assertStringContainsString('&lt;b&gt;kapot&lt;/b&gt;', $test['description']);
        $this->assertStringContainsString('options-connectors.php', $test['actions']);
    }

    public function test_failure_option_is_not_autoloaded(): void
    {
        Health::recordFailure('kapot');

        $this->assertArrayNotHasKey(Health::OPTION, wp_load_alloptions());
    }
}
