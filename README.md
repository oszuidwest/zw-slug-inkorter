# ZuidWest Slug Sniper

WordPress plugin that gives new posts a short slug made by AI. A post called "Brand verwoest garage aan de Kerkstraat in Putte, niemand gewond" could get `brand-verwoest-garage-putte` instead of the full title.

It replaces the `slug-inkorter-mu.php` mu-plugin on zuidwestupdate.nl.

## How it works

When a post is published or scheduled for the first time, the plugin sends the title, the excerpt and the region to the AI provider set up in WordPress. Without an excerpt it sends the text. It uses the WordPress AI Client and asks for `gpt-5.4-mini`. If the provider does not have that model, WordPress picks another text model.

The answer becomes the slug when it has at most 50 characters, is not taken yet and contains the region, if there is one. If not, the plugin asks once more and tells the model why. A second answer without the region is fine. If the second answer is too long or taken as well, the post keeps the slug WordPress made from the title. The plugin never adds `-2` to a short slug.

The slug is set while the post is saved, so it is in place before anything that runs after the save. Scheduled posts get their slug when they are scheduled, because cron publishes them without saving them again.

The plugin skips:

- posts that already have a slug, because they were published or scheduled before or the editor typed one
- drafts and pending posts
- post types without a public URL

## Region

The region comes from the `regio` taxonomy. Regions picked in the same save count, in the block editor and in the classic editor. If the Yoast primary region is one of them, that one is used. Otherwise it is the first region. Without a region, the model is asked to take the place from the title or excerpt.

In the classic editor a primary region picked in the same save counts too. The block editor sends that choice after the publish request, so there the primary region saved before is used.

## Timing and errors

The answer gets 8 seconds. Without a persistent object cache, WordPress first fetches the provider's model list on every publish. That can take up to 5 seconds more. When the first answer is not used, the second one also gets 8 seconds, so in the worst case a publish waits about 21 seconds. On zuidwestupdate.nl it took about 2 seconds.

When the provider gives an error or no answer in time, the post keeps the title slug. The error goes to the PHP error log and shows in Tools → Site Health until the provider answers again. Site Health does not call the provider itself, so a broken key only shows there after a post is published or scheduled.

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher
- An AI provider for text under Settings → Connectors, for example the OpenAI provider plugin with a key

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/oszuidwest/zw-slug-sniper/releases)
2. Upload the ZIP file via WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate the plugin

There are no settings. When AI is switched off for the site (`wp_supports_ai`), the plugin leaves slugs alone and Site Health says so.

## Development

```bash
composer install
composer quality   # PHPCS and PHPStan

npm ci
npm run env:start  # WordPress 7.1.5 with the OpenAI provider in wp-env
npm run env:composer
npm run test:php   # Integration tests
npm run pot        # Regenerate languages/zw-slug-sniper.pot
```

## License

GPL-2.0-or-later
