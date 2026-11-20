<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Env,Ids};
if(Env::get('APP_ENV')!=='development'||!str_contains(Env::get('DB_NAME',''),'test'))throw new RuntimeException('Solo test');
$p=Database::pdo();$pass=0;$fail=0;
function ck(string $n,bool $ok):void{global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'✓ ':'✗ ').$n."\n";}
final class DebugClient {
 public string $jar;public string $csrf='';
 function __construct(){$this->jar=tempnam(sys_get_temp_dir(),'debug-http-');$this->csrf=$this->req('GET','/api/me')[1]['csrf'];}
 function __destruct(){@unlink($this->jar);}
 function handle(string $method,string $url,?array $in=null):CurlHandle{$c=curl_init((getenv('APP')?:'http://127.0.0.1:8101').$url);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$this->csrf],CURLOPT_TIMEOUT=>20]);if($in!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($in));return $c;}
 function req(string $method,string $url,?array $in=null):array{$c=$this->handle($method,$url,$in);$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);$r=json_decode((string)$raw,true);if(isset($r['csrf']))$this->csrf=$r['csrf'];return [$status,$r];}
 function login(string $user):int{return $this->req('POST','/api/login',['login'=>$user,'password'=>'Debug-only-password'])[0];}
}
$c=new DebugClient();
ck('Login rifiuta codice array senza avvisi PHP',$c->req('POST','/api/login',['login'=>['bad'],'password'=>'x'])[0]===422);
ck('Login rifiuta password array senza avvisi PHP',$c->req('POST','/api/login',['login'=>'missing-debug','password'=>['bad']])[0]===422);
$make=function($role,$login)use($p){$p->prepare('INSERT INTO users(pseudonym,role,login,password_hash) VALUES(?,?,?,?)')->execute([Ids::pseudonym(),$role,$login,password_hash('Debug-only-password',PASSWORD_DEFAULT)]);return (int)$p->lastInsertId();};
$uid=$make('psychologist','debug-regression-pro');$sid=$make('student','debug-regression-student');
$p->prepare('INSERT INTO professional_students(professional_id,student_id) VALUES(?,?)')->execute([$uid,$sid]);
$p->prepare("INSERT INTO activities(owner_id,title,assignment_text,agent_enabled) VALUES(?,'Debug sintetico','Nessun dato reale',0)")->execute([$uid]);$aid=(int)$p->lastInsertId();
$ses=Ablet\Repository\SessionRepository::create($sid,$aid,null);$p->exec("UPDATE sessions SET state='forethought' WHERE id=$ses");$pub=$p->query("SELECT public_id FROM sessions WHERE id=$ses")->fetchColumn();
ck('Accesso professionista debug',$c->login('debug-regression-pro')===200);
ck('Filtro dashboard strutturato rifiutato',$c->req('GET','/api/psy/dashboard?from%5B%5D=2026-01-01')[0]===422);
ck('Filtro token strutturato rifiutato',$c->req('GET','/api/psy/tokens?provider%5B%5D=demo')[0]===422);
foreach(['from=2026-02-31','from=not-a-date','from=2026-02-01&to=2026-01-01'] as $query)ck('Periodo dashboard invalido: '.$query,$c->req('GET','/api/psy/dashboard?'.$query)[0]===422);
ck('Data token con byte nullo rifiutata',$c->req('GET','/api/psy/tokens?from=2026-01-01%00')[0]===422);
ck('Periodo dashboard valido',$c->req('GET','/api/psy/dashboard?from=2026-01-01&to=2026-02-01')[0]===200);
ck('Provider rifiuta chiave strutturata',$c->req('POST','/api/psy/providers',['provider_key'=>'demo','api_key'=>['not-a-key']])[0]===422);
$s=new DebugClient();$s->login('debug-regression-student');
ck('Messaggio array rifiutato',$s->req('POST',"/api/student/sessions/$pub/message",['text'=>['bad']])[0]===422);
ck('Azione array rifiutata',$s->req('POST',"/api/student/sessions/$pub/action",['action'=>['bad']])[0]===422);
ck('Nessun messaggio spurio salvato',(int)$p->query("SELECT COUNT(*) FROM messages WHERE session_id=$ses")->fetchColumn()===0);
// Collisione fra due salvataggi di provider predefiniti, stesso professionista.
$mh=curl_multi_init();$hs=[];for($i=0;$i<8;$i++){$h=$c->handle('POST','/api/psy/providers',['provider_key'=>'demo','label'=>'Default concorrente '.$i,'is_default'=>true]);$hs[]=$h;curl_multi_add_handle($mh,$h);}do{$status=curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,0.2);}while($running&&$status===CURLM_OK);
$all=true;foreach($hs as $h){$all=$all&&curl_getinfo($h,CURLINFO_RESPONSE_CODE)===200;curl_multi_remove_handle($mh,$h);curl_close($h);}curl_multi_close($mh);ck('Salvataggi provider concorrenti validi',$all);
ck('Un solo provider predefinito dopo concorrenza',(int)$p->query("SELECT COUNT(*) FROM provider_configs WHERE owner_id=$uid AND is_default=1")->fetchColumn()===1);
ck('Limite token oltre la colonna SQL respinto',$c->req('POST','/api/psy/providers',['provider_key'=>'demo','label'=>'Limite eccessivo','is_default'=>true,'monthly_token_limit'=>4294967296])[0]===422);
ck('Salvataggio fallito conserva provider predefinito',(int)$p->query("SELECT COUNT(*) FROM provider_configs WHERE owner_id=$uid AND is_default=1")->fetchColumn()===1);
$p->exec("UPDATE users SET role='admin' WHERE id=$uid");
ck('Voce rifiuta testo strutturato prima del provider',$c->req('POST','/api/voice/speech',['text'=>['bad']])[0]===422);
ck('Super admin accede alla configurazione vocale professionale',$c->req('POST','/api/voice/speech',['text'=>'Prova sintetica'])[1]['error']==='voce_server_non_configurata');
// Il controllo della preview deve valere anche nel workspace dell'amministratore.
ck('Preview voce admin segue la configurazione richiesta',$c->req('POST','/api/voice/speech',['text'=>'Prova','preview'=>['engine'=>'server']])[1]['error']==='scegli_provider_audio');
$p->exec("DELETE FROM sessions WHERE student_id=$sid");$p->exec("DELETE FROM activities WHERE id=$aid");$p->exec("DELETE FROM users WHERE id IN($sid,$uid)");
echo "Debug regressioni: $pass superati, $fail falliti.\n";exit($fail?1:0);
