# ZuidWest Slug Inkorter

WordPress plugin that gives new posts a short, readable slug made by AI, such as `brand-verwoest-garage-putte` instead of the full title.

It replaces the `slug-inkorter-mu.php` mu-plugin on zuidwestupdate.nl.

## How it works

When a post is first published or scheduled, the plugin sends the title, the excerpt (or the first 50 words of the text) and the region to the AI provider set up in WordPress. It goes through the WordPress AI Client, like Knabbel. It asks for `gpt-5.4-mini`; if the provider doesn't have that model, WordPress picks another text model. The answer becomes the slug if it has at most 50 characters, is not taken yet and contains the region. Otherwise the plugin asks once more and says why the first answer was not used. If the second answer is still too long or taken, the post keeps the slug WordPress made from the title. The plugin never adds `-2` itself; WordPress only does that to the title slug when another post has the same title. A second answer without the region is used.

The slug is set while the post is saved, so other plugins see the short URL from the start. Scheduled posts get their slug when they are scheduled, because cron publishes them without saving them again.

It leaves these alone:

- posts that were published or scheduled before
- posts with a slug the editor typed
- drafts and pending posts
- post types without a public URL

The region comes from the `regio` taxonomy. Without a region, the model is asked to use the place from the title or excerpt. The Yoast primary region wins if it is still picked, otherwise the first region is used. Regions picked in the same save count, in both the block editor and the classic editor. A primary region picked in that same save is not seen yet, because Yoast sends it after the publish request. The first region is used then.

The answer gets 8 seconds. Without a persistent object cache WordPress first fetches the provider's model list on every publish, which can take up to 5 seconds more. On zuidwestupdate.nl the whole call took about 2 seconds. If the provider gives no answer in time or an error, the post keeps the slug WordPress made from the title. The error goes to the PHP error log and shows in Tools → Site Health until the next slug works.

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher
- An AI provider for text under Settings → Connectors, for example the OpenAI provider plugin with a key

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/oszuidwest/zw-slug-inkorter/releases)
2. Upload the ZIP file via WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate the plugin

There are no settings. When AI is switched off for the site (`wp_supports_ai`), the plugin does nothing.

## Development

```bash
composer install
composer quality   # PHPCS and PHPStan

npm ci
npm run env:start  # WordPress 7.1 with the OpenAI provider in wp-env
npm run env:composer
npm run test:php   # Integration tests
```

## License

GPL-2.0-or-later
