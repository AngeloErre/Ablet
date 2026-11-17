<?php
declare(strict_types=1);
namespace Ablet\Service;
use Ablet\Support\Database;
use Ablet\Repository\SessionRepository;
use Ablet\Http\ApiError;
use Ablet\Http\App;

/** Ogni riga è una sessione, non un'abilitazione a ripetere indefinitamente un'attività. */
final class SessionPlans
{
    private static function rows(string $sql, array $args=[]): array { $q=Database::pdo()->prepare($sql);$q->execute($args);return $q->fetchAll(); }
    public static function create(int $owner,int $student,array $in): array
    {
        $steps=$in['steps'] ?? [];
        if (!is_array($steps) || !array_is_list($steps) || count($steps)<1 || count($steps)>30) throw new ApiError(422,'sequenza_non_valida');
        return Database::transaction(static function($pdo)use($owner,$student,$in,$steps):array{
            $q=$pdo->prepare('SELECT id FROM professional_students WHERE professional_id=? AND student_id=? AND active_to IS NULL FOR UPDATE');$q->execute([$owner,$student]);
            if(!$q->fetchColumn())throw new ApiError(404,'non_trovato');
            $validated=[];
            foreach($steps as $step){
                if(!is_array($step))throw new ApiError(422,'sessione_programmata_non_valida');
                $q=$pdo->prepare('SELECT id, agent_enabled FROM activities WHERE id=? AND owner_id=?');$q->execute([(int)($step['activity_id']??0),$owner]);$activity=$q->fetch();
                if(!$activity)throw new ApiError(404,'attivita_non_trovata');
                $version=null;
                if($activity['agent_enabled']){
                    $q=$pdo->prepare("SELECT pv.id FROM protocol_versions pv JOIN protocols p ON p.id=pv.protocol_id WHERE pv.id=? AND p.owner_id=? AND p.archived=0 AND pv.status='published' LOCK IN SHARE MODE");
                    $q->execute([(int)($step['protocol_version_id']??0),$owner]);$version=$q->fetchColumn();
                    if(!$version)throw new ApiError(422,'scegli_protocollo_pubblicato');
                }
                $validated[]=[(int)$activity['id'],$version];
            }
            $title=mb_substr(trim((string)($in['title']??'')),0,160) ?: (count($steps)===1?'Sessione singola':'Percorso di '.count($steps).' sessioni');
            $pdo->prepare('INSERT INTO session_plans(owner_id,student_id,title) VALUES(?,?,?)')->execute([$owner,$student,$title]);$id=(int)$pdo->lastInsertId();
            foreach($validated as $i=>[$activity,$version]){
                $pdo->prepare('INSERT INTO session_plan_steps(plan_id,position,activity_id,protocol_version_id) VALUES(?,?,?,?)')->execute([$id,$i+1,$activity,$version]);
                $pdo->prepare("UPDATE activities SET visibility='assigned' WHERE id=?")->execute([$activity]);
                // Il nuovo piano sostituisce per questo studente la precedente abilitazione illimitata alla stessa attività.
                $pdo->prepare('UPDATE activity_assignments SET active=0, updated_at=CURRENT_TIMESTAMP(3) WHERE activity_id=? AND student_id=?')->execute([$activity,$student]);
            }
            return ['id'=>$id,'sessions'=>count($validated)];
        });
    }
    public static function listing(int $student,?int $owner=null): array
    {
        $sql='SELECT p.* FROM session_plans p JOIN professional_students ps ON ps.professional_id=p.owner_id AND ps.student_id=p.student_id AND ps.active_to IS NULL WHERE p.student_id=?';$args=[$student];
        if($owner!==null){$sql.=' AND p.owner_id=?';$args[]=$owner;}
        $plans=self::rows($sql." ORDER BY (p.status='active') DESC,p.id DESC",$args);
        foreach($plans as &$plan){
            $plan['id']=(int)$plan['id'];$plan['student_id']=(int)$plan['student_id'];
            $steps=self::rows("SELECT st.id,st.position,st.activity_id,st.protocol_version_id,st.status,a.title activity,a.assignment_text,a.activity_mode,a.agent_enabled,p.title protocol,p.archived protocol_archived,pv.version_no,s.public_id session_public_id,s.state session_state FROM session_plan_steps st JOIN activities a ON a.id=st.activity_id LEFT JOIN protocol_versions pv ON pv.id=st.protocol_version_id LEFT JOIN protocols p ON p.id=pv.protocol_id LEFT JOIN sessions s ON s.id=st.session_id WHERE st.plan_id=? ORDER BY st.position",[$plan['id']]);
            $first=true;
            foreach($steps as &$step){
                $step['id']=(int)$step['id'];$step['position']=(int)$step['position'];$step['activity_id']=(int)$step['activity_id'];
                $done=in_array($step['status'],['completed','skipped','cancelled'],true);
                $current=$first&&!$done;
                $missing=$current?Consent::forSession($student,(int)$plan['owner_id'],(bool)$step['agent_enabled']):[];
                $step['current']=$current&&$plan['status']==='active';
                $step['available']=$step['current']&&($step['status']==='started'? !empty($step['session_public_id']) : ($step['status']==='pending'&&empty($step['protocol_archived'])&&$missing===[]));
                $step['reason']=!$step['current']?'sequenza':($step['status']==='blocked'?'sessione_da_riprogrammare':(!empty($step['protocol_archived'])&&$step['status']==='pending'?'protocollo_archiviato':($missing?'autorizzazioni':null)));
                if(!$done)$first=false;
            }unset($step);
            $plan['steps']=$steps;
        }unset($plan);
        return $plans;
    }
    public static function start(int $student,int $stepId): int
    {
        return Database::transaction(static function($pdo)use($student,$stepId):int{
            $q=$pdo->prepare('SELECT p.* FROM session_plans p JOIN session_plan_steps st ON st.plan_id=p.id JOIN professional_students ps ON ps.professional_id=p.owner_id AND ps.student_id=p.student_id AND ps.active_to IS NULL WHERE st.id=? AND p.student_id=? FOR UPDATE');$q->execute([$stepId,$student]);$plan=$q->fetch();
            if(!$plan)throw new ApiError(404,'non_trovato');
            if($plan['status']!=='active')throw new ApiError(422,'percorso_non_attivo');
            $q=$pdo->prepare("SELECT st.*,a.agent_enabled,p.archived,pv.status publication FROM session_plan_steps st JOIN activities a ON a.id=st.activity_id LEFT JOIN protocol_versions pv ON pv.id=st.protocol_version_id LEFT JOIN protocols p ON p.id=pv.protocol_id WHERE st.plan_id=? AND st.status IN ('pending','started','blocked') ORDER BY st.position LIMIT 1 FOR UPDATE");$q->execute([$plan['id']]);$step=$q->fetch();
            if(!$step||(int)$step['id']!==$stepId)throw new ApiError(422,'prima_completa_sessione_precedente');
            if($step['status']==='blocked')throw new ApiError(422,'sessione_da_riprogrammare');
            if($step['status']==='started'){
                if(!$step['session_id'])throw new ApiError(422,'sessione_rimossa_per_conservazione');
                return (int)$step['session_id'];
            }
            if($step['agent_enabled']&&($step['archived']||$step['publication']!=='published'))throw new ApiError(422,'protocollo_archiviato');
            if($missing=Consent::forSession($student,(int)$plan['owner_id'],(bool)$step['agent_enabled']))throw new ApiError(422,'autorizzazioni_mancanti',$missing);
            $sid=SessionRepository::create($student,(int)$step['activity_id'],$step['protocol_version_id']!==null?(int)$step['protocol_version_id']:null);
            $pdo->prepare("UPDATE session_plan_steps SET session_id=?,status='started' WHERE id=?")->execute([$sid,$stepId]);
            SessionRepository::recordEvent($sid,'system','planned_session_created',['plan_id'=>(int)$plan['id'],'step_id'=>$stepId,'position'=>(int)$step['position']],null,$pdo);
            return $sid;
        });
    }
    public static function onComplete(int $session,\PDO $pdo):void
    {
        $pdo->prepare("UPDATE session_plan_steps SET status='completed' WHERE session_id=? AND status='started'")->execute([$session]);
        $q=$pdo->prepare('SELECT plan_id FROM session_plan_steps WHERE session_id=?');$q->execute([$session]);$plan=$q->fetchColumn();
        if($plan)self::finish((int)$plan,$pdo);
    }
    private static function finish(int $plan,\PDO $pdo):void
    {
        $q=$pdo->prepare("SELECT COUNT(*) FROM session_plan_steps WHERE plan_id=? AND status IN ('pending','started','blocked')");$q->execute([$plan]);
        if((int)$q->fetchColumn()===0)$pdo->prepare("UPDATE session_plans SET status='completed' WHERE id=? AND status='active'")->execute([$plan]);
    }
    public static function cancel(int $owner,int $student,int $planId):void
    {
        Database::transaction(static function($pdo)use($owner,$student,$planId):void{
            $q=$pdo->prepare('SELECT id FROM session_plans WHERE id=? AND owner_id=? AND student_id=? FOR UPDATE');$q->execute([$planId,$owner,$student]);if(!$q->fetchColumn())throw new ApiError(404,'non_trovato');
            $pdo->prepare("UPDATE session_plans SET status='cancelled' WHERE id=?")->execute([$planId]);
            $pdo->prepare("UPDATE session_plan_steps SET status='cancelled' WHERE plan_id=? AND status IN ('pending','blocked')")->execute([$planId]);
        });
    }
    public static function skip(int $owner,int $student,int $stepId):void
    {
        Database::transaction(static function($pdo)use($owner,$student,$stepId):void{
            $q=$pdo->prepare('SELECT p.id,p.status plan_status,st.status,s.state session_state FROM session_plans p JOIN session_plan_steps st ON st.plan_id=p.id LEFT JOIN sessions s ON s.id=st.session_id WHERE st.id=? AND p.owner_id=? AND p.student_id=? FOR UPDATE');$q->execute([$stepId,$owner,$student]);$row=$q->fetch();
            if(!$row)throw new ApiError(404,'non_trovato');
            if($row['plan_status']!=='active'||!in_array($row['status'],['pending','started','blocked'],true)||($row['status']==='started'&&!in_array($row['session_state'],['closed_incomplete',null],true)))throw new ApiError(422,'chiudi_sessione_prima_di_saltare');
            $pdo->prepare("UPDATE session_plan_steps SET status='skipped' WHERE id=?")->execute([$stepId]);self::finish((int)$row['id'],$pdo);
        });
    }
}
