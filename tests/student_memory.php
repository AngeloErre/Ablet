<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Ids};
use Ablet\Http\{App,ApiError};
use Ablet\Repository\SessionRepository as Repo;
use Ablet\Service\StudentMemory as Memory;
use Ablet\Domain\Agent\PromptCompiler;
class_exists(App::class);
$p=Database::pdo();if(!str_contains(getenv('DB_NAME')?:'','test'))throw new RuntimeException('Usare solo database di test');$checks=0;
function ck(string $label,bool $ok):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "✓ $label\n";}
function user(string $role,string $login):int{$p=Database::pdo();$p->prepare('INSERT INTO users(pseudonym,role,login,password_hash) VALUES(?,?,?,?)')->execute([Ids::pseudonym(),$role,$login,password_hash('Test-memory-only-password',PASSWORD_DEFAULT)]);return (int)$p->lastInsertId();}
$owner=user('psychologist','memory-owner-test');$other=user('psychologist','memory-other-test');$student=user('student','memory-student-test');
$p->prepare('INSERT INTO professional_students(professional_id,student_id) VALUES(?,?)')->execute([$owner,$student]);
function activity(int $owner):int{$p=Database::pdo();$p->prepare("INSERT INTO activities(owner_id,title,assignment_text,agent_enabled) VALUES(?,'Contesto sintetico','Dati sintetici',0)")->execute([$owner]);return (int)$p->lastInsertId();}
$a=activity($owner);$b=activity($owner);$foreign=activity($other);
$sid=Repo::create($student,$a,null);ck('Sessione aperta non genera memoria',(function()use($sid){Memory::capture($sid);return Database::pdo()->query("SELECT COUNT(*) FROM student_memory_deltas WHERE session_id=$sid")->fetchColumn()==0;})());
$p->prepare("UPDATE sessions SET state='forethought',srl_data=? WHERE id=?")->execute([json_encode(['strategy'=>'Testo che non deve essere duplicato','time_estimate_min'=>10,'self_efficacy'=>4,'expected_difficulty'=>2]),$sid]);
Repo::addGoal($sid,'Obiettivo sintetico',5);
foreach(['hint_requested','hint_requested','difficulty_flagged','strategy_changed'] as $type)Repo::recordEvent($sid,'student',$type);
$p->prepare("INSERT INTO messages(session_id,role,content,phase) VALUES(?,'student','Testo privato non duplicabile','self_reflection')")->execute([$sid]);
ck('Chiusura incompleta valida',Repo::transition($sid,'close_incomplete','student')['ok']);
$v=Memory::view($owner,$student);ck('Chiusura consolida automaticamente un delta',count($v['sessions'])===1);$f=$v['sessions'][0]['facts'];
ck('Indizi, difficoltà e cambi strategia dai reali eventi',$f['hints']===2&&$f['difficulty']===1&&$f['strategy_changes']===1);
ck('Riflessione conta messaggi senza giudicarne qualità',$f['reflection_messages']===1);
ck('Autovalutazioni restano dichiarazioni',$f['self_efficacy_declared']===4&&$f['difficulty_expected_declared']===2);
ck('Chiusura incompleta distinta',$f['closed']==='incomplete');ck('Nessuna copia di chat o strategia testuale',!str_contains(json_encode($v),'Testo privato')&&!str_contains(json_encode($v),'Testo che non'));
Memory::capture($sid);ck('Consolidamento idempotente',count(Memory::view($owner,$student)['sessions'])===1);
ck('Memoria di altri professionisti isolata',Memory::view($other,$student)['sessions']===[]);
$sim=Repo::create($student,$a,null,['is_simulation'=>true]);$p->exec("UPDATE sessions SET state='closed_complete',ended_at=UTC_TIMESTAMP(3) WHERE id=$sim");Memory::capture($sim);ck('Simulazioni escluse',count(Memory::view($owner,$student)['sessions'])===1);
$fs=Repo::create($student,$foreign,null);$p->exec("UPDATE sessions SET state='closed_complete',ended_at=UTC_TIMESTAMP(3) WHERE id=$fs");Memory::capture($fs);ck('Attività di altro professionista escluse',count(Memory::view($owner,$student)['sessions'])===1);
$settings=Memory::save($owner,$student,['enabled'=>true,'guidance'=>'Proponi una verifica del piano.','revision'=>0]);ck('Indicazioni professionali persistono',$settings['revision']===1);
try{Memory::save($owner,$student,['enabled'=>true,'guidance'=>'Sovrascrittura','revision'=>0]);ck('Conflitto revisioni',false);}catch(ApiError $e){ck('Conflitto revisioni',$e->status===409);}
try{Memory::save($owner,$student,['enabled'=>true,'guidance'=>str_repeat('è',501),'revision'=>1]);ck('Limite caratteri',false);}catch(ApiError $e){ck('Limite caratteri unicode',$e->status===422);}
Memory::exclude($owner,$student,$sid,true);ck('Esclusione rimuove sessione dal contesto',Memory::forAgent($owner,$student,$a)['sessions']===[]);
Memory::rebuild($owner,$student);ck('Recupero storico conserva esclusioni',Memory::view($owner,$student)['sessions'][0]['excluded']);Memory::exclude($owner,$student,$sid,false);
try{Memory::exclude($owner,$student,$fs,true);ck('Esclusione altrui',false);}catch(ApiError $e){ck('Esclusione altrui respinta',$e->status===404);}
Memory::save($owner,$student,['enabled'=>false,'guidance'=>'Indicazione','revision'=>1]);ck('Disattivazione interrompe invio al modello',Memory::forAgent($owner,$student,$a)===null);
Memory::save($owner,$student,['enabled'=>true,'guidance'=>str_repeat('è',500),'revision'=>2]);
// Le sessioni pregresse vengono recuperate con un limite, senza chiamate a provider.
for($i=0;$i<24;$i++){$n=Repo::create($student,$b,null);$p->exec("UPDATE sessions SET state='closed_complete',ended_at=UTC_TIMESTAMP(3) WHERE id=$n");}
Memory::rebuild($owner,$student);$v=Memory::view($owner,$student);ck('Finestra limitata a venti sessioni',count($v['sessions'])===20);
$agent=Memory::forAgent($owner,$student,$b);ck('Al modello al massimo cinque sessioni',count($agent['sessions'])===5);ck('Contesto compatto entro seimila byte',strlen(json_encode($agent,JSON_UNESCAPED_UNICODE))<=6000);ck('Contesto identifica fonte e attività',$agent['source']==='server_observed_counts'&&$agent['sessions'][0]['activity']===$b);
$recent=Repo::create($student,$a,null);$p->exec("UPDATE sessions SET state='closed_complete',ended_at=UTC_TIMESTAMP(3) WHERE id=$recent");Memory::capture($recent);ck('Priorità al medesimo contesto attività',Memory::forAgent($owner,$student,$a)['sessions'][0]['sid']===$recent);
$turn=PromptCompiler::buildTurn('Protocollo',['activity_mode'=>'prepared'],[],[],['longitudinal_memory'=>Memory::forAgent($owner,$student,$a)]);ck('Memoria presente nel contesto del prompt',str_contains($turn['context'],'srl-memory-1')&&str_contains($turn['context'],'non capacità stabili'));
// HTTP: proprietà della risorsa, CSRF, revisione, export e cancellazione.
final class MemoryClient{private string $jar;private string $csrf='';function __construct(){$this->jar=tempnam(sys_get_temp_dir(),'memory-http-');$this->csrf=$this->req('GET','/api/me')[1]['csrf'];}function __destruct(){@unlink($this->jar);}function req(string $method,string $url,?array $in=null,bool $csrf=true):array{$c=curl_init((getenv('APP')?:'http://127.0.0.1:8101').$url);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.($csrf?$this->csrf:'')],CURLOPT_TIMEOUT=>20]);if($in!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($in));$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);$r=json_decode((string)$raw,true);if(isset($r['csrf']))$this->csrf=$r['csrf'];return [$status,$r];}}
$c=new MemoryClient();$d=new MemoryClient();$anon=new MemoryClient();
ck('Accesso professionista fixture',$c->req('POST','/api/login',['login'=>'memory-owner-test','password'=>'Test-memory-only-password'])[0]===200);$d->req('POST','/api/login',['login'=>'memory-other-test','password'=>'Test-memory-only-password']);$url="/api/psy/students/$student/memory";
ck('Memoria non pubblica',$anon->req('GET',$url)[0]===401);ck('Altro professionista non legge',$d->req('GET',$url)[0]===404);ck('Altro professionista non modifica',$d->req('PUT',$url,['enabled'=>false,'guidance'=>'','revision'=>3])[0]===404);
ck('CSRF su modifica memoria',$c->req('PUT',$url,['enabled'=>false,'guidance'=>'','revision'=>3],false)[0]===403);
ck('Memoria leggibile dal titolare',$c->req('GET',$url)[0]===200);ck('Esclusione HTTP',$c->req('POST',$url.'/'.$recent,['excluded'=>true])[0]===200);ck('Esclusione HTTP validate',$c->req('POST',$url.'/'.$recent,['excluded'=>'true'])[0]===422);
ck('Salvataggio HTTP indicazioni',$c->req('PUT',$url,['enabled'=>true,'guidance'=>'Indicazione revisionata','revision'=>3])[0]===200);
$export=$c->req('GET',"/api/psy/students/$student/export");ck('Export contiene impostazioni e tutti i delta',$export[0]===200&&$export[1]['longitudinal_memory']['settings']['guidance']==='Indicazione revisionata'&&count($export[1]['memory_deltas'])>20);
$p->exec("DELETE FROM sessions WHERE id=$recent");ck('Cancellazione evidenza elimina delta',$p->query("SELECT COUNT(*) FROM student_memory_deltas WHERE session_id=$recent")->fetchColumn()==0);
ck('Cancellazione studente',$c->req('DELETE',"/api/psy/students/$student")[0]===200);ck('Cancellazione elimina impostazioni',$p->query("SELECT COUNT(*) FROM student_memory_settings WHERE student_id=$student")->fetchColumn()==0);
$p->exec("DELETE FROM activities WHERE id IN ($a,$b,$foreign)");$p->exec("DELETE FROM users WHERE id IN ($owner,$other)");
echo "Memoria studente: $checks controlli superati.\n";
