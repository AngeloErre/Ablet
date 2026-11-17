<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\Database;
$p=Database::pdo();$app=getenv('APP')?:'http://127.0.0.1:8101';$checks=0;
function ck(string $name,bool $ok):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;echo "✓ $name\n";}
final class AccountClient{
 private string $jar;public string $csrf='';
 public function __construct(){ $this->jar=tempnam(sys_get_temp_dir(),'abl-account');$this->csrf=$this->request('GET','/api/me')[1]['csrf']; }
 public function __destruct(){unlink($this->jar);}
 public function request(string $method,string $path,?array $body=null,bool $csrf=true):array{
  global $app;$ch=curl_init($app.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.($csrf?$this->csrf:'')],CURLOPT_TIMEOUT=>10]);
  if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,json_decode((string)$raw,true)];
 }
}
$c=new AccountClient();$in=['full_name'=>'Professionista sintetico','profession'=>'educator','email'=>'account-test@example.invalid','password'=>'Test-only-password-123','accepted_notice'=>true];
$p->exec("DELETE FROM rate_limits WHERE bucket LIKE 'signup:%'");
ck('Registrazione richiede CSRF',$c->request('POST','/api/register',$in,false)[0]===403);
ck('Password breve respinta',$c->request('POST','/api/register',array_replace($in,['password'=>'breve']))[0]===422);
ck('Informazioni account da confermare',$c->request('POST','/api/register',array_replace($in,['accepted_notice'=>false]))[0]===422);
ck('Professione arbitraria respinta',$c->request('POST','/api/register',array_replace($in,['profession'=>'admin']))[0]===422);
[$status,$result]=$c->request('POST','/api/register',$in+['role'=>'admin']);
ck('Registrazione educatore e accesso immediato',$status===200&&($result['user']['role']??'')==='psychologist');$c->csrf=$result['csrf'];
$hash=$p->query("SELECT password_hash FROM users WHERE login='account-test@example.invalid'")->fetchColumn();ck('Password memorizzata con hash',password_verify($in['password'],$hash)&&$hash!==$in['password']);
$profile=$c->request('GET','/api/psy/account')[1];ck('Profilo educatore privato senza qualifica verificata',$profile['profile']['profession']==='educator'&&$profile['qualification_verified']===false);
$workspace=$c->request('GET','/api/psy/bootstrap')[1];
ck('Nuovo account non vede studenti altrui',$workspace['students']===[]);
ck('Nuovo spazio ha provider demo e rubriche iniziali',count($workspace['providers'])===1&&$workspace['providers'][0]['provider_key']==='demo'&&count($workspace['rubrics'])===2);
ck('Profilo modificabile',$c->request('PUT','/api/psy/account',['full_name'=>'Nome aggiornato','profession'=>'psychologist','organization'=>'Organizzazione sintetica','biography'=>'Presentazione sintetica'])[0]===200);
ck('Modifica profilo persistita',$c->request('GET','/api/psy/account')[1]['profile']['full_name']==='Nome aggiornato');
$anon=new AccountClient();ck('Profilo non leggibile senza accesso',$anon->request('GET','/api/psy/account')[0]===401);
ck('E-mail duplicata respinta',$anon->request('POST','/api/register',$in)[0]===409);
ck('Registrazione limitata per IP',$anon->request('POST','/api/register',array_replace($in,['email'=>'limited@example.invalid']))[0]===429);
$p->exec("DELETE FROM rate_limits WHERE bucket LIKE 'signup:%'");
$second=array_replace($in,['email'=>'psychologist-test@example.invalid','profession'=>'psychologist','full_name'=>'Secondo profilo sintetico']);
[$status,$result]=$anon->request('POST','/api/register',$second);ck('Registrazione psicologo disponibile',$status===200);$anon->csrf=$result['csrf'];
$other=(int)$p->query("SELECT id FROM users WHERE login='account-test@example.invalid'")->fetchColumn();
ck('Parametro user_id non espone il profilo altrui',$anon->request('GET','/api/psy/account?user_id='.$other)[1]['profile']['full_name']===$second['full_name']);
$logout=$c->request('POST','/api/logout')[1];$c->csrf=$logout['csrf'];
[$status,$result]=$c->request('POST','/api/login',['login'=>$in['email'],'password'=>$in['password'],'expected_role'=>'psychologist']);ck('Credenziali valide dopo logout',$status===200);
$p->exec("DELETE FROM rubrics WHERE owner_id IN(SELECT id FROM users WHERE login IN('account-test@example.invalid','psychologist-test@example.invalid'))");
$p->exec("DELETE FROM professional_settings WHERE owner_id IN(SELECT id FROM users WHERE login IN('account-test@example.invalid','psychologist-test@example.invalid'))");
$p->exec("DELETE FROM users WHERE login IN('account-test@example.invalid','psychologist-test@example.invalid')");
echo "Account: $checks verifiche superate.\n";
