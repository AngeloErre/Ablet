<?php
declare(strict_types=1);

namespace Ablet\Domain\Analytics;

/**
 * Tempi di sessione ricostruiti dagli eventi persistiti, con orario del SERVER.
 * Il timer del browser è solo una visualizzazione: non è fonte di dati.
 *
 * Tre grandezze distinte, nessuna delle quali misura attenzione o apprendimento:
 *   - elapsed_s: dall'avvio alla chiusura (o a "now"), pause comprese;
 *   - active_s: tempo non in pausa;
 *   - interaction_s: somma degli intervalli fra eventi consecutivi dello studente inferiori alla soglia di inattività.
 */
final class SessionClock
{
    /**
     * @param array<int,array{type:string,actor:string,ts:float}> $events ordinati; ts = epoch secondi (server)
     * @return array{elapsed_s:?float,active_s:?float,paused_s:float,pause_count:int,interaction_s:float,inactivity_gaps:int,start_latency_s:?float}
     */
    public static function compute(array $events, ?float $createdTs, float $now, int $inactivityThresholdS = 180): array
    {
        $start = null; $end = null; $pausedAt = null; $paused = 0.0; $pauses = 0;
        $lastStudent = null; $interaction = 0.0; $gaps = 0;

        foreach ($events as $e) {
            $t = (float)$e['ts'];
            // Intervallo di interazione valutato sullo stato PRECEDENTE all'evento (l'evento di pausa chiude un intervallo attivo).
            if ($e['actor'] === 'student' && $pausedAt === null && $start !== null) {
                if ($lastStudent !== null) {
                    $d = $t - $lastStudent;
                    if ($d <= $inactivityThresholdS) { $interaction += $d; } else { $gaps++; }
                }
                $lastStudent = $t;
            }
            switch ($e['type']) {
                case 'session_started':
                    if ($start === null) { $start = $t; $lastStudent = $e['actor'] === 'student' ? $t : null; }
                    break;
                case 'session_paused':
                    if ($pausedAt === null && $start !== null) { $pausedAt = $t; $pauses++; }   // pausa doppia ignorata
                    break;
                case 'session_resumed':
                    if ($pausedAt !== null) { $paused += $t - $pausedAt; $pausedAt = null; $lastStudent = $t; }   // ripresa doppia ignorata
                    break;
                case 'session_closed': $end ??= $t; break;
            }
        }
        if ($start === null) {
            return ['elapsed_s' => null, 'active_s' => null, 'paused_s' => 0.0, 'pause_count' => 0, 'interaction_s' => 0.0, 'inactivity_gaps' => 0, 'start_latency_s' => null];
        }
        $stop = $end ?? $now;
        if ($pausedAt !== null) {
            $paused += $stop - $pausedAt;
        }
        $elapsed = $stop - $start;
        return [
            'elapsed_s' => round($elapsed, 3),
            'active_s' => round($elapsed - $paused, 3),
            'paused_s' => round($paused, 3),
            'pause_count' => $pauses,
            'interaction_s' => round($interaction, 3),
            'inactivity_gaps' => $gaps,
            'start_latency_s' => $createdTs !== null ? round($start - $createdTs, 3) : null,
        ];
    }
}
