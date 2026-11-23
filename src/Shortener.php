<?php
/**
 * Swaps the title slug for a short one when a post first goes live.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper;

/**
 * Hooks into wp_insert_post_data, so the short slug is in place before anything else sees the URL.
 */
final class Shortener
{
    /** Statuses that give a post its public URL. Scheduled posts count, because cron publishes them without this filter. */
    private const LIVE = ['publish', 'future'];

    /** A rejected answer goes back to the model once, with the reason. */
    private const ATTEMPTS = 2;

    /**
     * @param array<string, mixed> $data    Slashed post data about to be saved.
     * @param array<string, mixed> $postarr Slashed input, merged with the saved post on updates.
     * @return array<string, mixed>
     */
    public static function filter(array $data, array $postarr): array
    {
        $postId = (int) ($postarr['ID'] ?? 0);
        if (!self::applies($data, $postarr, $postId)) {
            return $data;
        }

        $title    = (string) wp_unslash((string) $data['post_title']);
        $excerpt  = (string) wp_unslash((string) $data['post_excerpt']);
        $summary  = wp_strip_all_tags($excerpt !== '' ? $excerpt : strip_shortcodes((string) wp_unslash((string) $data['post_content'])), true);
        $region   = Region::forPost($postId, $postarr);
        $rejected = [];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $slug = Generator::generate($title, $summary, $region, $rejected);
            if (is_wp_error($slug)) {
                Health::recordFailure($slug->get_error_message());
                return $data;
            }

            // On the last attempt a slug without the place is accepted.
            $problem = match (true) {
                strlen($slug) > Generator::MAX_LENGTH => sprintf('te lang, meer dan %d tekens', Generator::MAX_LENGTH),
                wp_unique_post_slug($slug, $postId, (string) $data['post_status'], (string) $data['post_type'], (int) $data['post_parent']) !== $slug => 'bestaat al',
                $attempt < self::ATTEMPTS && $region !== null && !str_contains("-{$slug}-", '-' . sanitize_title($region) . '-') => 'plaats ontbreekt',
                default => null,
            };
            if ($problem === null) {
                $data['post_name'] = $slug;
                break;
            }
            $rejected[] = "{$slug} ({$problem})";
        }

        // The model works. Without a usable slug the post keeps the title slug WordPress made, rather than -2 on the short one.
        Health::recordSuccess();
        return $data;
    }

    /**
     * @param array<string, mixed> $data    Slashed post data about to be saved.
     * @param array<string, mixed> $postarr Slashed input, merged with the saved post on updates.
     * @param int                  $postId  Post ID, 0 for a new post.
     */
    private static function applies(array $data, array $postarr, int $postId): bool
    {
        // Only a post that never had a slug. Published and scheduled posts always have one, and so has a draft
        // whose slug the editor typed or that was public before.
        return in_array($data['post_status'], self::LIVE, true)
            && (string) ($postarr['post_name'] ?? '') === ''
            && ($postId === 0 || get_post($postId)?->post_name === '')
            && is_post_type_viewable((string) $data['post_type'])
            && wp_supports_ai();
    }
}
