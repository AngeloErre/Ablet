<?php
declare(strict_types=1);

namespace Ablet\Llm;

use Ablet\Support\Database;
use Ablet\Support\Env;
use Ablet\Support\Secrets;

/** Punto unico di accesso ai provider: scelta della configurazione, tetto mensile, registro tecnico, fallback esplicito. */
final class Gateway
{
    public static function adapter(array $cfg): Adapter
    {
        $key = $cfg['api_key_enc'] !== null ? Secrets::decrypt($cfg['api_key_enc']) : '';
        $t = (int)$cfg['timeout_s'];
        $r = (int)$cfg['max_retries'];
        $params = json_decode($cfg['params'] ?? 'null', true) ?: [];
        $k = $cfg['provider_key'];
        if ($k === 'demo') {
            return new DemoAdapter();
        }
        if ($key === '') {
            throw new ProviderException('config', 'chiave API non configurata');
        }
        if ($k === 'gemini') {
            return new GeminiAdapter($cfg['base_url'] ?: GeminiAdapter::DEFAULT_BASE, $key, $cfg['model_name'], $t, $r);
        }
        if ($k === 'anthropic') {
            return new AnthropicAdapter($cfg['base_url'] ?: AnthropicAdapter::DEFAULT_BASE, $key, $cfg['model_name'], $t, $r);
        }
        $base = $cfg['base_url'] ?: OpenAiCompatAdapter::PROFILES[$k]['base'];
        if (!$base) {
            throw new ProviderException('config', 'indirizzo dell\'endpoint non configurato');
        }
        return new OpenAiCompatAdapter($k, $base, $key, $cfg['model_name'], $t, $r, array_intersect_key($params, ['token_param' => 1, 'response_format' => 1]));
    }

    /** Solo https, salvo host locale in ambiente di sviluppo. */
    public static function validBaseUrl(?string $url): bool
    {
        if ($url === null || $url === '') { return true; }
        $p = parse_url($url);
        if (!$p || empty($p['host']) || isset($p['user'])) { return false; }
        if (($p['scheme'] ?? '') === 'https') { return true; }
        return ($p['scheme'] ?? '') === 'http' && Env::get('APP_ENV') === 'development' && in_array($p['host'], ['127.0.0.1', 'localhost'], true);
    }

    public static function configFor(int $ownerId, ?int $configId = null): array
    {
        $pdo = Database::pdo();
        if ($configId) {
            $st = $pdo->prepare('SELECT * FROM provider_configs WHERE id = ? AND owner_id = ? AND enabled = 1');
            $st->execute([$configId, $ownerId]);
        } else {
            $st = $pdo->prepare('SELECT * FROM provider_configs WHERE owner_id = ? AND enabled = 1 ORDER BY is_default DESC, id LIMIT 1');
            $st->execute([$ownerId]);
        }
        $cfg = $st->fetch();
        if (!$cfg) { // nessuna configurazione: modalità dimostrativa
            return ['id' => null, 'owner_id' => $ownerId, 'provider_key' => 'demo', 'label' => 'Modalità dimostrativa', 'model_name' => null, 'api_key_enc' => null,
                'base_url' => null, 'params' => null, 'timeout_s' => 5, 'max_retries' => 0, 'fallback_config_id' => null, 'monthly_token_limit' => null];
        }
        return $cfg;
    }

    /**
     * @return array{text:string,provider_key:string,model_name:?string,simulated:bool,is_fallback:bool}
     * @throws ProviderException
     */
    public static function call(array $cfg, string $purpose, ?int $sessionId, string $system, array $messages, array $params, bool $isFallback = false): array
    {
        $pdo = Database::pdo();
        $t0 = microtime(true);
        $log = static function (string $status, ?int $http, ?int $in, ?int $out) use ($pdo, $cfg, $purpose, $sessionId, $t0, $isFallback): void {
            $pdo->prepare('INSERT INTO llm_calls (provider_config_id, owner_id, session_id, purpose, provider_key, model_name, status, http_status, tokens_in, tokens_out, duration_ms, is_fallback) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cfg['id'], $cfg['owner_id'], $sessionId, $purpose, $cfg['provider_key'], $cfg['model_name'], $status, $http, $in, $out, (int)((microtime(true) - $t0) * 1000), (int)$isFallback]);
        };
        $attempted = false;
        try {
            if ($cfg['id']) { Cooldown::check((int)$cfg['id']); }
            if ($cfg['id'] && $cfg['monthly_token_limit']) {
                $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(tokens_in,0)+COALESCE(tokens_out,0)),0) FROM llm_calls WHERE provider_config_id = ? AND created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')");
                $st->execute([$cfg['id']]);
                if ((int)$st->fetchColumn() >= (int)$cfg['monthly_token_limit']) {
                    throw new ProviderException('budget', 'tetto mensile di token raggiunto');
                }
            }
            if (!function_exists('curl_init') && $cfg['provider_key'] !== 'demo') {
                throw new ProviderException('config', 'estensione curl non disponibile sull\'hosting');
            }
            $adapter = self::adapter($cfg);
            $attempted = true;
            $res = $adapter->generate($system, $messages, $params);
            $log('ok', $res['http_status'], $res['tokens_in'], $res['tokens_out']);
            return ['text' => $res['text'], 'provider_key' => $cfg['provider_key'], 'model_name' => $cfg['model_name'], 'simulated' => $cfg['provider_key'] === 'demo', 'is_fallback' => $isFallback];
        } catch (ProviderException $e) {
            if ($attempted && $cfg['id']) { Cooldown::record((int)$cfg['id'], $e); }
            $log(!$attempted && $e->retryAfter !== null ? 'cooldown' : $e->kind, $e->httpStatus, null, null);
            // Fallback solo se configurato esplicitamente: cambia la destinazione dei dati.
            if (!$isFallback && $cfg['fallback_config_id'] && !in_array($e->kind, ['cancelled', 'config'], true)) {
                return self::call(self::configFor((int)$cfg['owner_id'], (int)$cfg['fallback_config_id']), $purpose, $sessionId, $system, $messages, $params, true);
            }
            throw $e;
        } catch (\RuntimeException $e) { // es. chiave non decifrabile
            $log('config', null, null, null);
            throw new ProviderException('config', $e->getMessage());
        }
    }

    /** Unisce turni consecutivi con lo stesso ruolo e garantisce l'avvio con 'user'. */
    public static function normalize(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $n = count($out);
            if ($n && $out[$n - 1]['role'] === $m['role']) {
                $out[$n - 1]['content'] .= "\n\n" . $m['content'];
            } else {
                $out[] = $m;
            }
        }
        if ($out && $out[0]['role'] !== 'user') {
            array_unshift($out, ['role' => 'user', 'content' => '(avvio della sessione)']);
        }
        return $out;
    }
}
