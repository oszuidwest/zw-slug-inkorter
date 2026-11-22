<?php
/**
 * Asks the WordPress AI Client for a short slug.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

/**
 * Uses the AI provider set up under Settings → Connectors.
 */
final class Generator
{
    public const MAX_LENGTH = 50;

    /** Used when a provider offers it; otherwise WordPress picks a text model. Fast, and no reasoning by default. */
    public const MODEL = 'gpt-5.4-mini';

    /** Publishing waits for the answer, so keep this short. */
    private const TIMEOUT = 8.0;

    private const SYSTEM = 'Je bent eindredacteur van een regionale nieuwssite in West-Brabant. Je maakt korte URL-slugs die lezen als een kop in goed Nederlands. Je antwoordt alleen met de slug.';

    private const PROMPT = 'Maak een korte URL-slug voor dit nieuwsbericht.

TITEL: %1$s
SAMENVATTING: %2$s
%3$s
Regels:
- 3 tot 6 woorden, liefst 25 tot 40 tekens, nooit meer dan %4$d.
- Vat het nieuws samen zoals de titel dat doet. Gebruik alleen feiten uit de titel en de samenvatting.
- Laat lidwoorden en vulwoorden weg (de, het, een, er, wordt, is). Een kort woord als "op" of "na" mag blijven als de slug anders krom leest.
- Alleen kleine letters a-z, cijfers en koppeltekens. Geen accenten of apostroffen: "camera\'s" wordt "cameras".
- Geen datum, weeknummer of jaartal, tenzij dat zelf het nieuws is.
%5$s
Goede voorbeelden:
- woningbrand-appartement-roosendaal
- fatbike-botst-op-bus-etten-leur
- a58-dicht-na-botsing-vrachtwagens
- illegaal-vuurwerk-gevonden-putte

Krom: geen-nieuw-flexwoningen. Goed: flexwoningen-project-stopgezet.

Antwoord alleen met de slug.';

    /**
     * @param string       $title    Post title.
     * @param string       $summary  Excerpt, or the start of the text.
     * @param string|null  $region   Region name that should be in the slug.
     * @param list<string> $rejected Earlier answers for this post, each with the reason it was not used.
     */
    public static function generate(string $title, string $summary, ?string $region, array $rejected = []): string|\WP_Error
    {
        $place = $region !== null
            ? sprintf("PLAATS: %s. Deze plaats of streek staat één keer in de slug, bij voorkeur aan het eind.\n", $region)
            : "PLAATS: zet de plaats uit de titel of samenvatting in de slug, bij voorkeur aan het eind.\n";
        $retry = $rejected === [] ? '' : "\nDeze slugs kun je niet gebruiken:\n- " . implode("\n- ", $rejected) . "\n";

        // Anything thrown here would break the save. The builder only turns its own exceptions into a WP_Error, not PHP errors.
        try {
            $answer = wp_ai_client_prompt(sprintf(self::PROMPT, $title, $summary, $place, self::MAX_LENGTH, $retry))
                ->using_system_instruction(self::SYSTEM)
                ->using_max_tokens(300)
                ->using_model_preference(self::MODEL)
                ->using_request_options(RequestOptions::fromArray([RequestOptions::KEY_TIMEOUT => self::TIMEOUT]))
                ->generate_text();
        } catch (\Throwable $e) {
            return new \WP_Error('zw_slug_sniper_client', $e->getMessage());
        }

        if (is_wp_error($answer)) {
            return $answer;
        }

        $slug = self::clean($answer);
        if ($slug === '') {
            return new \WP_Error('zw_slug_sniper_answer', 'Het AI-model gaf geen bruikbare slug.');
        }

        return $slug;
    }

    /**
     * Turn the first line of the answer into a slug.
     *
     * @param string $answer What the model answered.
     */
    public static function clean(string $answer): string
    {
        // sanitize_title also drops quotes, accents and outer hyphens.
        return sanitize_title((string) preg_replace('/\R.*/s', '', trim($answer)));
    }
}
