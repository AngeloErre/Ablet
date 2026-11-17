<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Migrator};
if(getenv('APP_ENV')!=='development'||!str_contains((string)getenv('DB_NAME'),'test'))throw new RuntimeException('Solo database di test');
$p=Database::pdo();Migrator::dropAll($p);$dir=sys_get_temp_dir().'/ablet-upgrade-'.bin2hex(random_bytes(6));mkdir($dir);
try{
 foreach(glob(ABLET_ROOT.'/database/migrations/*.sql') as $f)if((int)basename($f)<=9)copy($f,$dir.'/'.basename($f));
 Migrator::run($p,$dir);$argv=['demo.php','--quiet'];ob_start();require ABLET_ROOT.'/database/seeds/demo.php';ob_end_clean();
 $before=$p->query('SELECT id,login,password_hash,role FROM users ORDER BY id')->fetchAll();
 $r=Migrator::run($p,ABLET_ROOT.'/database/migrations');
 if($r['applied']!==['010_professional_profiles.sql','011_super_admin.sql'])throw new RuntimeException('Migrazione inattesa');
 if($before!==$p->query('SELECT id,login,password_hash,role FROM users ORDER BY id')->fetchAll())throw new RuntimeException('Account precedenti alterati');
 if((int)$p->query('SELECT COUNT(*) FROM professional_profiles')->fetchColumn()!==0)throw new RuntimeException('Profili preesistenti inventati');
 if(Migrator::run($p,ABLET_ROOT.'/database/migrations')['applied']!==[])throw new RuntimeException('Migrazione non idempotente');
 echo "Upgrade account 009→011: 4 controlli superati.\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
