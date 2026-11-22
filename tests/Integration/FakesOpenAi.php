<?php
/**
 * Answers the OpenAI provider's requests without the network.
 *
 * @package ZW_Slug_Sniper
 */

declare(strict_types=1);

namespace ZWSlugSniper\Tests\Integration;

trait FakesOpenAi
{
    /** @var list<array{args: array<string, mixed>, body: array<string, mixed>}> Requests to the Responses API. */
    private array $requests = [];

    /** @var list<string> Every OpenAI URL that was called, the model list included. */
    private array $openAiUrls = [];

    /** @var array<string, mixed>|\WP_Error Answer from the Responses API once the queue is empty. */
    private array|\WP_Error $reply = [];

    /** @var list<array<string, mixed>> Answers given first, in order. */
    private array $queue = [];

    private function fakeOpenAi(string $answer): void
    {
        $this->requests   = [];
        $this->openAiUrls = [];
        $this->answer($answer);
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
            return self::json(200, ['data' => [['id' => 'gpt-5.4-mini'], ['id' => 'gpt-4.1']]]);
        }
        if ($url !== 'https://api.openai.com/v1/responses') {
            return $pre;
        }
        $this->requests[] = ['args' => $args, 'body' => (array) json_decode((string) $args['body'], true)];
        return array_shift($this->queue) ?? $this->reply;
    }

    private function answer(string $text, int $code = 200): void
    {
        $this->reply = $code === 200
            ? self::json(200, ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]]])
            : self::json($code, ['error' => ['message' => $text, 'type' => 'invalid_request_error']]);
    }

    private function answers(string ...$texts): void
    {
        $this->queue = array_map(static fn (string $text): array => self::json(200, ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]]]), $texts);
    }

    private function prompt(int $index = 0): string
    {
        return (string) $this->requests[$index]['body']['input'][0]['content'][0]['text'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function json(int $code, array $body): array
    {
        return ['response' => ['code' => $code, 'message' => ''], 'headers' => ['content-type' => 'application/json'], 'body' => (string) wp_json_encode($body), 'cookies' => []];
    }
}
