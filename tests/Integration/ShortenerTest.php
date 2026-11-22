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
    /** @var list<array{url: string, args: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, mixed>|\WP_Error Next response OpenAI gives. */
    private array|\WP_Error $reply = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->requests = [];
        $this->answer('brand-verwoest-garage-putte');
        add_filter('pre_http_request', [$this, 'fakeOpenAi'], 10, 3);
    }

    /**
     * @param false|array<string, mixed> $pre
     * @param array<string, mixed>       $args
     * @return array<string, mixed>|\WP_Error
     */
    public function fakeOpenAi($pre, array $args, string $url): array|\WP_Error
    {
        $this->requests[] = ['url' => $url, 'args' => $args];
        return $this->reply;
    }

    private function answer(string $content, int $code = 200): void
    {
        $body        = $code === 200
            ? ['choices' => [['message' => ['content' => $content]]]]
            : ['error' => ['message' => $content]];
        $this->reply = ['response' => ['code' => $code, 'message' => ''], 'headers' => [], 'body' => (string) wp_json_encode($body), 'cookies' => []];
    }

    private function prompt(int $index = 0): string
    {
        $body = json_decode((string) $this->requests[$index]['args']['body'], true);
        return (string) $body['messages'][1]['content'];
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
        $this->assertSame('https://api.openai.com/v1/chat/completions', $this->requests[0]['url']);
        $this->assertSame('Bearer sk-test', $this->requests[0]['args']['headers']['Authorization']);
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

        $this->assertStringContainsString('EXCERPT: Een garage in Putte is uitgebrand.', $this->prompt());
    }

    public function test_without_excerpt_the_text_is_sent_without_blocks_tags_or_shortcodes(): void
    {
        add_shortcode('kader', static fn (): string => '');
        $this->publish(['post_excerpt' => '', 'post_content' => '<!-- wp:paragraph --><p>De <strong>brandweer</strong> kwam snel.</p><!-- /wp:paragraph -->[kader]Info[/kader]']);

        $this->assertStringContainsString("EXCERPT: De brandweer kwam snel.\n", $this->prompt());
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

    public function test_slug_is_made_unique(): void
    {
        $first  = $this->publish();
        $second = $this->publish();

        $this->assertSame('brand-verwoest-garage-putte', $first->post_name);
        $this->assertSame('brand-verwoest-garage-putte-2', $second->post_name);
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
        $this->assertStringContainsString('OpenAI gaf HTTP 401. The project you are requesting has been archived', $test['description']);

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
        $this->assertSame('cURL error 28: Operation timed out', get_option(Health::OPTION)['message']);
    }

    public function test_unexpected_answer_keeps_the_title_slug(): void
    {
        $this->reply = ['response' => ['code' => 200, 'message' => ''], 'headers' => [], 'body' => '{"choices":[]}', 'cookies' => []];
        $post        = $this->publish();

        $this->assertSame('brand-verwoest-garage-aan-de-kerkstraat-in-putte-niemand-gewond', $post->post_name);
        $this->assertSame('OpenAI gaf geen bruikbare slug.', get_option(Health::OPTION)['message']);
    }

    public function test_request_is_shaped_for_chat_completions(): void
    {
        $this->publish();
        $body = json_decode((string) $this->requests[0]['args']['body'], true);

        $this->assertSame('gpt-4.1-mini', $body['model']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame(100, $body['max_completion_tokens']);
        $this->assertSame(8, $this->requests[0]['args']['timeout']);
        $this->assertStringContainsString('MAXIMUM: 50 tekens', $this->prompt());
    }

    public function test_filter_returns_data_untouched_when_nothing_applies(): void
    {
        $data = ['post_status' => 'draft', 'post_type' => 'post', 'post_name' => '', 'post_title' => 'x', 'post_content' => '', 'post_excerpt' => '', 'post_parent' => 0];

        $this->assertSame($data, Shortener::filter($data, []));
    }
}
