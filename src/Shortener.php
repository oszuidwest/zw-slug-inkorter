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
        $postId    = (int) ($postarr['ID'] ?? 0);
        $restTerms = Region::takeRestTerms($postId);
        if (!self::applies($data, $postarr, $postId)) {
            return $data;
        }

        $content  = (string) wp_unslash((string) $data['post_content']);
        $excerpt  = (string) wp_unslash((string) $data['post_excerpt']);
        $title    = (string) wp_unslash((string) $data['post_title']);
        $summary  = $excerpt !== '' ? $excerpt : wp_trim_words(wp_strip_all_tags(strip_shortcodes($content)), 50);
        $region   = Region::forPost($postId, $postarr, $restTerms);
        $rejected = [];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $slug = Generator::generate($title, $summary, $region, $rejected);
            if (is_wp_error($slug)) {
                Health::recordFailure($slug->get_error_message());
                return $data;
            }

            $problem = self::problem($slug, $region, $data, $postId, $attempt === self::ATTEMPTS);
            if ($problem === null) {
                Health::recordSuccess();
                $data['post_name'] = $slug;
                return $data;
            }
            $rejected[] = "{$slug} ({$problem})";
        }

        // The model works, but gave no usable slug: keep the title slug WordPress made rather than add -2 to the short one.
        Health::recordSuccess();
        return $data;
    }

    /**
     * Why the slug can't be used, or null when it can.
     *
     * @param string               $slug   Slug from the model.
     * @param string|null          $region Region that should be in it.
     * @param array<string, mixed> $data   Post data about to be saved.
     * @param int                  $postId Post ID, 0 for a new post.
     * @param bool                 $last   Whether this is the last attempt; a missing place is accepted then.
     */
    private static function problem(string $slug, ?string $region, array $data, int $postId, bool $last): ?string
    {
        if (strlen($slug) > Generator::MAX_LENGTH) {
            return sprintf('te lang, meer dan %d tekens', Generator::MAX_LENGTH);
        }
        if (wp_unique_post_slug($slug, $postId, (string) $data['post_status'], (string) $data['post_type'], (int) $data['post_parent']) !== $slug) {
            return 'bestaat al';
        }
        if (!$last && $region !== null && !str_contains("-{$slug}-", '-' . sanitize_title($region) . '-')) {
            return 'plaats ontbreekt';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data    Slashed post data about to be saved.
     * @param array<string, mixed> $postarr Slashed input, merged with the saved post on updates.
     * @param int                  $postId  Post ID, 0 for a new post.
     */
    private static function applies(array $data, array $postarr, int $postId): bool
    {
        if (!in_array($data['post_status'], self::LIVE, true) || !is_post_type_viewable((string) $data['post_type']) || !wp_supports_ai()) {
            return false;
        }

        // Only a post that never had a slug. Published and scheduled posts always have one, and so has a draft
        // whose slug the editor typed or that was public before.
        $existing = $postId > 0 ? get_post($postId) : null;
        return (string) ($postarr['post_name'] ?? '') === '' && (!$existing || $existing->post_name === '');
    }
}
