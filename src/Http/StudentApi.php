<?php
declare(strict_types=1);

namespace Ablet\Http;

use Ablet\Llm\Gateway;
use Ablet\Repository\SessionRepository;
use Ablet\Service\Consent;
use Ablet\Service\SessionView;
use Ablet\Service\TurnService;
use Ablet\Support\Database;

final class StudentApi
{
    public static function register(): void
    {
        App::route('POST', '/api/student/plan-steps/{step}/start', [self::class,'startPlanned'], 'student');
        App::route('GET', '/api/student/home', [self::class, 'home'], 'student');
        App::route('POST', '/api/student/sessions', [self::class, 'create'], 'student');
        App::route('GET', '/api/student/sessions/{pid}', static fn($a) => ['session' => SessionView::forStudent(self::resolve($a['pid']))], 'student');
        App::route('POST', '/api/student/sessions/{pid}/message', [self::class, 'message'], 'student');
        App::route('POST', '/api/student/sessions/{pid}/action', [self::class, 'action'], 'student');
    }

    /** Una sessione altrui risulta «non trovata»: nessuna informazione sulla sua esistenza. */
    private static function resolve(string $pid): int
    {
        $st = Database::pdo()->prepare('SELECT id FROM sessions WHERE public_id = ? AND student_id = ?');
        $st->execute([$pid, App::user()['id']]);
        $id = $st->fetchColumn();
        if (!$id) { throw new ApiError(404, 'non_trovato'); }
        return (int)$id;
    }

