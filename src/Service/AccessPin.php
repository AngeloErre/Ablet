<?php
declare(strict_types=1);
namespace Ablet\Service;
use Ablet\Support\{Database,Env};
use Ablet\Http\ApiError;

/** Credenziale globale: HMAC per ricerca univoca, hash lento per verifica. */
final class AccessPin
{
    public static function lookup(string $pin): string
    {
        $key=base64_decode(Env::get('APP_SECRET_KEY',''),true);
        if($key===false || strlen($key)!==32) throw new ApiError(503,'pin_non_disponibile');
        return hash_hmac('sha256',"ablet:access-pin:v1:".$pin,$key);
    }
    public static function issue(int $id, ?int $expectedVersion=null): array
    {
        self::lookup('');
        return Database::transaction(static function($p)use($id,$expectedVersion):array{
            $q=$p->prepare("SELECT role,status,session_version FROM users WHERE id=? FOR UPDATE");$q->execute([$id]);$u=$q->fetch();
            if(!$u || $u['status']!=='active' || !in_array($u['role'],['student','psychologist'],true)) throw new ApiError(403,'pin_non_consentito');
            if($expectedVersion!==null && $expectedVersion!==(int)$u['session_version'])throw new ApiError(401,'non_autenticato');
            // Non riutilizzare il PIN precedente, neppure in caso di collisione casuale.
            $q=$p->prepare('SELECT lookup_hash FROM access_pins WHERE user_id=?');$q->execute([$id]);$old=$q->fetchColumn();
            for($i=0;$i<30;$i++){
                $pin=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);$lookup=self::lookup($pin);
                if($lookup===$old)continue;
                try {
                    $q=$p->prepare('SELECT user_id FROM access_pins WHERE lookup_hash=?');$q->execute([$lookup]);if($q->fetchColumn())continue;
                    $p->prepare('DELETE FROM access_pins WHERE user_id=?')->execute([$id]);
                    $p->prepare('INSERT INTO access_pins(user_id,lookup_hash,pin_hash,issued_version) VALUES(?,?,?,?)')->execute([$id,$lookup,password_hash($pin,PASSWORD_DEFAULT),$u['session_version']]);
                    return ['pin'=>$pin,'note'=>'Mostrato una sola volta. Conservalo in privato: permette di accedere al tuo account.'];
                }catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1062)throw $e;}
            }
            throw new ApiError(503,'pin_non_disponibile');
        });
    }
    public static function revoke(int $id): void
    {
        Database::transaction(static function($p)use($id):void{
            $q=$p->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);
            $p->prepare('DELETE FROM access_pins WHERE user_id=?')->execute([$id]);
        });
    }
}
