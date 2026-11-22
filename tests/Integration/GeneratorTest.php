<?php
/**
 * Cleaning up what OpenAI answers.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper\Tests\Integration;

use ZWSlugSniper\Generator;

final class GeneratorTest extends \WP_UnitTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function answers(): array
    {
        return [
            'quotes and spaces'     => ["\n  \"brand-verwoest-garage-putte\" \n", 'brand-verwoest-garage-putte'],
            'backticks'             => ['`brand-garage-putte`', 'brand-garage-putte'],
            'capitals and accents'  => ['Café Brandt in Hoogerheide', 'cafe-brandt-in-hoogerheide'],
            'only first line'       => ["brand-garage-putte\nDit is de slug.", 'brand-garage-putte'],
            'empty'                 => ['  ', ''],
        ];
    }

    /**
     * @dataProvider answers
     */
    public function test_clean(string $answer, string $expected): void
    {
        $this->assertSame($expected, Generator::clean($answer));
    }
}
