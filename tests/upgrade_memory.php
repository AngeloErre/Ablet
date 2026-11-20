<?php
declare(strict_types=1);
// Solo database di test: ricrea la condizione di aggiornamento da schema 013.
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Support\{Database,Migrator,Env};
if(!str_contains(Env::get('DB_NAME',''),'test'))throw new RuntimeException('Database di test richiesto');
$p=Database::pdo();$users=(int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn();$sessions=(int)$p->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
$p->exec('DROP TABLE student_memory_deltas');$p->exec('DROP TABLE student_memory_settings');$p->exec("DELETE FROM schema_migrations WHERE name='014_student_memory.sql'");
$r=Migrator::run($p,ABLET_ROOT.'/database/migrations');
if($r['applied']!==['014_student_memory.sql'])throw new RuntimeException('Migrazione inattesa');
if((int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn()!==$users||(int)$p->query('SELECT COUNT(*) FROM sessions')->fetchColumn()!==$sessions)throw new RuntimeException('Dati pregressi modificati');
if(Migrator::run($p,ABLET_ROOT.'/database/migrations')['applied']!==[])throw new RuntimeException('Migrazione non idempotente');
echo "Upgrade memoria: migrazione 014 applicata, utenti e sessioni conservati, seconda esecuzione senza modifiche.\n";
