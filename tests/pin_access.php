<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\Database;
use Ablet\Service\AccessPin;
$p=Database::pdo();if(!str_contains(getenv('DB_NAME')?:'','test'))throw new RuntimeException('Database di test richiesto');
$app=getenv('APP')?:'http://127.0.0.1:8101';$checks=0;
function ck(string $n,bool $v):void{global $checks;if(!$v)throw new RuntimeException($n);$checks++;echo "✓ $n\n";}
final class PinClient{
 private string $jar;public string $csrf='';
 function __construct(){$this->jar=tempnam(sys_get_temp_dir(),'pin-test-');$this->csrf=$this->req('GET','/api/me')[1]['csrf'];}
 function __destruct(){@unlink($this->jar);}
 function req(string $m,string $url,?array $body=null,bool $csrf=true):array{global $app;$c=curl_init($app.$url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.($csrf?$this->csrf:'')],CURLOPT_TIMEOUT=>15]);if($body!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($c);$s=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);$r=json_decode((string)$raw,true);if(isset($r['csrf']))$this->csrf=$r['csrf'];return [$s,$r];}
}
function clearLimits():void{Database::pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'pin:%' OR bucket LIKE 'pin-manage:%'");}
clearLimits();$p->exec("DELETE FROM rate_limits WHERE bucket LIKE 'signup:%'");
$pw='Synthetic-pin-test-password';$pro=new PinClient();[$s,$r]=$pro->req('POST','/api/register',['full_name'=>'PIN test','profession'=>'psychologist','email'=>'pin-test@example.invalid','password'=>$pw,'accepted_notice'=>true]);ck('Creazione professionista test',$s===200);
$uid=(int)$p->query("SELECT id FROM users WHERE login='pin-test@example.invalid'")->fetchColumn();
ck('Gestione PIN richiede password attuale',$pro->req('POST','/api/psy/account/pin',['password'=>'wrong'])[0]===401);
ck('Gestione PIN richiede CSRF',$pro->req('POST','/api/psy/account/pin',['password'=>$pw],false)[0]===403);
[$s,$r]=$pro->req('POST','/api/psy/account/pin',['password'=>$pw]);$pin=$r['pin']??'';ck('PIN professionista di sei cifre',$s===200&&preg_match('/^[0-9]{6}$/D',$pin)===1);
$q=$p->prepare('SELECT * FROM access_pins WHERE user_id=?');$q->execute([$uid]);$row=$q->fetch();ck('PIN conservato come HMAC e hash lento',$row['lookup_hash']===AccessPin::lookup($pin)&&$row['pin_hash']!==$pin&&password_verify($pin,$row['pin_hash']));
$anon=new PinClient();ck('Accesso PIN senza CSRF respinto',$anon->req('POST','/api/login',['pin'=>$pin],false)[0]===403);
[$s,$r]=$anon->req('POST','/api/login',['pin'=>$pin]);ck('Professionista entra col solo PIN senza identificatore',$s===200&&$r['user']['role']==='psychologist');
$wrong=new PinClient();ck('PIN nel portale errato ha errore generico',$wrong->req('POST','/api/login',['pin'=>$pin,'expected_role'=>'student'])[1]['error']==='credenziali_non_valide');
[$s,$r]=$pro->req('POST','/api/psy/account/pin',['password'=>$pw]);$new=$r['pin'];ck('Rigenerazione cambia PIN',$s===200&&$new!==$pin);
ck('PIN precedente non funziona più',(new PinClient())->req('POST','/api/login',['pin'=>$pin])[0]===401);
clearLimits();ck('Nuovo PIN funziona',(new PinClient())->req('POST','/api/login',['pin'=>$new])[0]===200);
[$s,$student]=$pro->req('POST','/api/psy/students',['alias'=>'Studente PIN sintetico','age_band'=>'8-10']);$sid=$student['id'];ck('Creazione studente',$s===200);
$duplicate=false;try{$p->prepare('INSERT INTO access_pins(user_id,lookup_hash,pin_hash,issued_version) VALUES(?,?,?,1)')->execute([$sid,AccessPin::lookup($new),password_hash($new,PASSWORD_DEFAULT)]);}catch(PDOException $e){$duplicate=(int)($e->errorInfo[1]??0)===1062;}ck('Indice univoco impedisce stesso PIN fra ruoli',$duplicate);
[$s,$r]=$pro->req('POST',"/api/psy/students/$sid/pin");$spin=$r['pin'];ck('Professionista genera PIN studente',$s===200&&strlen($spin)===6&&$spin!==$new);
$learner=new PinClient();[$s,$r]=$learner->req('POST','/api/login',['pin'=>$spin,'expected_role'=>'student']);ck('Studente entra col solo PIN',$s===200&&$r['user']['role']==='student');
ck('Accesso studente non concede area professionale',$learner->req('POST','/api/psy/account/pin',['password'=>$pw])[0]===403);
clearLimits();
// Esercita il percorso con zeri iniziali senza dipendere dal generatore casuale.
$p->prepare('UPDATE access_pins SET lookup_hash=?,pin_hash=? WHERE user_id=?')->execute([AccessPin::lookup('001234'),password_hash('001234',PASSWORD_DEFAULT),$sid]);
ck('Zeri iniziali preservati',(new PinClient())->req('POST','/api/login',['pin'=>'001234'])[0]===200);
ck('PIN numerico anziché stringa respinto',(new PinClient())->req('POST','/api/login',['pin'=>1234])[0]===401);
ck('Revoca PIN studente',$pro->req('DELETE',"/api/psy/students/$sid/pin")[0]===200);
ck('PIN revocato respinto',(new PinClient())->req('POST','/api/login',['pin'=>'001234'])[0]===401);
clearLimits();$pro->req('POST',"/api/psy/students/$sid/pin");$reset=$pro->req('POST',"/api/psy/students/$sid/reset");
$q->execute([$sid]);$sr=$q->fetch();$v=$p->query("SELECT session_version FROM users WHERE id=$sid")->fetchColumn();ck('Reset password studente invalida il PIN',(int)$sr['issued_version']!==(int)$v&&$reset[0]===200);
ck('Password alternativa resta disponibile',(new PinClient())->req('POST','/api/login',['login'=>$student['login'],'password'=>$reset[1]['password']])[0]===200);
clearLimits();ck('Disattivazione professionista',$pro->req('DELETE','/api/psy/account/pin',['password'=>$pw])[0]===200);ck('PIN professionista disattivato',(new PinClient())->req('POST','/api/login',['pin'=>$new])[0]===401);
clearLimits();for($i=0;$i<5;$i++)ck('Tentativo non valido limitato '.$i,(new PinClient())->req('POST','/api/login',['pin'=>'not-valid'])[0]===401);
ck('Sesto tentativo limitato anche cambiando PIN',(new PinClient())->req('POST','/api/login',['pin'=>'999999'])[0]===429);
clearLimits();$p->exec("INSERT INTO rate_limits(bucket,window_start,hits) VALUES('pin:global',UTC_TIMESTAMP(3),100) ON DUPLICATE KEY UPDATE hits=100,window_start=UTC_TIMESTAMP(3)");ck('Limite globale contro tentativi distribuiti',(new PinClient())->req('POST','/api/login',['pin'=>'999999'])[0]===429);clearLimits();
// Sospensione, cambio versione e promozione amministratore invalidano l'accesso.
$active=AccessPin::issue($uid)['pin'];$p->exec("UPDATE users SET status='suspended' WHERE id=$uid");ck('Account sospeso non accede con PIN',(new PinClient())->req('POST','/api/login',['pin'=>$active])[0]===401);$p->exec("UPDATE users SET status='active',session_version=session_version+1 WHERE id=$uid");ck('PIN emesso prima del reset non valido',(new PinClient())->req('POST','/api/login',['pin'=>$active])[0]===401);
$active=AccessPin::issue($uid)['pin'];$p->exec("UPDATE users SET role='admin' WHERE id=$uid");ck('Super admin escluso dal PIN',(new PinClient())->req('POST','/api/login',['pin'=>$active])[0]===401);$p->exec("UPDATE users SET role='psychologist' WHERE id=$uid");
$other=(int)$p->query("SELECT student_id FROM professional_students WHERE professional_id<>$uid AND active_to IS NULL LIMIT 1")->fetchColumn();ck('PIN studente altrui non gestibile',$other>0&&$pro->req('POST',"/api/psy/students/$other/pin")[0]===401); // pro session invalidata dal cambio versione
$pro->req('GET','/api/me');$pro->req('POST','/api/login',['login'=>'pin-test@example.invalid','password'=>$pw]);ck('Isolamento studenti dopo nuova autenticazione',$pro->req('POST',"/api/psy/students/$other/pin")[0]===404);
$logs=$p->query("SELECT meta FROM audit_log WHERE entity='users' AND entity_id=$uid")->fetchAll();ck('Audit senza PIN in chiaro',!str_contains(json_encode($logs),$pin)&&!str_contains(json_encode($logs),$new));
$pro->req('DELETE',"/api/psy/students/$sid");$p->exec("DELETE FROM rubrics WHERE owner_id=$uid");$p->exec("DELETE FROM professional_settings WHERE owner_id=$uid");$p->exec("DELETE FROM users WHERE id=$uid");clearLimits();
echo "PIN: $checks verifiche superate.\n";
