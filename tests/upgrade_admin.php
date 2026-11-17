<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Migrator};
if(getenv('APP_ENV')!=='development'||!str_contains((string)getenv('DB_NAME'),'test'))throw new RuntimeException('Solo database di test');
$p=Database::pdo();Migrator::dropAll($p);$dir=sys_get_temp_dir().'/ablet-admin-upgrade-'.bin2hex(random_bytes(6));mkdir($dir);
try{
 foreach(glob(ABLET_ROOT.'/database/migrations/*.sql') as $f)if((int)basename($f)<=10)copy($f,$dir.'/'.basename($f));
 Migrator::run($p,$dir);$argv=['demo.php','--quiet'];ob_start();require ABLET_ROOT.'/database/seeds/demo.php';ob_end_clean();
 $before=$p->query('SELECT id,login,password_hash,role,status FROM users ORDER BY id')->fetchAll();
 $r=Migrator::run($p,ABLET_ROOT.'/database/migrations');if($r['applied']!==['011_super_admin.sql','012_password_recovery.sql'])throw new RuntimeException('Migrazione inattesa');
 if($before!==$p->query('SELECT id,login,password_hash,role,status FROM users ORDER BY id')->fetchAll())throw new RuntimeException('Account alterati');
 if((int)$p->query('SELECT COUNT(*) FROM users WHERE session_version<>1')->fetchColumn()!==0)throw new RuntimeException('Versioni sessione inattese');
 if((int)$p->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()!==0)throw new RuntimeException('Promozione automatica non consentita');
 if($p->query('SELECT published_json FROM site_homepage WHERE id=1')->fetchColumn()!=='{}')throw new RuntimeException('Contenuto iniziale alterato');
 if(Migrator::run($p,ABLET_ROOT.'/database/migrations')['applied']!==[])throw new RuntimeException('Riesecuzione non idempotente');
 echo "Upgrade admin 010→012: 6 controlli superati.\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
