<?php
declare(strict_types=1);

namespace Ablet\Llm;

use Ablet\Service\SolutionRequest;

/**
 * Modalità dimostrativa: nessuna chiamata esterna, nessuna chiave. Le risposte sono SIMULATE da regole fisse
 * sullo stato della sessione e vengono marcate come tali nell'interfaccia. Non è un modello linguistico:
 * non comprende il contenuto dei messaggi e serve a provare il flusso, non la qualità pedagogica.
 */
final class DemoAdapter implements Adapter
{
    // Testi pensati per l'ascolto: frasi brevi, una sola richiesta alla fine, niente riferimenti alla scrittura.
    private const ASK = [
        'task_statement' => 'Che cosa devi fare oggi?',
        'restatement' => 'Prima di cominciare, dimmi con parole tue che cosa ti chiede il compito.',
        'expected_outcome' => 'Pensa alla fine del lavoro. Che cosa saprai fare, o che cosa avrai pronto?',
        'completion_criterion' => 'Come farai a capire che hai finito?',
        'prior_knowledge' => 'Che cosa sai già su questo argomento?',
        'expected_difficulty' => 'Secondo te, quanto sarà difficile? Dimmi un numero da uno a cinque.',
        'self_efficacy' => 'E quanto ti senti capace di farcela? Sempre da uno a cinque.',
        'strategy' => 'Come pensi di lavorare? Dimmi anche perché hai scelto questo modo.',
        'time_estimate_min' => 'Quanti minuti ti serviranno in tutto, secondo te?',
        'micro_goal' => 'Dimmi il prossimo piccolo passo del piano. Uno solo. Se vuoi, dimmi anche quanti minuti ci vorranno.',
    ];
    private const HINTS = [
        'Ecco un primo indizio. Ripensa a che cosa ti chiede questo passo. Che cosa devi preparare, esattamente?',
        'Secondo indizio. Apri il materiale e trova il punto che parla di questo passo. Di che cosa parla la prima frase?',
        'Terzo indizio. Prova a dividere il passo in due pezzi più piccoli. Qual è il primo pezzo?',
    ];
    private const REFLECT = [
        'Che cosa ti ha aiutato di più oggi?',
        'E che cosa ti ha reso le cose più difficili? Può dipendere dal compito, dal posto dove eri, o dal modo di lavorare.',
        'Adesso inventa una regola per la prossima volta. Per esempio: la prossima volta, se succede questo, allora farò quest\'altro.',
    ];

    public function capabilities(): array
    {
        return ['text' => true, 'structured_output' => 'simulato', 'streaming' => false, 'voice' => false, 'model_listing' => false];
    }

    public function listModels(): array
    {
        return [];
    }

