<?php
/**
 * Which region ends up in the prompt.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper\Tests\Integration;

final class RegionTest extends \WP_UnitTestCase
{
    use FakesOpenAi;

    private int $roosendaal;
    private int $putte;

    public function set_up(): void
    {
        parent::set_up();
        $this->roosendaal = self::factory()->term->create(['taxonomy' => 'regio', 'name' => 'Roosendaal']);
        $this->putte      = self::factory()->term->create(['taxonomy' => 'regio', 'name' => 'Putte']);
        $this->fakeOpenAi('garagebrand-putte-roosendaal');
    }

    public function tear_down(): void
    {
        unset($_POST['post_ID'], $_POST['yoast_wpseo_primary_regio_term']);
        parent::tear_down();
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

    public function test_regions_removed_in_the_block_editor_save_are_not_used(): void
    {
        $this->restPublish($this->draft([$this->putte]), []);

        $this->assertStringContainsString('PLAATS: zet de plaats uit de titel', $this->prompt(0));
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

        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('PLAATS: zet de plaats uit de titel', $this->prompt(0));
    }

    public function test_post_saved_by_a_hook_during_a_rest_save_does_not_get_its_regions(): void
    {
        $this->answers('garagebrand-putte-roosendaal', 'ander-bericht-putte-roosendaal');
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

        $this->assertCount(2, $this->requests);
        $this->assertStringContainsString('PLAATS: Putte.', $this->prompt(0));
        $this->assertStringContainsString('PLAATS: zet de plaats uit de titel', $this->prompt(1));
    }

    public function test_post_inserted_before_the_rest_post_does_not_take_its_regions(): void
    {
        $this->answers('garagebrand-putte-roosendaal', 'ander-bericht-putte-roosendaal');
        $id     = $this->draft();
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

        $this->assertCount(2, $this->requests);
        $this->assertStringContainsString('PLAATS: zet de plaats uit de titel', $this->prompt(0));
        $this->assertStringContainsString('PLAATS: Roosendaal.', $this->prompt(1));
    }

    public function test_regions_of_a_failed_rest_save_are_not_reused_by_a_later_save(): void
    {
        $id = $this->draft();
        add_filter('wp_insert_post_empty_content', '__return_true');
        $this->restPublish($id, [$this->putte]);
        remove_filter('wp_insert_post_empty_content', '__return_true');
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('PLAATS: zet de plaats uit de titel', $this->prompt(0));
    }

    public function test_region_picked_in_the_classic_editor_save(): void
    {
        $id = $this->draft([$this->putte]);
        wp_update_post(['ID' => $id, 'post_status' => 'publish', 'tax_input' => ['regio' => ['0', (string) $this->roosendaal]]]);

        $this->assertStringContainsString('PLAATS: Roosendaal.', $this->prompt(0));
    }

    public function test_yoast_primary_region_wins_when_it_is_still_picked(): void
    {
        $id = $this->draft([$this->roosendaal, $this->putte]);
        update_post_meta($id, '_yoast_wpseo_primary_regio', (string) $this->roosendaal);
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertStringContainsString('PLAATS: Roosendaal.', $this->prompt(0));
    }

    public function test_yoast_primary_region_is_ignored_when_it_is_no_longer_picked(): void
    {
        $id = $this->draft([$this->putte]);
        update_post_meta($id, '_yoast_wpseo_primary_regio', (string) $this->putte);
        $this->restPublish($id, [$this->roosendaal]);

        $this->assertStringContainsString('PLAATS: Roosendaal.', $this->prompt(0));
    }

    public function test_yoast_primary_region_picked_in_the_classic_editor_save_wins(): void
    {
        $id = $this->draft([$this->roosendaal, $this->putte]);
        update_post_meta($id, '_yoast_wpseo_primary_regio', (string) $this->putte);
        $_POST['post_ID']                        = (string) $id;
        $_POST['yoast_wpseo_primary_regio_term'] = (string) $this->roosendaal;
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertStringContainsString('PLAATS: Roosendaal.', $this->prompt(0));
    }

    public function test_yoast_primary_region_cleared_in_the_classic_editor_save_uses_the_first_region(): void
    {
        $id = $this->draft([$this->roosendaal, $this->putte]);
        update_post_meta($id, '_yoast_wpseo_primary_regio', (string) $this->roosendaal);
        $_POST['post_ID']                        = (string) $id;
        $_POST['yoast_wpseo_primary_regio_term'] = '';
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertStringContainsString('PLAATS: Putte.', $this->prompt(0));
    }

    public function test_classic_editor_form_of_another_post_keeps_the_saved_yoast_primary_region(): void
    {
        $id = $this->draft([$this->roosendaal, $this->putte]);
        update_post_meta($id, '_yoast_wpseo_primary_regio', (string) $this->roosendaal);
        $_POST['post_ID']                        = (string) ($id + 1);
        $_POST['yoast_wpseo_primary_regio_term'] = (string) $this->putte;
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);

        $this->assertStringContainsString('PLAATS: Roosendaal.', $this->prompt(0));
    }

    public function test_slug_without_the_region_as_a_whole_word_is_asked_again_once(): void
    {
        $this->answers('garagebrand', 'garagebrand-putte', 'garagebrand-puttenaar', 'garage-uitgebrand');
        $first  = $this->restPublish($this->draft([$this->putte]), null);
        $second = $this->restPublish($this->draft([$this->putte]), null);

        $this->assertStringContainsString('- garagebrand (plaats ontbreekt)', $this->prompt(1));
        $this->assertSame('garagebrand-putte', $first->get_data()['slug']);
        $this->assertSame('garage-uitgebrand', $second->get_data()['slug']);
    }

    public function test_region_of_several_words_is_found(): void
    {
        $bergen = self::factory()->term->create(['taxonomy' => 'regio', 'name' => 'Bergen op Zoom']);
        $this->answers('woningbouw-versnellen-bergen-op-zoom');
        $response = $this->restPublish($this->draft([$bergen]), null);

        $this->assertStringContainsString('PLAATS: Bergen op Zoom.', $this->prompt(0));
        $this->assertSame('woningbouw-versnellen-bergen-op-zoom', $response->get_data()['slug']);
    }

    public function test_entities_in_title_excerpt_and_region_reach_the_model_as_plain_text(): void
    {
        $region = self::factory()->term->create(['taxonomy' => 'regio', 'name' => 'Halderberge & Rucphen']);
        $this->assertSame('Halderberge &amp; Rucphen', get_term($region)->name);
        $id = self::factory()->post->create(['post_title' => 'Kaas &amp; wijn in Putte', 'post_excerpt' => 'Het &#039;proeflokaal&#039; opent.', 'post_status' => 'draft']);
        wp_set_post_terms($id, [$region], 'regio');
        $this->answers('kaas-wijn-halderberge-rucphen');
        $this->restPublish($id, null);

        $this->assertStringContainsString("TITEL: Kaas & wijn in Putte\n", $this->prompt(0));
        $this->assertStringContainsString("SAMENVATTING: Het 'proeflokaal' opent.\n", $this->prompt(0));
        $this->assertStringContainsString('PLAATS: Halderberge & Rucphen.', $this->prompt(0));
        $this->assertSame('kaas-wijn-halderberge-rucphen', get_post_field('post_name', $id));
        $this->assertCount(1, $this->requests);
    }
}
