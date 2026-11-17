<?php
declare(strict_types=1);

namespace Ablet\Http;

use Ablet\Domain\Agent\MaterialScanner;
use Ablet\Domain\Agent\PromptCompiler;
use Ablet\Domain\Protocol\ProtocolValidator;
use Ablet\Llm\Gateway;
use Ablet\Llm\ProviderException;
use Ablet\Repository\AnnotationRepository;
use Ablet\Service\Agreement;
use Ablet\Service\Annotator;
use Ablet\Service\Consent;
use Ablet\Service\Retention;
use Ablet\Service\Indicators;
use Ablet\Service\Simulator;
use Ablet\Support\Database;
use Ablet\Support\Ids;
use Ablet\Support\Secrets;

/** API dello psicologo. Ogni gestore verifica la titolarità della risorsa: l'identificativo nella richiesta non basta. */
final class PsyApi
{
    private const TEMPLATES = ['pianificazione_autoregolazione', 'comprensione_testo', 'base_piano', 'base_comprensione', 'base_motivazione', 'base_controllo', 'base_riflessione'];
    private const AGE = ['8-10', '11-13', '14-16', '17-19', 'adulto'];

    public static function register(): void
    {
        $r = static fn(string $m, string $p, string $fn) => App::route($m, '/api/psy' . $p, [self::class, $fn], 'psychologist');
        $r('GET', '/bootstrap', 'bootstrap');
        $r('GET', '/students/{id}/plans', 'plansList');
        $r('POST', '/students/{id}/plans', 'plansCreate');
        $r('POST', '/students/{id}/plans/{plan}/cancel', 'plansCancel');
        $r('POST', '/students/{id}/plan-steps/{step}/skip', 'plansSkip');
        $r('POST', '/students', 'studentCreate');
        $r('POST', '/students/{id}/reset', 'studentReset');
        $r('PUT', '/students/{id}/profile', 'studentProfile');
        $r('PUT', '/students/{id}/model', 'studentModel');
        $r('GET', '/students/{id}/export', 'studentExport');
        $r('DELETE', '/students/{id}', 'studentDelete');
        $r('POST', '/groups', 'groupCreate');
        $r('PUT', '/groups/{id}', 'groupUpdate');
        $r('DELETE', '/groups/{id}', 'groupDelete');
        $r('POST', '/students/{id}/consents', 'consentAdd');
        $r('POST', '/consents/{id}/revoke', 'consentRevoke');
        $r('PUT', '/privacy', 'privacySave');
        $r('GET', '/privacy/notice-template', 'noticeTemplate');
        $r('GET', '/retention/preview', 'retentionPreview');
        $r('POST', '/retention/run', 'retentionRun');
        $r('GET', '/coding/export', 'codingExport');
        $r('POST', '/coding/agreement', 'codingAgreement');
        $r('GET', '/coding/model-agreement', 'codingModelAgreement');
        $r('GET', '/protocols/{id}/impact', 'protocolImpact');
        $r('DELETE', '/protocols/{id}', 'protocolArchive');
        $r('POST', '/protocols/{id}/restore', 'protocolRestore');
        $r('POST', '/activities/{id}/assign', 'activityAssign');
        $r('POST', '/activity-assignments/{id}/revoke', 'activityRevoke');
        $r('POST', '/protocols', 'protocolCreate');
        $r('POST', '/protocols/preview', 'protocolPreview');
        $r('GET', '/protocols/{id}', 'protocolGet');
        $r('PUT', '/protocols/{id}/draft', 'protocolDraft');
        $r('POST', '/protocols/{id}/publish', 'protocolPublish');
        $r('GET', '/protocols/{id}/diff', 'protocolDiff');
        $r('POST', '/protocols/{id}/assign', 'protocolAssign');
        $r('POST', '/protocols/{id}/simulate', 'protocolSimulate');
        $r('POST', '/activities', 'activityCreate');
        $r('PUT', '/activities/{id}', 'activityUpdate');
        $r('POST', '/providers', 'providerSave');
        $r('POST', '/providers/{id}/test', 'providerTest');
        $r('GET', '/providers/{id}/models', 'providerModels');
        $r('DELETE', '/providers/{id}', 'providerDelete');
        $r('PUT', '/settings', 'settingsSave');
        $r('GET', '/dashboard', 'dashboard');
        $r('GET', '/sessions/{id}', 'sessionGet');
        $r('POST', '/sessions/{id}/annotate', 'sessionAnnotate');
        $r('POST', '/sessions/{id}/rating', 'sessionRating');
        $r('POST', '/sessions/{id}/note', 'sessionNote');
        $r('POST', '/annotations/{id}/review', 'annotationReview');
    }

    // ───────────────────────── helper
    private static function me(): int { return App::user()['id']; }

    private static function rows(string $sql, array $a = []): array
    {
        $st = Database::pdo()->prepare($sql);
        $st->execute($a);
        return $st->fetchAll();
    }

    private static function own(string $what, int $id): array
    {
        $sql = match ($what) {
            'student' => "SELECT u.* FROM users u JOIN professional_students ps ON ps.student_id = u.id AND ps.professional_id = :me AND ps.active_to IS NULL WHERE u.id = :id AND u.role = 'student'",
            'protocol' => 'SELECT * FROM protocols WHERE id = :id AND owner_id = :me',
            'activity' => 'SELECT * FROM activities WHERE id = :id AND owner_id = :me',
            'provider' => 'SELECT * FROM provider_configs WHERE id = :id AND owner_id = :me',
            'rubric' => 'SELECT * FROM rubrics WHERE id = :id AND owner_id = :me',
            'session' => 'SELECT s.* FROM sessions s JOIN activities a ON a.id = s.activity_id AND a.owner_id = :me
                          WHERE s.id = :id AND (s.is_simulation = 1 OR EXISTS (SELECT 1 FROM professional_students ps WHERE ps.student_id = s.student_id AND ps.professional_id = :me2 AND ps.active_to IS NULL))',
            'annotation' => 'SELECT t.* FROM text_annotations t JOIN sessions s ON s.id = t.session_id JOIN activities a ON a.id = s.activity_id AND a.owner_id = :me WHERE t.id = :id',
        };
        $args = ['me' => self::me(), 'id' => $id] + ($what === 'session' ? ['me2' => self::me()] : []);
        $row = self::rows($sql, $args)[0] ?? null;
        if (!$row) { throw new ApiError(404, 'non_trovato'); } // 404 anche per risorse altrui
        return $row;
    }

    private static function providerPublic(array $p): array
    {
        return ['id' => (int)$p['id'], 'provider_key' => $p['provider_key'], 'label' => $p['label'], 'base_url' => $p['base_url'], 'model_name' => $p['model_name'],
            'key_mask' => $p['key_last4'] ? '••••••••' . $p['key_last4'] : null, 'params' => json_decode($p['params'] ?? 'null', true), 'monthly_token_limit' => $p['monthly_token_limit'],
            'timeout_s' => (int)$p['timeout_s'], 'max_retries' => (int)$p['max_retries'], 'fallback_config_id' => $p['fallback_config_id'], 'is_default' => (bool)$p['is_default'],
            'capabilities' => (static function () use ($p) { try { return Gateway::adapter($p)->capabilities(); } catch (\Throwable) { return null; } })()];
    }

