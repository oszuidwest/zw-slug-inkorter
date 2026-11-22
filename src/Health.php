<?php
/**
 * Site Health check, so a broken key does not go unnoticed.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

namespace ZWSlugInkorter;

/**
 * Keeps the last failure until the next slug is made.
 */
final class Health
{
    public const OPTION = 'zw_slug_inkorter_last_error';

    /**
     * @param string $message What went wrong.
     */
    public static function recordFailure(string $message): void
    {
        update_option(self::OPTION, ['time' => time(), 'message' => $message], false);
        error_log('zw-slug-inkorter: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Also in the server log.
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
        $tests['direct']['zw_slug_inkorter'] = [
            'label' => __('Slug Inkorter', 'zw-slug-inkorter'),
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
            'label'       => __('Slug Inkorter maakt korte slugs', 'zw-slug-inkorter'),
            'status'      => 'good',
            'badge'       => ['label' => __('Slug Inkorter', 'zw-slug-inkorter'), 'color' => 'blue'],
            'description' => '<p>' . esc_html__('Nieuwe berichten krijgen bij publiceren of inplannen een korte slug.', 'zw-slug-inkorter') . '</p>',
            'actions'     => '',
            'test'        => 'zw_slug_inkorter',
        ];

        if (Generator::apiKey() === '') {
            $result['status']      = 'critical';
            $result['label']       = __('Slug Inkorter heeft geen OpenAI-sleutel', 'zw-slug-inkorter');
            $result['description'] = '<p>' . esc_html__('Zet ZW_SLUG_INKORTER_API_KEY in wp-config.php. Tot die tijd houden berichten de lange slug van de titel.', 'zw-slug-inkorter') . '</p>';
            return $result;
        }

        $error = get_option(self::OPTION);
        if (is_array($error)) {
            $result['status']      = 'critical';
            $result['label']       = __('Slug Inkorter kon de laatste slug niet maken', 'zw-slug-inkorter');
            $result['description'] = '<p>' . esc_html(sprintf(
                /* translators: 1: date and time, 2: error message */
                __('Op %1$s ging het mis: %2$s Het bericht hield de lange slug van de titel.', 'zw-slug-inkorter'),
                wp_date('j F Y H:i', (int) ($error['time'] ?? 0)),
                (string) ($error['message'] ?? '')
            )) . '</p>';
        }

        return $result;
    }
}
