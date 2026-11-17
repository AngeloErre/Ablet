<?php
declare(strict_types=1);
namespace Ablet\Domain\Agent;

/** Previsione locale, non tokenizer del provider né preventivo di fatturazione. */
final class PromptBudget
{
    public static function tokens(string $text): int { return (int)ceil(strlen($text) / 3.0); }
    public static function estimate(string $text, array $config, array $provider): array
    {
        $b = $config['prompt_budget'] ?? [];
        $model = (string)($provider['model_name'] ?? '');
        // Tariffe standard testo (USD/1M), fonte ufficiale Google consultata il 16/11/2026.
        $catalog = ['gemini-2.5-flash' => [0.30,2.50,1048576], 'gemini-2.5-flash-lite' => [0.10,0.40,1048576]];
        $openai = ['gpt-4o-mini' => [0.15,0.60,128000], 'gpt-4.1-mini' => [0.40,1.60,1047576]];
        $known = ($provider['provider_key'] ?? '') === 'gemini' ? ($catalog[$model] ?? null) : (($provider['provider_key'] ?? '') === 'openai' ? ($openai[$model] ?? null) : null);
        if (($provider['provider_key'] ?? '') === 'demo') $known = [0,0,1000000];
        $input = isset($b['input_usd_million']) && is_numeric($b['input_usd_million']) ? max(0,(float)$b['input_usd_million']) : ($known[0] ?? null);
        $output = isset($b['output_usd_million']) && is_numeric($b['output_usd_million']) ? max(0,(float)$b['output_usd_million']) : ($known[1] ?? null);
        $limit = max(1024,min(2000000,(int)($b['context_tokens'] ?? ($known[2] ?? 32768))));
        $response = max(100,(int)($config['provider']['params']['max_output_tokens'] ?? 600));
        if (($provider['provider_key'] ?? '') === 'gemini' && preg_match('/^gemini-(2\.5|[3-9])/', $model)) $response = max(4096,$response);
        $tokens = self::tokens($text);
        $safety = (int)ceil($tokens * 1.25) + $response;
        return ['model' => $model, 'provider' => $provider['provider_key'] ?? null, 'estimated_input_tokens' => $tokens,
            'reserved_output_tokens' => $response, 'context_tokens' => $limit, 'context_source' => isset($b['context_tokens']) ? 'impostato dal professionista' : ($known ? 'catalogo' : 'limite prudenziale ABLET, da verificare sul provider'),
            'fits' => $safety <= $limit, 'input_usd_million' => $input, 'output_usd_million' => $output,
            'input_cost_usd' => $input !== null ? $tokens * $input / 1000000 : null,
            'turn_cost_usd_up_to_reserved_output' => $input !== null && $output !== null ? ($tokens*$input+$response*$output)/1000000 : null,
            'pricing_source' => isset($b['input_usd_million']) ? 'tariffe inserite dal professionista' : ($known ? (($provider['provider_key'] ?? '') === 'gemini' ? 'https://ai.google.dev/gemini-api/docs/pricing (16/11/2026)' : 'https://developers.openai.com/api/docs/pricing (16/11/2026)') : 'tariffa sconosciuta: inserire valori verificati'),
            'note' => 'Stima approssimativa con margine del 25% per controllo locale. Non include audio, cache, tasse, strumenti, tentativi aggiuntivi o ragionamento oltre la riserva. Verificare limiti e fatturazione del provider.'];
    }
}
