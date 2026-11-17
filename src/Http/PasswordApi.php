<?php
declare(strict_types=1);
namespace Ablet\Http;
use Ablet\Support\{Database,Env};
use Ablet\Service\RecoveryMail;

final class PasswordApi
{
 public static function register():void {
  App::route('GET','/api/password-reset/status',static fn()=>['available'=>RecoveryMail::ready()]);
  App::route('POST','/api/password-reset/request',[self::class,'request']);
  App::route('POST','/api/password-reset/confirm',[self::class,'confirm']);
  App::route('GET','/api/admin/mail',static fn()=>['config'=>RecoveryMail::config(),'available'=>RecoveryMail::ready(),'simulated'=>RecoveryMail::simulated()],'admin');
  App::route('PUT','/api/admin/mail',[self::class,'saveMail'],'admin');
  App::route('POST','/api/admin/mail/test',[self::class,'testMail'],'admin');
 }
 private static function ipLimit(string $key,int $max):void {App::rateLimit('pwreset:'.$key.':'.hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.Env::get('APP_SECRET_KEY','')),$max,900);}
 public static function request():array {
  self::ipLimit('request',5);$in=App::input();$email=is_string($in['email']??null)?mb_strtolower(trim($in['email'])):'';
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new ApiError(422,'email_non_valida');
  App::rateLimit('pwreset:mail:'.hash('sha256',$email.'|'.Env::get('APP_SECRET_KEY','')),3,3600);
  if(!RecoveryMail::ready())throw new ApiError(503,'recupero_email_non_configurato');
  // Il trasporto non trattiene il lock PHP della sessione.
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  $p=Database::pdo();$p->exec('DELETE FROM password_reset_tokens WHERE expires_at < UTC_TIMESTAMP(3) - INTERVAL 1 DAY LIMIT 1000');
  $q=$p->prepare("SELECT id,session_version FROM users WHERE login=? AND role IN ('psychologist','admin') AND status='active'");$q->execute([$email]);$u=$q->fetch();
  $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
  if($u){
   $p->prepare('INSERT INTO password_reset_tokens(token_hash,user_id,issued_version,expires_at) VALUES(?,?,?,UTC_TIMESTAMP(3)+INTERVAL 30 MINUTE)')->execute([$hash,$u['id'],$u['session_version']]);
   $config=RecoveryMail::config();$url=$config['public_url'].'/?recupero=password#token='.$token;
   $body="Hai richiesto una nuova password per ABLET.\n\nApri questo collegamento entro 30 minuti:\n".$url."\n\nIl link e' monouso. Se non hai richiesto il recupero, ignora questa e-mail: la password attuale non cambia.\nNon condividere il link.\n";
   try{$sent=RecoveryMail::send($email,'ABLET - Recupero della password',$body);}catch(\Throwable){$sent=false;}
   if(!$sent)$p->prepare('DELETE FROM password_reset_tokens WHERE token_hash=?')->execute([$hash]);
   App::audit($sent?'password_reset_mail_accepted':'password_reset_mail_failed','users',(int)$u['id']);
  }
  return ['ok'=>true,'message'=>'Se esiste un account professionale attivo associato a questa e-mail, riceverai un collegamento temporaneo. Controlla anche la posta indesiderata.'];
 }
 public static function confirm():array {
  self::ipLimit('confirm',10);$in=App::input();$token=$in['token']??null;$password=$in['password']??null;
  if(!is_string($password)||mb_strlen($password)<12||strlen($password)>72||str_contains($password,"\0"))throw new ApiError(422,'password_non_valida');
  if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/',$token))throw new ApiError(422,'link_recupero_non_valido');
  $hash=hash('sha256',$token);$newHash=password_hash($password,PASSWORD_DEFAULT);
  $p=Database::pdo();$q=$p->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=?');$q->execute([$hash]);$id=$q->fetchColumn();if(!$id)throw new ApiError(422,'link_recupero_non_valido');
  $lock='ablet_pw_'.substr(hash('sha256',(string)Env::get('DB_NAME')),0,12).'_'.$id;$q=$p->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);
  if((int)$q->fetchColumn()!==1)throw new ApiError(503,'recupero_temporaneamente_occupato');
  try { Database::transaction(static function($p)use($hash,$newHash,$id):void{
   // La lettura di identità precede la transazione; il token viene comunque ricontrollato sotto lock.
   $q=$p->prepare("SELECT id,session_version FROM users WHERE id=? AND status='active' AND role IN ('psychologist','admin') FOR UPDATE");$q->execute([$id]);$u=$q->fetch();if(!$u)throw new ApiError(422,'link_recupero_non_valido');
   $q=$p->prepare('SELECT issued_version FROM password_reset_tokens WHERE token_hash=? AND user_id=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP(3) FOR UPDATE');$q->execute([$hash,$id]);$issued=$q->fetchColumn();
   if($issued===false||(int)$issued!==(int)$u['session_version'])throw new ApiError(422,'link_recupero_non_valido');
   $p->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?')->execute([$newHash,$id]);
   $p->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP(3) WHERE user_id=? AND used_at IS NULL')->execute([$id]);
   // Il reset è autorizzato dal token, non dall'eventuale account aperto nel browser.
   $p->prepare("INSERT INTO audit_log(actor_id,action,entity,entity_id,meta,ip_hash) VALUES(NULL,'password_self_reset','users',?,NULL,?)")->execute([$id,hash('sha256',($_SERVER['REMOTE_ADDR']??'').Env::get('APP_SECRET_KEY',''))]);
  }); } finally { $p->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
  return ['ok'=>true];
 }
 public static function saveMail():array {
  $c=RecoveryMail::validate(App::input());Database::transaction(static function($p)use($c):void{$p->prepare("INSERT INTO app_flags(name,value) VALUES('password_mail',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([json_encode($c,JSON_UNESCAPED_UNICODE)]);if(!$c['enabled'])$p->exec('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP(3) WHERE used_at IS NULL');});App::audit('password_mail_configured','app_flags',null,['enabled'=>$c['enabled']]);return ['ok'=>true,'available'=>RecoveryMail::ready()];
 }
 public static function testMail():array {
  App::rateLimit('mail-test:'.App::user()['id'],5,900);$in=App::input();$to=$in['to']??'';
  if(!is_string($to)||!filter_var($to,FILTER_VALIDATE_EMAIL)||strlen($to)>190)throw new ApiError(422,'email_non_valida');
  if(!RecoveryMail::ready())throw new ApiError(503,'recupero_email_non_configurato');
  try{$ok=RecoveryMail::send($to,'ABLET - Prova invio posta',"Questa e' una prova di invio dalla tua installazione ABLET.\nNon contiene link di accesso o password.\n");}catch(\Throwable){$ok=false;}
  App::audit('password_mail_test','app_flags',null,['accepted'=>$ok]);return ['accepted'=>$ok,'simulated'=>RecoveryMail::simulated()];
 }
}
