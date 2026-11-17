<?php
declare(strict_types=1);

namespace Ablet\Support;

use PDO;

/**
 * Applica le migrazioni SQL in ordine. Usato da bin/migrate.php (riga di comando) e da public/install.php (browser).
 *
 * I trigger di immutabilità (migrazione 003) sono una protezione aggiuntiva a livello di database.
 * Su molti hosting condivisi l'utente del database non può creare trigger (privilegio TRIGGER o SUPER assente,
 * log binario attivo): in quel caso il trigger viene saltato e segnalato, e restano attive le protezioni
 * applicative (le query di modifica agiscono solo su bozze e non riassegnano la versione di una sessione).
 */
final class Migrator
{
    /** Codici MySQL/MariaDB per privilegi insufficienti sui trigger. */
    private const TRIGGER_DENIED = [1142, 1227, 1419, 1044];

    /** @return array{applied:string[],skipped_triggers:string[]} */
    public static function run(PDO $pdo, string $dir): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) PRIMARY KEY, applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_flags (
            name VARCHAR(80) PRIMARY KEY, value VARCHAR(255) NOT NULL, updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $profile = self::profile($pdo);
        $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        $skipped = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = self::adapt((string)file_get_contents($file), $profile);
            // I file con trigger separano le istruzioni con il doppio dollaro; gli altri con ";" a fine riga.
            $statements = str_contains($sql, '$$') ? explode('$$', $sql) : preg_split('/;\s*\n/', $sql);
            foreach ($statements as $stmt) {
                $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt) ?? '');
                if ($stmt === '' || $stmt === ';') {
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $e) {
                    $code = (int)($e->errorInfo[1] ?? 0);
                    if (preg_match('/^CREATE\s+TRIGGER\s+(\w+)/i', $stmt, $m) && in_array($code, self::TRIGGER_DENIED, true)) {
                        $skipped[] = $m[1];
                        continue;
                    }
                    throw new \RuntimeException("Migrazione $name non riuscita (errore $code). Correggere e rieseguire su schema pulito.", 0, $e);
                }
            }
            $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }
        if ($skipped !== []) {
            $pdo->prepare('INSERT INTO app_flags (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute(['db_triggers', 'non_disponibili: ' . implode(', ', $skipped)]);
        } elseif (in_array('003_immutability.sql', $applied, true)) {
            $pdo->prepare('INSERT INTO app_flags (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute(['db_triggers', 'attivi']);
        }
        return ['applied' => $applied, 'skipped_triggers' => $skipped];
    }

    /**
     * Caratteristiche del server rilevanti per lo schema.
     * @return array{version:string,maria:bool,num:int,native_json:bool,check_constraints:bool}
     */
    public static function profile(PDO $pdo): array
    {
        $v = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $maria = stripos($v, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $v, $m);
        $num = isset($m[1]) ? (int)$m[1] * 10000 + (int)$m[2] * 100 + (int)$m[3] : 0;
        if (Env::get('DB_COMPAT') === 'mysql56') { // solo per le prove: simula lo schema per MySQL 5.6 su un server più recente
            return ['version' => $v . ' (simulazione 5.6)', 'maria' => false, 'num' => 50651, 'native_json' => false, 'check_constraints' => false];
        }
        return [
            'version' => $v, 'maria' => $maria, 'num' => $num,
            // MariaDB: JSON è sinonimo di LONGTEXT; MySQL: tipo nativo da 5.7.8.
            'native_json' => $maria || $num >= 50708,
            // MySQL applica i vincoli CHECK da 8.0.16 (prima li ignora); MariaDB da 10.2.1.
            'check_constraints' => $maria ? $num >= 100201 : $num >= 80016,
        ];
    }

    /**
     * Adatta lo schema ai server più vecchi (es. MySQL 5.6 degli hosting condivisi):
     * - colonne JSON → LONGTEXT: i valori sono sempre scritti e letti dall'applicazione come testo JSON, nessuna query usa funzioni JSON;
     * - vincoli CHECK rimossi dove il server non li applicherebbe comunque: gli stessi limiti sono controllati dall'applicazione.
     * @param array{native_json:bool,check_constraints:bool} $profile
     */
    public static function adapt(string $sql, array $profile): string
    {
        if (!$profile['native_json']) {
            $sql = preg_replace('/\bJSON(?=\s+(?:NOT\s+)?NULL\b)/', 'LONGTEXT', $sql) ?? $sql;
        }
        if (!$profile['check_constraints']) {
            $sql = preg_replace('/^[ \t]*CONSTRAINT\s+ck_\w+\s+CHECK\b.*\R/m', '', $sql) ?? $sql;
            $sql = preg_replace('/,(\s*\R\s*\)\s*ENGINE)/', '$1', $sql) ?? $sql;
        }
        return $sql;
    }

    public static function dropAll(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
