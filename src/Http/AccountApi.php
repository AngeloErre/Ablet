<?php
declare(strict_types=1);
namespace Ablet\Http;
use Ablet\Support\{Database,Ids,Env};

/** Gli account educatore e psicologo usano lo stesso ruolo tecnico con spazi di lavoro isolati. */
final class AccountApi
{
    public static function registerRoutes():void
    {
        App::route('POST','/api/register',[self::class,'signup']);
        App::route('GET','/api/psy/account',[self::class,'profile'],'psychologist');
        App::route('PUT','/api/psy/account',[self::class,'save'],'psychologist');
    }
    private static function validated(array $in):array
    {
        foreach (['full_name','profession','organization','biography'] as $key) {
            if (isset($in[$key]) && !is_string($in[$key])) throw new ApiError(422,'profilo_non_valido');
        }
        $name=trim($in['full_name']??'');$profession=$in['profession']??'';
        $organization=trim($in['organization']??'');$bio=trim($in['biography']??'');
        if(mb_strlen($name)<2 || mb_strlen($name)>80 || !in_array($profession,['educator','psychologist'],true) || mb_strlen($organization)>160 || mb_strlen($bio)>2000) throw new ApiError(422,'profilo_non_valido');
        return [$name,$profession,$organization?:null,$bio?:null];
    }
    public static function signup():array
    {
        if (Env::get('ALLOW_SELF_REGISTRATION','1')!=='1') throw new ApiError(403,'registrazione_disabilitata');
        App::rateLimit('signup:'.hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.Env::get('APP_SECRET_KEY','')),5,3600);
        $in=App::input();
        if(!empty($in['website']))throw new ApiError(422,'registrazione_non_valida');
        [$name,$profession,$organization,$bio]=self::validated($in);
        $email=is_string($in['email']??null)?mb_strtolower(trim($in['email'])):'';
        $password=$in['password']??null;
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new ApiError(422,'email_non_valida');
        // Limite in byte compatibile anche con il backend bcrypt predefinito di PHP.
        if(!is_string($password)||mb_strlen($password)<12||strlen($password)>72||str_contains($password,"\0"))throw new ApiError(422,'password_non_valida');
        if(($in['accepted_notice']??false)!==true)throw new ApiError(422,'conferma_informazioni_account');
        $hash=password_hash($password,PASSWORD_DEFAULT);
        try {
            $uid=Database::transaction(static function($pdo)use($name,$profession,$organization,$bio,$email,$hash):int{
                $pdo->prepare("INSERT INTO users(pseudonym,role,login,password_hash,display_alias) VALUES(?,'psychologist',?,?,?)")->execute([Ids::pseudonym(),$email,$hash,$name]);
                $id=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO professional_profiles(user_id,full_name,profession,organization,biography,accepted_notice_version,accepted_at) VALUES(?,?,?,?,?,'account-1',UTC_TIMESTAMP(3))")->execute([$id,$name,$profession,$organization,$bio]);
                \Ablet\Support\Setup::initializeProfessionalWorkspace($pdo,$id);
                return $id;
            });
        }catch(\PDOException $e){if(($e->errorInfo[1]??null)===1062)throw new ApiError(409,'account_gia_presente');throw $e;}
        session_regenerate_id(true);
        $_SESSION=['uid'=>$uid,'last'=>time(),'csrf'=>bin2hex(random_bytes(24))];
        App::audit('professional_registered','users',$uid,['profession'=>$profession]);
        return App::me();
    }
    public static function profile():array
    {
        $u=App::user();$q=Database::pdo()->prepare('SELECT full_name,profession,organization,biography FROM professional_profiles WHERE user_id=?');$q->execute([$u['id']]);
        return ['profile'=>($q->fetch()?:['full_name'=>$u['display_alias']?:$u['login'],'profession'=>'psychologist','organization'=>null,'biography'=>null]),'login'=>$u['login'],'qualification_verified'=>false];
    }
    public static function save():array
    {
        $uid=App::user()['id'];[$name,$profession,$organization,$bio]=self::validated(App::input());
        Database::transaction(static function($pdo)use($uid,$name,$profession,$organization,$bio):void{
            $pdo->prepare('INSERT INTO professional_profiles(user_id,full_name,profession,organization,biography) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),profession=VALUES(profession),organization=VALUES(organization),biography=VALUES(biography),updated_at=UTC_TIMESTAMP(3)')->execute([$uid,$name,$profession,$organization,$bio]);
            $pdo->prepare('UPDATE users SET display_alias=? WHERE id=?')->execute([$name,$uid]);
        });
        App::audit('professional_profile_saved','users',$uid);
        return ['ok'=>true];
    }
}