    private static function versionFor(int $studentId, int $ownerId): ?int
    {
        $st = Database::pdo()->prepare("SELECT pa.protocol_version_id FROM protocol_assignments pa
            JOIN protocol_versions pv ON pv.id = pa.protocol_version_id AND pv.status = 'published' JOIN protocols p ON p.id = pv.protocol_id AND p.owner_id = ? AND p.archived = 0
            WHERE pa.active_to IS NULL AND (pa.student_id = ? OR pa.group_id IN (SELECT group_id FROM student_group_members WHERE student_id = ?))
            ORDER BY (pa.student_id IS NOT NULL) DESC, pa.id DESC LIMIT 1");
        $st->execute([$ownerId, $studentId, $studentId]);
        $v = $st->fetchColumn();
        return $v ? (int)$v : null;
    }

    private static function grant(int $student, int $activity): ?array
    {
        $q = Database::pdo()->prepare('SELECT aa.*, p.archived FROM activity_assignments aa LEFT JOIN protocol_versions pv ON pv.id = aa.protocol_version_id LEFT JOIN protocols p ON p.id = pv.protocol_id WHERE aa.student_id = ? AND aa.activity_id = ?');
        $q->execute([$student, $activity]);
        return $q->fetch() ?: null;
    }

    public static function home(): array
    {
        $uid = App::user()['id'];
        $programs = \Ablet\Service\SessionPlans::listing((int)$uid);
        $plannedActivities = [];
        foreach ($programs as $program) foreach ($program['steps'] as $step) $plannedActivities[$step['activity_id']] = true;
        $pdo = Database::pdo();
        $a = $pdo->prepare("SELECT a.id, a.title, a.activity_mode, a.assignment_text, a.expected_minutes, a.agent_enabled, a.owner_id, a.visibility FROM activities a
            JOIN professional_students ps ON ps.professional_id = a.owner_id AND ps.student_id = ? AND ps.active_to IS NULL WHERE a.visibility = 'all' OR EXISTS (SELECT 1 FROM activity_assignments aa WHERE aa.activity_id = a.id AND aa.student_id = ? AND aa.active = 1) OR EXISTS (SELECT 1 FROM sessions sx WHERE sx.activity_id = a.id AND sx.student_id = ? AND sx.state NOT LIKE 'closed%') ORDER BY a.id");
        $a->execute([$uid, $uid, $uid]);
        $acts = [];
        $owners = [];
        foreach ($programs as $program) $owners[(int)$program['owner_id']] = true;
        foreach ($a->fetchAll() as $r) {
            if (isset($plannedActivities[$r['id']])) { $owners[(int)$r['owner_id']] = true; continue; }
            $grant = self::grant($uid, (int)$r['id']);
            $allowed = $r['visibility'] === 'all' || ($grant && $grant['active']);
            $ver = $grant && $grant['active'] ? ($grant['archived'] ? null : $grant['protocol_version_id']) : self::versionFor($uid, (int)$r['owner_id']);
            $ready = $allowed && (!$r['agent_enabled'] || $ver !== null);
            $missing = Consent::forSession($uid, (int)$r['owner_id'], (bool)$r['agent_enabled']);
            $acts[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'mode' => $r['activity_mode'], 'assignment' => $r['assignment_text'], 'expected_minutes' => $r['expected_minutes'], 'agent_enabled' => (bool)$r['agent_enabled'],
                'available' => $ready && $missing === [], 'reason' => !$ready ? 'protocollo' : ($missing ? 'autorizzazioni' : null)];
            $owners[(int)$r['owner_id']] = true;
        }
        $s = $pdo->prepare('SELECT s.public_id, s.state, s.activity_id, s.srl_data, a.title, a.activity_mode, UNIX_TIMESTAMP(s.created_at) at FROM sessions s JOIN activities a ON a.id = s.activity_id WHERE s.student_id = ? ORDER BY s.id DESC LIMIT 20');
        $s->execute([$uid]);
        $sessions = array_map(static function (array $row): array {
            $slots = json_decode($row['srl_data'], true) ?: [];
            unset($row['srl_data']);
            if ($row['activity_mode'] === 'free') { $row['title'] = $slots['task_statement'] ?? $row['title']; }
            return $row;
        }, $s->fetchAll());
        // Avatar e voce scelti dal professionista, per la schermata iniziale (prima di aprire una sessione).
        $look = ['avatar' => ['enabled' => true, 'name' => 'Ablet', 'style' => 'volto', 'color' => '#2f6f6a'], 'voice' => ['language' => 'it-IT', 'rate' => 1.0, 'pitch' => 1.0, 'voice_name' => '', 'engine' => 'browser', 'provider_config_id' => 0, 'tts_model' => '', 'stt_model' => '', 'server_voice' => '', 'output_enabled' => true, 'input_enabled' => true]];
        if ($owners) {
            $q = $pdo->prepare('SELECT voice, avatar FROM professional_settings WHERE owner_id = ?');
            $q->execute([array_key_first($owners)]);
            if ($row = $q->fetch()) {
                $look['avatar'] = (json_decode($row['avatar'] ?? 'null', true) ?: []) + $look['avatar'];
                $look['voice'] = array_intersect_key((json_decode($row['voice'] ?? 'null', true) ?: []) + $look['voice'], $look['voice']);
            }
            $look['voice']['output_enabled'] = $look['voice']['output_enabled'] && Consent::missing($uid, (int)array_key_first($owners), ['voce']) === [];
        }
        $look['voice']['api_context'] = ['owner_id' => (int)(array_key_first($owners) ?? 0)];
        if (($look['voice']['engine'] ?? 'browser') === 'server' && !in_array('audio_provider', Consent::active((int)$uid), true)) {
            $look['voice']['input_enabled'] = false; $look['voice']['output_enabled'] = false;
        }
        if (($look['voice']['engine'] ?? 'browser') === 'server' && !empty($look['voice']['provider_config_id'])) {
            $audioProvider = Gateway::configFor((int)(array_key_first($owners) ?? 0), (int)$look['voice']['provider_config_id']);
            $look['voice']['audio_service'] = $audioProvider['label'];
        }
        $services = [];
        foreach (array_keys($owners) as $o) {
            $p = Gateway::configFor($o);
            $services[] = $p['provider_key'] === 'demo' ? 'nessun servizio esterno (risposte simulate)' : $p['label'];
        }
        if (!empty($look['voice']['audio_service'])) $services[] = 'Audio: ' . $look['voice']['audio_service'];
        // Informativa sintetica per lo studente: che cosa è ABLET, che cosa registra, chi vede i dati.
        return ['programs' => $programs, 'activities' => $acts, 'sessions' => $sessions, 'look' => $look, 'notice' => [
            'services' => array_values(array_unique($services)),
            'recorded' => ['i messaggi che scrivi o detti (il testo è salvato; l’audio non è archiviato da ABLET)', 'i pulsanti che premi e gli orari', 'il piano, le tue valutazioni da 1 a 5 e la riflessione finale'],
            'who' => 'Il tuo psicologo vede questi dati per seguire il tuo modo di studiare. Non sono voti.',
        ]];
    }

    public static function create(): array
    {
        $uid = App::user()['id'];
        $aid = (int)(App::input()['activity_id'] ?? 0);
        $st = Database::pdo()->prepare('SELECT a.owner_id, a.agent_enabled, a.visibility FROM activities a JOIN professional_students ps ON ps.professional_id = a.owner_id AND ps.student_id = ? AND ps.active_to IS NULL WHERE a.id = ?');
        $st->execute([$uid, $aid]);
        $act = $st->fetch();
        if (!$act) { throw new ApiError(404, 'non_trovato'); }
        $planned = Database::pdo()->prepare('SELECT st.id FROM session_plan_steps st JOIN session_plans p ON p.id=st.plan_id WHERE p.student_id=? AND st.activity_id=? LIMIT 1');
        $planned->execute([$uid,$aid]);
        if ($planned->fetchColumn()) throw new ApiError(422,'usa_sessione_programmata');
        $grant = self::grant($uid, $aid);
        if ($act['visibility'] === 'assigned' && (!$grant || !$grant['active'])) { throw new ApiError(404, 'attivita_non_assegnata'); }
        $ver = $act['agent_enabled'] ? ($grant && $grant['active'] ? ($grant['archived'] ? null : $grant['protocol_version_id']) : self::versionFor($uid, (int)$act['owner_id'])) : null;
        if ($act['agent_enabled'] && $ver === null) { throw new ApiError(422, 'nessun_protocollo_assegnato'); }
        if ($missing = Consent::forSession($uid, (int)$act['owner_id'], (bool)$act['agent_enabled'])) {
            throw new ApiError(422, 'autorizzazioni_mancanti', $missing);
        }
        App::rateLimit("newsession:$uid", 20, 3600);
        return ['session' => SessionView::forStudent(SessionRepository::create($uid, $aid, $ver))];
    }

    public static function startPlanned(array $a):array {
        $uid=(int)App::user()['id'];App::rateLimit('plannedstart:'.$uid,30,300);
        $sid=\Ablet\Service\SessionPlans::start($uid,(int)$a['step']);
        return ['session'=>SessionView::forStudent($sid)];
    }

    public static function message(array $a): array
    {
        $sid = self::resolve($a['pid']);
        $in = App::input();
        $text = trim(App::text($in,'text'));
        if ($text === '' || mb_strlen($text) > 2000) { throw new ApiError(422, 'messaggio_non_valido'); }
        App::rateLimit("msg:$sid", 30, 300);
        $r = TurnService::studentMessage($sid, $text, ($in['modality'] ?? 'text') === 'voice' ? 'voice' : 'text', self::cid($in));
        return ['notice' => $r['notice'], 'session' => SessionView::forStudent($sid)];
    }

    public static function action(array $a): array
    {
        $sid = self::resolve($a['pid']);
        $in = App::input();
        App::rateLimit("act:$sid", 60, 300);
        $r = TurnService::action($sid, App::text($in,'action'), is_array($in['payload'] ?? null) ? $in['payload'] : [], self::cid($in));
        return ['notice' => $r['notice'], 'session' => SessionView::forStudent($sid)];
    }

    private static function cid(array $in): ?string
    {
        $c = $in['client_event_id'] ?? null;
        return is_string($c) && preg_match('/^[0-9a-f-]{36}$/i', $c) ? strtolower($c) : null;
    }
}
