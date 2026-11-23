<?php
/**
 * Finds the region a post is about.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper;

/**
 * Terms are saved after wp_insert_post_data, so the regions picked in this save come with the post input itself.
 */
final class Region
{
    public const TAXONOMY = 'regio';

    /** Post input key that carries the regions of a REST save to that post's own insert. */
    private const REST_KEY = 'zw_slug_sniper_rest_regions';

    /**
     * Copy the regions of a block editor save onto the post input. Runs late on init, after the theme registered the taxonomy.
     */
    public static function listenToRest(): void
    {
        $taxonomy = get_taxonomy(self::TAXONOMY);
        if (!$taxonomy || !$taxonomy->show_in_rest) {
            return;
        }

        $param = is_string($taxonomy->rest_base) && !empty($taxonomy->rest_base) ? $taxonomy->rest_base : $taxonomy->name;
        foreach ($taxonomy->object_type as $type) {
            add_filter(
                "rest_pre_insert_{$type}",
                static function ($prepared, \WP_REST_Request $request) use ($param) {
                    if ($prepared instanceof \stdClass) {
                        $prepared->{self::REST_KEY} = $request->get_param($param);
                    }
                    return $prepared;
                },
                10,
                2
            );
        }
    }

    /**
     * The Yoast primary region if it is still picked, else the first region.
     *
     * A REST save carries its regions under REST_KEY; the classic editor form carries tax_input and, with its post_ID, the
     * Yoast pick. The block editor sends the Yoast pick after the publish request, so the saved one is read then.
     *
     * @param int                  $postId  Post ID, 0 for a new post.
     * @param array<string, mixed> $postarr Slashed post input.
     */
    public static function forPost(int $postId, array $postarr): ?string
    {
        $sent    = $postarr[self::REST_KEY] ?? $postarr['tax_input'][self::TAXONOMY] ?? null;
        $ids     = self::ids($sent ?? ($postId > 0 ? wp_get_post_terms($postId, self::TAXONOMY, ['fields' => 'ids']) : []));
        $field   = 'yoast_wpseo_primary_' . self::TAXONOMY . '_term';
        $form    = isset($postarr[$field]) && (int) ($postarr['post_ID'] ?? 0) === $postId;
        $primary = (int) ($form ? $postarr[$field] : get_post_meta($postId, '_yoast_wpseo_primary_' . self::TAXONOMY, true));
        $term    = get_term(in_array($primary, $ids, true) ? $primary : ($ids[0] ?? 0), self::TAXONOMY);
        return $term instanceof \WP_Term ? html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    /**
     * @param mixed $value Term IDs as an array or comma-separated string; anything else, such as a WP_Error, gives none.
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        return is_array($value) || is_string($value) ? array_values(array_filter(wp_parse_id_list($value))) : [];
    }
}
