<?php
declare(strict_types=1);
/** Solo ambiente locale e database di test già inizializzato. Nessun provider reale. */
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\Database;
use Ablet\Repository\SessionRepository;
if (getenv('APP_ENV')!=='development' || !str_contains((string)getenv('DB_NAME'),'test')) throw new RuntimeException('Solo database di test');
$app=getenv('APP')?:'http://127.0.0.1:8101';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#',$app)) throw new RuntimeException('Solo server locale');
$pdo=Database::pdo();$checks=0;$httpRequests=0;$stats=[];$jar=tempnam(sys_get_temp_dir(),'abl-stress');
function checkStress(string $name,bool $ok):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;echo "✓ $name\n";}
function handleStress(string $path,?array $data=null,?string $raw=null):CurlHandle{
 global $app,$jar,$csrf,$httpRequests; $httpRequests++;
 $ch=curl_init($app.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.($csrf??'')]]);
 if($data!==null||$raw!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$raw??json_encode($data));return $ch;
}
function batchStress(array $requests,int $concurrency,bool $retainBody=true):array{
 $out=[];
 foreach(array_chunk($requests,$concurrency) as $chunk){
  $mh=curl_multi_init();$hs=[];
  foreach($chunk as $r){$ch=handleStress(...$r);$hs[]=$ch;curl_multi_add_handle($mh,$ch);}
  do{curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,0.1);}while($running);
  foreach($hs as $ch){$decoded=json_decode(curl_multi_getcontent($ch),true);$out[]=['status'=>curl_getinfo($ch,CURLINFO_RESPONSE_CODE),'seconds'=>curl_getinfo($ch,CURLINFO_TOTAL_TIME),'valid_json'=>is_array($decoded),'body'=>$retainBody?$decoded:null,'error'=>curl_errno($ch)];unset($decoded);curl_multi_remove_handle($mh,$ch);curl_close($ch);}curl_multi_close($mh);
 }return $out;
}
try{
 // Fixture sintetica: password esclusivamente del database dedicato.
 $pdo->prepare("UPDATE users SET password_hash=? WHERE login='STUDENTE-DEMO-A'")->execute([password_hash('Stress-local-only',PASSWORD_DEFAULT)]);
 $ch=handleStress('/api/me');curl_setopt($ch,CURLOPT_COOKIEJAR,$jar);$me=json_decode(curl_exec($ch),true);curl_close($ch);unset($ch);$csrf=$me['csrf'];
 $ch=handleStress('/api/login',['login'=>'STUDENTE-DEMO-A','password'=>'Stress-local-only']);curl_setopt($ch,CURLOPT_COOKIEJAR,$jar);$login=json_decode(curl_exec($ch),true);curl_close($ch);unset($ch);$csrf=$login['csrf'];
 $student=(int)$pdo->query("SELECT id FROM users WHERE login='STUDENTE-DEMO-A'")->fetchColumn();
 $activity=(int)$pdo->query('SELECT id FROM activities WHERE agent_enabled=0 LIMIT 1')->fetchColumn();
 $sid=SessionRepository::create($student,$activity,null);$q=$pdo->prepare('SELECT public_id FROM sessions WHERE id=?');$q->execute([$sid]);$pid=$q->fetchColumn();$path='/api/student/sessions/'.$pid;
 $start=batchStress([[$path.'/action',['action'=>'start']]],1);checkStress('Sessione sintetica avviata',$start[0]['status']===200);
 foreach([1,4,12] as $concurrency){
  $requests=[];for($i=0;$i<120;$i++)$requests[]=[match($i%3){0=>'/api/me',1=>'/api/student/home',default=>$path}];
  $t=microtime(true);$r=batchStress($requests,$concurrency,false);$duration=microtime(true)-$t;$times=array_column($r,'seconds');sort($times);
  checkStress("120 letture concorrenti, livello $concurrency",count(array_filter($r,fn($v)=>$v['status']===200&&$v['valid_json']&&$v['error']===0))===120);
  $stats[]=['surface'=>'student','concurrency'=>$concurrency,'requests'=>120,'seconds'=>round($duration,3),'requests_per_second'=>round(120/$duration,2),'p50_ms'=>round($times[59]*1000,2),'p95_ms'=>round($times[113]*1000,2),'max_ms'=>round(max($times)*1000,2)];
 }
 $cid='00000000-0000-4000-8000-000000000001';$r=batchStress(array_fill(0,16,[$path.'/message',['text'=>'Messaggio sintetico duplicato','client_event_id'=>$cid]]),12);
 checkStress('Sedici invii duplicati restituiscono risposte valide',count(array_filter($r,fn($v)=>$v['status']===200))===16);
 $q=$pdo->prepare("SELECT COUNT(*) FROM messages WHERE session_id=? AND role='student'");$q->execute([$sid]);checkStress('Invii concorrenti salvano un solo messaggio',(int)$q->fetchColumn()===1);
 $requests=[];for($i=0;$i<8;$i++)$requests[]=[$path.'/message',['text'=>'Messaggio sintetico '.$i,'client_event_id'=>sprintf('00000000-0000-4000-8000-%012d',$i+2)]];
 $r=batchStress($requests,8);$q->execute([$sid]);checkStress('Otto messaggi distinti conservati senza perdita',count(array_filter($r,fn($v)=>$v['status']===200))===8&&(int)$q->fetchColumn()===9);
 $r=batchStress(array_fill(0,20,[$path.'/message',['text'=>'Prova limitazione']]),12);
 checkStress('Raffica eccedente il limite produce 429 controllati',count(array_filter($r,fn($v)=>$v['status']===429))>0&&count(array_filter($r,fn($v)=>!in_array($v['status'],[200,429],true)))===0);
 $r=batchStress([[$path.'/message',null,str_repeat(' ',600001)],[$path.'/message',null,'{invalido'],['/api/psy/bootstrap']],3);
 checkStress('Payload eccessivo produce 413',$r[0]['status']===413);checkStress('JSON malformato produce 400',$r[1]['status']===400);checkStress('Studente non accede ad area professionista sotto carico',$r[2]['status']===403);
 $r=batchStress([[$path]],1);checkStress('Sessione ancora leggibile dopo la raffica',$r[0]['status']===200);
 // Dataset più ampio per dashboard e analisi del professionista.
 for($i=0;$i<50;$i++)SessionRepository::create($student,$activity,null);
 $insert=$pdo->prepare("INSERT INTO messages(session_id,role,modality,content,phase) VALUES(?,'student','text',?,'forethought')");
 $pdo->beginTransaction();for($i=0;$i<1000;$i++)$insert->execute([$sid,'Messaggio sintetico di carico '.$i]);$pdo->commit();
 $pdo->prepare("UPDATE users SET password_hash=? WHERE login='demo.psicologo'")->execute([password_hash('Stress-teacher-only',PASSWORD_DEFAULT)]);
 $ch=handleStress('/api/login',['login'=>'demo.psicologo','password'=>'Stress-teacher-only']);curl_setopt($ch,CURLOPT_COOKIEJAR,$jar);$login=json_decode(curl_exec($ch),true);curl_close($ch);unset($ch);$csrf=$login['csrf'];
 checkStress('Accesso professionista per carico dashboard',isset($csrf));
 foreach([4,12] as $concurrency){
  $requests=[];for($i=0;$i<120;$i++)$requests[]=[match($i%3){0=>'/api/psy/bootstrap',1=>'/api/psy/dashboard',default=>'/api/psy/sessions/'.$sid}];
  $t=microtime(true);$r=batchStress($requests,$concurrency,false);$duration=microtime(true)-$t;$times=array_column($r,'seconds');sort($times);
  checkStress("120 letture professionista con 51 sessioni e oltre 1000 messaggi, livello $concurrency",count(array_filter($r,fn($v)=>$v['status']===200&&$v['valid_json']&&$v['error']===0))===120);
  $stats[]=['surface'=>'psychologist','concurrency'=>$concurrency,'requests'=>120,'seconds'=>round($duration,3),'requests_per_second'=>round(120/$duration,2),'p50_ms'=>round($times[59]*1000,2),'p95_ms'=>round($times[113]*1000,2),'max_ms'=>round(max($times)*1000,2)];
 }
 $out=['checks'=>$checks,'requests'=>$httpRequests,'load'=>$stats,'limitations'=>['Server PHP locale con due worker, non hosting Aruba','Nessun provider reale e nessuna misura di capacità del servizio Gemini','Dataset sintetico: 51 sessioni aggiunte e oltre 1000 messaggi nella sessione analizzata; nessuna prova prolungata di durata']];
 file_put_contents(getenv('STRESS_REPORT')?:sys_get_temp_dir().'/ablet-stress.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
}finally{unlink($jar);}
