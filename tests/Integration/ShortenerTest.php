<?php
/**
 * When a post gets a short slug, and which one.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper\Tests\Integration;

use ZWSlugSniper\Health;
use ZWSlugSniper\Shortener;

final class ShortenerTest extends OpenAiTestCase
{
    private function publish(array $args = []): \WP_Post
    {
        return self::factory()->post->create_and_get($args + ['post_title' => 'Brand verwoest garage aan de Kerkstraat in Putte, niemand gewond', 'post_status' => 'publish']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function prompts(): array
    {
        return [
            'title with quotes, unslashed' => [['post_title' => 'Wethouder: "Dit is een \'flinke\' tegenvaller voor de gemeente Rucphen"'], 'TITEL: Wethouder: "Dit is een \'flinke\' tegenvaller voor de gemeente Rucphen"'],
            'excerpt without tags'         => [['post_excerpt' => 'Een <em>garage</em> in Putte is uitgebrand.', 'post_content' => '<!-- wp:paragraph --><p>Lange tekst</p><!-- /wp:paragraph -->'], 'SAMENVATTING: Een garage in Putte is uitgebrand.'],
            'text without blocks, tags or shortcodes' => [['post_excerpt' => '', 'post_content' => '<!-- wp:paragraph --><p>De <strong>brandweer</strong> kwam snel.</p><!-- /wp:paragraph -->[kader]Info[/kader]'], "SAMENVATTING: De brandweer kwam snel.\n"],
            'no region, place from the title' => [[], 'PLAATS: zet de plaats uit de titel of samenvatting in de slug'],
        ];
    }

    /**
     * @dataProvider prompts
     */
    public function test_prompt(array $post, string $expected): void
    {
        add_shortcode('kader', static fn (): string => '');
        $this->publish($post);

        $this->assertStringContainsString($expected, $this->prompt());
        $this->assertStringNotContainsString('niet gebruiken', $this->prompt());
    }

    public function test_post_gets_the_short_slug_once_and_keeps_it(): void
    {
        $id = $this->publish(['post_status' => 'draft'])->ID;
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        wp_update_post(['ID' => $id, 'post_title' => 'Nieuwe titel']);
        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        wp_update_post(['ID' => $id, 'post_status' => 'future', 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        wp_update_post(['ID' => $id, 'post_date' => gmdate('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS)]);
        wp_publish_post($id);

        $this->assertSame('brand-verwoest-garage-putte', get_post($id)->post_name);
        $this->assertCount(1, $this->requests);
    }

    public function test_post_that_has_a_slug_never_asks_openai(): void
    {
        $post = $this->publish(['post_name' => 'eigen-slug']);
        $this->assertSame('eigen-slug', $post->post_name);
        wp_update_post(['ID' => $post->ID, 'post_name' => '']);
        wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
        wp_insert_post(['ID' => $post->ID, 'post_title' => $post->post_title, 'post_content' => 'Tekst', 'post_status' => 'publish']);

        $this->assertSame([], $this->requests);
    }

    public function test_taken_slug_is_asked_again_with_the_reason(): void
    {
        $this->publish();
        $this->answers('brand-verwoest-garage-putte', 'garagebrand-kerkstraat-putte');
        $second = $this->publish();

        $this->assertSame('garagebrand-kerkstraat-putte', $second->post_name);
        $this->assertStringContainsString("Deze slugs kun je niet gebruiken:\n- brand-verwoest-garage-putte (bestaat al)", $this->prompt(2));
    }

    public function test_slug_taken_by_a_scheduled_post_counts_as_taken(): void
    {
        $this->assertSame('brand-verwoest-garage-putte', $this->publish(['post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)])->post_name);
        $second = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $second->post_name);
    }

    public function test_slug_of_exactly_the_maximum_length_is_used_and_one_more_is_asked_again(): void
    {
        $max  = str_repeat('a', 25) . '-' . str_repeat('b', 24);
        $long = str_repeat('c', 25) . '-' . str_repeat('d', 25);
        $this->answers($max, $long, 'garagebrand-putte');

        $this->assertSame($max, $this->publish()->post_name);
        $this->assertSame('garagebrand-putte', $this->publish()->post_name);
        $this->assertStringContainsString("- {$long} (te lang, meer dan 50 tekens)", $this->prompt(2));
    }

    public function test_title_slug_fallback_still_clears_an_old_failure(): void
    {
        update_option(Health::OPTION, ['time' => time(), 'message' => 'kapot'], false);
        $this->answers('brand-verwoest-garage-aan-de-kerkstraat-in-putte-vannacht');

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $this->publish()->post_name);
        $this->assertCount(2, $this->requests);
        $this->assertFalse(get_option(Health::OPTION));
    }

    public function test_pages_get_the_short_slug_and_post_types_without_a_public_url_are_skipped(): void
    {
        register_post_type('zw_intern', ['public' => false]);
        $this->assertSame('brand-verwoest-garage-putte', $this->publish(['post_type' => 'page'])->post_name);
        $this->publish(['post_type' => 'zw_intern']);

        $this->assertCount(1, $this->requests);
    }

    public function test_api_error_keeps_the_title_slug_and_shows_in_site_health(): void
    {
        $this->answers(self::json(401, ['error' => ['message' => 'The project you are requesting has been archived', 'type' => 'invalid_request_error']]));
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $test = Health::test();
        $this->assertSame('critical', $test['status']);
        $this->assertStringContainsString('Unauthorized (401) - The project you are requesting has been archived', $test['description']);

        $this->answers('brand-verwoest-garage-putte');
        $this->publish();
        $this->assertSame('good', Health::test()['status']);
        $this->assertFalse(get_option(Health::OPTION));
    }

    /**
     * @return array<string, array{string|array<string, mixed>|\WP_Error|\Throwable|null, string}>
     */
    public static function failures(): array
    {
        // Reply of the Responses API (null: the provider lists no models), pattern for the recorded failure.
        return [
            'timeout'          => [new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'), '/cURL error 28: Operation timed out/'],
            'malformed answer' => [self::json(200, ['output' => []]), '/./'],
            'PHP error'        => [new \Error('kapot'), '/^kapot$/'],
            'no usable slug'   => ['  "---"  ', '/^Het AI-model gaf geen bruikbare slug\.$/'],
            'no text model'    => [null, '/No models found/'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function test_failure_keeps_the_title_slug_and_is_recorded(string|array|\WP_Error|\Throwable|null $reply, string $pattern): void
    {
        $reply === null ? $this->models = [] : $this->answers($reply);
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertMatchesRegularExpression($pattern, get_option(Health::OPTION)['message']);
    }

    public function test_request_uses_the_preferred_model_and_a_short_timeout(): void
    {
        $this->publish();
        $body = $this->requests[0]['body'];

        $this->assertSame('gpt-5.4-mini', $body['model']);
        $this->assertStringContainsString('eindredacteur', $body['instructions']);
        $this->assertSame(300, $body['max_output_tokens']);
        $this->assertEquals(8, $this->requests[0]['args']['timeout']);
        $this->assertSame('Bearer test-key', $this->requests[0]['args']['headers']['Authorization']);
        $this->assertStringContainsString('nooit meer dan 50', $this->prompt());
    }

    public function test_falls_back_to_another_text_model_without_the_preferred_one(): void
    {
        $this->models = ['gpt-4.1'];
        $post         = $this->publish();

        $this->assertSame('brand-verwoest-garage-putte', $post->post_name);
        $this->assertSame('gpt-4.1', $this->requests[0]['body']['model']);
    }

    public function test_nothing_happens_when_ai_is_switched_off(): void
    {
        add_filter('wp_supports_ai', '__return_false');
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertSame([], $this->openAiUrls);
        $this->assertFalse(get_option(Health::OPTION));
        $this->assertSame('recommended', Health::test()['status']);
    }

    public function test_filter_returns_data_untouched_when_nothing_applies(): void
    {
        $data = ['post_status' => 'draft', 'post_type' => 'post', 'post_name' => '', 'post_title' => 'x', 'post_content' => '', 'post_excerpt' => '', 'post_parent' => 0];

        $this->assertSame($data, Shortener::filter($data, []));
    }
}
