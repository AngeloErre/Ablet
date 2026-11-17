<?php
declare(strict_types=1);

namespace Ablet\Llm;

/**
 * API «chat completions» (OpenAI/ChatGPT, DeepSeek, endpoint compatibili):
 * POST {base}/chat/completions, intestazione Authorization: Bearer.
 * Le differenze note fra fornitori sono esplicite nel profilo ($flavor), non presunte uguali.
 */
final class OpenAiCompatAdapter implements Adapter
{
    public const PROFILES = [
        'openai'            => ['base' => 'https://api.openai.com/v1', 'token_param' => 'max_completion_tokens', 'json' => 'json_object'],
        'deepseek'          => ['base' => 'https://api.deepseek.com',  'token_param' => 'max_tokens',            'json' => 'json_object'],
        'openai_compatible' => ['base' => null,                        'token_param' => 'max_tokens',            'json' => 'json_object'],
    ];

    /** @param array<string,mixed> $opts sovrascritture: token_param, response_format ('json_object'|'none') */
    public function __construct(private string $flavor, private string $base, private string $key, private ?string $model, private int $timeout, private int $retries, private array $opts = []) {}

    public function capabilities(): array
    {
        $json = ($this->opts['response_format'] ?? self::PROFILES[$this->flavor]['json']) === 'json_object';
        return ['text' => true, 'structured_output' => $json ? 'modalità JSON' : 'solo istruzione nel prompt', 'streaming' => false, 'voice' => false, 'model_listing' => true];
    }

    public function generate(string $system, array $messages, array $params): array
    {
        if (!$this->model) {
            throw new ProviderException('config', 'modello non configurato');
        }
        $p = self::PROFILES[$this->flavor];
        $body = ['model' => $this->model, 'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages)];
        if (($this->opts['response_format'] ?? $p['json']) === 'json_object') {
            $body['response_format'] = ['type' => 'json_object'];
        }
        if (isset($params['temperature'])) { $body['temperature'] = (float)$params['temperature']; }
        if (isset($params['max_output_tokens'])) { $body[$this->opts['token_param'] ?? $p['token_param']] = (int)$params['max_output_tokens']; }
        $r = Http::request('POST', rtrim($this->base, '/') . '/chat/completions', ['Authorization' => 'Bearer ' . $this->key], $body, $this->timeout, $this->retries);
        $text = $r['json']['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new ProviderException('bad_response', 'nessun contenuto nella risposta', $r['status']);
        }
        $u = $r['json']['usage'] ?? [];
        return ['text' => $text, 'tokens_in' => $u['prompt_tokens'] ?? null, 'tokens_out' => $u['completion_tokens'] ?? null, 'http_status' => $r['status']];
    }

    public function listModels(): array
    {
        $r = Http::request('GET', rtrim($this->base, '/') . '/models', ['Authorization' => 'Bearer ' . $this->key], null, $this->timeout, 0);
        return array_values(array_filter(array_map(static fn($m) => $m['id'] ?? null, $r['json']['data'] ?? [])));
    }
}
