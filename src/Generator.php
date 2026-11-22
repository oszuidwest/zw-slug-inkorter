<?php
/**
 * Asks OpenAI for a short slug.
 *
 * @package ZW_Slug_Inkorter
 */

declare(strict_types=1);

namespace ZWSlugInkorter;

/**
 * Set ZW_SLUG_INKORTER_API_KEY in wp-config.php; ZW_SLUG_INKORTER_MODEL is optional.
 */
final class Generator
{
    public const MAX_LENGTH = 50;

    private const ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_MODEL = 'gpt-4.1-mini';

    /** Publishing waits for the answer, so keep this short. */
    private const TIMEOUT = 8;

    private const SYSTEM = 'Je bent een native Nederlandse journalist met perfecte taalbeheersing. Je maakt URL slugs die grammaticaal correct Nederlands zijn - zoals een krantenkop. Geen kromme zinnen zoals "geen-nieuw-flexwoningen" maar natuurlijk Nederlands zoals "flexwoningen-project-stopgezet". Output is ALLEEN de slug.';

    private const PROMPT = 'Genereer een KORTE, NATUURLIJK LEESBARE URL-slug voor dit nieuwsartikel.

TITEL: %s
EXCERPT: %s

REGELS:
1. IDEAAL: 25-40 tekens, MAXIMUM: %d tekens
2. %s
3. De slug moet NATUURLIJK lezen, zoals je het nieuws kort zou omschrijven
4. Alleen a-z (lowercase), 0-9, en koppeltekens (-)
5. Samengestelde woorden met koppelteken behouden: ja-ja-stickers

VERWIJDER alle stopwoorden en hulpwerkwoorden (de, het, een, van, voor, in, op, wordt, moet, gaat, is, zijn, etc.)

NATUURLIJK ZINSVERLOOP - zoals een krantenkop:
- "woningbrand-appartement-roosendaal"
- "brand-verwoest-garage-putte"
- "fatbike-botst-op-bus-etten-leur"
- "a58-dicht-na-botsing-vrachtwagens"
- "illegaal-vuurwerk-gevonden-putte"
- "flexwoningen-project-uitgesteld-etten-leur"

VERMIJD onnatuurlijk Nederlands:
- FOUT: "geen-nieuw-flexwoningen" → GOED: "flexwoningen-project-stopgezet"
- FOUT: "meer-incidenten-moerdijk" → GOED: "reeks-incidenten-moerdijk"

De slug moet klinken als een natuurlijke Nederlandse krantenkop.

Geef ALLEEN de slug, geen uitleg of aanhalingstekens.';

    /**
     * The key from wp-config.php, or an empty string.
     */
    public static function apiKey(): string
    {
        return defined('ZW_SLUG_INKORTER_API_KEY') ? trim((string) constant('ZW_SLUG_INKORTER_API_KEY')) : '';
    }

    /**
     * @param string      $title   Post title.
     * @param string      $summary Excerpt, or the start of the text.
     * @param string|null $region  Region name that should be in the slug.
     */
    public static function generate(string $title, string $summary, ?string $region): string|\WP_Error
    {
        $regionRule = $region !== null
            ? sprintf('REGIO: "%s" - moet in de slug voorkomen, bij voorkeur vooraan maar mag ook elders als dat natuurlijker leest', $region)
            : '';

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . self::apiKey(),
                'Content-Type'  => 'application/json',
            ],
            'body'    => (string) wp_json_encode([
                'model'                 => defined('ZW_SLUG_INKORTER_MODEL') ? (string) constant('ZW_SLUG_INKORTER_MODEL') : self::DEFAULT_MODEL,
                'messages'              => [
                    ['role' => 'system', 'content' => self::SYSTEM],
                    ['role' => 'user', 'content' => sprintf(self::PROMPT, $title, $summary, self::MAX_LENGTH, $regionRule)],
                ],
                'max_completion_tokens' => 100,
                'temperature'           => 0.2,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $error = is_array($body) && is_string($body['error']['message'] ?? null) ? $body['error']['message'] : '';
            return new \WP_Error('zw_slug_inkorter_http', trim(sprintf('OpenAI gaf HTTP %d. %s', $code, $error)));
        }

        $answer = is_array($body) ? ($body['choices'][0]['message']['content'] ?? null) : null;
        $slug   = is_string($answer) ? self::clean($answer) : '';
        if ($slug === '') {
            return new \WP_Error('zw_slug_inkorter_answer', 'OpenAI gaf geen bruikbare slug.');
        }

        return $slug;
    }

    /**
     * Turn the answer into a slug of at most MAX_LENGTH characters, cut between words.
     *
     * @param string $answer What the model answered.
     */
    public static function clean(string $answer): string
    {
        // sanitize_title also drops quotes and outer hyphens.
        $slug = sanitize_title((string) preg_replace('/\R.*/s', '', trim($answer)));

        if (strlen($slug) > self::MAX_LENGTH) {
            $cut  = substr($slug, 0, self::MAX_LENGTH + 1);
            $slug = str_contains($cut, '-') ? substr($cut, 0, (int) strrpos($cut, '-')) : substr($cut, 0, self::MAX_LENGTH);
        }

        return $slug;
    }
}
