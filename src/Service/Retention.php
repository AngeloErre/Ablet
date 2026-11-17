<?php
declare(strict_types=1);

namespace Ablet\Service;

use Ablet\Support\Database;

/**
 * Conservazione dei dati secondo le impostazioni di ciascun professionista. Pensata per hosting senza cron:
 * viene avviata al più una volta al giorno durante una richiesta del professionista, oppure a mano.
 *  - sessioni (con messaggi, eventi, annotazioni, autovalutazioni, adattamenti) più vecchie di messages_days;
 *  - sessioni dello studente sintetico più vecchie di 30 giorni;
 *  - registro tecnico delle chiamate ai provider più vecchio di technical_logs_days;
 *  - contatori di limitazione delle richieste scaduti.
 * Il registro di audit non viene cancellato automaticamente (serve a ricostruire chi ha fatto che cosa).
 */
final class Retention
{
    public const SIM_DAYS = 30;

    public static function settings(int $ownerId): array
    {
        $st = Database::pdo()->prepare('SELECT retention FROM professional_settings WHERE owner_id = ?');
        $st->execute([$ownerId]);
        return (json_decode((string)$st->fetchColumn(), true) ?: []) + ['messages_days' => 365, 'technical_logs_days' => 30];
    }

    /** @return array{sessions:int,simulations:int,llm_calls:int} conteggio di ciò che verrebbe (o è stato) cancellato */
    public static function run(int $ownerId, bool $dryRun = false): array
    {
        $pdo = Database::pdo();
        $r = self::settings($ownerId);
        $days = max(30, (int)$r['messages_days']);
        $tech = max(7, (int)$r['technical_logs_days']);
        $where = [
            'sessions' => ['s.is_simulation = 0 AND s.created_at < UTC_TIMESTAMP() - INTERVAL ? DAY', $days],
            'simulations' => ['s.is_simulation = 1 AND s.created_at < UTC_TIMESTAMP() - INTERVAL ? DAY', self::SIM_DAYS],
        ];
        $out = [];
        foreach ($where as $k => [$cond, $d]) {
            $st = $pdo->prepare("SELECT s.id FROM sessions s JOIN activities a ON a.id = s.activity_id WHERE a.owner_id = ? AND $cond");
            $st->execute([$ownerId, $d]);
            $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
            $out[$k] = count($ids);
            if (!$dryRun) {
                foreach (array_chunk($ids, 200) as $chunk) {
                    $pdo->prepare('DELETE FROM sessions WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
                }
            }
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM llm_calls WHERE owner_id = ? AND created_at < UTC_TIMESTAMP() - INTERVAL ? DAY');
        $st->execute([$ownerId, $tech]);
        $out['llm_calls'] = (int)$st->fetchColumn();
        if (!$dryRun) {
            $pdo->prepare('DELETE FROM llm_calls WHERE owner_id = ? AND created_at < UTC_TIMESTAMP() - INTERVAL ? DAY')->execute([$ownerId, $tech]);
            $pdo->exec('DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY');
            if (array_sum($out) > 0) {
                $pdo->prepare('INSERT INTO audit_log (actor_id, action, entity, meta) VALUES (?,?,?,?)')
                    ->execute([$ownerId, 'retention_applied', 'sessions', json_encode($out + ['messages_days' => $days, 'technical_logs_days' => $tech])]);
            }
        }
        return $out;
    }

    /** Esecuzione automatica al più una volta ogni 24 ore per professionista. Gli errori non bloccano la richiesta. */
    public static function maybeRun(int $ownerId): void
    {
        try {
            $pdo = Database::pdo();
            $key = 'retention_last_run:' . $ownerId;
            $st = $pdo->prepare('SELECT value FROM app_flags WHERE name = ?');
            $st->execute([$key]);
            $last = (int)$st->fetchColumn();
            if (time() - $last < 86400) {
                return;
            }
            $pdo->prepare('INSERT INTO app_flags (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$key, (string)time()]);
            self::run($ownerId);
        } catch (\Throwable $e) {
            error_log('[ABLET] conservazione: ' . $e::class);
        }
    }
}
