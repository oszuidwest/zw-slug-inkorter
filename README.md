# ZuidWest Slug Inkorter

WordPress plugin that gives new posts a short, readable slug made by OpenAI, such as `brand-verwoest-garage-putte` instead of the full title.

It replaces the `slug-inkorter-mu.php` mu-plugin on zuidwestupdate.nl.

## How it works

When a post is first published or scheduled, the plugin sends the title, the excerpt (or the first 50 words of the text) and the region to OpenAI. The answer becomes the slug, at most 50 characters, cut between words and made unique.

The slug is set while the post is saved, so other plugins see the short URL from the start. Scheduled posts get their slug when they are scheduled, because cron publishes them without saving them again.

It leaves these alone:

- posts that were published or scheduled before
- posts with a slug the editor typed
- drafts and pending posts
- post types without a public URL

The region comes from the `regio` taxonomy. The Yoast primary region wins if it is still picked, otherwise the first region is used. Regions picked in the same save count, in both the block editor and the classic editor. A primary region picked in that same save is not seen yet, because Yoast sends it after the publish request. The first region is used then.

If OpenAI does not answer within 8 seconds or gives an error, the post keeps the slug WordPress made from the title. The error goes to the PHP error log and shows in Tools → Site Health until the next slug works.

## Requirements

- WordPress 7.1 or higher
- PHP 8.4 or higher
- An OpenAI API key

## Installation

1. Download the latest release from [GitHub Releases](https://github.com/oszuidwest/zw-slug-inkorter/releases)
2. Upload the ZIP file via WordPress Admin → Plugins → Add New → Upload Plugin
3. Add the key to `wp-config.php`:

   ```php
   define('ZW_SLUG_INKORTER_API_KEY', 'sk-...');
   ```

4. Activate the plugin

The model is `gpt-4.1-mini`. To use another one:

```php
define('ZW_SLUG_INKORTER_MODEL', 'gpt-4.1');
```

## Development

```bash
composer install
composer quality   # PHPCS and PHPStan

npm ci
npm run env:start  # WordPress 7.1 in wp-env
npm run env:composer
npm run test:php   # Integration tests
```

## License

GPL-2.0-or-later
