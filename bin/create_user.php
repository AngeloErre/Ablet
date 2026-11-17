<?php
declare(strict_types=1);

// Uso: php bin/create_user.php psychologist|admin <login> [alias]
// Senza SSH il primo professionista si crea dall'installatore web (/install.php).
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Ablet\Support\Database;
use Ablet\Support\Setup;

[$role, $login] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!in_array($role, ['psychologist', 'admin'], true) || $login === '') {
    fwrite(STDERR, "Uso: php bin/create_user.php psychologist|admin <login> [alias]\n");
    exit(1);
}
$pw = Setup::createProfessional(Database::pdo(), $login, $argv[3] ?? $login, $role);
echo "Utente creato. Login: $login · password (mostrata una sola volta): $pw\n";
