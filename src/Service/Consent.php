<?php
declare(strict_types=1);

namespace Ablet\Service;

use Ablet\Llm\Gateway;
use Ablet\Support\Database;

/**
 * Autorizzazioni registrate dal professionista (es. raccolte su modulo cartaceo firmato).
 * La piattaforma non stabilisce quali autorizzazioni servano né chi debba darle: lo decide il titolare del trattamento.
 * Se il professionista attiva l'obbligo, l'uso è subordinato alle autorizzazioni richieste:
 *  - 'informativa' e 'uso_piattaforma' per avviare una sessione;
 *  - 'invio_provider_esterno' per inviare testi a un fornitore esterno (altrimenti l'agente non risponde);
 *  - 'voce' per dettatura e lettura ad alta voce.
 */
final class Consent
{
    public const KINDS = [
        'informativa' => 'Presa visione dell\'informativa',
        'uso_piattaforma' => 'Uso della piattaforma',
        'invio_provider_esterno' => 'Invio dei testi a un fornitore esterno di IA',
        'voce' => 'Uso delle funzioni vocali',
        'audio_provider' => 'Invio temporaneo dell’audio al provider vocale configurato (trascrizione e sintesi)',
        'ricerca' => 'Uso dei dati pseudonimizzati per ricerca',
    ];
    public const BY = ['studente' => 'Studente', 'esercente_responsabilita_genitoriale' => 'Esercente la responsabilità genitoriale', 'entrambi' => 'Entrambi'];
    public const SESSION_KINDS = ['informativa', 'uso_piattaforma'];

    public static function settings(int $ownerId): array
    {
        try {
            $st = Database::pdo()->prepare('SELECT privacy FROM professional_settings WHERE owner_id = ?');
            $st->execute([$ownerId]);
            $v = json_decode((string)$st->fetchColumn(), true) ?: [];
        } catch (\PDOException) {
            $v = []; // file aggiornati ma migrazione 005 non ancora applicata: valori predefiniti finché non si aggiorna il database
        }
        return $v + ['require_consents' => false, 'notice_version' => '1.0'];
    }

    /** @return string[] tipi attivi (non revocati) */
    public static function active(int $studentId): array
    {
        $st = Database::pdo()->prepare('SELECT DISTINCT kind FROM consents WHERE student_id = ? AND revoked_at IS NULL');
        $st->execute([$studentId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] autorizzazioni mancanti per l'operazione */
    public static function missing(int $studentId, int $ownerId, array $kinds): array
    {
        if (!self::settings($ownerId)['require_consents']) {
            return [];
        }
        return array_values(array_diff($kinds, self::active($studentId)));
    }

    /** Requisiti per avviare una sessione: uso + (se il provider è esterno e l'attività usa l'agente) invio al fornitore. */
    public static function forSession(int $studentId, int $ownerId, bool $agent): array
    {
        $kinds = self::SESSION_KINDS;
        if ($agent && Gateway::configFor($ownerId)['provider_key'] !== 'demo') {
            $kinds[] = 'invio_provider_esterno';
        }
        return self::missing($studentId, $ownerId, $kinds);
    }

    public static function labels(array $kinds): string
    {
        return implode(', ', array_map(static fn($k) => mb_strtolower(self::KINDS[$k] ?? $k), $kinds));
    }
}
