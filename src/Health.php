<?php
/**
 * Site Health check, so a broken key does not go unnoticed.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper;

/**
 * Keeps the last failure until the next slug is made. The Site Health test only
 * reads that failure and never calls the AI provider.
 */
final class Health
{
    public const OPTION = 'zw_slug_sniper_last_error';

    /**
     * @param string $message What went wrong.
     */
    public static function recordFailure(string $message): void
    {
        update_option(self::OPTION, ['time' => time(), 'message' => $message], false);
        error_log('zw-slug-sniper: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Also in the server log.
    }

    /**
     * Clear the failure after a slug was made.
     */
    public static function recordSuccess(): void
    {
        if (get_option(self::OPTION) !== false) {
            delete_option(self::OPTION);
        }
    }

    /**
     * @param array<string, mixed> $tests Site Health tests.
     * @return array<string, mixed>
     */
    public static function register(array $tests): array
    {
        $tests['direct']['zw_slug_sniper'] = [
            'label' => __('Slug Sniper', 'zw-slug-sniper'),
            'test'  => [self::class, 'test'],
        ];
        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public static function test(): array
    {
        $result = [
            'label'       => __('Slug Sniper meldt geen fouten', 'zw-slug-sniper'),
            'status'      => 'good',
            'badge'       => ['label' => __('Slug Sniper', 'zw-slug-sniper'), 'color' => 'blue'],
            'description' => '<p>' . esc_html__('De laatste poging bij de AI-provider gaf geen fout, of er was nog geen poging. Of de provider nu werkt, blijkt pas bij het volgende nieuwe bericht dat wordt gepubliceerd of ingepland.', 'zw-slug-sniper') . '</p>',
            'actions'     => '',
            'test'        => 'zw_slug_sniper',
        ];

        if (!wp_supports_ai()) {
            $result['status']      = 'recommended';
            $result['label']       = __('Slug Sniper staat uit, want AI staat uit op deze site', 'zw-slug-sniper');
            $result['description'] = '<p>' . esc_html__('Berichten houden de lange slug van de titel.', 'zw-slug-sniper') . '</p>';
            return $result;
        }

        $error = get_option(self::OPTION);
        if (is_array($error)) {
            $result['status']      = 'critical';
            $result['label']       = __('Slug Sniper kon de laatste slug niet maken', 'zw-slug-sniper');
            $result['description'] = '<p>' . esc_html(sprintf(
                /* translators: 1: date and time, 2: error message */
                __('Op %1$s ging het mis: %2$s Het bericht hield de lange slug van de titel.', 'zw-slug-sniper'),
                wp_date('j F Y H:i', (int) ($error['time'] ?? 0)),
                (string) ($error['message'] ?? '')
            )) . '</p>';
            $result['actions']     = '<p><a href="' . esc_url(admin_url('options-connectors.php')) . '">' . esc_html__('Naar Connectors', 'zw-slug-sniper') . '</a></p>';
        }

        return $result;
    }
}
