<?php
/**
 * Swaps the title slug for a short one when a post first goes live.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

namespace ZWSlugInkorter;

/**
 * Hooks into wp_insert_post_data, so the short slug is in place before anything else sees the URL.
 */
final class Shortener
{
    /** Statuses that give a post its public URL. Scheduled posts count, because cron publishes them without this filter. */
    private const LIVE = ['publish', 'future'];

    /**
     * @param array<string, mixed> $data    Slashed post data about to be saved.
     * @param array<string, mixed> $postarr Slashed input, merged with the saved post on updates.
     * @return array<string, mixed>
     */
    public static function filter(array $data, array $postarr): array
    {
        $postId    = (int) ($postarr['ID'] ?? 0);
        $restTerms = Region::takeRestTerms($postId);
        if (!self::applies($data, $postarr, $postId)) {
            return $data;
        }

        $content = (string) wp_unslash((string) $data['post_content']);
        $excerpt = (string) wp_unslash((string) $data['post_excerpt']);
        $slug    = Generator::generate(
            (string) wp_unslash((string) $data['post_title']),
            $excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags(strip_shortcodes($content)), 50),
            Region::forPost($postId, $postarr, $restTerms)
        );

        if (is_wp_error($slug)) {
            Health::recordFailure($slug->get_error_message());
            return $data;
        }

        Health::recordSuccess();
        $data['post_name'] = wp_unique_post_slug($slug, $postId, (string) $data['post_status'], (string) $data['post_type'], (int) $data['post_parent']);
        return $data;
    }

    /**
     * @param array<string, mixed> $data    Slashed post data about to be saved.
     * @param array<string, mixed> $postarr Slashed input, merged with the saved post on updates.
     * @param int                  $postId  Post ID, 0 for a new post.
     */
    private static function applies(array $data, array $postarr, int $postId): bool
    {
        if (!in_array($data['post_status'], self::LIVE, true) || !is_post_type_viewable((string) $data['post_type']) || Generator::apiKey() === '') {
            return false;
        }

        // Only a post that never had a slug. Published and scheduled posts always have one, and so has a draft
        // whose slug the editor typed or that was public before.
        $existing = $postId > 0 ? get_post($postId) : null;
        return (string) ($postarr['post_name'] ?? '') === '' && (!$existing || $existing->post_name === '');
    }
}
