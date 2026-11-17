<?php
declare(strict_types=1);

namespace Ablet\Llm;

/**
 * Gemini API (REST): POST {base}/models/{model}:generateContent, intestazione x-goog-api-key.
 * Output strutturato richiesto con generationConfig.responseMimeType = application/json (modalità JSON);
 * la conformità allo schema è verificata dal server (OutputValidator), non delegata al provider.
 */
final class GeminiAdapter implements Adapter
{
    public const DEFAULT_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    /** Limite minimo (ragionamento + risposta) per i modelli con ragionamento; si pagano solo i token effettivamente generati. */
    public const THINKING_MIN_TOKENS = 4096;

    public function __construct(private string $base, private string $key, private ?string $model, private int $timeout, private int $retries) {}

    public function capabilities(): array
    {
        return ['text' => true, 'structured_output' => 'modalità JSON', 'streaming' => false, 'voice' => false, 'model_listing' => true];
    }

    public function generate(string $system, array $messages, array $params): array
    {
        if (!$this->model) {
            throw new ProviderException('config', 'modello non configurato');
        }
        $contents = [];
        foreach ($messages as $m) {
            $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]];
        }
        $gen = ['responseMimeType' => 'application/json'];
        if (isset($params['temperature'])) { $gen['temperature'] = (float)$params['temperature']; }
        // Nei modelli Gemini con ragionamento, maxOutputTokens comprende anche i token di ragionamento: un limite basso
        // tronca la risposta (finishReason MAX_TOKENS). Si alza quindi la soglia e si limita il ragionamento con thinkingLevel.
        $thinking = (bool)preg_match('/^gemini-(?:2\.5|[3-9])/', (string)$this->model);
        $max = isset($params['max_output_tokens']) ? (int)$params['max_output_tokens'] : null;
        if ($thinking) { $max = max($max ?? 0, self::THINKING_MIN_TOKENS); }
        if ($max) { $gen['maxOutputTokens'] = $max; }
        if (preg_match('/^gemini-[3-9]/', (string)$this->model)) {
            $gen['thinkingConfig'] = ['thinkingLevel' => in_array($params['thinking_level'] ?? '', ['low', 'medium', 'high'], true) ? $params['thinking_level'] : 'low'];
        }
        $r = Http::request('POST', rtrim($this->base, '/') . '/models/' . rawurlencode($this->model) . ':generateContent',
            ['x-goog-api-key' => $this->key],
            ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => $gen],
            $this->timeout, $this->retries);
        $text = '';
        foreach ($r['json']['candidates'][0]['content']['parts'] ?? [] as $p) {
            if (!empty($p['thought'])) { continue; } // eventuali parti di ragionamento non sono la risposta
            $text .= $p['text'] ?? '';
        }
        $finish = (string)($r['json']['candidates'][0]['finishReason'] ?? '');
        if ($finish === 'MAX_TOKENS') {
            throw new ProviderException('bad_response', 'risposta troncata dal limite di token (MAX_TOKENS): aumentare la lunghezza massima nel protocollo', $r['status']);
        }
        if ($text === '') {
            throw new ProviderException('bad_response', 'nessun contenuto nella risposta' . ($finish !== '' ? " ($finish)" : ''), $r['status']);
        }
        $u = $r['json']['usageMetadata'] ?? [];
        return ['text' => $text, 'tokens_in' => $u['promptTokenCount'] ?? null, 'tokens_out' => $u['candidatesTokenCount'] ?? null, 'http_status' => $r['status']];
    }

    public function listModels(): array
    {
        $r = Http::request('GET', rtrim($this->base, '/') . '/models?pageSize=200', ['x-goog-api-key' => $this->key], null, $this->timeout, 0);
        $out = [];
        foreach ($r['json']['models'] ?? [] as $m) {
            if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
                $out[] = preg_replace('#^models/#', '', (string)$m['name']);
            }
        }
        return $out;
    }
}