    // ───────────────────────── avvio
    public static function bootstrap(): array
    {
        $me = self::me();
        Retention::maybeRun($me); // conservazione automatica, al più una volta al giorno (nessun cron necessario)
        $tpl = [];
        foreach (self::TEMPLATES as $k) {
            $tpl[$k] = json_decode((string)file_get_contents(ABLET_ROOT . "/resources/templates/$k.json"), true);
        }
        $set = self::rows('SELECT voice, avatar, retention FROM professional_settings WHERE owner_id = ?', [$me])[0] ?? [];
        return [
            'students' => self::rows("SELECT u.id, u.pseudonym, u.login, u.display_alias, fp.age_band, fp.strengths, fp.accessibility_needs, fp.constraints_text, fp.preferences,
                    sm.support_level, sm.step_size, sm.preferences AS model_prefs,
                    (SELECT COUNT(*) FROM sessions s WHERE s.student_id = u.id) sessions,
                    (SELECT p.title FROM protocol_assignments pa JOIN protocol_versions pv ON pv.id = pa.protocol_version_id JOIN protocols p ON p.id = pv.protocol_id
                      WHERE pa.student_id = u.id AND pa.active_to IS NULL AND p.owner_id = ps.professional_id ORDER BY pa.id DESC LIMIT 1) protocol
                FROM users u JOIN professional_students ps ON ps.student_id = u.id AND ps.professional_id = ? AND ps.active_to IS NULL
                LEFT JOIN functional_profiles fp ON fp.student_id = u.id AND fp.version_no = (SELECT MAX(version_no) FROM functional_profiles WHERE student_id = u.id)
                LEFT JOIN student_models sm ON sm.student_id = u.id WHERE u.role = 'student' AND u.status = 'active' ORDER BY u.id", [$me]),
            'groups' => self::rows('SELECT g.id, g.title, (SELECT COUNT(*) FROM student_group_members m WHERE m.group_id = g.id) members FROM student_groups g WHERE g.owner_id = ?', [$me]),
            'protocols' => self::rows("SELECT p.id, p.title, p.template_key, (SELECT MAX(version_no) FROM protocol_versions v WHERE v.protocol_id = p.id) latest,
                    (SELECT MAX(version_no) FROM protocol_versions v WHERE v.protocol_id = p.id AND v.status = 'published') published FROM protocols p WHERE p.owner_id = ? AND p.archived = 0 ORDER BY p.id", [$me]),
            'archived_protocols' => self::rows('SELECT id, title FROM protocols WHERE owner_id = ? AND archived = 1 ORDER BY id', [$me]),
            'activity_assignments' => self::rows('SELECT aa.id, aa.activity_id, aa.student_id, aa.protocol_version_id, aa.active, pv.version_no, p.title protocol_title FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id LEFT JOIN protocol_versions pv ON pv.id = aa.protocol_version_id LEFT JOIN protocols p ON p.id = pv.protocol_id WHERE a.owner_id = ? ORDER BY aa.id', [$me]),
            'published_versions' => self::rows("SELECT pv.id, pv.version_no, p.title, p.id protocol_id FROM protocol_versions pv JOIN protocols p ON p.id = pv.protocol_id WHERE p.owner_id = ? AND p.archived = 0 AND pv.status = 'published' ORDER BY p.id, pv.version_no DESC", [$me]),
            'activities' => self::rows('SELECT a.id, a.title, a.visibility, a.activity_mode, a.assignment_text, a.completion_criteria, a.expected_minutes, a.agent_enabled, (SELECT COUNT(*) FROM materials m WHERE m.activity_id = a.id) materials,
                    (SELECT COUNT(*) FROM materials m WHERE m.activity_id = a.id AND CHAR_LENGTH(m.scan_flags) > 4) flagged FROM activities a WHERE a.owner_id = ? ORDER BY a.id', [$me]),
            'providers' => array_map([self::class, 'providerPublic'], self::rows('SELECT * FROM provider_configs WHERE owner_id = ? AND enabled = 1 ORDER BY id', [$me])),
            'secrets_ready' => Secrets::available(),
            'settings' => ['voice' => json_decode($set['voice'] ?? 'null', true), 'avatar' => json_decode($set['avatar'] ?? 'null', true), 'retention' => json_decode($set['retention'] ?? 'null', true)],
            'rubrics' => self::rows('SELECT id, title, purpose, scale_min, scale_max FROM rubrics WHERE owner_id = ?', [$me]),
            'templates' => $tpl,
            'codebook' => Annotator::codebook(),
            'indicators' => json_decode((string)file_get_contents(ABLET_ROOT . '/resources/indicators/indicators_v0.1.json'), true),
            'simulation_profiles' => Simulator::PROFILES,
            'consents' => self::rows("SELECT c.id, c.student_id, c.kind, c.granted_by, c.document_version, c.granted_at FROM consents c
                JOIN professional_students ps ON ps.student_id = c.student_id AND ps.professional_id = ? AND ps.active_to IS NULL WHERE c.revoked_at IS NULL ORDER BY c.id", [$me]),
            'consent_kinds' => Consent::KINDS, 'consent_by' => Consent::BY, 'privacy' => Consent::settings($me),
            'group_members' => self::rows('SELECT m.group_id, m.student_id FROM student_group_members m JOIN student_groups g ON g.id = m.group_id AND g.owner_id = ?', [$me]),
            'rule_vocabulary' => ['indicators' => \Ablet\Domain\Adaptation\RuleEngine::INDICATORS, 'actions' => \Ablet\Domain\Adaptation\RuleEngine::ACTIONS],
        ];
    }

    // ───────────────────────── studenti
    public static function plansList(array $a):array {self::own('student',(int)$a['id']);return ['plans'=>\Ablet\Service\SessionPlans::listing((int)$a['id'],self::me())];}
    public static function plansCreate(array $a):array {App::rateLimit('plans:'.self::me(),30,300);self::own('student',(int)$a['id']);$r=\Ablet\Service\SessionPlans::create(self::me(),(int)$a['id'],App::input());App::audit('session_plan_created','session_plans',$r['id'],['student_id'=>(int)$a['id'],'sessions'=>$r['sessions']]);return $r;}
    public static function plansCancel(array $a):array {self::own('student',(int)$a['id']);\Ablet\Service\SessionPlans::cancel(self::me(),(int)$a['id'],(int)$a['plan']);App::audit('session_plan_cancelled','session_plans',(int)$a['plan']);return ['ok'=>true];}
    public static function plansSkip(array $a):array {self::own('student',(int)$a['id']);\Ablet\Service\SessionPlans::skip(self::me(),(int)$a['id'],(int)$a['step']);App::audit('planned_step_skipped','session_plan_steps',(int)$a['step']);return ['ok'=>true];}

    public static function studentCreate(): array
    {
        $in = App::input();
        $alias = mb_substr(trim((string)($in['alias'] ?? '')), 0, 60);
        if ($alias === '' || !in_array($in['age_band'] ?? '', self::AGE, true)) { throw new ApiError(422, 'dati_non_validi'); }
        $pw = Ids::pseudonym(10);
        $login = 'ST-' . Ids::pseudonym(6);
        $id = Database::transaction(static function (\PDO $pdo) use ($alias, $in, $pw, $login): int {
            $pdo->prepare("INSERT INTO users (pseudonym, role, login, password_hash, display_alias) VALUES (?, 'student', ?, ?, ?)")->execute([Ids::pseudonym(), $login, password_hash($pw, PASSWORD_DEFAULT), $alias]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO professional_students (professional_id, student_id) VALUES (?,?)')->execute([self::me(), $id]);
            $pdo->prepare('INSERT INTO functional_profiles (student_id, author_id, age_band) VALUES (?,?,?)')->execute([$id, self::me(), $in['age_band']]);
            return $id;
        });
        App::audit('student_created', 'users', $id);
        return ['id' => $id, 'login' => $login, 'password' => $pw, 'note' => 'Credenziali mostrate una sola volta.'];
    }

    public static function studentReset(array $a): array
    {
        $s = self::own('student', (int)$a['id']);
        $pw = Ids::pseudonym(10);
        Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $s['id']]);
        App::audit('student_password_reset', 'users', (int)$s['id']);
        return ['login' => $s['login'], 'password' => $pw];
    }

    public static function studentProfile(array $a): array
    {
        $s = self::own('student', (int)$a['id']);
        $in = App::input();
        if (!in_array($in['age_band'] ?? '', self::AGE, true)) { throw new ApiError(422, 'fascia_eta_non_valida'); }
        $warn = ProtocolValidator::validate(['functional_profile' => array_filter($in, 'is_string')])['warnings'];
        $warn = array_values(array_filter($warn, static fn($w) => str_contains($w, 'Profilo funzionale')));
        Database::pdo()->prepare('INSERT INTO functional_profiles (student_id, author_id, age_band, strengths, accessibility_needs, constraints_text, preferences, version_no)
            SELECT ?,?,?,?,?,?,?, COALESCE(MAX(version_no),0)+1 FROM functional_profiles WHERE student_id = ?')
            ->execute([$s['id'], self::me(), $in['age_band'], mb_substr((string)($in['strengths'] ?? ''), 0, 1000), json_encode($in['accessibility_needs'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                mb_substr((string)($in['constraints_text'] ?? ''), 0, 1000), json_encode($in['preferences'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE), $s['id']]);
        return ['ok' => true, 'warnings' => $warn];
    }

    public static function studentModel(array $a): array
    {
        $s = self::own('student', (int)$a['id']);
        $in = App::input();
        $lvl = max(0, min(3, (int)($in['support_level'] ?? 3)));
        $step = max(1, min(5, (int)($in['step_size'] ?? 3)));
        $prefs = json_encode(['lock_support_level' => !empty($in['lock_support_level'])]);
        Database::pdo()->prepare('INSERT INTO student_models (student_id, support_level, step_size, preferences) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE support_level = VALUES(support_level), step_size = VALUES(step_size), preferences = VALUES(preferences)')->execute([$s['id'], $lvl, $step, $prefs]);
        App::audit('student_model_set', 'users', (int)$s['id'], ['support_level' => $lvl, 'step_size' => $step]);
        return ['ok' => true];
    }

    public static function studentExport(array $a): array
    {
        $s = self::own('student', (int)$a['id']);
        $sessions = array_map(static fn($r) => Indicators::session((int)$r['id']), self::rows('SELECT id FROM sessions WHERE student_id = ? ORDER BY id', [$s['id']]));
        App::audit('student_export', 'users', (int)$s['id']);
        return ['exported_at' => gmdate('c'), 'student' => ['pseudonym' => $s['pseudonym'], 'alias' => $s['display_alias']],
            'functional_profiles' => self::rows('SELECT age_band, strengths, accessibility_needs, constraints_text, preferences, version_no, created_at FROM functional_profiles WHERE student_id = ?', [$s['id']]),
            'consents' => self::rows('SELECT kind, granted_by, document_version, granted_at, revoked_at FROM consents WHERE student_id = ?', [$s['id']]),
            'sessions' => $sessions];
    }

    public static function studentDelete(array $a): array
    {
        $s = self::own('student', (int)$a['id']);
        // Le chiavi esterne ON DELETE CASCADE rimuovono sessioni, messaggi, eventi, autovalutazioni, annotazioni, adattamenti, profili.
        Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$s['id']]);
        App::audit('student_deleted', 'users', (int)$s['id'], ['pseudonym' => $s['pseudonym']]);
        return ['ok' => true];
    }

    public static function groupCreate(): array
    {
        $in = App::input();
        $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 100);
        if ($title === '') { throw new ApiError(422, 'titolo_mancante'); }
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO student_groups (owner_id, title) VALUES (?,?)')->execute([self::me(), $title]);
        $gid = (int)$pdo->lastInsertId();
        foreach ((array)($in['student_ids'] ?? []) as $sid) {
            $pdo->prepare('INSERT IGNORE INTO student_group_members (group_id, student_id) VALUES (?,?)')->execute([$gid, self::own('student', (int)$sid)['id']]);
        }
        return ['id' => $gid];
    }

    public static function groupUpdate(array $a): array
    {
        $g = self::rows('SELECT id FROM student_groups WHERE id = ? AND owner_id = ?', [(int)$a['id'], self::me()])[0] ?? null;
        if (!$g) { throw new ApiError(404, 'non_trovato'); }
        $in = App::input();
        $ids = array_map(static fn($s) => (int)self::own('student', (int)$s)['id'], (array)($in['student_ids'] ?? []));
        Database::transaction(static function (\PDO $pdo) use ($g, $in, $ids): void {
            if (isset($in['title']) && trim((string)$in['title']) !== '') {
                $pdo->prepare('UPDATE student_groups SET title = ? WHERE id = ?')->execute([mb_substr(trim((string)$in['title']), 0, 100), $g['id']]);
            }
            $pdo->prepare('DELETE FROM student_group_members WHERE group_id = ?')->execute([$g['id']]);
            foreach (array_unique($ids) as $sid) {
                $pdo->prepare('INSERT INTO student_group_members (group_id, student_id) VALUES (?,?)')->execute([$g['id'], $sid]);
            }
        });
        return ['ok' => true];
    }

    public static function groupDelete(array $a): array
    {
        $st = Database::pdo()->prepare('DELETE FROM student_groups WHERE id = ? AND owner_id = ?');
        $st->execute([(int)$a['id'], self::me()]);
        if (!$st->rowCount()) { throw new ApiError(404, 'non_trovato'); }
        return ['ok' => true];
    }

    // ───────────────────────── autorizzazioni, informativa, conservazione
    public static function consentAdd(array $a): array
    {
        $s = self::own('student', (int)$a['id']);
        $in = App::input();
        if (!isset(Consent::KINDS[$in['kind'] ?? '']) || !isset(Consent::BY[$in['granted_by'] ?? ''])) { throw new ApiError(422, 'dati_non_validi'); }
        $date = (string)($in['granted_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > gmdate('Y-m-d')) { throw new ApiError(422, 'data_non_valida'); }
        $ver = mb_substr(trim((string)($in['document_version'] ?? '')), 0, 40);
        if ($ver === '') { throw new ApiError(422, 'versione_documento_mancante'); }
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE consents SET revoked_at = CURRENT_TIMESTAMP(3) WHERE student_id = ? AND kind = ? AND revoked_at IS NULL')->execute([$s['id'], $in['kind']]); // una sola registrazione attiva per tipo
        $pdo->prepare('INSERT INTO consents (student_id, kind, granted_by, document_version, recorded_by, granted_at) VALUES (?,?,?,?,?,?)')
            ->execute([$s['id'], $in['kind'], $in['granted_by'], $ver, self::me(), $date . ' 00:00:00']);
        $id = (int)$pdo->lastInsertId();
        App::audit('consent_recorded', 'consents', $id, ['kind' => $in['kind'], 'granted_by' => $in['granted_by']]);
        return ['id' => $id];
    }

    public static function consentRevoke(array $a): array
    {
        $c = self::rows('SELECT c.id FROM consents c JOIN professional_students ps ON ps.student_id = c.student_id AND ps.professional_id = ? AND ps.active_to IS NULL WHERE c.id = ? AND c.revoked_at IS NULL', [self::me(), (int)$a['id']])[0] ?? null;
        if (!$c) { throw new ApiError(404, 'non_trovato'); }
        Database::pdo()->prepare('UPDATE consents SET revoked_at = CURRENT_TIMESTAMP(3) WHERE id = ?')->execute([$c['id']]);
        App::audit('consent_revoked', 'consents', (int)$c['id']);
        return ['ok' => true];
    }

    public static function privacySave(): array
    {
        $in = App::input();
        $p = ['require_consents' => !empty($in['require_consents']), 'notice_version' => mb_substr(trim((string)($in['notice_version'] ?? '1.0')), 0, 40) ?: '1.0'];
        $rt = (array)($in['retention'] ?? []);
        $ret = ['messages_days' => max(30, min(3650, (int)($rt['messages_days'] ?? 365))), 'technical_logs_days' => max(7, min(365, (int)($rt['technical_logs_days'] ?? 30)))];
        Database::pdo()->prepare('INSERT INTO professional_settings (owner_id, privacy, retention) VALUES (?,?,?) ON DUPLICATE KEY UPDATE privacy = VALUES(privacy), retention = VALUES(retention)')
            ->execute([self::me(), json_encode($p), json_encode($ret)]);
        App::audit('privacy_settings_saved', 'professional_settings', self::me(), $p + $ret);
        return ['privacy' => $p, 'retention' => $ret];
    }

    public static function noticeTemplate(): array
    {
        return ['text' => (string)file_get_contents(ABLET_ROOT . '/resources/informativa/modello_informativa.md')];
    }

    public static function retentionPreview(): array
    {
        return Retention::run(self::me(), true) + Retention::settings(self::me());
    }

    public static function retentionRun(): array
    {
        return Retention::run(self::me());
    }

    // ───────────────────────── accordo fra codificatori
    public static function codingExport(): array
    {
        $rows = Agreement::export(self::me(), max(0, min(500, (int)($_GET['per_phase'] ?? 0))), (int)($_GET['seed'] ?? 1), !empty($_GET['reviewed_only']));
        App::audit('coding_export', 'messages', null, ['rows' => count($rows)]);
        return ['rows' => count($rows), 'csv' => Agreement::toCsv($rows)];
    }

    public static function codingAgreement(): array
    {
        $csv = (string)(App::input()['csv'] ?? '');
        if (strlen($csv) > 500000) { throw new ApiError(422, 'file_troppo_grande'); }
        return Agreement::withSecondCoder(self::me(), $csv);
    }

    public static function codingModelAgreement(): array
    {
        return Agreement::modelVsHuman(self::me());
    }

    // ───────────────────────── protocolli
    public static function protocolCreate(): array
    {
        $in = App::input();
        if (isset($in['config']) && is_array($in['config'])) {           // importazione JSON
            $cfg = $in['config'];
        } elseif (in_array($in['template_key'] ?? '', self::TEMPLATES, true)) {
            $cfg = json_decode((string)file_get_contents(ABLET_ROOT . "/resources/templates/{$in['template_key']}.json"), true);
        } else {
            throw new ApiError(422, 'template_o_configurazione_richiesti');
        }
        if (($cfg['schema_version'] ?? null) !== ProtocolValidator::SCHEMA_VERSION) { throw new ApiError(422, 'schema_version_non_supportata'); }
        unset($cfg['system_template']); // Si crea una copia personale; la risorsa di sistema resta immutabile.
        unset($cfg['provider']['provider_config_id']);                   // i riferimenti interni non si importano
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO protocols (owner_id, title, template_key) VALUES (?,?,?)')->execute([self::me(), mb_substr((string)($in['title'] ?? $cfg['title'] ?? 'Protocollo'), 0, 150), $cfg['template_key'] ?? null]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO protocol_versions (protocol_id, version_no, status, config_json, created_by) VALUES (?,1,'draft',?,?)")->execute([$pid, json_encode($cfg, JSON_UNESCAPED_UNICODE), self::me()]);
        return ['id' => $pid];
    }

    public static function protocolPreview(): array
    {
        $cfg = App::input()['config'] ?? null;
        if (!is_array($cfg)) { throw new ApiError(422, 'configurazione_mancante'); }
        $v = ProtocolValidator::validate($cfg);
        $c = PromptCompiler::compile($cfg);
        if (!empty($cfg['provider']['provider_config_id'])) self::own('provider', (int)$cfg['provider']['provider_config_id']);
        $provider = Gateway::configFor(self::me(), $cfg['provider']['provider_config_id'] ?? null);
        $schema = (string)file_get_contents(ABLET_ROOT . '/resources/schema/agent_output.schema.json');
        return $v + ['prompt' => $c['prompt'], 'hash' => $c['hash'], 'chars' => mb_strlen($c['prompt']),
            'estimate' => \Ablet\Domain\Agent\PromptBudget::estimate($c['prompt'] . $schema, $cfg, $provider)];
    }

    public static function protocolGet(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        $versions = self::rows('SELECT id, version_no, status, config_json, prompt_hash, created_at, published_at,
            (SELECT COUNT(*) FROM sessions s WHERE s.protocol_version_id = v.id) sessions FROM protocol_versions v WHERE protocol_id = ? ORDER BY version_no DESC', [$p['id']]);
        foreach ($versions as &$v) { $v['config'] = json_decode($v['config_json'], true); unset($v['config_json']); }
        return ['protocol' => ['id' => (int)$p['id'], 'title' => $p['title'], 'archived' => (bool)$p['archived']], 'versions' => $versions,
            'assignments' => self::rows('SELECT pa.id, pv.version_no, pa.student_id, pa.group_id, pa.active_to FROM protocol_assignments pa JOIN protocol_versions pv ON pv.id = pa.protocol_version_id WHERE pv.protocol_id = ? AND pa.active_to IS NULL', [$p['id']])];
    }

    public static function protocolDraft(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        if ($p['archived']) { throw new ApiError(422, 'protocollo_archiviato'); }
        $cfg = App::input()['config'] ?? null;
        if (!is_array($cfg)) { throw new ApiError(422, 'configurazione_mancante'); }
        if (isset($cfg['provider']['provider_config_id'])) { self::own('provider', (int)$cfg['provider']['provider_config_id']); }
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        $pdo = Database::pdo();
        $last = self::rows('SELECT id, version_no, status FROM protocol_versions WHERE protocol_id = ? ORDER BY version_no DESC LIMIT 1', [$p['id']])[0];
        if ($last['status'] === 'draft') {
            $pdo->prepare("UPDATE protocol_versions SET config_json = ? WHERE id = ? AND status = 'draft'")->execute([$json, $last['id']]); // mai su versioni pubblicate
            $no = (int)$last['version_no'];
        } else { // la versione pubblicata resta intatta: si apre una nuova bozza
            $no = (int)$last['version_no'] + 1;
            $pdo->prepare("INSERT INTO protocol_versions (protocol_id, version_no, status, config_json, created_by) VALUES (?,?,'draft',?,?)")->execute([$p['id'], $no, $json, self::me()]);
        }
        $pdo->prepare('UPDATE protocols SET title = ? WHERE id = ?')->execute([mb_substr((string)($cfg['title'] ?? $p['title']), 0, 150), $p['id']]);
        return ['version_no' => $no] + ProtocolValidator::validate($cfg);
    }

    public static function protocolPublish(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        if ($p['archived']) { throw new ApiError(422, 'protocollo_archiviato'); }
        $d = self::rows("SELECT id, version_no, config_json FROM protocol_versions WHERE protocol_id = ? AND status = 'draft' ORDER BY version_no DESC LIMIT 1", [$p['id']])[0] ?? null;
        if (!$d) { throw new ApiError(422, 'nessuna_bozza'); }
        $cfg = json_decode($d['config_json'], true);
        $v = ProtocolValidator::validate($cfg);
        if ($v['errors'] !== []) { throw new ApiError(422, 'protocollo_non_valido', $v); }
        $c = PromptCompiler::compile($cfg);
        Database::pdo()->prepare("UPDATE protocol_versions SET status = 'published', compiled_prompt = ?, prompt_hash = ?, platform_rules_version = ?, published_at = CURRENT_TIMESTAMP(3) WHERE id = ? AND status = 'draft'")
            ->execute([$c['prompt'], $c['hash'], $c['platform_rules_version'], $d['id']]);
        App::audit('protocol_published', 'protocol_versions', (int)$d['id'], ['version_no' => (int)$d['version_no'], 'hash' => $c['hash']]);
        return ['version_id' => (int)$d['id'], 'version_no' => (int)$d['version_no'], 'hash' => $c['hash'], 'warnings' => $v['warnings']];
    }

    private static function flat(mixed $v, string $p = ''): array
    {
        if (!is_array($v)) { return [$p => is_bool($v) ? ($v ? 'sì' : 'no') : (string)$v]; }
        $o = [];
        foreach ($v as $k => $x) { $o += self::flat($x, $p === '' ? (string)$k : "$p.$k"); }
        return $o;
    }

    public static function protocolDiff(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        $get = static fn(int $no) => self::rows('SELECT config_json FROM protocol_versions WHERE protocol_id = ? AND version_no = ?', [$p['id'], $no])[0]['config_json'] ?? null;
        $x = $get((int)($_GET['a'] ?? 0)); $y = $get((int)($_GET['b'] ?? 0));
        if ($x === null || $y === null) { throw new ApiError(404, 'versione_non_trovata'); }
        $fa = self::flat(json_decode($x, true)); $fb = self::flat(json_decode($y, true));
        $out = [];
        foreach (array_unique(array_merge(array_keys($fa), array_keys($fb))) as $k) {
            if (($fa[$k] ?? null) !== ($fb[$k] ?? null)) { $out[] = ['path' => $k, 'a' => $fa[$k] ?? null, 'b' => $fb[$k] ?? null]; }
        }
        usort($out, static fn($x, $y) => strnatcmp($x['path'], $y['path'])); // MySQL 8 riordina le chiavi JSON: ordine stabile per percorso
        return ['changes' => $out];
    }

    public static function protocolAssign(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        if ($p['archived']) { throw new ApiError(422, 'protocollo_archiviato'); }
        $in = App::input();
        $v = self::rows("SELECT id FROM protocol_versions WHERE protocol_id = ? AND version_no = ? AND status = 'published'", [$p['id'], (int)($in['version_no'] ?? 0)])[0] ?? null;
        if (!$v) { throw new ApiError(422, 'versione_non_pubblicata'); }
        $sid = isset($in['student_id']) ? (int)self::own('student', (int)$in['student_id'])['id'] : null;
        $gid = isset($in['group_id']) ? (int)(self::rows('SELECT id FROM student_groups WHERE id = ? AND owner_id = ?', [(int)$in['group_id'], self::me()])[0]['id'] ?? 0) : null;
        if (($sid === null) === ($gid === null) || $gid === 0) { throw new ApiError(422, 'destinatario_non_valido'); }
        $pdo = Database::pdo();
        // Una sola assegnazione attiva per destinatario fra i protocolli di questo professionista. Le sessioni già create restano sulla loro versione.
        $pdo->prepare('UPDATE protocol_assignments pa JOIN protocol_versions pv ON pv.id = pa.protocol_version_id JOIN protocols p ON p.id = pv.protocol_id AND p.owner_id = ?
            SET pa.active_to = CURRENT_TIMESTAMP(3) WHERE pa.active_to IS NULL AND (pa.student_id <=> ?) AND (pa.group_id <=> ?)')->execute([self::me(), $sid, $gid]);
        $pdo->prepare('INSERT INTO protocol_assignments (protocol_version_id, student_id, group_id, assigned_by) VALUES (?,?,?,?)')->execute([$v['id'], $sid, $gid, self::me()]);
        return ['ok' => true];
    }

    public static function protocolSimulate(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        if ($p['archived']) { throw new ApiError(422, 'protocollo_archiviato'); }
        $in = App::input();
        $v = self::rows('SELECT id FROM protocol_versions WHERE protocol_id = ? AND version_no = ?', [$p['id'], (int)($in['version_no'] ?? 0)])[0] ?? null;
        $act = self::own('activity', (int)($in['activity_id'] ?? 0));
        if (!$v || !isset(Simulator::PROFILES[$in['profile'] ?? ''])) { throw new ApiError(422, 'parametri_non_validi'); }
        App::rateLimit('sim:' . self::me(), 20, 3600);
        return Simulator::run(self::me(), (int)$v['id'], (int)$act['id'], $in['profile']);
    }

    // ───────────────────────── attività
    public static function activityCreate(): array
    {
        $in = App::input();
        $visibility = $in['visibility'] ?? 'all';
        if (!in_array($visibility, ['all', 'assigned'], true)) { throw new ApiError(422, 'visibilita_non_valida'); }
        $mode = $in['activity_mode'] ?? 'prepared';
        if (!in_array($mode, ['prepared', 'free'], true)) { throw new ApiError(422, 'modalita_attivita_non_valida'); }
        $free = $mode === 'free';
        if ($free && (!empty($in['materials']) || !($in['agent_enabled'] ?? true))) {
            throw new ApiError(422, 'attivita_libera_richiede_agente_senza_materiali');
        }
        $title = mb_substr(trim((string)($in['title'] ?? ($free ? 'Attività libera' : ''))), 0, 150);
        $text = trim((string)($in['assignment_text'] ?? ''));
        if ($free && $text === '') { $text = 'Scegli tu il compito. Costruiremo insieme il piano seguendo il protocollo del tuo psicologo.'; }
        if ($title === '' || $text === '') { throw new ApiError(422, 'titolo_e_consegna_obbligatori'); }
        $flags = [];
        $id = Database::transaction(static function (\PDO $pdo) use ($in, $title, $text, $mode, $free, $visibility, &$flags): int {
            $pdo->prepare('INSERT INTO activities (owner_id, title, assignment_text, completion_criteria, expected_minutes, agent_enabled, activity_mode, visibility) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([self::me(), $title, mb_substr($text, 0, 4000), mb_substr((string)($in['completion_criteria'] ?? ''), 0, 2000) ?: null,
                    ($in['expected_minutes'] ?? 0) > 0 ? min(600, (int)$in['expected_minutes']) : null, (int)($in['agent_enabled'] ?? true), $mode, $visibility]);
            $aid = (int)$pdo->lastInsertId();
            foreach ((array)($in['materials'] ?? []) as $m) {
                $c = mb_substr((string)($m['content_text'] ?? ''), 0, 60000);
                if (trim($c) === '') { continue; }
                $f = MaterialScanner::scan($c);
                if ($f) { $flags[] = ['title' => $m['title'] ?? '', 'flags' => array_column($f, 'flag')]; }
                $pdo->prepare('INSERT INTO materials (activity_id, title, kind, content_text, source_note, checksum, scan_flags) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$aid, mb_substr((string)($m['title'] ?? 'Materiale'), 0, 150), 'testo', $c, mb_substr((string)($m['source_note'] ?? ''), 0, 250) ?: null, hash('sha256', $c), json_encode($f, JSON_UNESCAPED_UNICODE)]);
            }
            return $aid;
        });
        return ['id' => $id, 'material_warnings' => $flags];
    }

    /** Modifica del catalogo libero, anche se già assegnato. Le sessioni esistenti conservano le indicazioni precedenti. */
    public static function activityUpdate(array $a): array
    {
        self::own('activity', (int)$a['id']);
        $in = App::input();
        if (isset($in['activity_mode']) && $in['activity_mode'] !== 'free') { throw new ApiError(422, 'modalita_attivita_non_valida'); }
        if (!empty($in['materials']) || (isset($in['agent_enabled']) && !$in['agent_enabled'])) { throw new ApiError(422, 'attivita_libera_richiede_agente_senza_materiali'); }
        Database::transaction(static function (\PDO $pdo) use ($a, $in): void {
            $q=$pdo->prepare('SELECT * FROM activities WHERE id=? AND owner_id=? FOR UPDATE');$q->execute([(int)$a['id'],self::me()]);$old=$q->fetch();
            if (!$old) { throw new ApiError(404, 'non_trovato'); }
            if ($old['activity_mode'] !== 'free') { throw new ApiError(422, 'modifica_riservata_attivita_libera'); }
            $title=mb_substr(trim((string)($in['title'] ?? $old['title'])),0,150);
            $text=mb_substr(trim((string)($in['assignment_text'] ?? $old['assignment_text'])),0,4000);
            if ($title === '' || $text === '') { throw new ApiError(422, 'titolo_e_consegna_obbligatori'); }
            $minutes=$in['expected_minutes'] ?? $old['expected_minutes'];
            if ($minutes === '' || $minutes === 0 || $minutes === null) { $minutes=null; }
            elseif (filter_var($minutes,FILTER_VALIDATE_INT) === false || (int)$minutes<1 || (int)$minutes>600) { throw new ApiError(422, 'tempo_non_valido'); }
            $snapshot=json_encode(['a_title'=>$old['title'],'assignment_text'=>$old['assignment_text'],'completion_criteria'=>$old['completion_criteria'],'expected_minutes'=>$old['expected_minutes']],JSON_UNESCAPED_UNICODE);
            $pdo->prepare("UPDATE sessions SET srl_data=JSON_SET(srl_data,'$._activity_snapshot',JSON_EXTRACT(?,'$')) WHERE activity_id=? AND JSON_EXTRACT(srl_data,'$._activity_snapshot') IS NULL")->execute([$snapshot,(int)$a['id']]);
            $pdo->prepare('UPDATE activities SET title=?,assignment_text=?,completion_criteria=?,expected_minutes=? WHERE id=? AND owner_id=?')->execute([$title,$text,mb_substr((string)($in['completion_criteria'] ?? $old['completion_criteria'] ?? ''),0,2000) ?: null,$minutes,(int)$a['id'],self::me()]);
        });
        App::audit('activity_updated','activities',(int)$a['id']);
        return ['ok'=>true,'note'=>'Modifiche valide per le nuove sessioni. Sessioni già create e assegnazioni conservate.'];
    }

    public static function activityAssign(array $a): array
    {
        $activity = self::own('activity', (int)$a['id']); $in = App::input();
        $student = self::own('student', (int)($in['student_id'] ?? 0));
        $version = null;
        if ($activity['agent_enabled']) {
            $v = self::rows("SELECT pv.id FROM protocol_versions pv JOIN protocols p ON p.id = pv.protocol_id WHERE pv.id = ? AND p.owner_id = ? AND p.archived = 0 AND pv.status = 'published'", [(int)($in['protocol_version_id'] ?? 0), self::me()])[0] ?? null;
            if (!$v) { throw new ApiError(422, 'scegli_protocollo_pubblicato'); }
            $version = (int)$v['id'];
        }
        Database::transaction(static function ($pdo) use ($activity, $student, $version): void {
            $pdo->prepare("UPDATE activities SET visibility = 'assigned' WHERE id = ?")->execute([$activity['id']]);
            $pdo->prepare('INSERT INTO activity_assignments (activity_id, student_id, protocol_version_id, assigned_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE protocol_version_id = VALUES(protocol_version_id), assigned_by = VALUES(assigned_by), active = 1, updated_at = CURRENT_TIMESTAMP(3)')
                ->execute([$activity['id'], $student['id'], $version, self::me()]);
        });
        App::audit('activity_assigned', 'activities', (int)$activity['id'], ['student_id' => (int)$student['id'], 'protocol_version_id' => $version]);
        return ['ok' => true, 'note' => 'Attività riservata agli studenti assegnati. Le sessioni già create mantengono il proprio protocollo.'];
    }

    public static function activityRevoke(array $a): array
    {
        $row = self::rows('SELECT aa.id FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id WHERE aa.id = ? AND a.owner_id = ?', [(int)$a['id'], self::me()])[0] ?? null;
        if (!$row) { throw new ApiError(404, 'non_trovato'); }
        Database::pdo()->prepare('UPDATE activity_assignments SET active = 0, updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?')->execute([$row['id']]);
        App::audit('activity_assignment_revoked', 'activity_assignments', (int)$row['id']);
        return ['ok' => true];
    }

    public static function protocolImpact(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        $versions = array_column(self::rows('SELECT id FROM protocol_versions WHERE protocol_id = ?', [$p['id']]), 'id');
        $args = $versions ?: [0]; $marks = implode(',', array_fill(0, count($args), '?'));
        return ['global_assignments' => (int)(self::rows("SELECT COUNT(*) n FROM protocol_assignments WHERE protocol_version_id IN ($marks) AND active_to IS NULL", $args)[0]['n']),
            'activity_assignments' => (int)(self::rows("SELECT COUNT(*) n FROM activity_assignments WHERE protocol_version_id IN ($marks) AND active = 1", $args)[0]['n']),
            'planned_sessions' => (int)(self::rows("SELECT COUNT(*) n FROM session_plan_steps WHERE protocol_version_id IN ($marks) AND status = 'pending'", $args)[0]['n']),
            'sessions' => (int)(self::rows("SELECT COUNT(*) n FROM sessions WHERE protocol_version_id IN ($marks)", $args)[0]['n']),
            'consequence' => 'Il protocollo viene archiviato e le sue assegnazioni disattivate. Le sessioni già create e i dati restano disponibili con la versione originale. Nessuna nuova sessione può usare il protocollo archiviato. Puoi ripristinarlo e riassegnarlo esplicitamente.'];
    }

    public static function protocolArchive(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        $impact = self::protocolImpact($a);
        Database::transaction(static function ($pdo) use ($p): void {
            $pdo->prepare('UPDATE protocols SET archived = 1 WHERE id = ?')->execute([$p['id']]);
            $pdo->prepare('UPDATE protocol_assignments pa JOIN protocol_versions pv ON pv.id = pa.protocol_version_id SET pa.active_to = CURRENT_TIMESTAMP(3) WHERE pv.protocol_id = ? AND pa.active_to IS NULL')->execute([$p['id']]);
            $pdo->prepare('UPDATE activity_assignments aa JOIN protocol_versions pv ON pv.id = aa.protocol_version_id SET aa.active = 0, aa.updated_at = CURRENT_TIMESTAMP(3) WHERE pv.protocol_id = ?')->execute([$p['id']]);
            $pdo->prepare("UPDATE session_plan_steps st JOIN protocol_versions pv ON pv.id=st.protocol_version_id SET st.status='blocked' WHERE pv.protocol_id=? AND st.status='pending'")->execute([$p['id']]);
        });
        App::audit('protocol_archived', 'protocols', (int)$p['id'], $impact);
        return ['ok' => true, 'impact' => $impact];
    }

    public static function protocolRestore(array $a): array
    {
        $p = self::own('protocol', (int)$a['id']);
        Database::pdo()->prepare('UPDATE protocols SET archived = 0 WHERE id = ?')->execute([$p['id']]);
        App::audit('protocol_restored', 'protocols', (int)$p['id']);
        return ['ok' => true, 'note' => 'Ripristinato. Le assegnazioni vanno ricreate esplicitamente.'];
    }

    // ───────────────────────── provider
    public static function providerSave(): array
    {
        $in = App::input();
        $key = (string)($in['provider_key'] ?? '');
        if (!in_array($key, ['demo', 'gemini', 'openai', 'anthropic', 'deepseek', 'openai_compatible'], true)) { throw new ApiError(422, 'provider_sconosciuto'); }
        $base = trim((string)($in['base_url'] ?? '')) ?: null;
        if (!Gateway::validBaseUrl($base) || ($key === 'openai_compatible' && !$base)) { throw new ApiError(422, 'indirizzo_non_valido', 'È richiesto un indirizzo https.'); }
        $pdo = Database::pdo();
        $existing = isset($in['id']) ? self::own('provider', (int)$in['id']) : null;
        $fb = isset($in['fallback_config_id']) && $in['fallback_config_id'] ? (int)self::own('provider', (int)$in['fallback_config_id'])['id'] : null;
        if ($fb !== null && empty($in['ack_fallback'])) { throw new ApiError(422, 'fallback_da_confermare', 'Il fallback invia i dati a un altro fornitore: va confermato esplicitamente.'); }
        if ($fb !== null && $existing && $fb === (int)$existing['id']) { throw new ApiError(422, 'fallback_non_valido'); }
        $enc = $existing['api_key_enc'] ?? null; $last4 = $existing['key_last4'] ?? null;
        $apiKey = trim((string)($in['api_key'] ?? ''));
        if ($apiKey !== '') {
            if (!Secrets::available()) { throw new ApiError(422, 'chiave_applicativa_assente', 'Impostare APP_SECRET_KEY in .env prima di salvare chiavi API.'); }
            $enc = Secrets::encrypt($apiKey); $last4 = substr($apiKey, -4);
        }
        $params = array_intersect_key((array)($in['params'] ?? []), ['token_param' => 1, 'response_format' => 1]);
        $vals = [$key, mb_substr(trim((string)($in['label'] ?? $key)), 0, 80) ?: $key, $base, mb_substr(trim((string)($in['model_name'] ?? '')), 0, 120) ?: null, $enc, $last4, json_encode($params ?: new \stdClass()),
            ($in['monthly_token_limit'] ?? 0) > 0 ? (int)$in['monthly_token_limit'] : null, max(3, min(120, (int)($in['timeout_s'] ?? 30))), max(0, min(3, (int)($in['max_retries'] ?? 1))), $fb, (int)!empty($in['is_default'])];
        if (!empty($in['is_default'])) { $pdo->prepare('UPDATE provider_configs SET is_default = 0 WHERE owner_id = ?')->execute([self::me()]); }
        if ($existing) {
            $pdo->prepare('UPDATE provider_configs SET provider_key=?, label=?, base_url=?, model_name=?, api_key_enc=?, key_last4=?, params=?, monthly_token_limit=?, timeout_s=?, max_retries=?, fallback_config_id=?, is_default=? WHERE id=?')
                ->execute(array_merge($vals, [$existing['id']]));
            $id = (int)$existing['id'];
            if ($existing['provider_key'] !== $key || $existing['base_url'] !== $base || $existing['model_name'] !== $vals[3] || $apiKey !== '') {
                $pdo->prepare('DELETE FROM rate_limits WHERE bucket IN (?,?)')->execute(["llm-wait:$id:429", "llm-wait:$id:503"]);
            }
        } else {
            $pdo->prepare('INSERT INTO provider_configs (provider_key, label, base_url, model_name, api_key_enc, key_last4, params, monthly_token_limit, timeout_s, max_retries, fallback_config_id, is_default, owner_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge($vals, [self::me()]));
            $id = (int)$pdo->lastInsertId();
        }
        App::audit('provider_saved', 'provider_configs', $id, ['provider_key' => $key, 'key_changed' => $apiKey !== '']); // mai la chiave
        return ['provider' => self::providerPublic(self::own('provider', $id))];
    }

    public static function providerTest(array $a): array
    {
        $cfg = self::own('provider', (int)$a['id']);
        App::rateLimit('ptest:' . self::me(), 20, 600);
        \Ablet\Llm\Http::setBudget(40);
        try {
            $r = Gateway::call(array_merge($cfg, ['fallback_config_id' => null]), 'prova', null, 'Rispondi soltanto con un oggetto JSON.', [['role' => 'user', 'content' => 'Prova di connessione. Rispondi con il JSON {"ok": true}.']], ['max_output_tokens' => 200]);
            return ['ok' => true, 'simulated' => $r['simulated'], 'json_valid' => json_decode(\Ablet\Domain\Agent\OutputValidator::unwrap($r['text']), true) !== null];
        } catch (ProviderException $e) {
            return ['ok' => false, 'error' => $e->kind, 'detail' => $e->getMessage(), 'http_status' => $e->httpStatus, 'retry_after' => $e->retryAfter];
        }
    }

    public static function providerModels(array $a): array
    {
        try {
            return ['models' => Gateway::adapter(self::own('provider', (int)$a['id']))->listModels()];
        } catch (ProviderException $e) {
            return ['models' => [], 'error' => $e->kind, 'detail' => $e->getMessage()];
        } catch (\RuntimeException $e) {
            return ['models' => [], 'error' => 'config'];
        }
    }

    public static function providerDelete(array $a): array
    {
        $p = self::own('provider', (int)$a['id']);
        Database::pdo()->prepare('UPDATE provider_configs SET enabled = 0, api_key_enc = NULL, key_last4 = NULL, is_default = 0 WHERE id = ?')->execute([$p['id']]);
        App::audit('provider_removed', 'provider_configs', (int)$p['id']);
        return ['ok' => true];
    }

    public static function settingsSave(): array
    {
        $in = App::input();
        $num = static fn($v, $lo, $hi, $d) => is_numeric($v) ? max($lo, min($hi, (float)$v)) : $d;
        $v = (array)($in['voice'] ?? []); $av = (array)($in['avatar'] ?? []); $rt = (array)($in['retention'] ?? []);
        $voice = ['engine' => ($v['engine'] ?? '') === 'server' ? 'server' : 'browser', 'input_enabled' => !empty($v['input_enabled']), 'output_enabled' => !empty($v['output_enabled']), 'autoplay' => !empty($v['autoplay']),
            'language' => preg_match('/^[a-z]{2}-[A-Z]{2}$/', (string)($v['language'] ?? '')) ? $v['language'] : 'it-IT', 'rate' => $num($v['rate'] ?? null, 0.5, 1.6, 1.0), 'pitch' => $num($v['pitch'] ?? null, 0.5, 1.6, 1.0),
            'voice_name' => mb_substr((string)($v['voice_name'] ?? ''), 0, 120), 'store_audio' => false];
        if ($voice['engine'] === 'server') {
            $provider = self::own('provider', (int)($v['provider_config_id'] ?? 0));
            if (!in_array($provider['provider_key'], ['gemini','openai','openai_compatible'], true)) throw new ApiError(422,'provider_audio_non_supportato');
        }
        $voice += ['provider_config_id' => (int)($v['provider_config_id'] ?? 0), 'tts_model' => mb_substr(trim((string)($v['tts_model'] ?? '')),0,120),
            'stt_model' => mb_substr(trim((string)($v['stt_model'] ?? '')),0,120), 'server_voice' => mb_substr(trim((string)($v['server_voice'] ?? '')),0,80)];
        $avatar = ['enabled' => !empty($av['enabled']), 'name' => mb_substr(trim((string)($av['name'] ?? 'Ablet')), 0, 30) ?: 'Ablet',
            'style' => in_array($av['style'] ?? '', ['astratto', 'volto', 'robot'], true) ? $av['style'] : 'astratto', 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($av['color'] ?? '')) ? $av['color'] : '#2f6f6a'];
        $ret = ['messages_days' => (int)$num($rt['messages_days'] ?? null, 30, 3650, 365), 'technical_logs_days' => (int)$num($rt['technical_logs_days'] ?? null, 7, 365, 30)];
        Database::pdo()->prepare('INSERT INTO professional_settings (owner_id, voice, avatar, retention) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE voice = VALUES(voice), avatar = VALUES(avatar), retention = VALUES(retention)')
            ->execute([self::me(), json_encode($voice, JSON_UNESCAPED_UNICODE), json_encode($avatar, JSON_UNESCAPED_UNICODE), json_encode($ret)]);
        return ['settings' => ['voice' => $voice, 'avatar' => $avatar, 'retention' => $ret]];
    }

    // ───────────────────────── dashboard e analisi
    public static function dashboard(): array
    {
        $me = self::me();
        $w = ['a.owner_id = ?']; $args = [$me];
        if (empty($_GET['include_sim'])) {
            $w[] = 's.is_simulation = 0 AND EXISTS (SELECT 1 FROM professional_students ps WHERE ps.student_id = s.student_id AND ps.professional_id = ? AND ps.active_to IS NULL)'; $args[] = $me;
        }
        foreach (['student_id' => 's.student_id = ?', 'activity_id' => 's.activity_id = ?'] as $k => $c) {
            if (!empty($_GET[$k])) { $w[] = $c; $args[] = (int)$_GET[$k]; }
        }
        foreach (['from' => 's.created_at >= ?', 'to' => 's.created_at < DATE_ADD(?, INTERVAL 1 DAY)'] as $k => $c) {
            if (!empty($_GET[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k])) { $w[] = $c; $args[] = $_GET[$k]; }
        }
        $ids = array_map('intval', array_column(self::rows('SELECT s.id FROM sessions s JOIN activities a ON a.id = s.activity_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.id DESC LIMIT 150', $args), 'id'));
        return Indicators::overview(array_reverse($ids), max(2, min(6, (int)($_GET['k'] ?? 3))));
    }

    public static function sessionGet(array $a): array
    {
        return Indicators::session((int)self::own('session', (int)$a['id'])['id']);
    }

    public static function sessionAnnotate(array $a): array
    {
        $s = self::own('session', (int)$a['id']);
        App::rateLimit('annot:' . self::me(), 40, 3600);
        return Annotator::run((int)$s['id'], self::me(), (App::input()['mode'] ?? 'lessicale') === 'llm' ? 'llm' : 'lessicale');
    }

    public static function annotationReview(array $a): array
    {
        $t = self::own('annotation', (int)$a['id']);
        $in = App::input();
        $d = (string)($in['decision'] ?? '');
        if ($d === 'correct') {
            $codes = array_column(Annotator::codebook()['content']['codes'], 'key');
            $code = $in['code_key'] ?? null;
            if ($code !== null && !in_array($code, $codes, true)) { throw new ApiError(422, 'codice_sconosciuto'); }
            return ['id' => AnnotationRepository::correct((int)$t['id'], self::me(), ['code_key' => $code, 'rationale' => mb_substr((string)($in['rationale'] ?? ''), 0, 400) ?: null])];
        }
        if (!in_array($d, ['confirm', 'reject'], true) || $t['review_status'] === 'corretta') { throw new ApiError(422, 'decisione_non_valida'); }
        Database::pdo()->prepare('UPDATE text_annotations SET review_status = ? WHERE id = ?')->execute([$d === 'confirm' ? 'confermata' : 'rifiutata', $t['id']]);
        App::audit('annotation_' . $d, 'text_annotations', (int)$t['id']);
        return ['ok' => true];
    }

    public static function sessionRating(array $a): array
    {
        $s = self::own('session', (int)$a['id']);
        $in = App::input();
        $r = self::own('rubric', (int)($in['rubric_id'] ?? 0));
        $score = $in['score'] ?? null;
        if (!is_numeric($score) || $score < $r['scale_min'] || $score > $r['scale_max']) { throw new ApiError(422, 'punteggio_fuori_scala'); }
        $gid = null;
        if (!empty($in['goal_id'])) {
            $gid = (int)(self::rows('SELECT id FROM session_goals WHERE id = ? AND session_id = ?', [(int)$in['goal_id'], $s['id']])[0]['id'] ?? 0) ?: null;
        }
        Database::pdo()->prepare('INSERT INTO professional_ratings (session_id, goal_id, rubric_id, rater_id, score, note) VALUES (?,?,?,?,?,?)')
            ->execute([$s['id'], $gid, $r['id'], self::me(), (float)$score, mb_substr((string)($in['note'] ?? ''), 0, 2000) ?: null]);
        return ['ok' => true];
    }

    public static function sessionNote(array $a): array
    {
        $s = self::own('session', (int)$a['id']);
        $body = mb_substr(trim((string)(App::input()['body'] ?? '')), 0, 4000);
        if ($body === '') { throw new ApiError(422, 'nota_vuota'); }
        Database::pdo()->prepare('INSERT INTO professional_notes (student_id, session_id, author_id, body) VALUES (?,?,?,?)')->execute([$s['student_id'], $s['id'], self::me(), $body]);
        return ['ok' => true];
    }
}
