<?php
/**
 * Finds the region a post is about.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper;

/**
 * Terms are saved after wp_insert_post_data, so the regions picked in this save come from the request itself.
 */
final class Region
{
    public const TAXONOMY = 'regio';

    /** @var array{id: int, ids: list<int>|null}|null Regions sent in the current REST save, for post id (0 for a new post); null ids when none were sent. */
    private static ?array $pending = null;

    /**
     * Remember the regions in block editor saves. Runs late on init, after the theme registered the taxonomy.
     */
    public static function listenToRest(): void
    {
        $taxonomy = get_taxonomy(self::TAXONOMY);
        if (!$taxonomy || !$taxonomy->show_in_rest) {
            return;
        }

        // Also forget them when the REST call ends, in case it failed before the insert.
        add_filter(
            'rest_request_after_callbacks',
            static function ($response) {
                self::$pending = null;
                return $response;
            }
        );

        $param = is_string($taxonomy->rest_base) && !empty($taxonomy->rest_base) ? $taxonomy->rest_base : $taxonomy->name;
        foreach ($taxonomy->object_type as $type) {
            add_filter(
                "rest_pre_insert_{$type}",
                static function ($prepared, \WP_REST_Request $request) use ($param) {
                    if ($prepared instanceof \stdClass) {
                        self::$pending = [
                            'id'  => (int) ($prepared->ID ?? 0),
                            'ids' => $request->has_param($param) ? self::ids($request->get_param($param)) : null,
                        ];
                    }
                    return $prepared;
                },
                10,
                2
            );
        }
    }

    /**
     * Regions sent in the REST save of this post, or null. Only that post takes them: another plugin may insert a
     * different post in between.
     *
     * @param int $postId Post ID, 0 for a new post.
     * @return list<int>|null
     */
    public static function takeRestTerms(int $postId): ?array
    {
        if (self::$pending === null || self::$pending['id'] !== $postId) {
            return null;
        }

        $ids           = self::$pending['ids'];
        self::$pending = null;
        return $ids;
    }

    /**
     * The Yoast primary region if it is still picked, else the first region.
     *
     * Yoast saves the primary region after this filter. The classic editor sends a new pick with the save, so that one is
     * used. The block editor sends it after the publish request, so the saved one is read then.
     *
     * @param int                  $postId    Post ID, 0 for a new post.
     * @param array<string, mixed> $postarr   Slashed post input.
     * @param list<int>|null       $restTerms Regions sent in a REST save.
     */
    public static function forPost(int $postId, array $postarr, ?array $restTerms): ?string
    {
        if (!taxonomy_exists(self::TAXONOMY)) {
            return null;
        }

        if ($restTerms !== null) {
            $ids = $restTerms;
        } elseif (is_array($postarr['tax_input'] ?? null) && array_key_exists(self::TAXONOMY, $postarr['tax_input'])) {
            $ids = self::ids($postarr['tax_input'][self::TAXONOMY]);
        } else {
            $saved = $postId > 0 ? wp_get_post_terms($postId, self::TAXONOMY, ['fields' => 'ids']) : [];
            $ids   = is_array($saved) ? self::ids($saved) : [];
        }

        if ($ids === []) {
            return null;
        }

        // Only the classic editor form sends this field with post_ID. Core checked that form's nonce before the save, and
        // the pick only chooses among the regions in $ids.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $field   = 'yoast_wpseo_primary_' . self::TAXONOMY . '_term';
        $primary = isset($_POST[$field], $_POST['post_ID']) && (int) $_POST['post_ID'] === $postId
            ? (int) filter_var(wp_unslash($_POST[$field]), FILTER_VALIDATE_INT)
            : (int) get_post_meta($postId, '_yoast_wpseo_primary_' . self::TAXONOMY, true);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $term    = get_term(in_array($primary, $ids, true) ? $primary : $ids[0], self::TAXONOMY);
        return $term instanceof \WP_Term ? $term->name : null;
    }

    /**
     * @param mixed $value Term IDs as an array or comma-separated string.
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) (is_scalar($value) ? $value : ''));
        $ids   = array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $items);
        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }
}
