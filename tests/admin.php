<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Env};
$p=Database::pdo();$app=getenv('APP')?:'http://127.0.0.1:8101';$count=0;
function ac(string $n,bool $ok):void{global $count;if(!$ok)throw new RuntimeException($n);$count++;echo "✓ $n\n";}
final class AdminClient{
 private string $jar;public string $csrf='';
 function __construct(){ $this->jar=tempnam(sys_get_temp_dir(),'abladmin');$this->csrf=$this->req('GET','/api/me')[1]['csrf']; }
 function __destruct(){unlink($this->jar);}
 function req(string $method,string $path,?array $body=null,bool $csrf=true):array{global $app;$ch=curl_init($app.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.($csrf?$this->csrf:'')],CURLOPT_TIMEOUT=>10]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($ch);$s=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$s,json_decode((string)$raw,true),$raw];}
 function login(string $login,string $pw,string $role='psychologist'):array{$this->csrf=$this->req('GET','/api/me')[1]['csrf'];$r=$this->req('POST','/api/login',['login'=>$login,'password'=>$pw,'expected_role'=>$role]);if(isset($r[1]['csrf']))$this->csrf=$r[1]['csrf'];return $r;}
}
$a=new AdminClient();$anon=new AdminClient();ac('Admin richiede autenticazione',$anon->req('GET','/api/admin/users')[0]===401);
ac('Professionista accede con le credenziali esistenti',$a->login('demo.psicologo','Prova-demo.psicologo')[0]===200);
ac('Professionista non entra nell’area admin',$a->req('GET','/api/admin/users')[0]===403);
ac('Chiave errata non promuove',$a->req('POST','/api/psy/activate-admin',['owner_key'=>'errata'])[0]===403);
ac('Chiave proprietario abilita solo il primo admin',$a->req('POST','/api/psy/activate-admin',['owner_key'=>Env::get('INSTALL_KEY')])[0]===200);
$adminLogin=new AdminClient();ac('Accesso dedicato super admin disponibile',$adminLogin->login('demo.psicologo','Prova-demo.psicologo','admin')[0]===200);
ac('Ruolo amministratore verificato dal server',$a->req('GET','/api/me')[1]['user']['role']==='admin');
ac('Nessuna seconda attivazione dopo il primo admin',$a->req('POST','/api/psy/activate-admin',['owner_key'=>Env::get('INSTALL_KEY')])[0]===409);
ac('Admin conserva il proprio spazio professionale',$a->req('GET','/api/psy/bootstrap')[0]===200);
$body=['full_name'=>'Professionista admin test','profession'=>'educator','organization'=>'Test','biography'=>'','login'=>'admin-created-test'];
[$status,$new]=$a->req('POST','/api/admin/users',$body);ac('Admin crea un professionista',$status===200&&isset($new['password']));$id=(int)$new['id'];
$client=new AdminClient();ac('Password iniziale utilizzabile',$client->login($body['login'],$new['password'])[0]===200);
$untouched=new AdminClient();$untouched->login($body['login'],$new['password']);
$list=$a->req('GET','/api/admin/users?q=admin-created-test')[1];ac('Ricerca e conteggi senza hash o password',$list['total']===1&&!str_contains(json_encode($list),'password_hash'));
ac('Modifica admin richiede CSRF',$a->req('PUT','/api/admin/users/'.$id,$body+['status'=>'suspended'],false)[0]===403);
ac('Sospensione disponibile',$a->req('PUT','/api/admin/users/'.$id,$body+['status'=>'suspended'])[0]===200);
ac('Sospensione blocca la sessione esistente',$client->req('GET','/api/psy/account')[0]===401);
ac('Riattivazione disponibile',$a->req('PUT','/api/admin/users/'.$id,$body+['status'=>'active'])[0]===200);
ac('Riattivazione non riabilita i cookie precedenti',$client->req('GET','/api/psy/account')[0]===401);
ac('Cookie non usato durante sospensione resta revocato dopo riattivazione',$untouched->req('GET','/api/psy/account')[0]===401);
ac('Nuovo accesso dopo riattivazione',$client->login($body['login'],$new['password'])[0]===200);
$pw=$a->req('POST','/api/admin/users/'.$id.'/reset',[])[1]['password'];
ac('Reset password revoca le sessioni',$client->req('GET','/api/psy/account')[0]===401);
ac('Password precedente non più valida',$client->login($body['login'],$new['password'])[0]===401);
ac('Nuova password valida',$client->login($body['login'],$pw)[0]===200);
ac('Professionista non legge il CMS',$client->req('GET','/api/admin/home')[0]===403);
ac('Admin non modifica un altro amministratore tramite utenti professionali',$a->req('PUT','/api/admin/users/1',$body+['status'=>'suspended'])[0]===404);
$home=$a->req('GET','/api/admin/home')[1];$key=$home['fields'][0]['key'];$rev=$home['revision'];
$draft=['revision'=>$rev,'texts'=>[$key=>'Titolo sintetico <script>non eseguibile</script>'],'images'=>[]];
ac('Salvataggio bozza',$a->req('PUT','/api/admin/home',$draft)[0]===200);
ac('Bozza non esposta al pubblico',!str_contains($anon->req('GET','/api/site/home')[2],'Titolo sintetico'));
ac('Conflitto fra revisioni rilevato',$a->req('PUT','/api/admin/home',$draft)[0]===409);
ac('Pubblicazione con revisione corrente',$a->req('POST','/api/admin/home/publish',['revision'=>$rev+1])[0]===200);
ac('Pubblico vede soltanto il pubblicato',str_contains($anon->req('GET','/api/site/home')[2],'Titolo sintetico'));
$home=$a->req('GET','/api/admin/home')[1];$version=$home['versions'][0]['id'];
ac('Storico ripristinabile in bozza',$a->req('POST','/api/admin/home/restore/'.$version,['revision'=>$home['revision']])[0]===200);
ac('Immagine non raster respinta',$a->req('POST','/api/admin/home/image',['data'=>base64_encode('<svg onload="alert(1)"></svg>')])[0]===422);
$png=file_get_contents(ABLET_ROOT.'/public/assets/icons/favicon-64.png');$image=$a->req('POST','/api/admin/home/image',['data'=>base64_encode($png)]);ac('Caricamento PNG validato',$image[0]===200&&strlen($image[1]['id'])===64);$media=$image[1]['id'];
ac('Immagine pubblica restituita come file',$anon->req('GET','/api/site/media/'.$media)[2]===$png);
$home=$a->req('GET','/api/admin/home')[1];
ac('Testo alternativo immagine obbligatorio',$a->req('PUT','/api/admin/home',['revision'=>$home['revision'],'texts'=>[],'images'=>['hero'=>['id'=>$media,'alt'=>'']]])[0]===422);
ac('Testi fuori catalogo respinti',$a->req('PUT','/api/admin/home',['revision'=>$home['revision'],'texts'=>['chiave_falsa'=>'x'],'images'=>[]])[0]===422);
// Ripristino dei soli dati sintetici di questa suite.
$p->exec("UPDATE site_homepage SET draft_json='{}',published_json='{}'");
$p->prepare('DELETE FROM rubrics WHERE owner_id=?')->execute([$id]);$p->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
$p->exec("UPDATE users SET role='psychologist' WHERE login='demo.psicologo'");$p->prepare('DELETE FROM site_media WHERE id=?')->execute([$media]);@unlink(ABLET_ROOT.'/storage/site-media/'.$media);
echo "Super admin: $count controlli superati.\n";
