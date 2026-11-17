<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Env};
$p=Database::pdo();$app=getenv('APP')?:'http://127.0.0.1:8101';$n=0;$outbox=(string)Env::get('PASSWORD_RESET_TEST_OUTBOX');
function pc(string $s,bool $ok):void{global $n;if(!$ok)throw new RuntimeException($s);$n++;echo "✓ $s\n";}
final class RecoveryClient{
 private string $jar;public string $csrf='';
 function __construct(){$this->jar=tempnam(sys_get_temp_dir(),'ablreset');$this->refresh();}
 function __destruct(){unlink($this->jar);}
 function refresh():void{$this->csrf=$this->req('GET','/api/me')[1]['csrf'];}
 function jar():string{return $this->jar;}
 function req(string $method,string $path,?array $body=null,bool $csrf=true,string $host=''):array{global $app;$ch=curl_init($app.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','X-CSRF-Token: '.($csrf?$this->csrf:'')],$host!==''?['Host: '.$host]:[]),CURLOPT_TIMEOUT=>10]);if($host!==''){foreach(file($this->jar) as $line){$parts=explode("\t",trim($line));if(count($parts)===7&&$parts[5]==='ablet_sid'){curl_setopt($ch,CURLOPT_COOKIE,'ablet_sid='.$parts[6]);break;}}}if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,json_decode((string)$raw,true),$raw];}
 function login(string $login,string $pw,string $role):int{$this->refresh();$r=$this->req('POST','/api/login',['login'=>$login,'password'=>$pw,'expected_role'=>$role]);if(isset($r[1]['csrf']))$this->csrf=$r[1]['csrf'];return $r[0];}
}
if(Env::get('APP_ENV')!=='development'||!str_contains((string)Env::get('DB_NAME'),'test')||$outbox==='')throw new RuntimeException('Solo ambiente test con outbox simulato');
$p->exec("DELETE FROM rate_limits WHERE bucket LIKE 'pwreset:%'");file_put_contents($outbox,'');$owner=(int)$p->query("SELECT id FROM users WHERE login='demo.psicologo'")->fetchColumn();$p->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$owner]);
$admin=new RecoveryClient();pc('Configurazione richiede super admin',(new RecoveryClient())->req('GET','/api/admin/mail')[0]===401);
pc('Accesso admin di test',$admin->login('demo.psicologo','Prova-demo.psicologo','admin')===200);
$c=new RecoveryClient();pc('Servizio non configurato esplicitamente non disponibile',$c->req('GET','/api/password-reset/status')[1]['available']===false);
$settings=['enabled'=>true,'from'=>'ablet@example.invalid','public_url'=>$app];
pc('URL con credenziali non ammesso',$admin->req('PUT','/api/admin/mail',array_replace($settings,['public_url'=>'https://user:secret@example.invalid']))[0]===422);
pc('Mittente con intestazioni aggiuntive respinto',$admin->req('PUT','/api/admin/mail',array_replace($settings,['from'=>"sender@example.invalid\r\nBcc: other@example.invalid"]))[0]===422);
pc('Configurazione posta salvata',$admin->req('PUT','/api/admin/mail',$settings)[0]===200);
pc('Stato pubblico disponibile',$c->req('GET','/api/password-reset/status')[1]['available']===true);
$test=$admin->req('POST','/api/admin/mail/test',['to'=>'test-recipient@example.invalid'])[1];pc('Prova e-mail simulata disponibile al super admin',$test['accepted']===true&&$test['simulated']===true);file_put_contents($outbox,'');
$login='reset-prof@example.invalid';$password='Before-reset-only-123';
$p->prepare("INSERT INTO users(pseudonym,role,login,password_hash,display_alias) VALUES(?,'psychologist',?,?,'Reset sintetico')")->execute([\Ablet\Support\Ids::pseudonym(),$login,password_hash($password,PASSWORD_DEFAULT)]);$uid=(int)$p->lastInsertId();
$member=new RecoveryClient();pc('Account prima del recupero valido',$member->login($login,$password,'psychologist')===200);
pc('Professionista non modifica la posta',$member->req('PUT','/api/admin/mail',$settings)[0]===403);
pc('Richiesta richiede CSRF',$c->req('POST','/api/password-reset/request',['email'=>$login],false)[0]===403);
$known=$c->req('POST','/api/password-reset/request',['email'=>$login],true,'forged.example.invalid');$unknown=$c->req('POST','/api/password-reset/request',['email'=>'unknown@example.invalid']);
pc('Risposta identica per e-mail presente e assente',$known[0]===200&&$known[1]===$unknown[1]);
$p->prepare("INSERT INTO users(pseudonym,role,login,password_hash) VALUES(?,'student','student-recovery@example.invalid',?)")->execute([\Ablet\Support\Ids::pseudonym(),password_hash('Student-test-only-123',PASSWORD_DEFAULT)]);$student=(int)$p->lastInsertId();
$studentRequest=$c->req('POST','/api/password-reset/request',['email'=>'student-recovery@example.invalid']);pc('Gli studenti non ricevono recuperi professionali',$studentRequest[1]===$known[1]&&count(file($outbox))===1);
$mail=json_decode(trim(file_get_contents($outbox)),true);pc('Solo il destinatario valido genera una e-mail',$mail['to']===$login&&count(file($outbox))===1);
preg_match('/#token=([a-f0-9]{64})/',$mail['body'],$m);$token=$m[1];
pc('Link usa URL canonico e frammento',str_contains($mail['body'],$app.'/?recupero=password#token='));
$q=$p->prepare('SELECT token_hash FROM password_reset_tokens WHERE user_id=?');$q->execute([$uid]);$stored=$q->fetchColumn();pc('Database conserva solo hash del token',$stored===hash('sha256',$token)&&$stored!==$token);
pc('Token non esposto dalla risposta HTTP',!str_contains($known[2],$token));
$next='After-reset-only-456';
pc('Conferma richiede CSRF',$c->req('POST','/api/password-reset/confirm',['token'=>$token,'password'=>$next],false)[0]===403);
pc('Password troppo breve respinta',$c->req('POST','/api/password-reset/confirm',['token'=>$token,'password'=>'short'])[0]===422);
pc('Token inesistente respinto',$c->req('POST','/api/password-reset/confirm',['token'=>str_repeat('0',64),'password'=>$next])[0]===422);
pc('Link valido aggiorna la password',$c->req('POST','/api/password-reset/confirm',['token'=>$token,'password'=>$next])[0]===200);
pc('Link usato non riutilizzabile',$c->req('POST','/api/password-reset/confirm',['token'=>$token,'password'=>$next])[0]===422);
pc('Le sessioni precedenti sono revocate',$member->req('GET','/api/psy/account')[0]===401);
pc('Password vecchia non più utilizzabile',$member->login($login,$password,'psychologist')===401);
pc('Accesso con nuova password',$member->login($login,$next,'psychologist')===200);
pc('Il recupero non autentica automaticamente',$c->req('GET','/api/me')[1]['user']===null);
$p->exec("DELETE FROM rate_limits WHERE bucket LIKE 'pwreset:%'");file_put_contents($outbox,'');$c->refresh();
$c->req('POST','/api/password-reset/request',['email'=>$login]);$mail=json_decode(trim(file_get_contents($outbox)),true);preg_match('/#token=([a-f0-9]{64})/',$mail['body'],$m);$expired=$m[1];$p->prepare('UPDATE password_reset_tokens SET expires_at=UTC_TIMESTAMP(3)-INTERVAL 1 SECOND WHERE token_hash=?')->execute([hash('sha256',$expired)]);
pc('Link scaduto respinto',$c->req('POST','/api/password-reset/confirm',['token'=>$expired,'password'=>'Expired-token-pass-123'])[0]===422);
file_put_contents($outbox,'');$c->req('POST','/api/password-reset/request',['email'=>$login]);$mail=json_decode(trim(file_get_contents($outbox)),true);preg_match('/#token=([a-f0-9]{64})/',$mail['body'],$m);$stale=$m[1];$p->prepare('UPDATE users SET session_version=session_version+1 WHERE id=?')->execute([$uid]);
pc('Reset amministrativo o revoca invalida anche i link pendenti',$c->req('POST','/api/password-reset/confirm',['token'=>$stale,'password'=>'Invalidated-pass-123'])[0]===422);
$c->req('POST','/api/password-reset/request',['email'=>$login]);
pc('Soglia per indirizzo blocca richieste ripetute',$c->req('POST','/api/password-reset/request',['email'=>$login])[0]===429);
$rawAudit=(string)$p->query("SELECT GROUP_CONCAT(COALESCE(meta,'')) FROM audit_log WHERE action LIKE 'password_%'")->fetchColumn();pc('Audit non contiene token o password',!str_contains($rawAudit,$token)&&!str_contains($rawAudit,$next));
$p->exec("DELETE FROM rate_limits WHERE bucket LIKE 'pwreset:%'");file_put_contents($outbox,'');
$c->req('POST','/api/password-reset/request',['email'=>$login]);$c->req('POST','/api/password-reset/request',['email'=>$login]);
$tokens=[];foreach(file($outbox) as $line){$mail=json_decode($line,true);preg_match('/#token=([a-f0-9]{64})/',$mail['body'],$m);$tokens[]=$m[1];}
pc('Due richieste generano token indipendenti',count(array_unique($tokens))===2);
$second=new RecoveryClient();$c->refresh();$clients=[$c,$second];$mh=curl_multi_init();$handles=[];
$version=(int)$p->query('SELECT session_version FROM users WHERE id='.$uid)->fetchColumn();
foreach($tokens as $i=>$tk){$ch=curl_init($app.'/api/password-reset/confirm');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$clients[$i]->jar(),CURLOPT_POSTFIELDS=>json_encode(['token'=>$tk,'password'=>'Concurrent-password-'.$i.'-123']),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$clients[$i]->csrf],CURLOPT_TIMEOUT=>10]);$handles[]=$ch;curl_multi_add_handle($mh,$ch);}
do{curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,0.1);}while($running);
$statuses=[];foreach($handles as $ch){$statuses[]=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($mh,$ch);curl_close($ch);}curl_multi_close($mh);sort($statuses);
pc('Due conferme concorrenti consentono un solo cambio password',$statuses===[200,422]);
pc('La concorrenza incrementa la versione di sessione una sola volta',(int)$p->query('SELECT session_version FROM users WHERE id='.$uid)->fetchColumn()===$version+1);
$c->req('POST','/api/password-reset/request',['email'=>$login]);$lines=file($outbox);$last=json_decode(end($lines),true);preg_match('/#token=([a-f0-9]{64})/',$last['body'],$m);$pending=$m[1];
$admin->req('PUT','/api/admin/mail',array_replace($settings,['enabled'=>false]));
pc('Disattivazione interrompe il recupero pubblico',$c->req('GET','/api/password-reset/status')[1]['available']===false);
pc('Disattivazione revoca anche i link pendenti',$c->req('POST','/api/password-reset/confirm',['token'=>$pending,'password'=>'Disabled-flow-test-123'])[0]===422);
$p->prepare('DELETE FROM users WHERE id=?')->execute([$student]);$p->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);$p->prepare("UPDATE users SET role='psychologist' WHERE id=?")->execute([$owner]);$p->exec("DELETE FROM app_flags WHERE name='password_mail'");@unlink($outbox);
echo "Recupero password: $n controlli superati.\n";
