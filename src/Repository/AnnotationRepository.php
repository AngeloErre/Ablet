<?php
declare(strict_types=1);

namespace Ablet\Repository;

use Ablet\Support\Database;
use PDO;

/**
 * Annotazioni testuali append-only. Una correzione non sovrascrive: crea una nuova annotazione umana
 * che punta alla precedente (supersedes_id) e marca quest'ultima come 'corretta'.
 */
final class AnnotationRepository
{
    public static function insert(array $a): int
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO text_annotations (session_id, message_id, code_key, segment_text, segment_start, segment_end, srl_phase, rationale,
                          uncertainty, elicitation, annotator_type, annotator_user_id, model_name, codebook_version_id, review_status, supersedes_id)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$a['session_id'], $a['message_id'], $a['code_key'], $a['segment_text'] ?? null, $a['segment_start'] ?? null, $a['segment_end'] ?? null,
                $a['srl_phase'], $a['rationale'] ?? null, $a['uncertainty'], $a['elicitation'], $a['annotator_type'], $a['annotator_user_id'] ?? null,
                $a['model_name'] ?? null, $a['codebook_version_id'], $a['review_status'] ?? 'da_rivedere', $a['supersedes_id'] ?? null]);
        return (int)$pdo->lastInsertId();
    }

    /** @param array{code_key:?string,rationale?:string,uncertainty?:string} $change */
    public static function correct(int $annotationId, int $reviewerId, array $change): int
    {
        return Database::transaction(static function (PDO $pdo) use ($annotationId, $reviewerId, $change): int {
            $st = $pdo->prepare('SELECT * FROM text_annotations WHERE id = ? FOR UPDATE');
            $st->execute([$annotationId]);
            $old = $st->fetch();
            if (!$old) {
                throw new \DomainException('annotazione_inesistente');
            }
            if ($old['review_status'] === 'corretta') {
                throw new \DomainException('annotazione_già_sostituita');
            }
            $pdo->prepare("UPDATE text_annotations SET review_status = 'corretta' WHERE id = ?")->execute([$annotationId]);
            $new = array_merge($old, [
                'code_key' => $change['code_key'],
                'rationale' => $change['rationale'] ?? $old['rationale'],
                'uncertainty' => $change['uncertainty'] ?? ($change['code_key'] === null ? 'astensione' : 'bassa'),
                'annotator_type' => 'umano', 'annotator_user_id' => $reviewerId, 'model_name' => null,
                'review_status' => 'confermata', 'supersedes_id' => $annotationId,
            ]);
            $id = self::insert($new);
            $pdo->prepare('INSERT INTO audit_log (actor_id, action, entity, entity_id, meta) VALUES (?,?,?,?,?)')
                ->execute([$reviewerId, 'annotation_corrected', 'text_annotations', $id,
                    json_encode(['supersedes' => $annotationId, 'from' => $old['code_key'], 'to' => $change['code_key']])]);
            return $id;
        });
    }
}
