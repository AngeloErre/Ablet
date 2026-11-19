<?php
declare(strict_types=1);
// Richiede seed dimostrativo e server di tests/run_all.sh. Solo database di test.
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\Database;
use Ablet\Repository\SessionRepository as Repo;
use Ablet\Service\SessionView;
$pdo=Database::pdo();$passed=0;
function verify(string $label,bool $ok):void { global $passed; if(!$ok)throw new RuntimeException($label);$passed++;echo "✓ $label\n"; }
final class ActivityEditClient {
 private string $jar;private string $csrf='';
 public function __construct(){ $this->jar=tempnam(sys_get_temp_dir(),'abl-edit-');$this->csrf=$this->request('GET','/api/me')[1]['csrf']; }
 public function __destruct(){@unlink($this->jar);}
 public function request(string $method,string $path,?array $data=null,bool $csrf=true):array {
  $h=['Content-Type: application/json'];if($csrf)$h[]='X-CSRF-Token: '.$this->csrf;
  $ch=curl_init((getenv('APP')?:'http://127.0.0.1:8101').$path);
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_HTTPHEADER=>$h,CURLOPT_PROXY=>'',CURLOPT_TIMEOUT=>20]);
  if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data));
  $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$body=json_decode((string)$raw,true);
  if($path==='/api/login'&&$status===200)$this->csrf=$body['csrf'];return [$status,$body];
 }
 public function login(string $name):int{return $this->request('POST','/api/login',['login'=>$name,'password'=>'Prova-'.$name])[0];}
}
$pdo->exec('DELETE FROM rate_limits');
$owner=new ActivityEditClient();$other=new ActivityEditClient();$student=new ActivityEditClient();$anon=new ActivityEditClient();
verify('Accesso professionista di prova',$owner->login('demo.psicologo')===200);
verify('Accessi per verificare isolamento',$other->login('demo.psicologo2')===200&&$student->login('STUDENTE-DEMO-B')===200);
[$status,$created]=$owner->request('POST','/api/psy/activities',['activity_mode'=>'free','visibility'=>'assigned','title'=>'Libera originale','assignment_text'=>'Indicazioni originali: scegli il compito.','completion_criteria'=>'Criterio originale','expected_minutes'=>25,'agent_enabled'=>true]);
verify('Creazione attività libera',$status===200);$aid=(int)$created['id'];$url='/api/psy/activities/'.$aid;
// Una modifica senza sessioni deve riuscire, anche quando le funzioni JSON SQL non esistono.
[$status]=$owner->request('PUT',$url,['title'=>'Libera prima delle sessioni']);verify('Salvataggio senza sessioni',$status===200);
$studentId=(int)$pdo->query("SELECT id FROM users WHERE login='STUDENTE-DEMO-B'")->fetchColumn();
$ownerId=(int)$pdo->query("SELECT id FROM users WHERE login='demo.psicologo'")->fetchColumn();
$q=$pdo->prepare("SELECT pv.id FROM protocol_versions pv JOIN protocols p ON p.id=pv.protocol_id WHERE p.owner_id=? AND pv.status='published' LIMIT 1");$q->execute([$ownerId]);$version=(int)$q->fetchColumn();
$sid=Repo::create($studentId,$aid,$version,['is_demo'=>true]);$closed=Repo::create($studentId,$aid,$version,['is_demo'=>true]);
$pdo->prepare("UPDATE sessions SET state='closed_incomplete',ended_at=CURRENT_TIMESTAMP(3) WHERE id=?")->execute([$closed]);
Repo::mergeSlots($sid,['task_statement'=>'Studiare le frazioni','time_estimate_min'=>15]);
$q=$pdo->prepare('SELECT activity_id,protocol_version_id,prompt_hash,state,state_version FROM sessions WHERE id=?');$q->execute([$sid]);$binding=$q->fetch();
$assignment=$pdo->prepare('INSERT INTO activity_assignments(activity_id,student_id,protocol_version_id,assigned_by,active) VALUES(?,?,?,?,1)');$assignment->execute([$aid,$studentId,$version,$ownerId]);$assignmentId=(int)$pdo->lastInsertId();
$change=['title'=>'Libera aggiornata','assignment_text'=>'Nuove indicazioni con accenti: attività e perché.','completion_criteria'=>'Confronta le strategie','expected_minutes'=>40];
[$status]=$owner->request('PUT',$url,$change);verify('Salvataggio con sessioni già create',$status===200);
$context=SessionView::context($sid);verify('Le vecchie sessioni conservano titolo, consegna, criteri e durata',$context['a_title']==='Libera prima delle sessioni'&&$context['assignment_text']==='Indicazioni originali: scegli il compito.'&&$context['completion_criteria']==='Criterio originale'&&(int)$context['expected_minutes']===25);
verify('La sessione chiusa conserva le indicazioni originali',SessionView::context($closed)['assignment_text']==='Indicazioni originali: scegli il compito.');
verify('Gli slot dello studente rimangono conservati',$context['srl']['task_statement']==='Studiare le frazioni'&&(int)$context['srl']['time_estimate_min']===15);
$q->execute([$sid]);verify('Versione del protocollo, hash e stato non cambiano',$q->fetch()===$binding);
$read=$pdo->prepare('SELECT active,protocol_version_id FROM activity_assignments WHERE id=?');$read->execute([$assignmentId]);$assigned=$read->fetch();verify('Assegnazione conservata',(int)$assigned['active']===1&&(int)$assigned['protocol_version_id']===$version);
$new=Repo::create($studentId,$aid,$version,['is_demo'=>true]);verify('Le nuove sessioni usano le indicazioni aggiornate',SessionView::context($new)['assignment_text']===$change['assignment_text']);
Repo::mergeSlots($sid,['strategy'=>'Esempi e autoverifica']);verify('Un turno successivo preserva lo snapshot',SessionView::context($sid)['a_title']==='Libera prima delle sessioni');
[$status]=$owner->request('PUT',$url,['title'=>'Seconda modifica','completion_criteria'=>'','expected_minutes'=>0]);verify('Durata e criteri facoltativi possono essere rimossi',$status===200&&SessionView::context(Repo::create($studentId,$aid,$version))['expected_minutes']===null);
verify('Un secondo salvataggio non sovrascrive lo storico',SessionView::context($sid)['a_title']==='Libera prima delle sessioni'&&SessionView::context($new)['a_title']==='Libera aggiornata');
verify('Durata non valida respinta',$owner->request('PUT',$url,['title'=>'Non salvare','expected_minutes'=>601])[0]===422);
$boot=$owner->request('GET','/api/psy/bootstrap')[1];$row=array_values(array_filter($boot['activities'],fn($a)=>(int)$a['id']===$aid))[0];verify('Il salvataggio respinto lascia i dati invariati',$row['title']==='Seconda modifica');
verify('Professionista estraneo respinto',$other->request('PUT',$url,$change)[0]===404);
verify('Studente respinto',$student->request('PUT',$url,$change)[0]===403);
verify('Richiesta anonima respinta',$anon->request('PUT',$url,$change)[0]===401);
verify('Token CSRF obbligatorio',$owner->request('PUT',$url,$change,false)[0]===403);
// Evita la reintroduzione della dipendenza che interrompe MySQL 5.6 persino senza sessioni.
$source=file_get_contents(ABLET_ROOT.'/src/Http/PsyApi.php');$source=preg_replace('~//[^\n]*~','',$source);
verify('Il salvataggio non richiede funzioni JSON SQL',!preg_match('/\bJSON_(?:SET|EXTRACT)\s*\(/i',$source));
echo "Modifica attività libera: $passed controlli superati.\n";
