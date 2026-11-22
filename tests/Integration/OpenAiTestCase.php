<?php
/**
 * Answers the OpenAI provider's requests without the network.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper\Tests\Integration;

abstract class OpenAiTestCase extends \WP_UnitTestCase
{
    /** @var list<array{args: array<string, mixed>, body: array<string, mixed>}> Requests to the Responses API. */
    protected array $requests = [];

    /** @var list<string> Every OpenAI URL that was called, the model list included. */
    protected array $openAiUrls = [];

    /** @var list<string|array<string, mixed>|\WP_Error|\Throwable> Replies of the Responses API in order, the last one repeats. A string is the answer text. */
    protected array $replies = [];

    /** @var list<string> Models the provider lists. */
    protected array $models = ['gpt-5.4-mini', 'gpt-4.1'];

    public function set_up(): void
    {
        parent::set_up();
        $this->answers('brand-verwoest-garage-putte');
        add_filter('pre_http_request', [$this, 'interceptOpenAi'], 10, 3);
    }

    /**
     * @param false|array<string, mixed>|\WP_Error $pre
     * @param array<string, mixed>                 $args
     * @return false|array<string, mixed>|\WP_Error
     */
    public function interceptOpenAi($pre, array $args, string $url)
    {
        if (str_starts_with($url, 'https://api.openai.com/')) {
            $this->openAiUrls[] = $url;
        }
        if ($url === 'https://api.openai.com/v1/models') {
            return self::json(200, ['data' => array_map(static fn (string $id): array => ['id' => $id], $this->models)]);
        }
        if ($url !== 'https://api.openai.com/v1/responses') {
            return $pre;
        }
        $this->requests[] = ['args' => $args, 'body' => (array) json_decode((string) $args['body'], true)];
        $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];
        if ($reply instanceof \Throwable) {
            throw $reply;
        }
        return is_string($reply) ? self::text($reply) : $reply;
    }

    /**
     * @param string|array<string, mixed>|\WP_Error|\Throwable ...$replies
     */
    protected function answers(string|array|\WP_Error|\Throwable ...$replies): void
    {
        $this->replies = array_values($replies);
    }

    protected static function text(string $text): array
    {
        return self::json(200, ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]]]);
    }

    protected function prompt(int $index = 0): string
    {
        return (string) $this->requests[$index]['body']['input'][0]['content'][0]['text'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected static function json(int $code, array $body): array
    {
        return ['response' => ['code' => $code, 'message' => ''], 'headers' => ['content-type' => 'application/json'], 'body' => (string) wp_json_encode($body), 'cookies' => []];
    }
}
