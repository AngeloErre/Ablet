<?php
declare(strict_types=1);
namespace Ablet\Http;
use Ablet\Support\{Database,Ids,Setup};

/** Nessuna di queste rotte conferisce privilegi tramite registrazione pubblica. */
final class AdminApi
{
 public static function register():void {
  $route=static fn($m,$p,$f)=>App::route($m,'/api/admin'.$p,[self::class,$f],'admin');
  $route('GET','/version','version');
  $route('GET','/users','users');$route('POST','/users','create');$route('PUT','/users/{id}','update');$route('POST','/users/{id}/reset','reset');
  $route('GET','/home','home');$route('PUT','/home','saveHome');$route('POST','/home/publish','publish');$route('POST','/home/restore/{id}','restore');$route('POST','/home/image','upload');
  App::route('POST','/api/psy/activate-admin',[self::class,'activate'],'psychologist');
  App::route('GET','/api/site/home',static function():array { $q=Database::pdo()->query('SELECT published_json FROM site_homepage WHERE id=1');return ['content'=>json_decode($q->fetchColumn()?:'{}',true)]; });
  App::route('GET','/api/site/media/{id}',[self::class,'media']);
 }
 public static function version():array { return ['version'=>\Ablet\Support\Version::CURRENT]; }
 public static function activate():array {
  App::rateLimit('activate-admin:'.App::user()['id'],5,3600);
  $in=App::input();if(!Setup::ownerKeyMatches(is_string($in['owner_key']??null)?$in['owner_key']:''))throw new ApiError(403,'chiave_installazione_non_valida');
  $uid=App::user()['id'];
  Database::transaction(static function($p)use($uid):void{
   $p->exec("INSERT IGNORE INTO app_flags(name,value) VALUES('first_admin','pending')");
   $p->query("SELECT value FROM app_flags WHERE name='first_admin' FOR UPDATE")->fetchColumn();
   if((int)$p->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()>0)throw new ApiError(409,'super_admin_gia_presente');
   $p->prepare("UPDATE users SET role='admin' WHERE id=? AND role='psychologist' AND status='active'")->execute([$uid]);
   $p->exec("UPDATE app_flags SET value='activated' WHERE name='first_admin'");
  });App::audit('first_admin_activated','users',$uid);return ['ok'=>true];
 }
 private static function professional(int $id,bool $lock=false):array {
  $q=Database::pdo()->prepare("SELECT * FROM users WHERE id=? AND role='psychologist'".($lock?' FOR UPDATE':''));$q->execute([$id]);$u=$q->fetch();if(!$u)throw new ApiError(404,'professionista_non_trovato');return $u;
 }
 public static function users():array {
  $search=mb_substr(trim((string)($_GET['q']??'')),0,100);$page=max(1,min(100000,(int)($_GET['page']??1)));$args=[];$w="u.role='psychologist'";
  if($search!==''){$w.=' AND (u.login LIKE ? OR u.display_alias LIKE ?)';$args=['%'.$search.'%','%'.$search.'%'];}
  $p=Database::pdo();$q=$p->prepare("SELECT COUNT(*) FROM users u WHERE $w");$q->execute($args);$count=(int)$q->fetchColumn();$offset=($page-1)*25;
  $q=$p->prepare("SELECT u.id,u.login,u.display_alias,u.status,u.created_at,pp.profession,pp.organization,pp.biography,(SELECT COUNT(*) FROM professional_students ps WHERE ps.professional_id=u.id AND ps.active_to IS NULL) students FROM users u LEFT JOIN professional_profiles pp ON pp.user_id=u.id WHERE $w ORDER BY u.id DESC LIMIT 25 OFFSET $offset");$q->execute($args);
  return ['users'=>$q->fetchAll(),'total'=>$count,'page'=>$page,'page_size'=>25];
 }
 public static function create():array {
  $in=App::input();[$name,$profession,$organization,$bio]=AccountApi::validated($in);$login=self::login($in['login']??null);$pw=Ids::pseudonym(20);
  try{$id=Database::transaction(static function($p)use($name,$profession,$organization,$bio,$login,$pw):int{
   $p->prepare("INSERT INTO users(pseudonym,role,login,password_hash,display_alias) VALUES(?,'psychologist',?,?,?)")->execute([Ids::pseudonym(),$login,password_hash($pw,PASSWORD_DEFAULT),$name]);$id=(int)$p->lastInsertId();Setup::initializeProfessionalWorkspace($p,$id);
   $p->prepare('INSERT INTO professional_profiles(user_id,full_name,profession,organization,biography) VALUES(?,?,?,?,?)')->execute([$id,$name,$profession,$organization,$bio]);return $id;
  });}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1062)throw new ApiError(409,'account_gia_presente');throw $e;}
  App::audit('admin_created_professional','users',$id);return ['id'=>$id,'login'=>$login,'password'=>$pw];
 }
 private static function login(mixed $value):string {if(!is_string($value)||trim($value)===''||mb_strlen($value)>190||preg_match('/[\x00-\x1f]/',$value))throw new ApiError(422,'login_non_valido');return trim($value);}
 public static function update(array $a):array {
  $id=(int)$a['id'];$in=App::input();[$name,$profession,$organization,$bio]=AccountApi::validated($in);$login=self::login($in['login']??null);$status=$in['status']??'';
  if(!in_array($status,['active','suspended'],true))throw new ApiError(422,'stato_non_valido');
  try{Database::transaction(static function($p)use($id,$name,$profession,$organization,$bio,$login,$status):void{
   $u=self::professional($id,true);$revoke=$status!==$u['status']||$login!==$u['login'];
   $p->prepare('UPDATE users SET login=?,display_alias=?,status=?,session_version=session_version+? WHERE id=?')->execute([$login,$name,$status,(int)$revoke,$id]);
   $p->prepare('INSERT INTO professional_profiles(user_id,full_name,profession,organization,biography) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),profession=VALUES(profession),organization=VALUES(organization),biography=VALUES(biography),updated_at=UTC_TIMESTAMP(3)')->execute([$id,$name,$profession,$organization,$bio]);
  });}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)===1062)throw new ApiError(409,'account_gia_presente');throw $e;}
  App::audit('admin_updated_professional','users',$id,['status'=>$status]);return ['ok'=>true];
 }
 public static function reset(array $a):array {
  $id=(int)$a['id'];self::professional($id);App::rateLimit('admin-reset:'.App::user()['id'],20,300);$pw=Ids::pseudonym(20);
  Database::pdo()->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);App::audit('admin_reset_password','users',$id);return ['password'=>$pw];
 }
 private static function schema():array{return json_decode((string)file_get_contents(ABLET_ROOT.'/resources/home-content.json'),true)?:[];}
 public static function home():array {
  $row=Database::pdo()->query('SELECT * FROM site_homepage WHERE id=1')->fetch();
  return ['draft'=>json_decode($row['draft_json'],true),'published'=>json_decode($row['published_json'],true),'revision'=>(int)$row['revision'],'fields'=>self::schema(),'versions'=>Database::pdo()->query('SELECT id,created_at FROM site_home_versions ORDER BY id DESC LIMIT 20')->fetchAll()];
 }
 private static function content(array $in):array {
  $texts=$in['texts']??[];$images=$in['images']??[];if(!is_array($texts)||!is_array($images))throw new ApiError(422,'contenuto_non_valido');$allowed=array_column(self::schema(),null,'key');
  foreach($texts as $key=>$text)if(!isset($allowed[$key])||!is_string($text)||mb_strlen($text)>6000)throw new ApiError(422,'testo_non_valido');
  foreach($images as $slot=>$image){
   if(!in_array($slot,['hero','dashboard','analysis'],true)||!is_array($image)||!is_string($image['id']??null)||!preg_match('/^[a-f0-9]{64}$/',$image['id'])||!is_string($image['alt']??null)||trim($image['alt'])===''||mb_strlen($image['alt'])>250||!is_string($image['caption']??'')||mb_strlen($image['caption']??'')>600)throw new ApiError(422,'immagine_non_valida');
   $q=Database::pdo()->prepare('SELECT id FROM site_media WHERE id=?');$q->execute([$image['id']]);if(!$q->fetchColumn())throw new ApiError(422,'immagine_non_trovata');
   $images[$slot]=array_intersect_key($image,['id'=>1,'alt'=>1,'caption'=>1]);
  }return ['texts'=>(object)$texts,'images'=>(object)$images];
 }
 private static function mutate(callable $fn):array {
  $in=App::input();return Database::transaction(static function($p)use($in,$fn):array{
   $row=$p->query('SELECT * FROM site_homepage WHERE id=1 FOR UPDATE')->fetch();if(!isset($in['revision'])||(int)$in['revision']!==(int)$row['revision'])throw new ApiError(409,'contenuto_modificato_ricarica');
   $fn($p,$row,$in);return ['ok'=>true,'revision'=>(int)$row['revision']+1];
  });
 }
 public static function saveHome():array {$r=self::mutate(static function($p,$row,$in):void{$content=self::content($in);$p->prepare('UPDATE site_homepage SET draft_json=?,revision=revision+1,updated_at=UTC_TIMESTAMP(3) WHERE id=1')->execute([json_encode($content,JSON_UNESCAPED_UNICODE)]);});App::audit('home_draft_saved','site_homepage',1);return $r;}
 public static function publish():array {$uid=App::user()['id'];$r=self::mutate(static function($p,$row)use($uid):void{$p->prepare('INSERT INTO site_home_versions(content_json,editor_id) VALUES(?,?)')->execute([$row['draft_json'],$uid]);$p->exec('UPDATE site_homepage SET published_json=draft_json,revision=revision+1,updated_at=UTC_TIMESTAMP(3) WHERE id=1');});App::audit('home_published','site_homepage',1);return $r;}
 public static function restore(array $a):array {$id=(int)$a['id'];$r=self::mutate(static function($p)use($id):void{$q=$p->prepare('SELECT content_json FROM site_home_versions WHERE id=?');$q->execute([$id]);$json=$q->fetchColumn();if($json===false)throw new ApiError(404,'versione_non_trovata');$p->prepare('UPDATE site_homepage SET draft_json=?,revision=revision+1,updated_at=UTC_TIMESTAMP(3) WHERE id=1')->execute([$json]);});App::audit('home_version_restored_to_draft','site_homepage',1,['version'=>$id]);return $r;}
 public static function upload():array {
  App::rateLimit('site-image:'.App::user()['id'],20,300);$in=App::input();$raw=is_string($in['data']??null)?base64_decode($in['data'],true):false;
  if($raw===false||strlen($raw)>2000000||strlen($raw)<20)throw new ApiError(422,'immagine_non_valida');$size=@getimagesizefromstring($raw);$mime=$size['mime']??'';
  if(!$size||!in_array($mime,['image/png','image/jpeg','image/webp'],true)||$size[0]>4096||$size[1]>4096||$size[0]*$size[1]>10000000)throw new ApiError(422,'formato_immagine_non_valido');
  $id=hash('sha256',$raw);$dir=ABLET_ROOT.'/storage/site-media';if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new ApiError(500,'archivio_immagini_non_disponibile');
  $path=$dir.'/'.$id;if(!is_file($path)&&file_put_contents($path,$raw,LOCK_EX)===false)throw new ApiError(500,'salvataggio_immagine_fallito');
  Database::pdo()->prepare('INSERT IGNORE INTO site_media(id,mime,bytes) VALUES(?,?,?)')->execute([$id,$mime,strlen($raw)]);App::audit('home_image_uploaded','site_homepage',1,['id'=>$id]);return ['id'=>$id];
 }
 public static function media(array $a):never {
  $id=$a['id'];if(!preg_match('/^[a-f0-9]{64}$/',$id))throw new ApiError(404,'non_trovato');$q=Database::pdo()->prepare('SELECT mime,bytes FROM site_media WHERE id=?');$q->execute([$id]);$m=$q->fetch();$path=ABLET_ROOT.'/storage/site-media/'.$id;
  if(!$m||!is_file($path))throw new ApiError(404,'non_trovato');if(session_status()===PHP_SESSION_ACTIVE)session_write_close();header('Content-Type: '.$m['mime']);header('Cache-Control: private, max-age=86400, immutable');header('Content-Length: '.filesize($path));readfile($path);exit;
 }
}
