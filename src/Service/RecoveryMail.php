<?php
declare(strict_types=1);
namespace Ablet\Service;
use Ablet\Support\{Database,Env};
use Ablet\Http\ApiError;

/** Trasporto nativo PHP: il successo indica accettazione, non consegna nella casella. */
final class RecoveryMail
{
 public static function config():array {
  $q=Database::pdo()->query("SELECT value FROM app_flags WHERE name='password_mail'");$saved=json_decode((string)$q->fetchColumn(),true);
  return is_array($saved)?$saved:['enabled'=>Env::get('PASSWORD_RESET_ENABLED','0')==='1','from'=>Env::get('MAIL_FROM',''),'public_url'=>Env::get('APP_PUBLIC_URL','')];
 }
 public static function validate(array $in):array {
  $from=is_string($in['from']??null)?trim($in['from']):'';$url=is_string($in['public_url']??null)?rtrim(trim($in['public_url']),'/'):'';$p=parse_url($url);
  $https=$p&&($p['scheme']??'')==='https';$local=Env::get('APP_ENV')==='development'&&($p['scheme']??'')==='http'&&in_array($p['host']??'',['127.0.0.1','localhost'],true);
  if(!filter_var($from,FILTER_VALIDATE_EMAIL)||strlen($from)>190||preg_match('/[\r\n]/',$from)||strlen($url)>500||!$p||empty($p['host'])||(!$https&&!$local)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||preg_match('/[\x00-\x20]/',$url))throw new ApiError(422,'configurazione_posta_non_valida');
  return ['enabled'=>($in['enabled']??false)===true,'from'=>$from,'public_url'=>$url];
 }
 public static function simulated():bool{return Env::get('APP_ENV')==='development'&&Env::get('PASSWORD_RESET_TEST_OUTBOX','')!=='';}
 public static function ready():bool {
  try{$c=self::validate(self::config());return $c['enabled']&&(self::simulated()||function_exists('mail'));}catch(ApiError){return false;}
 }
 public static function send(string $to,string $subject,string $body):bool {
  $c=self::validate(self::config());if(!$c['enabled'])return false;
  if(!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$to))return false;
  if(self::simulated())return @file_put_contents((string)Env::get('PASSWORD_RESET_TEST_OUTBOX'),json_encode(['to'=>$to,'subject'=>$subject,'body'=>$body],JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX)!==false;
  if(!function_exists('mail'))return false;
  return @mail($to,$subject,quoted_printable_encode($body),['From'=>'ABLET <'.$c['from'].'>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'quoted-printable']);
 }
}
