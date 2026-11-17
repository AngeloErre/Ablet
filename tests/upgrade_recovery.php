<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Migrator};
if(getenv('APP_ENV')!=='development'||!str_contains((string)getenv('DB_NAME'),'test'))throw new RuntimeException('Solo database di test');
$p=Database::pdo();Migrator::dropAll($p);$dir=sys_get_temp_dir().'/ablet-recovery-upgrade-'.bin2hex(random_bytes(6));mkdir($dir);
try{
 foreach(glob(ABLET_ROOT.'/database/migrations/*.sql') as $f)if((int)basename($f)<=11)copy($f,$dir.'/'.basename($f));
 Migrator::run($p,$dir);$argv=['demo.php','--quiet'];ob_start();require ABLET_ROOT.'/database/seeds/demo.php';ob_end_clean();
 $p->exec("INSERT INTO app_flags(name,value) VALUES('recovery_migration_test','valore precedente')");
 $before=$p->query('SELECT id,login,password_hash,role,status,session_version FROM users ORDER BY id')->fetchAll();
 $r=Migrator::run($p,ABLET_ROOT.'/database/migrations');if($r['applied']!==['012_password_recovery.sql'])throw new RuntimeException('Migrazione inattesa');
 if($before!==$p->query('SELECT id,login,password_hash,role,status,session_version FROM users ORDER BY id')->fetchAll())throw new RuntimeException('Account alterati');
 if((int)$p->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn()!==0)throw new RuntimeException('Token inattesi');
 if($p->query("SELECT value FROM app_flags WHERE name='recovery_migration_test'")->fetchColumn()!=='valore precedente')throw new RuntimeException('Flag precedenti alterati');
 if($p->query("SHOW COLUMNS FROM app_flags LIKE 'value'")->fetch()['Type']!=='text')throw new RuntimeException('Capienza configurazione insufficiente');
 if(Migrator::run($p,ABLET_ROOT.'/database/migrations')['applied']!==[])throw new RuntimeException('Riesecuzione non idempotente');
 echo "Upgrade recupero 011→012: 6 controlli superati.\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
