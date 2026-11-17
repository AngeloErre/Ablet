<?php
declare(strict_types=1);

// Uso: php bin/migrate.php            applica le migrazioni mancanti
//      php bin/migrate.php --fresh    ELIMINA tutte le tabelle e riapplica (solo sviluppo/test)
// Senza accesso SSH (es. hosting condiviso) usare l'installatore dal browser: /install.php

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Ablet\Support\Database;
use Ablet\Support\Env;
use Ablet\Support\Migrator;

$pdo = Database::pdo();

if (in_array('--fresh', $argv, true)) {
    if (Env::get('APP_ENV', 'production') === 'production') {
        fwrite(STDERR, "Rifiutato: --fresh non è consentito con APP_ENV=production.\n");
        exit(1);
    }
    Migrator::dropAll($pdo);
    echo "Schema azzerato.\n";
}

$r = Migrator::run($pdo, dirname(__DIR__) . '/database/migrations');
foreach ($r['applied'] as $name) {
    echo "Applicata: $name\n";
}
if ($r['skipped_triggers'] !== []) {
    echo "Attenzione: l'utente del database non può creare trigger (" . implode(', ', $r['skipped_triggers']) . ").\n"
        . "L'immutabilità delle versioni pubblicate resta garantita dall'applicazione, non dal database.\n";
}
echo "Migrazioni aggiornate.\n";
