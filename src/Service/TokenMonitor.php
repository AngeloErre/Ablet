<?php
declare(strict_types=1);
namespace Ablet\Service;
use Ablet\Support\Database;
use Ablet\Http\ApiError;
use Ablet\Llm\Gateway;

/** Monitor del solo spazio professionale autenticato, senza credenziali o conversazioni. */
final class TokenMonitor
{
 public static function report(int $owner, array $q): array
 {
  $pdo=Database::pdo();$cfg=Gateway::configFor($owner);
  $key=(string)($q['provider']??$cfg['provider_key']);
  if(!in_array($key,['demo','gemini','openai','anthropic','deepseek','openai_compatible'],true))throw new ApiError(422,'provider_sconosciuto');
  $from=(string)($q['from']??gmdate('Y-m-d',strtotime('-29 days')));$to=(string)($q['to']??gmdate('Y-m-d'));
  foreach([$from,$to] as $date){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d')!==$date)throw new ApiError(422,'periodo_non_valido');}
  if($from>$to||strtotime($to)-strtotime($from)>=366*86400)throw new ApiError(422,'periodo_non_valido');
  $page=max(1,min(100000,(int)($q['page']??1)));$offset=($page-1)*25;
  $run=static function(string $sql,array $args=[])use($pdo):array{$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll();};
  // La somma comprende soltanto le componenti disponibili; gli assenti restano espliciti.
  $sum="COUNT(*) calls, COALESCE(SUM(status='ok'),0) successful, COALESCE(SUM(status='cooldown'),0) cooldowns, COALESCE(SUM(tokens_in),0) tokens_in, COALESCE(SUM(tokens_out),0) tokens_out, COALESCE(SUM(tokens_in IS NULL OR tokens_out IS NULL),0) unknown_usage, COALESCE(SUM(is_fallback),0) fallbacks";
  $where='owner_id=? AND provider_key=?';$args=[$owner,$key];
  $life=$run("SELECT $sum, MIN(created_at) first_call FROM llm_calls WHERE $where",$args)[0];
  $month=$run("SELECT $sum FROM llm_calls WHERE $where AND created_at>=DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')",$args)[0];
  $today=$run("SELECT $sum FROM llm_calls WHERE $where AND created_at>=UTC_DATE()",$args)[0];
  $period="$where AND created_at>=? AND created_at<DATE_ADD(?, INTERVAL 1 DAY)";$pa=[$owner,$key,$from,$to];
  $total=$run("SELECT $sum FROM llm_calls WHERE $period",$pa)[0];
  $days=$run("SELECT DATE(created_at) day, $sum FROM llm_calls WHERE $period GROUP BY DATE(created_at) ORDER BY day",$pa);
  $models=$run("SELECT model_name, $sum FROM llm_calls WHERE $period GROUP BY model_name ORDER BY tokens_in+tokens_out DESC",$pa);
  $outside=$run("SELECT $sum FROM llm_calls WHERE $period AND session_id IS NULL",$pa)[0];
  // Le sessioni restano visibili solo se anche l'attività appartiene al professionista.
  $sw='c.owner_id=? AND c.provider_key=? AND c.created_at>=? AND c.created_at<DATE_ADD(?, INTERVAL 1 DAY) AND a.owner_id=?';$sa=[...$pa,$owner];
  $count=$run("SELECT COUNT(DISTINCT c.session_id) n FROM llm_calls c JOIN sessions s ON s.id=c.session_id JOIN activities a ON a.id=s.activity_id WHERE $sw",$sa)[0]['n'];
  $sessions=$run("SELECT c.session_id, s.student_id, a.title activity, MAX(c.created_at) last_call, COUNT(*) calls, COALESCE(SUM(c.tokens_in),0) tokens_in, COALESCE(SUM(c.tokens_out),0) tokens_out, SUM(c.tokens_in IS NULL OR c.tokens_out IS NULL) unknown_usage, GROUP_CONCAT(DISTINCT c.model_name ORDER BY c.model_name SEPARATOR ', ') models, SUM(c.is_fallback) fallbacks FROM llm_calls c JOIN sessions s ON s.id=c.session_id JOIN activities a ON a.id=s.activity_id WHERE $sw GROUP BY c.session_id,s.student_id,a.title ORDER BY last_call DESC,c.session_id DESC LIMIT 25 OFFSET $offset",$sa);
  $budget=null;
  if($cfg['id']&&$cfg['monthly_token_limit']){$used=$run("SELECT COALESCE(SUM(COALESCE(tokens_in,0)+COALESCE(tokens_out,0)),0) n FROM llm_calls WHERE owner_id=? AND provider_config_id=? AND created_at>=DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')",[$owner,$cfg['id']])[0]['n'];$budget=['limit'=>(int)$cfg['monthly_token_limit'],'used'=>(int)$used,'remaining'=>max(0,(int)$cfg['monthly_token_limit']-(int)$used)];}
  return ['provider'=>$key,'default'=>['provider_key'=>$cfg['provider_key'],'model_name'=>$cfg['model_name'],'label'=>$cfg['label']],'from'=>$from,'to'=>$to,'lifetime'=>$life,'month'=>$month,'today'=>$today,'period'=>$total,'days'=>$days,'models'=>$models,'outside_sessions'=>$outside,'sessions'=>$sessions,'page'=>$page,'session_count'=>(int)$count,'local_budget'=>$budget,'generated_at'=>gmdate('c')];
 }
}