    public function generate(string $system, array $messages, array $params): array
    {
        $d = $params['_demo'] ?? null;
        if ($d === null) { // prova di connessione o uso generico
            return ['text' => '{"ok":true,"nota":"risposta simulata"}', 'tokens_in' => 0, 'tokens_out' => 0, 'http_status' => null];
        }
        $st = $d['state'];
        $text = trim((string)($d['text'] ?? ''));
        $mid = $d['message_id'] ?? null;
        $action = $st['trigger']['action'] ?? null;
        $o = ['student_message' => '', 'current_phase' => $st['phase'], 'proposed_action' => 'continue', 'proposed_state_update' => [],
            'evidence_message_ids' => [], 'suggested_support_level' => (int)$st['support_level'], 'safety_status' => 'ok'];

        if ($text !== '' && SolutionRequest::matches($text)) {
            $n = (int)($st['solution_requests'] ?? 1);
            $o['proposed_action'] = 'offer_hint';
            $o['student_message'] = $n <= 1
                ? 'Capisco, vorresti la risposta. Io non te la do, perché il mio compito è aiutarti ad arrivarci da solo. Vuoi un indizio?'
                : 'Me lo chiedi di nuovo, e va bene. La risposta però la costruisci tu. Facciamo un passo più piccolo. Qual è la prima cosa, anche minima, che riesci a fare?';
            return $this->out($o);
        }

        switch ($st['phase']) {
            case 'forethought':
                $need = $st['next_missing'];
                if ($action === 'start') {
                    $o['student_message'] = 'Ciao, sono Ablet. Sono un\'intelligenza artificiale che ti aiuta a organizzare lo studio. Non sono una persona, e i compiti non li faccio al posto tuo. ' . self::ASK['task_statement'];
                    break;
                }
                if ($text !== '' && $mid && $need !== []) {
                    $k = array_shift($need);
                    $filled = true;
                    if ($k === 'micro_goal') {
                        $min = preg_match('/(\d{1,3})\s*(min|minut|\')/iu', $text, $m) ? (int)$m[1] : null;
                        $g = ['description' => mb_substr($text, 0, 300)];
                        if ($min) { $g['estimated_minutes'] = $min; }
                        $o['proposed_state_update'] = ['micro_goals' => [$g]];
                    } elseif (in_array($k, ['time_estimate_min', 'expected_difficulty', 'self_efficacy'], true)) {
                        if (preg_match('/\d{1,3}/', $text, $m) && ($k === 'time_estimate_min' || ((int)$m[0] >= 1 && (int)$m[0] <= 5))) {
                            $o['proposed_state_update'] = ['slots' => [$k => (int)$m[0]]];
                        } else {
                            $filled = false;
                            array_unshift($need, $k);
                        }
                    } else {
                        $o['proposed_state_update'] = ['slots' => [$k => mb_substr($text, 0, 500)]];
                    }
                    if ($filled) {
                        $o['evidence_message_ids'] = [$mid];
                    }
                }
                if ($need === []) {
                    $first = $st['goals'][0]['description'] ?? ($o['proposed_state_update']['micro_goals'][0]['description'] ?? 'il primo passo del tuo piano');
                    $o['proposed_action'] = 'begin_performance';
                    $o['student_message'] = "Il piano è pronto. Cominciamo dal primo passo: {$first}. Fai solo questo. Quando hai finito, dimmi fatto.";
                } else {
                    $o['student_message'] = ($o['proposed_state_update'] !== [] ? 'Bene, me lo segno. ' : '') . self::ASK[$need[0]];
                }
                break;

            case 'performance':
                $cur = $st['current_step']['description'] ?? null;
                if ($action === 'step_done') {
                    if ($cur === null) {
                        $o['proposed_action'] = 'begin_reflection';
                        $o['student_message'] = 'Hai finito tutti i passi del piano. Adesso guardiamo insieme com\'è andata.';
                    } else {
                        $o['student_message'] = "Fatto, passo finito. Il prossimo è: {$cur}. Prima però dimmi: che cosa ti ha aiutato nel passo di prima?";
                    }
                } elseif ($action === 'step_not_done') {
                    $o['student_message'] = $cur === null
                        ? 'Va bene, questo passo resta da finire. Non ci sono altri passi nel piano. Vuoi rivedere il piano, o vuoi guardare com\'è andata?'
                        : "Va bene, questo passo resta da finire. Poi proveremo con: {$cur}. Intanto dimmi: che cosa ti ha fermato?";
                } elseif ($action === 'hint') {
                    $o['proposed_action'] = 'offer_hint';
                    $o['student_message'] = ($st['activity_mode'] ?? 'prepared') === 'free'
                        ? 'Questa è una risposta simulata: non conosco il contenuto del compito. Ripensa alla strategia scelta. Quale piccolo passo puoi provare?'
                        : self::HINTS[min(2, max(0, (int)($st['current_step']['hints_used'] ?? 1) - 1))];
                } elseif ($action === 'difficulty') {
                    $o['student_message'] = 'Mi dici che è difficile. In quale punto preciso ti sei bloccato?';
                } elseif ($action === 'strategy_change') {
                    $o['proposed_action'] = 'suggest_strategy_change';
                    $o['student_message'] = 'Vuoi cambiare modo di lavorare. Che cosa non funzionava in quello di prima?';
                } elseif ($action === 'begin' || $action === 'resume') {
                    $o['student_message'] = $cur ? "Riprendiamo da qui: {$cur}. Quando hai finito, dimmi fatto." : 'Nel piano non ci sono passi da fare.';
                } elseif (!empty($st['checkpoint_due'])) {
                    $o['proposed_action'] = 'request_self_monitoring';
                    $o['student_message'] = 'Fermiamoci un momento. Da uno a cinque, quanto sei sicuro di quello che hai fatto finora?';
                } else {
                    $o['student_message'] = 'Ti ho sentito. Continua con il passo. Se ti blocchi, chiedimi un indizio. Quando hai finito, dimmi fatto.';
                }
                break;

            case 'self_reflection':
                $n = (int)($st['reflection_turns'] ?? 0);
                if ($action === 'to_reflection' || ($n === 0 && $text === '')) {
                    $t = $st['timing'];
                    $cmp = isset($t['estimated_min'], $t['active_min']) ? "Avevi pensato {$t['estimated_min']} minuti. Nell'app ne sono passati {$t['active_min']}. " : '';
                    $o['student_message'] = $cmp . 'Pensa al risultato che volevi. Com\'è andato il lavoro di oggi?';
                } elseif ($n <= count(self::REFLECT)) {
                    $o['student_message'] = self::REFLECT[$n - 1];
                } else {
                    $o['proposed_action'] = 'suggest_close';
                    $o['student_message'] = 'Bella regola, me la segno. Se ti va, dimmi come ti senti per il lavoro di oggi. Poi possiamo chiudere.';
                }
                break;
        }
        return $this->out($o);
    }

    private function out(array $o): array
    {
        if ($o['proposed_state_update'] === []) {
            $o['proposed_state_update'] = new \stdClass();
        }
        return ['text' => json_encode($o, JSON_UNESCAPED_UNICODE), 'tokens_in' => 0, 'tokens_out' => 0, 'http_status' => null];
    }
}
