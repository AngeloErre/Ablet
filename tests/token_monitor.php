<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/Http/App.php';
use Ablet\Support\Database;
use Ablet\Service\TokenMonitor;
$p=Database::pdo();$checks=0;
function ok(string $s,bool $v):void {global $checks;if(!$v)throw new RuntimeException($s);$checks++;echo "✓ $s\n";}
$p->beginTransaction();
try {
 $p->prepare("INSERT INTO users(login,password_hash,role,pseudonym) VALUES(?,?,'psychologist',?)")->execute(['token-fixture-a',password_hash('Test-fixture-123',PASSWORD_DEFAULT),'Fixture A']);$a=(int)$p->lastInsertId();
 $p->prepare("INSERT INTO users(login,password_hash,role,pseudonym) VALUES(?,?,'psychologist',?)")->execute(['token-fixture-b',password_hash('Test-fixture-123',PASSWORD_DEFAULT),'Fixture B']);$b=(int)$p->lastInsertId();
 $p->prepare("INSERT INTO provider_configs(owner_id,provider_key,label,model_name,is_default,monthly_token_limit) VALUES(?,'gemini','Gemini fixture','fixture-model',1,1000)")->execute([$a]);$config=(int)$p->lastInsertId();
 $ins=$p->prepare("INSERT INTO llm_calls(owner_id,provider_config_id,provider_key,model_name,purpose,status,tokens_in,tokens_out,created_at) VALUES(?,? ,?,?,'prova',?,?,?,?)");
 $today=gmdate('Y-m-d');$ts=$today.' 12:00:00';
 foreach([[$a,$config,'gemini','fixture-model','ok',100,20,$ts],[$a,$config,'gemini','old-model','ok',50,10,$ts],[$a,$config,'gemini','fixture-model','unavailable',null,null,$ts],[$a,null,'openai','other-model','ok',999,999,$ts],[$b,null,'gemini','private-model','ok',9000,9000,$ts],[$a,$config,'gemini','old-model','ok',7,3,'2020-01-01 12:00:00']] as $row)$ins->execute($row);
 $d=TokenMonitor::report($a,['from'=>$today,'to'=>$today]);
 ok('Provider scelto dal predefinito',$d['provider']==='gemini');
 ok('Totale comprende lo storico del provider',(int)$d['lifetime']['tokens_in']===157&&(int)$d['lifetime']['tokens_out']===33);
 ok('Periodo include ultimo giorno per intero',(int)$d['period']['tokens_in']===150&&(int)$d['period']['tokens_out']===30);
 ok('Consumi incompleti non convertiti in chiamate note',(int)$d['period']['unknown_usage']===1);
 ok('Isolamento per proprietario',(int)$d['period']['calls']===3&&!str_contains(json_encode($d),'private-model'));
 ok('Ripartizione modelli storici',count($d['models'])===2);
 ok('Chiamate senza sessione rendicontate',(int)$d['outside_sessions']['tokens_in']===150);
 ok('Budget locale distinto dal totale storico',$d['local_budget']['used']===180&&$d['local_budget']['remaining']===820);
 ok('Modello default senza credenziali',!str_contains(json_encode($d),'api_key'));
 ok('Filtro altro provider',(int)TokenMonitor::report($a,['provider'=>'openai','from'=>$today,'to'=>$today])['period']['tokens_in']===999);
 foreach([['from'=>'2026-02-31'],['from'=>'2026-12-01','to'=>'2026-01-01'],['from'=>'2020-01-01','to'=>'2026-01-01'],['provider'=>"gemini' OR 1=1"]] as $q){try{TokenMonitor::report($a,$q);throw new RuntimeException('Validazione assente');}catch(\Ablet\Http\ApiError $e){ok('Filtro non valido respinto',true);}}
 ok('Paginazione senza sessioni coerente',$d['sessions']===[]&&$d['session_count']===0);
 $p->prepare("INSERT INTO activities(owner_id,title,assignment_text) VALUES(?,'Fixture attività','Test')")->execute([$a]);$activity=(int)$p->lastInsertId();
 $student=(int)$p->query("SELECT id FROM users WHERE role='student' LIMIT 1")->fetchColumn();
 $ss=$p->prepare("INSERT INTO sessions(public_id,student_id,activity_id,srl_data) VALUES(?,?,?,'{}')");
 $cs=$p->prepare("INSERT INTO llm_calls(owner_id,provider_config_id,session_id,provider_key,model_name,purpose,status,tokens_in,tokens_out,created_at) VALUES(?,?,?,'gemini','session-model','turno','ok',10,2,?)");
 for($i=0;$i<26;$i++){$ss->execute([bin2hex(random_bytes(8)),$student,$activity]);$sid=(int)$p->lastInsertId();$cs->execute([$a,$config,$sid,$ts]);}
 $page1=TokenMonitor::report($a,['from'=>$today,'to'=>$today]);$page2=TokenMonitor::report($a,['from'=>$today,'to'=>$today,'page'=>2]);
 ok('Storico paginato 25 più 1',count($page1['sessions'])===25&&count($page2['sessions'])===1&&$page1['session_count']===26);
 ok('Pagine non sovrapposte',!in_array($page2['sessions'][0]['session_id'],array_column($page1['sessions'],'session_id')));
 ok('Consumi della sessione corretti',(int)$page2['sessions'][0]['tokens_in']===10&&(int)$page2['sessions'][0]['tokens_out']===2);
 $p->prepare('UPDATE activities SET owner_id=? WHERE id=?')->execute([$b,$activity]);
 ok('Sessione non esposta se attività trasferita',TokenMonitor::report($a,['from'=>$today,'to'=>$today])['sessions']===[]);

}finally{$p->rollBack();}
echo "Monitor token: $checks controlli superati.\n";
