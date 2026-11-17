<?php
declare(strict_types=1);

namespace Ablet\Service;

use Ablet\Repository\SessionRepository as Repo;
use Ablet\Support\Database;
use Ablet\Support\Ids;

/** Studente sintetico: copioni fissi che percorrono il protocollo attraverso lo stesso orchestratore delle sessioni reali. */
final class Simulator
{
    public const PROFILES = ['collaborativo' => 'Collaborativo: completa il ciclo', 'chiede_soluzione' => 'Chiede ripetutamente la soluzione', 'stallo' => 'Va in stallo durante il lavoro'];

    private const REPLY = [
        'task_statement' => 'Devo studiare il testo assegnato per saper rispondere alle domande.',
        'restatement' => 'Mi chiede di leggere il testo e poi saper rispondere senza guardarlo.',
        'expected_outcome' => 'Voglio saper rispondere a tre domande senza guardare il testo.',
        'completion_criterion' => 'Ho finito quando rispondo a tre domande.',
        'prior_knowledge' => 'So che una volta si lavorava a mano.',
        'expected_difficulty' => '3', 'self_efficacy' => '3', 'time_estimate_min' => '20 minuti',
        'strategy' => 'Divido il testo in blocchi perché così non mi perdo.',
    ];
    private const GOALS = ['Leggere il primo blocco e dire di che cosa parla, 5 minuti', 'Scrivere una frase per ogni blocco, 10 minuti', 'Prepararmi tre domande, 5 minuti', 'Ripetere le risposte a voce, 5 minuti'];
    private const REFLECT = ['Ho fatto quasi tutto, ci ho messo più del previsto.', 'Mi ha aiutato dividere il testo in blocchi.', 'All\'inizio mi sono bloccato perché leggevo tutto insieme.', 'La prossima volta, se il testo è lungo, lo divido subito.'];

    public static function syntheticStudent(int $psyId): int
    {
        $pdo = Database::pdo();
        $login = 'SINT-' . $psyId;
        $st = $pdo->prepare('SELECT id FROM users WHERE login = ?');
        $st->execute([$login]);
        if ($id = $st->fetchColumn()) { return (int)$id; }
        $pdo->prepare("INSERT INTO users (pseudonym, role, login, password_hash, display_alias, status) VALUES (?, 'student', ?, ?, 'Studente sintetico (simulazione)', 'suspended')")
            ->execute([Ids::pseudonym(), $login, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        return (int)$pdo->lastInsertId();
    }

    public static function run(int $psyId, int $versionId, int $activityId, string $profile): array
    {
        $sid = Repo::create(self::syntheticStudent($psyId), $activityId, $versionId, ['is_simulation' => true]);
        $say = static fn(string $t) => TurnService::studentMessage($sid, $t, 'text', null, 'simulazione');
        $do = static fn(string $a, array $p = []) => TurnService::action($sid, $a, $p, null, 'simulazione');
        $do('start');
        $asked = 0; $refl = 0; $stall = 0;
        for ($i = 0; $i < 24; $i++) {
            $c = SessionView::context($sid);
            if (!in_array($c['state'], ['forethought', 'performance', 'self_reflection'], true)) { break; }
            if ($profile === 'chiede_soluzione' && $i >= 1) {
                if ($asked++ < 3) { $say($asked === 1 ? 'Dimmi tu la risposta alle domande.' : 'Dai, dammi la soluzione, fallo tu.'); continue; }
                $do('close_incomplete'); break;
            }
            if ($c['state'] === 'forethought') {
                $need = SessionView::nextMissing($c);
                if ($need === []) { try { $do('begin'); } catch (\DomainException) { break; } continue; }
                $n = count(array_filter($c['goals'], static fn($g) => $g['status'] !== 'dropped'));
                $say($need[0] === 'micro_goal' ? self::GOALS[$n % 4] : (self::REPLY[$need[0]] ?? 'Va bene.'));
            } elseif ($c['state'] === 'performance') {
                if ($profile === 'stallo') {
                    $seq = ['step_not_done', 'step_not_done', 'difficulty', 'hint'];
                    if ($stall < 4 && SessionView::currentGoal($c)) { $do($seq[$stall++]); continue; }
                    $do('close_incomplete'); break;
                }
                SessionView::currentGoal($c) ? $do('step_done') : $do('to_reflection');
            } else {
                if ($refl < 4) { $say(self::REFLECT[$refl++]); continue; }
                $do('close_complete'); break;
            }
        }
        $m = Indicators::session($sid);
        return ['session_id' => $sid, 'final_state' => $m['session']['state'], 'messages' => $m['messages'], 'scaffolding' => $m['scaffolding'], 'phases' => $m['phases'], 'system' => $m['system'], 'goals' => $m['goals']];
    }
}
