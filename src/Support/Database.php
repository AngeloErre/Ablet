<?php
declare(strict_types=1);

namespace Ablet\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '3306'),
                Env::get('DB_NAME', 'ablet')
            );
            self::$pdo = new PDO($dsn, Env::get('DB_USER', 'ablet'), Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, // query parametrizzate lato server
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            // Modalità rigorosa anche sui server più vecchi (MySQL 5.6 non la attiva di default): niente troncamenti silenziosi.
            self::$pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES', 'NO_ENGINE_SUBSTITUTION')");
        }
        return self::$pdo;
    }

    /**
     * Esegue $fn in una transazione; rollback su eccezione.
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
