<?php
declare(strict_types=1);
// Richiede schema di TEST migrato + seed, app e mock_llm.php attivi. Mai su dati reali.
require dirname(__DIR__) . '/src/bootstrap.php';
use Ablet\Support\Database;
$pdo = Database::pdo(); $pass = 0; $fail = 0;
function check(string $n, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '✓ ' : '✗ ') . $n . "\n"; }
final class FreeClient {
    private string $jar; private string $csrf;
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'abl-free'); $this->csrf = $this->req('GET', '/api/me')[1]['csrf']; }
    public function __destruct() { @unlink($this->jar); }
    public function req(string $method, string $path, array $body = []): array {
        $ch = curl_init((getenv('APP') ?: 'http://127.0.0.1:8101') . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($this->csrf ?? '')]]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return [$code, json_decode((string)$raw, true)];
    }
    public function login(string $login, string $password): void { [$c,$r] = $this->req('POST','/api/login',compact('login','password')); if ($c !== 200) throw new RuntimeException('Login test non riuscito'); $this->csrf = $r['csrf']; }
}
foreach (['demo.psicologo', 'demo.psicologo2', 'STUDENTE-DEMO-A', 'STUDENTE-DEMO-C'] as $login) {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE login = ?')->execute([password_hash('Prova-'.$login, PASSWORD_DEFAULT), $login]);
}
$pdo->exec('DELETE FROM rate_limits');
$psy = new FreeClient(); $psy->login('demo.psicologo','Prova-demo.psicologo');
$other = new FreeClient(); $other->login('demo.psicologo2','Prova-demo.psicologo2');
$student = new FreeClient(); $student->login('STUDENTE-DEMO-A','Prova-STUDENTE-DEMO-A');
$alien = new FreeClient(); $alien->login('STUDENTE-DEMO-C','Prova-STUDENTE-DEMO-C');
check('Migrazione conserva tutte le attività precedenti come preimpostate', (int)$pdo->query("SELECT COUNT(*) FROM activities WHERE activity_mode <> 'prepared'")->fetchColumn() === 0);
[$code,$free] = $psy->req('POST','/api/psy/activities',['title'=>'Attività libera','activity_mode'=>'free']); $aid = $free['id'];
check('Creazione senza consegna, tempi o materiali', $code === 200 && $free['material_warnings'] === []);
check('Modalità sconosciuta rifiutata', $psy->req('POST','/api/psy/activities',['title'=>'X','activity_mode'=>'unknown'])[0] === 422);
check('Attività libera senza agente rifiutata', $psy->req('POST','/api/psy/activities',['title'=>'X','activity_mode'=>'free','agent_enabled'=>false])[0] === 422);
check('Materiali preimpostati nella modalità libera rifiutati', $psy->req('POST','/api/psy/activities',['title'=>'X','activity_mode'=>'free','materials'=>[['content_text'=>'test']]])[0] === 422);
check('Studente non può creare attività professionali', $student->req('POST','/api/psy/activities',['activity_mode'=>'free'])[0] === 403);
$acts = $student->req('GET','/api/student/home')[1]['activities']; $tile = array_values(array_filter($acts,fn($a)=>$a['id']==$aid))[0];
check('Attività libera disponibile con protocollo assegnato', $tile['mode'] === 'free' && $tile['available']);
check('Studente di un altro professionista escluso', $alien->req('POST','/api/student/sessions',['activity_id'=>$aid])[0] === 404);
[$code,$fresh] = $psy->req('POST','/api/psy/students',['alias'=>'Senza protocollo','age_band'=>'11-13']);
$unassigned = new FreeClient(); $unassigned->login($fresh['login'],$fresh['password']);
check('Sessione libera senza protocollo assegnato bloccata', $unassigned->req('POST','/api/student/sessions',['activity_id'=>$aid])[1]['error'] === 'nessun_protocollo_assegnato');
$session = $student->req('POST','/api/student/sessions',['activity_id'=>$aid])[1]['session']; $publicId = $session['id']; $base = '/api/student/sessions/'.$publicId;
$q = $pdo->prepare('SELECT id, protocol_version_id, prompt_hash FROM sessions WHERE public_id = ?'); $q->execute([$publicId]); $binding = $q->fetch(); $sid = $binding['id'];
check('Sessione fissata a versione e hash del protocollo professionale', $binding['protocol_version_id'] !== null && strlen($binding['prompt_hash']) === 64);
check('Sessione libera senza materiali', $session['activity']['mode']==='free' && $session['materials']===[]);
$act = fn($action,$payload=[]) => $student->req('POST',$base.'/action',['action'=>$action,'payload'=>$payload]);
$act('start');
$act('set_slot',['slot'=>'task_statement','value'=>'Studiare le frazioni']);
$act('set_slot',['slot'=>'restatement','value'=>'Voglio capire come confrontare le frazioni']);
$act('set_slot',['slot'=>'expected_outcome','value'=>'Confrontare tre coppie']);
$act('set_slot',['slot'=>'available_time_min','value'=>15]);
foreach (['Ripassare','Provare','Controllare'] as $i=>$name) $act('add_goal',['description'=>$name,'minutes'=>$i===1?10:5]);
$session=$student->req('GET',$base)[1]['session'];
check('Compito dichiarato mostrato nel titolo della sessione', $session['activity']['title']==='Studiare le frazioni');
check('Totale derivato dai passi, disponibilità separata', $session['time_plan']['estimated_min']===20 && $session['time_plan']['declared_total_min']===null && $session['time_plan']['available_min']===15 && $session['time_plan']['exceeds_available']);
check('Non viene scritto un totale dichiarato inesistente', !isset($session['slots']['time_estimate_min']));
$goal = $session['goals'][0]['id'];
check('Correzione del tempo di un passo', $act('set_goal_time',['goal_id'=>$goal,'minutes'=>7])[0]===200);
check('Tempo del passo non valido rifiutato', $act('set_goal_time',['goal_id'=>$goal,'minutes'=>0])[0]===422);
check('Tempo disponibile non valido rifiutato', $act('set_slot',['slot'=>'available_time_min','value'=>'molto'])[0]===422);
check('Passo estraneo non modificabile', $act('set_goal_time',['goal_id'=>999999,'minutes'=>5])[0]===422);
$report=$psy->req('GET','/api/psy/sessions/'.$sid)[1];
check('Dashboard distingue totale derivato e dichiarato', $report['session']['activity_mode']==='free' && $report['session']['activity']==='Studiare le frazioni' && $report['time']['estimated_min']==22 && $report['time_plan']['estimate_source']==='sum_student_micro_goals');
check('Sessione e dashboard altrui non accessibili', $alien->req('GET',$base)[0]===404 && $other->req('GET','/api/psy/sessions/'.$sid)[0]===404);
$mock=getenv('MOCK') ?: 'http://127.0.0.1:8102';
$provider=$psy->req('POST','/api/psy/providers',['provider_key'=>'openai_compatible','label'=>'Test libero','base_url'=>$mock.'/v1','api_key'=>'sk-test-SEGRETO-1234','model_name'=>'valid','is_default'=>true]);
check('Provider di test configurato', $provider[0]===200);
$student->req('POST',$base.'/message',['text'=>'Voglio controllare il piano.']);
$wire=json_decode(file_get_contents(sys_get_temp_dir().'/ablet_mock_last.json'),true);
$system=$wire['messages'][0]['content'];
check('Il provider riceve il prompt professionale con contesto SRL libero', str_contains($system,'PROTOCOLLO PROFESSIONALE') && str_contains($system,'MODALITÀ ATTIVITÀ LIBERA'));
check('Il provider riceve lo stato del compito e la provenienza dei contenuti', str_contains(json_encode($wire,JSON_UNESCAPED_UNICODE),'model_knowledge_unverified') && str_contains(json_encode($wire,JSON_UNESCAPED_UNICODE),'Studiare le frazioni'));
check('La prova del provider non modifica il legame al protocollo', (function()use($pdo,$publicId,$binding){$q=$pdo->prepare('SELECT protocol_version_id,prompt_hash FROM sessions WHERE public_id=?');$q->execute([$publicId]);$b=$q->fetch();return $b['protocol_version_id']===$binding['protocol_version_id'] && $b['prompt_hash']===$binding['prompt_hash'];})());
$session=$act('begin')[1]['session'];
check('Passaggio alla performance con sole stime per microattività', $session['state']==='performance');
check('Stime dei passi non modificabili durante la performance', $act('set_goal_time',['goal_id'=>$goal,'minutes'=>9])[0]===422);
$act('pause');check('Ripresa dalla pausa', $act('resume')[1]['session']['state']==='performance');
foreach ([1,2,3] as $i) $act('step_done');
$act('to_reflection');check('Riflessione finale disponibile', $student->req('GET',$base)[1]['session']['state']==='self_reflection');
check('Chiusura confermata dallo studente', $act('close_complete')[1]['session']['state']==='closed_complete');
$new=$student->req('POST','/api/student/sessions',['activity_id'=>$aid])[1]['session'];
check('Un nuovo compito libero ha una sessione e un piano distinti', $new['id']!==$publicId && $new['slots']===[] && $new['goals']===[]);
$home=$student->req('GET','/api/student/home')[1]; $past=array_values(array_filter($home['sessions'],fn($s)=>$s['public_id']===$publicId))[0];
check('Storico conserva il nome del compito scelto', $past['title']==='Studiare le frazioni');
echo "Superati: $pass · Falliti: $fail\n";
exit($fail ? 1 : 0);
