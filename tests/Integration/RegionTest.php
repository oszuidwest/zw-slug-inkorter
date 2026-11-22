<?php
/**
 * Which region ends up in the prompt.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

namespace ZWSlugInkorter\Tests\Integration;

final class RegionTest extends \WP_UnitTestCase
{
    /** @var list<string> */
    private array $prompts = [];

    private int $roosendaal;
    private int $putte;

    public function set_up(): void
    {
        parent::set_up();
        $this->prompts    = [];
        $this->roosendaal = self::factory()->term->create(['taxonomy' => 'regio', 'name' => 'Roosendaal']);
        $this->putte      = self::factory()->term->create(['taxonomy' => 'regio', 'name' => 'Putte']);
        add_filter('pre_http_request', function ($pre, array $args): array {
            $this->prompts[] = (string) json_decode((string) $args['body'], true)['messages'][1]['content'];
            return ['response' => ['code' => 200, 'message' => ''], 'headers' => [], 'body' => '{"choices":[{"message":{"content":"korte-slug"}}]}', 'cookies' => []];
        }, 10, 2);
    }

    private function draft(array $regions = []): int
    {
        $id = self::factory()->post->create(['post_title' => 'Brand in een garage', 'post_status' => 'draft']);
        wp_set_post_terms($id, $regions, 'regio');
        return $id;
    }

    private function restPublish(int $id, ?array $regions): \WP_REST_Response
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $request = new \WP_REST_Request('POST', '/wp/v2/posts/' . $id);
        $request->set_param('status', 'publish');
        if ($regions !== null) {
            $request->set_param('regio', $regions);
        }
        return rest_do_request($request);
    }

    public function test_region_picked_in_the_block_editor_save(): void
    {
        $response = $this->restPublish($this->draft(), [$this->roosendaal]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('korte-slug', $response->get_data()['slug']);
        $this->assertStringContainsString('REGIO: "Roosendaal"', $this->prompts[0]);
    }

    public function test_block_editor_save_without_regions_uses_the_saved_region(): void
    {
        $this->restPublish($this->draft([$this->putte]), null);

        $this->assertStringContainsString('REGIO: "Putte"', $this->prompts[0]);
    }

    public function test_regions_removed_in_the_block_editor_save_are_not_used(): void
    {
        $this->restPublish($this->draft([$this->putte]), []);

        $this->assertStringNotContainsString('REGIO:', $this->prompts[0]);
    }

    public function test_new_post_created_through_rest_uses_its_regions(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $request = new \WP_REST_Request('POST', '/wp/v2/posts');
        $request->set_body_params(['title' => 'Brand in een garage', 'status' => 'publish', 'regio' => [$this->putte]]);
        rest_do_request($request);

        $this->assertStringContainsString('REGIO: "Putte"', $this->prompts[0]);
    }

    public function test_regions_of_an_earlier_rest_save_in_the_same_request_are_not_reused(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $request = new \WP_REST_Request('POST', '/batch/v1');
        $request->set_body_params(['requests' => [
            ['path' => '/wp/v2/posts', 'body' => ['title' => 'Concept', 'status' => 'draft', 'regio' => [$this->putte]]],
            ['path' => '/wp/v2/posts', 'body' => ['title' => 'Brand in een garage', 'status' => 'publish']],
        ]]);
        rest_do_request($request);

        $this->assertCount(1, $this->prompts);
        $this->assertStringNotContainsString('REGIO:', $this->prompts[0]);
    }

    public function test_post_saved_by_a_hook_during_a_rest_save_does_not_get_its_regions(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $hook = static function (int $id, \WP_Post $post): void {
            if ($post->post_title === 'Brand in een garage') {
                wp_insert_post(['post_title' => 'Ander bericht', 'post_content' => 'Tekst', 'post_status' => 'publish']);
            }
        };
        add_action('save_post_post', $hook, 10, 2);
        $request = new \WP_REST_Request('POST', '/wp/v2/posts');
        $request->set_body_params(['title' => 'Brand in een garage', 'status' => 'publish', 'regio' => [$this->putte]]);
        rest_do_request($request);
        remove_action('save_post_post', $hook, 10);

        $this->assertCount(2, $this->prompts);
        $this->assertStringContainsString('REGIO: "Putte"', $this->prompts[0]);
        $this->assertStringNotContainsString('REGIO:', $this->prompts[1]);
    }

    public function test_post_inserted_before_the_rest_post_does_not_take_its_regions(): void
    {
        $id     = $this->draft([$this->putte]);
        $nested = static function (array $data, array $postarr) use ($id): array {
            static $done = false;
            if (!$done && (int) ($postarr['ID'] ?? 0) === $id) {
                $done = true;
                wp_insert_post(['post_title' => 'Ander bericht', 'post_content' => 'Tekst', 'post_status' => 'publish']);
            }
            return $data;
        };
        add_filter('wp_insert_post_data', $nested, 10, 2);
        $this->restPublish($id, [$this->roosendaal]);
        remove_filter('wp_insert_post_data', $nested, 10);

        $this->assertCount(2, $this->prompts);
        $this->assertStringNotContainsString('REGIO:', $this->prompts[0]);
        $this->assertStringContainsString('REGIO: "Roosendaal"', $this->prompts[1]);
    }

    public function test_regions_of_a_failed_rest_save_are_not_reused_by_a_later_save(): void
    {
        $id = $this->draft();
        add_filter('wp_insert_post_empty_content', '__return_true');
        $this->restPublish($id, [$this->putte]);
        remove_filter('wp_insert_post_empty_content', '__return_true');
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertCount(1, $this->prompts);
        $this->assertStringNotContainsString('REGIO:', $this->prompts[0]);
    }

    public function test_region_picked_in_the_classic_editor_save(): void
    {
        $id = $this->draft([$this->putte]);
        wp_update_post(['ID' => $id, 'post_status' => 'publish', 'tax_input' => ['regio' => ['0', (string) $this->roosendaal]]]);

        $this->assertStringContainsString('REGIO: "Roosendaal"', $this->prompts[0]);
    }

    public function test_yoast_primary_region_wins_when_it_is_still_picked(): void
    {
        $id = $this->draft([$this->roosendaal, $this->putte]);
        update_post_meta($id, '_yoast_wpseo_primary_regio', (string) $this->putte);
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertStringContainsString('REGIO: "Putte"', $this->prompts[0]);
    }

    public function test_yoast_primary_region_is_ignored_when_it_is_no_longer_picked(): void
    {
        $id = $this->draft([$this->putte]);
        update_post_meta($id, '_yoast_wpseo_primary_regio', (string) $this->putte);
        $this->restPublish($id, [$this->roosendaal]);

        $this->assertStringContainsString('REGIO: "Roosendaal"', $this->prompts[0]);
    }

    public function test_no_region_leaves_the_rule_out(): void
    {
        wp_update_post(['ID' => $this->draft(), 'post_status' => 'publish']);

        $this->assertStringNotContainsString('REGIO:', $this->prompts[0]);
    }
}
