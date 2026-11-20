<?php
declare(strict_types=1);
namespace Ablet\Service;
use Ablet\Support\Database;
use Ablet\Http\ApiError;

/** Memoria SRL descrittiva: contatori riproducibili, nessuna inferenza clinica o chiamata LLM. */
final class StudentMemory
{
    public const WINDOW=20;
    public const CONTEXT_ROWS=5;
    public const MAX_CONTEXT_BYTES=6000;
    private static function scale(mixed $v): ?int {return is_numeric($v)&&(int)$v==$v&&(int)$v>=1&&(int)$v<=5?(int)$v:null;}
    public static function capture(int $sid, ?\PDO $p=null): void
    {
        $p??=Database::pdo();
        $q=$p->prepare("SELECT state,is_simulation,is_demo,srl_data,support_level,step_size FROM sessions WHERE id=?");$q->execute([$sid]);$s=$q->fetch();
        if(!$s || (int)$s['is_simulation'] || !in_array($s['state'],['closed_complete','closed_incomplete'],true))return;
        $q=$p->prepare("SELECT COUNT(*) n,SUM(status='completed') done FROM session_goals WHERE session_id=? AND status<>'dropped'");$q->execute([$sid]);$g=$q->fetch();
        $q=$p->prepare("SELECT type,COUNT(*) n FROM events WHERE session_id=? AND actor='student' AND type IN ('hint_requested','difficulty_flagged','strategy_changed','step_completed','step_not_completed') GROUP BY type");$q->execute([$sid]);$ev=[];foreach($q->fetchAll() as $e)$ev[$e['type']]=(int)$e['n'];
        $q=$p->prepare("SELECT COUNT(*) FROM messages WHERE session_id=? AND role='student' AND phase='self_reflection'");$q->execute([$sid]);$ref=(int)$q->fetchColumn();
        $srl=json_decode($s['srl_data'],true)?:[];
        // Si conserva la presenza della dichiarazione, non testo, interpretazione o diagnosi.
        $facts=['v'=>1,'closed'=>$s['state']==='closed_complete'?'complete':'incomplete','demo'=>(bool)$s['is_demo'],
            'goals'=>(int)$g['n'],'done'=>(int)($g['done']??0),'hints'=>$ev['hint_requested']??0,'difficulty'=>$ev['difficulty_flagged']??0,
            'strategy_changes'=>$ev['strategy_changed']??0,'reflection_messages'=>$ref,
            'declared_plan'=>isset($srl['strategy']),'declared_estimate'=>isset($srl['time_estimate_min']),
            'self_efficacy_declared'=>self::scale($srl['self_efficacy']??null),'difficulty_expected_declared'=>self::scale($srl['expected_difficulty']??null),
            'support'=>(int)$s['support_level'],'step'=>(int)$s['step_size']];
        $p->prepare('INSERT INTO student_memory_deltas(session_id,facts) VALUES(?,?) ON DUPLICATE KEY UPDATE session_id=VALUES(session_id)')->execute([$sid,json_encode($facts,JSON_UNESCAPED_UNICODE)]);
    }
    public static function settings(int $owner,int $student): array
    {
        $q=Database::pdo()->prepare('SELECT enabled,guidance,revision FROM student_memory_settings WHERE owner_id=? AND student_id=?');$q->execute([$owner,$student]);$r=$q->fetch()?:['enabled'=>1,'guidance'=>'','revision'=>0];
        return ['enabled'=>(bool)$r['enabled'],'guidance'=>$r['guidance'],'revision'=>(int)$r['revision']];
    }
    public static function view(int $owner,int $student): array
    {
        $q=Database::pdo()->prepare('SELECT d.session_id,d.facts,d.excluded,s.activity_id,s.ended_at FROM sessions s JOIN activities a ON a.id=s.activity_id JOIN student_memory_deltas d ON d.session_id=s.id WHERE s.student_id=? AND a.owner_id=? AND s.is_simulation=0 ORDER BY s.ended_at DESC,s.id DESC LIMIT '.self::WINDOW);
        $q->execute([$student,$owner]);$rows=[];
        foreach($q->fetchAll() as $r){$rows[]=['session_id'=>(int)$r['session_id'],'activity_id'=>(int)$r['activity_id'],'ended_at'=>$r['ended_at'],'excluded'=>(bool)$r['excluded'],'facts'=>json_decode($r['facts'],true)];}
        return ['settings'=>self::settings($owner,$student),'window'=>self::WINDOW,'sessions'=>$rows,'bytes'=>strlen(json_encode($rows,JSON_UNESCAPED_UNICODE))];
    }
    public static function forAgent(int $owner,int $student,int $activity): ?array
    {
        $v=self::view($owner,$student);if(!$v['settings']['enabled'])return null;
        $rows=array_values(array_filter($v['sessions'],static fn($r)=>!$r['excluded']));
        // Pertinenza esplicita: stessa attività prima, altre attività riportate come tali.
        usort($rows,static fn($a,$b)=>(int)($b['activity_id']===$activity)<=>(int)($a['activity_id']===$activity));
        $rows=array_slice($rows,0,self::CONTEXT_ROWS);$compact=[];
        foreach($rows as $r)$compact[]=['sid'=>$r['session_id'],'activity'=>$r['activity_id'],'date'=>$r['ended_at'],'facts'=>$r['facts']];
        $out=['schema'=>'srl-memory-1','source'=>'server_observed_counts','scope'=>'ultime 20 sessioni conservate; massimo 5 pertinenti; non è un profilo psicometrico',
            'professional_guidance'=>$v['settings']['guidance'],'sessions'=>$compact];
        if(!$compact && $out['professional_guidance']==='')return null;
        while(strlen(json_encode($out,JSON_UNESCAPED_UNICODE))>self::MAX_CONTEXT_BYTES && $out['sessions'])array_pop($out['sessions']);
        return $out;
    }
    public static function save(int $owner,int $student,array $in): array
    {
        if(!is_bool($in['enabled']??null)||!is_string($in['guidance']??null)||mb_strlen($in['guidance'])>500||!is_int($in['revision']??null))throw new ApiError(422,'memoria_non_valida');
        return Database::transaction(static function($p)use($owner,$student,$in):array{
            // Serializza creazione e modifica anche quando la scheda non esiste ancora.
            $q=$p->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$student]);
            $current=self::settings($owner,$student);if($current['revision']!==$in['revision'])throw new ApiError(409,'memoria_superata');
            $p->prepare('INSERT INTO student_memory_settings(owner_id,student_id,enabled,guidance,revision) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),guidance=VALUES(guidance),revision=revision+1')->execute([$owner,$student,(int)$in['enabled'],trim($in['guidance'])]);
            return self::settings($owner,$student);
        });
    }
    public static function rebuild(int $owner,int $student): void
    {
        $q=Database::pdo()->prepare("SELECT s.id FROM sessions s JOIN activities a ON a.id=s.activity_id WHERE s.student_id=? AND a.owner_id=? AND s.is_simulation=0 AND s.state IN ('closed_complete','closed_incomplete') ORDER BY s.ended_at DESC,s.id DESC LIMIT ".self::WINDOW);$q->execute([$student,$owner]);foreach($q->fetchAll() as $r)self::capture((int)$r['id']);
    }
    public static function exclude(int $owner,int $student,int $sid,bool $exclude): void
    {
        $q=Database::pdo()->prepare('SELECT s.id FROM sessions s JOIN activities a ON a.id=s.activity_id JOIN student_memory_deltas d ON d.session_id=s.id WHERE s.id=? AND s.student_id=? AND a.owner_id=?');$q->execute([$sid,$student,$owner]);if(!$q->fetchColumn())throw new ApiError(404,'non_trovato');
        Database::pdo()->prepare('UPDATE student_memory_deltas SET excluded=? WHERE session_id=?')->execute([(int)$exclude,$sid]);
    }
}
