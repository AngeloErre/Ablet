<?php
declare(strict_types=1);

namespace Ablet\Llm;

/**
 * Anthropic Messages API: POST {base}/messages, intestazioni x-api-key e anthropic-version.
 * Nessun parametro di output strutturato: il formato JSON è richiesto nel prompt e verificato dal server.
 */
final class AnthropicAdapter implements Adapter
{
    public const DEFAULT_BASE = 'https://api.anthropic.com/v1';
    private const VERSION = '2023-06-01';

    public function __construct(private string $base, private string $key, private ?string $model, private int $timeout, private int $retries) {}

    public function capabilities(): array
    {
        return ['text' => true, 'structured_output' => 'solo istruzione nel prompt', 'streaming' => false, 'voice' => false, 'model_listing' => true];
    }

    private function headers(): array
    {
        return ['x-api-key' => $this->key, 'anthropic-version' => self::VERSION];
    }

    public function generate(string $system, array $messages, array $params): array
    {
        if (!$this->model) {
            throw new ProviderException('config', 'modello non configurato');
        }
        $body = ['model' => $this->model, 'max_tokens' => (int)($params['max_output_tokens'] ?? 800), 'system' => $system, 'messages' => $messages];
        if (isset($params['temperature'])) { $body['temperature'] = (float)$params['temperature']; }
        $r = Http::request('POST', rtrim($this->base, '/') . '/messages', $this->headers(), $body, $this->timeout, $this->retries);
        $text = '';
        foreach ($r['json']['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'text') { $text .= $b['text']; }
        }
        if ($text === '') {
            throw new ProviderException('bad_response', 'nessun contenuto nella risposta', $r['status']);
        }
        $u = $r['json']['usage'] ?? [];
        return ['text' => $text, 'tokens_in' => $u['input_tokens'] ?? null, 'tokens_out' => $u['output_tokens'] ?? null, 'http_status' => $r['status']];
    }

    public function listModels(): array
    {
        $r = Http::request('GET', rtrim($this->base, '/') . '/models?limit=100', $this->headers(), null, $this->timeout, 0);
        return array_values(array_filter(array_map(static fn($m) => $m['id'] ?? null, $r['json']['data'] ?? [])));
    }
}
