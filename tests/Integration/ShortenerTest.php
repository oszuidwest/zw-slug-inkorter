<?php
/**
 * When a post gets a short slug, and which one.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

namespace ZWSlugInkorter\Tests\Integration;

use ZWSlugInkorter\Health;
use ZWSlugInkorter\Shortener;

final class ShortenerTest extends \WP_UnitTestCase
{
    use FakesOpenAi;

    public function set_up(): void
    {
        parent::set_up();
        $this->fakeOpenAi('brand-verwoest-garage-putte');
    }

    private function publish(array $args = []): \WP_Post
    {
        $id = self::factory()->post->create($args + ['post_title' => 'Brand verwoest garage aan de Kerkstraat in Putte, niemand gewond', 'post_status' => 'publish']);
        return get_post($id);
    }

    public function test_published_post_gets_the_short_slug(): void
    {
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-putte', $post->post_name);
        $this->assertCount(1, $this->requests);
        $this->assertSame('Bearer test-key', $this->requests[0]['args']['headers']['Authorization']);
        $this->assertStringContainsString('TITEL: Brand verwoest garage aan de Kerkstraat in Putte, niemand gewond', $this->prompt());
    }

    public function test_title_with_quotes_reaches_openai_unslashed(): void
    {
        $this->publish(['post_title' => 'Wethouder: "Dit is een \'flinke\' tegenvaller"']);

        $this->assertStringContainsString('TITEL: Wethouder: "Dit is een \'flinke\' tegenvaller"', $this->prompt());
    }

    public function test_excerpt_is_sent_when_there_is_one(): void
    {
        $this->publish(['post_excerpt' => 'Een garage in Putte is uitgebrand.', 'post_content' => '<!-- wp:paragraph --><p>Lange tekst</p><!-- /wp:paragraph -->']);

        $this->assertStringContainsString('SAMENVATTING: Een garage in Putte is uitgebrand.', $this->prompt());
    }

    public function test_without_excerpt_the_text_is_sent_without_blocks_tags_or_shortcodes(): void
    {
        add_shortcode('kader', static fn (): string => '');
        $this->publish(['post_excerpt' => '', 'post_content' => '<!-- wp:paragraph --><p>De <strong>brandweer</strong> kwam snel.</p><!-- /wp:paragraph -->[kader]Info[/kader]']);

        $this->assertStringContainsString("SAMENVATTING: De brandweer kwam snel.\n", $this->prompt());
    }

    public function test_draft_is_left_alone(): void
    {
        $post = $this->publish(['post_status' => 'draft']);

        $this->assertSame('', $post->post_name);
        $this->assertSame([], $this->requests);
    }

    public function test_draft_gets_the_short_slug_when_it_is_published(): void
    {
        $post = $this->publish(['post_status' => 'draft']);
        wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);

        $this->assertSame('brand-verwoest-garage-putte', get_post($post->ID)->post_name);
        $this->assertCount(1, $this->requests);
    }

    public function test_scheduled_post_gets_the_short_slug_and_keeps_it_when_cron_publishes(): void
    {
        $post = $this->publish(['post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        $this->assertSame('future', $post->post_status);
        $this->assertSame('brand-verwoest-garage-putte', $post->post_name);

        wp_publish_post($post->ID);

        $this->assertSame('publish', get_post($post->ID)->post_status);
        $this->assertSame('brand-verwoest-garage-putte', get_post($post->ID)->post_name);
        $this->assertCount(1, $this->requests);
    }

    public function test_editing_a_published_post_keeps_its_slug(): void
    {
        $post = $this->publish();
        wp_update_post(['ID' => $post->ID, 'post_title' => 'Nieuwe titel']);

        $this->assertSame('brand-verwoest-garage-putte', get_post($post->ID)->post_name);
        $this->assertCount(1, $this->requests);
    }

    public function test_clearing_the_slug_of_a_published_post_does_not_ask_openai(): void
    {
        $post = $this->publish();
        wp_update_post(['ID' => $post->ID, 'post_name' => '']);

        $this->assertCount(1, $this->requests);
    }

    public function test_rescheduling_does_not_ask_again(): void
    {
        $post = $this->publish(['post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        wp_update_post(['ID' => $post->ID, 'post_date' => gmdate('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS)]);

        $this->assertCount(1, $this->requests);
    }

    public function test_slug_typed_by_the_editor_stays(): void
    {
        $post = $this->publish(['post_name' => 'eigen-slug']);

        $this->assertSame('eigen-slug', $post->post_name);
        $this->assertSame([], $this->requests);
    }

    public function test_slug_from_an_earlier_publication_stays(): void
    {
        $post = $this->publish();
        wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
        $this->answer('iets-anders');
        wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);

        $this->assertSame('brand-verwoest-garage-putte', get_post($post->ID)->post_name);
        $this->assertCount(1, $this->requests);
    }

    public function test_direct_insert_does_not_replace_the_slug_of_a_post_that_was_public_before(): void
    {
        $post = $this->publish(['post_name' => 'eigen-slug']);
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

    public function test_slug_that_stays_taken_gives_the_title_slug_instead_of_a_number(): void
    {
        $this->publish();
        $second = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $second->post_name);
        $this->assertCount(3, $this->requests);
        $this->assertFalse(get_option(Health::OPTION));
    }

    public function test_slug_taken_by_a_scheduled_post_counts_as_taken(): void
    {
        $this->publish(['post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        $second = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $second->post_name);
    }

    public function test_too_long_slug_is_asked_again(): void
    {
        $long = 'brand-verwoest-garage-aan-de-kerkstraat-in-putte-vannacht';
        $this->answers($long, 'brand-verwoest-garage-putte');
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-putte', $post->post_name);
        $this->assertStringContainsString("- {$long} (te lang, meer dan 50 tekens)", $this->prompt(1));
    }

    public function test_slug_of_exactly_the_maximum_length_is_used(): void
    {
        $slug = str_repeat('a', 25) . '-' . str_repeat('b', 24);
        $this->answers($slug);

        $this->assertSame($slug, $this->publish()->post_name);
        $this->assertCount(1, $this->requests);
    }

    public function test_slug_one_character_over_the_maximum_is_asked_again(): void
    {
        $this->answers(str_repeat('a', 25) . '-' . str_repeat('b', 25), 'brand-verwoest-garage-putte');

        $this->assertSame('brand-verwoest-garage-putte', $this->publish()->post_name);
        $this->assertCount(2, $this->requests);
    }

    public function test_title_slug_fallback_still_clears_an_old_failure(): void
    {
        update_option(Health::OPTION, ['time' => time(), 'message' => 'kapot'], false);
        $this->answer('brand-verwoest-garage-aan-de-kerkstraat-in-putte-vannacht');
        $this->publish();

        $this->assertFalse(get_option(Health::OPTION));
    }

    public function test_too_long_twice_gives_the_title_slug(): void
    {
        $this->answer('brand-verwoest-garage-aan-de-kerkstraat-in-putte-vannacht');
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertCount(2, $this->requests);
    }

    public function test_without_a_region_the_place_comes_from_the_title(): void
    {
        $this->publish();

        $this->assertStringContainsString('PLAATS: zet de plaats uit de titel of samenvatting in de slug', $this->prompt());
        $this->assertStringNotContainsString('niet gebruiken', $this->prompt());
    }

    public function test_post_types_without_a_public_url_are_skipped(): void
    {
        register_post_type('zw_intern', ['public' => false]);
        $this->publish(['post_type' => 'zw_intern']);

        $this->assertSame([], $this->requests);
    }

    public function test_page_gets_the_short_slug_too(): void
    {
        $post = $this->publish(['post_type' => 'page']);

        $this->assertSame('brand-verwoest-garage-putte', $post->post_name);
    }

    public function test_api_error_keeps_the_title_slug_and_shows_in_site_health(): void
    {
        $this->answer('The project you are requesting has been archived', 401);
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $test = Health::test();
        $this->assertSame('critical', $test['status']);
        $this->assertStringContainsString('Unauthorized (401) - The project you are requesting has been archived', $test['description']);

        $this->answer('brand-verwoest-garage-putte');
        $this->publish();
        $this->assertSame('good', Health::test()['status']);
        $this->assertFalse(get_option(Health::OPTION));
    }

    public function test_timeout_keeps_the_title_slug_and_is_recorded(): void
    {
        $this->reply = new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        $post        = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertStringContainsString('cURL error 28: Operation timed out', get_option(Health::OPTION)['message']);
    }

    public function test_malformed_answer_keeps_the_title_slug(): void
    {
        $this->reply = self::json(200, ['output' => []]);
        $post        = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertIsArray(get_option(Health::OPTION));
    }

    public function test_answer_without_usable_slug_keeps_the_title_slug(): void
    {
        $this->answer('  "---"  ');
        $post        = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertSame('Het AI-model gaf geen bruikbare slug.', get_option(Health::OPTION)['message']);
    }

    public function test_request_uses_the_preferred_model_and_a_short_timeout(): void
    {
        $this->publish();
        $body = $this->requests[0]['body'];

        $this->assertSame('gpt-5.4-mini', $body['model']);
        $this->assertStringContainsString('eindredacteur', $body['instructions']);
        $this->assertSame(300, $body['max_output_tokens']);
        $this->assertEquals(8, $this->requests[0]['args']['timeout']);
        $this->assertStringContainsString('nooit meer dan 50', $this->prompt());
    }

    public function test_without_a_text_model_the_title_slug_stays_and_is_reported(): void
    {
        $this->reply = self::json(500, ['error' => ['message' => 'not used']]);
        remove_filter('pre_http_request', [$this, 'interceptOpenAi'], 10);
        add_filter('pre_http_request', fn ($pre, array $args, string $url) => $url === 'https://api.openai.com/v1/models' ? self::json(200, ['data' => []]) : $pre, 10, 3);
        wp_cache_flush();
        $post = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertStringContainsString('No models found', get_option(Health::OPTION)['message']);
    }

    public function test_falls_back_to_another_text_model_without_the_preferred_one(): void
    {
        remove_filter('pre_http_request', [$this, 'interceptOpenAi'], 10);
        add_filter('pre_http_request', fn ($pre, array $args, string $url) => $url === 'https://api.openai.com/v1/models' ? self::json(200, ['data' => [['id' => 'gpt-4.1']]]) : $this->interceptOpenAi($pre, $args, $url), 10, 3);
        wp_cache_flush();
        $post = $this->publish();

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
