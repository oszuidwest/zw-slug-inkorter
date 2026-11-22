<?php
/**
 * Cleaning up what OpenAI answers.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

namespace ZWSlugInkorter\Tests\Integration;

use ZWSlugInkorter\Generator;

final class GeneratorTest extends \WP_UnitTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function answers(): array
    {
        return [
            'plain'                 => ['brand-verwoest-garage-putte', 'brand-verwoest-garage-putte'],
            'quotes and spaces'     => ["  \"brand-verwoest-garage-putte\" \n", 'brand-verwoest-garage-putte'],
            'backticks'             => ['`brand-garage-putte`', 'brand-garage-putte'],
            'capitals and accents'  => ['Café Brandt in Hoogerheide', 'cafe-brandt-in-hoogerheide'],
            'only first line'       => ["brand-garage-putte\nDit is de slug.", 'brand-garage-putte'],
            'cut between words'     => ['woningbrand-appartementencomplex-roosendaal-centrum-bewoners-geevacueerd', 'woningbrand-appartementencomplex-roosendaal'],
            'exactly the maximum'   => [str_repeat('a', 25) . '-' . str_repeat('b', 24), str_repeat('a', 25) . '-' . str_repeat('b', 24)],
            'one word over maximum' => [str_repeat('a', 60), str_repeat('a', 50)],
            'hyphen at the cut'     => [str_repeat('a', 50) . '-bbb', str_repeat('a', 50)],
            'empty'                 => ['  ', ''],
            'only punctuation'      => ['"---"', ''],
        ];
    }

    /**
     * @dataProvider answers
     */
    public function test_clean(string $answer, string $expected): void
    {
        $this->assertSame($expected, Generator::clean($answer));
        $this->assertLessThanOrEqual(Generator::MAX_LENGTH, strlen(Generator::clean($answer)));
    }

    public function test_api_key_comes_from_wp_config(): void
    {
        $this->assertSame('sk-test', Generator::apiKey());
    }
}
