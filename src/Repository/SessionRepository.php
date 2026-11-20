<?php
declare(strict_types=1);

namespace Ablet\Repository;

use Ablet\Domain\Srl\StateMachine;
use Ablet\Support\Database;
use Ablet\Support\Ids;
use PDO;

/**
 * Persistenza transazionale di sessioni, eventi, transizioni e adattamenti.
 * Il controllo degli accessi (chi può agire su quale sessione) è applicato a monte dal livello HTTP (incremento 2).
 */
final class SessionRepository
{
    /** Crea una sessione legandola in modo permanente alla versione pubblicata del protocollo. */
    public static function create(int $studentId, int $activityId, ?int $protocolVersionId, array $opts = []): int
    {
        $insert = static function (PDO $pdo) use ($studentId, $activityId, $protocolVersionId, $opts): int {
            $hash = null;
            $level = 3;
            if ($protocolVersionId !== null) {
                $st = $pdo->prepare('SELECT status, prompt_hash, config_json FROM protocol_versions WHERE id = ?');
                $st->execute([$protocolVersionId]);
                $v = $st->fetch();
                if (!$v) {
                    throw new \DomainException('versione_protocollo_inesistente');
                }
                if ($v['status'] !== 'published' && empty($opts['is_simulation'])) {
                    throw new \DomainException('versione_non_pubblicata');
                }
                $hash = $v['prompt_hash'];
                $cfg = json_decode($v['config_json'], true);
                $level = (int)($cfg['scaffolding']['initial_level'] ?? 3);
            }
            // Il modello dello studente persistente prevale sul livello iniziale del protocollo.
            $sm = $pdo->prepare('SELECT support_level, step_size FROM student_models WHERE student_id = ?');
            $sm->execute([$studentId]);
            $model = $sm->fetch() ?: ['support_level' => $level, 'step_size' => 3];

            $pdo->prepare('INSERT INTO sessions (public_id, student_id, activity_id, protocol_version_id, prompt_hash, support_level, step_size, srl_data, is_demo, is_simulation)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([Ids::pseudonym(16), $studentId, $activityId, $protocolVersionId, $hash,
                    $model['support_level'], $model['step_size'], '{}', (int)!empty($opts['is_demo']), (int)!empty($opts['is_simulation'])]);
            return (int)$pdo->lastInsertId();
        };
        $pdo = Database::pdo();
        return $pdo->inTransaction() ? $insert($pdo) : Database::transaction($insert);
    }

    /**
     * Registra un evento. Idempotente rispetto a client_event_id (doppio invio, più schede, riconnessione).
     * @return array{id:int,duplicate:bool}
     */
    public static function recordEvent(int $sessionId, string $actor, string $type, array $payload = [], ?string $clientEventId = null, ?PDO $pdo = null): array
    {
        $pdo ??= Database::pdo();
        if ($clientEventId !== null) {
            $st = $pdo->prepare('SELECT id FROM events WHERE session_id = ? AND client_event_id = ?');
            $st->execute([$sessionId, $clientEventId]);
            if ($id = $st->fetchColumn()) {
                return ['id' => (int)$id, 'duplicate' => true];
            }
        }
        try {
            $pdo->prepare('INSERT INTO events (session_id, actor, type, payload, client_event_id) VALUES (?,?,?,?,?)')
                ->execute([$sessionId, $actor, $type, json_encode($payload, JSON_UNESCAPED_UNICODE), $clientEventId]);
            return ['id' => (int)$pdo->lastInsertId(), 'duplicate' => false];
        } catch (\PDOException $e) {
            if ($clientEventId !== null && $e->getCode() === '23000') { // corsa fra due richieste identiche
                $st = $pdo->prepare('SELECT id FROM events WHERE session_id = ? AND client_event_id = ?');
                $st->execute([$sessionId, $clientEventId]);
                return ['id' => (int)$st->fetchColumn(), 'duplicate' => true];
            }
            throw $e;
        }
    }

    /** @return array{state:string,resume_state:?string,srl:array,goals:array,support_level:int,step_size:int,state_version:int,student_id:int,protocol_version_id:?int} */
    public static function load(int $sessionId, ?PDO $pdo = null, bool $forUpdate = false): array
    {
        $pdo ??= Database::pdo();
        $st = $pdo->prepare('SELECT * FROM sessions WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute([$sessionId]);
        $row = $st->fetch();
        if (!$row) {
            throw new \DomainException('sessione_inesistente');
        }
        $g = $pdo->prepare('SELECT id, position, description, estimated_minutes, status, hints_used FROM session_goals WHERE session_id = ? ORDER BY position');
        $g->execute([$sessionId]);
        return [
            'state' => $row['state'], 'resume_state' => $row['resume_state'],
            'srl' => json_decode($row['srl_data'], true) ?: [], 'goals' => $g->fetchAll(),
            'support_level' => (int)$row['support_level'], 'step_size' => (int)$row['step_size'],
            'state_version' => (int)$row['state_version'], 'student_id' => (int)$row['student_id'],
            'protocol_version_id' => $row['protocol_version_id'] !== null ? (int)$row['protocol_version_id'] : null,
            'activity_mode' => (static function () use ($pdo, $row): string {
                $q = $pdo->prepare('SELECT activity_mode FROM activities WHERE id = ?');
                $q->execute([$row['activity_id']]);
                return (string)$q->fetchColumn();
            })(),
        ];
    }

    /** Parametri di pianificazione letti dalla versione di protocollo fissata alla sessione. */
    public static function protocolGuards(?int $versionId, PDO $pdo): array
    {
        if ($versionId === null) {
            return [];
        }
        $st = $pdo->prepare('SELECT config_json FROM protocol_versions WHERE id = ?');
        $st->execute([$versionId]);
        $cfg = json_decode((string)$st->fetchColumn(), true) ?: [];
        return $cfg['srl_procedures']['forethought'] ?? [];
    }

    /**
     * Applica una transizione convalidata dalla macchina a stati, in transazione e con blocco di riga.
     * $expectedVersion (facoltativo) rifiuta richieste basate su uno stato superato (altra scheda).
     * @return array{ok:bool,error?:string,detail?:mixed,state?:string,state_version?:int,duplicate?:bool}
     */
    public static function transition(int $sessionId, string $action, string $actor, ?int $expectedVersion = null, ?string $clientEventId = null): array
    {
        return Database::transaction(static function (PDO $pdo) use ($sessionId, $action, $actor, $expectedVersion, $clientEventId): array {
            // Ordine di lock coerente con l'avvio delle sessioni programmate: piano, poi sessione.
            $planLock = $pdo->prepare('SELECT p.id FROM session_plans p JOIN session_plan_steps st ON st.plan_id=p.id WHERE st.session_id=? FOR UPDATE');
            $planLock->execute([$sessionId]);
            $s = self::load($sessionId, $pdo, true);
            if ($clientEventId !== null) {
                $dup = $pdo->prepare('SELECT 1 FROM events WHERE session_id = ? AND client_event_id = ?');
                $dup->execute([$sessionId, $clientEventId]);
                if ($dup->fetchColumn()) {
                    return ['ok' => true, 'duplicate' => true, 'state' => $s['state'], 'state_version' => $s['state_version']];
                }
            }
            if ($expectedVersion !== null && $expectedVersion !== $s['state_version']) {
                return ['ok' => false, 'error' => 'stato_superato', 'detail' => ['state' => $s['state'], 'state_version' => $s['state_version']]];
            }
            $r = StateMachine::apply($s, $action, $actor, self::protocolGuards($s['protocol_version_id'], $pdo));
            if (!$r['ok']) {
                self::recordEvent($sessionId, $actor, 'transition_rejected', ['action' => $action, 'error' => $r['error']], $clientEventId, $pdo);
                return $r;
            }
            $new = $r['state'];
            $closed = StateMachine::isClosed($new['state']);
            $set = 'state = ?, resume_state = ?, state_version = state_version + 1';
            $args = [$new['state'], $new['resume_state']];
            if ($action === 'start') {
                $set .= ', started_at = CURRENT_TIMESTAMP(3)';
            }
            if ($closed) {
                $set .= ', ended_at = CURRENT_TIMESTAMP(3), close_reason = ?';
                $args[] = $action;
            }
            $args[] = $sessionId;
            $pdo->prepare("UPDATE sessions SET $set WHERE id = ?")->execute($args);
            if ($new['state'] === StateMachine::CLOSED_COMPLETE) \Ablet\Service\SessionPlans::onComplete($sessionId, $pdo);

            $clock = match (true) {
                $action === 'start' => 'session_started',
                $new['state'] === StateMachine::PAUSED, $new['state'] === StateMachine::SAFETY_HOLD => 'session_paused',
                in_array($action, ['resume', 'safety_release'], true) => 'session_resumed',
                $closed => 'session_closed',
                default => 'phase_changed',
            };
            $ev = self::recordEvent($sessionId, $actor, $clock, ['action' => $action, 'from' => $s['state'], 'to' => $new['state']], $clientEventId, $pdo);
            $pdo->prepare('INSERT INTO state_transitions (session_id, action, from_state, to_state, actor, event_id) VALUES (?,?,?,?,?,?)')
                ->execute([$sessionId, $action, $s['state'], $new['state'], $actor, $ev['id']]);
            if ($closed) \Ablet\Service\StudentMemory::capture($sessionId,$pdo);
            return ['ok' => true, 'state' => $new['state'], 'state_version' => $s['state_version'] + 1];
        });
    }

    /** Aggiorna gli slot dichiarati dallo studente (fusione; chiavi già convalidate dall'OutputValidator o dal form). */
    public static function mergeSlots(int $sessionId, array $slots): void
    {
        Database::transaction(static function (PDO $pdo) use ($sessionId, $slots): void {
            $s = self::load($sessionId, $pdo, true);
            $slots = array_intersect_key($slots, array_flip(StateMachine::FORETHOUGHT_SLOTS));
            $pdo->prepare('UPDATE sessions SET srl_data = ? WHERE id = ?')
                ->execute([json_encode(array_merge($s['srl'], $slots), JSON_UNESCAPED_UNICODE), $sessionId]);
        });
    }

    public static function addGoal(int $sessionId, string $description, ?int $minutes): int
    {
        $pdo = Database::pdo();
        $pos = $pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM session_goals WHERE session_id = ?');
        $pos->execute([$sessionId]);
        $pdo->prepare('INSERT INTO session_goals (session_id, position, description, estimated_minutes) VALUES (?,?,?,?)')
            ->execute([$sessionId, (int)$pos->fetchColumn(), $description, $minutes]);
        return (int)$pdo->lastInsertId();
    }

    /** @return array<int,array{id:int,type:string,payload:array}> eventi osservati dello studente, in ordine */
    public static function observedEvents(int $sessionId): array
    {
        $st = Database::pdo()->prepare("SELECT id, type, payload FROM events WHERE session_id = ? AND (actor = 'student' OR (actor = 'system' AND type = 'step_started')) ORDER BY id");
        $st->execute([$sessionId]);
        return array_map(static fn(array $r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'payload' => json_decode($r['payload'] ?? '[]', true) ?: []], $st->fetchAll());
    }

    /** @return array<int,array<string,mixed>> */
    public static function adaptationHistory(int $sessionId): array
    {
        $st = Database::pdo()->prepare('SELECT action, outcome, evidence_event_ids, prev_support_level, new_support_level, prev_step_size, new_step_size FROM adaptations WHERE session_id = ? ORDER BY id');
        $st->execute([$sessionId]);
        return array_map(static function (array $r): array {
            $r['evidence_event_ids'] = json_decode($r['evidence_event_ids'], true) ?: [];
            return $r;
        }, $st->fetchAll());
    }

    /**
     * Registra una decisione di adattamento con evidenze, regola, stato precedente e nuovo stato.
     * Solo le decisioni 'applicata' modificano sessione e modello dello studente.
     */
    public static function recordAdaptation(int $sessionId, array $d): int
    {
        return Database::transaction(static function (PDO $pdo) use ($sessionId, $d): int {
            $s = self::load($sessionId, $pdo, true);
            $pdo->prepare('INSERT INTO adaptations (session_id, student_id, source, rule_key, rule_snapshot, action, outcome, indicators, evidence_event_ids,
                              prev_support_level, new_support_level, prev_step_size, new_step_size) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$sessionId, $s['student_id'], $d['source'], $d['rule_key'] ?? null,
                    isset($d['rule_snapshot']) ? json_encode($d['rule_snapshot'], JSON_UNESCAPED_UNICODE) : null,
                    $d['action'], $d['outcome'], json_encode($d['indicators']), json_encode($d['evidence_event_ids']),
                    $d['prev_support_level'], $d['new_support_level'], $d['prev_step_size'], $d['new_step_size']]);
            $id = (int)$pdo->lastInsertId();
            if ($d['outcome'] === 'applicata') {
                $pdo->prepare('UPDATE sessions SET support_level = ?, step_size = ? WHERE id = ?')
                    ->execute([$d['new_support_level'], $d['new_step_size'], $sessionId]);
                $pdo->prepare('INSERT INTO student_models (student_id, support_level, step_size) VALUES (?,?,?)
                               ON DUPLICATE KEY UPDATE support_level = VALUES(support_level), step_size = VALUES(step_size)')
                    ->execute([$s['student_id'], $d['new_support_level'], $d['new_step_size']]);
            }
            return $id;
        });
    }
}
