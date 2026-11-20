<?php
declare(strict_types=1);
namespace Ablet\Http;
use Ablet\Support\Database;
use Ablet\Llm\Gateway;
use Ablet\Llm\ProviderException;
use Ablet\Service\AudioService;
use Ablet\Service\Consent;
use Ablet\Service\SessionView;

final class VoiceApi
{
    public static function register(): void {
        foreach (['speech','transcribe'] as $operation) App::route('POST','/api/voice/'.$operation,static fn()=>self::run($operation));
    }
    private static function run(string $operation): array {
        $user=App::user();
        // Rotte condivise fra ruoli: dopo l'autenticazione liberare anche qui il lock PHP.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $in=App::input();$owner=(int)$user['id']; $context=$in['context'] ?? [];
        if(!is_array($context))throw new ApiError(422,'dati_non_validi');
        foreach(['text','audio','mime'] as $field)if(isset($in[$field]))App::text($in,$field);
        App::rateLimit('audio:'.$owner,30,300);
        $session=null;
        if ($user['role']==='student') {
            if (!empty($context['session_public_id'])) {
                $q=Database::pdo()->prepare('SELECT id FROM sessions WHERE public_id=? AND student_id=?');$q->execute([$context['session_public_id'],$user['id']]);$sid=$q->fetchColumn();
                if (!$sid) throw new ApiError(404,'non_trovato');
                $session=SessionView::context((int)$sid);$owner=(int)$session['owner_id'];
            } else $owner=(int)($context['owner_id'] ?? 0);
            $q=Database::pdo()->prepare('SELECT id FROM professional_students WHERE student_id=? AND professional_id=? AND active_to IS NULL');$q->execute([$user['id'],$owner]);
            if (!$q->fetchColumn()) throw new ApiError(404,'non_trovato');
            $missing=Consent::missing((int)$user['id'],$owner,['voce','invio_provider_esterno']);
            // Nuova destinazione dell'audio: richiede una registrazione esplicita anche con obbligo generale disattivato.
            if (!in_array('audio_provider',Consent::active((int)$user['id']),true)) $missing[]='audio_provider';
            if ($missing) throw new ApiError(422,'autorizzazione_audio_provider_mancante');
        } elseif (!in_array($user['role'],['psychologist','admin'],true)) throw new ApiError(403,'non_autorizzato');
        $q=Database::pdo()->prepare('SELECT voice FROM professional_settings WHERE owner_id=?');$q->execute([$owner]);$voice=json_decode((string)$q->fetchColumn(),true) ?: [];
        if (in_array($user['role'],['psychologist','admin'],true) && isset($in['preview']) && is_array($in['preview'])) $voice=array_merge($voice,array_intersect_key($in['preview'],array_flip(['engine','provider_config_id','tts_model','stt_model','server_voice'])));
        if (($voice['engine'] ?? 'browser')!=='server') throw new ApiError(422,'voce_server_non_configurata');
        if ($user['role']==='student') {
            $flag=$operation==='speech'?'output_enabled':'input_enabled'; $mod=$operation==='speech'?'voice_output':'voice_input';
            if (empty($voice[$flag]) || ($session && !($session['cfg']['modality'][$mod] ?? true))) throw new ApiError(403,'voce_disattivata');
        }
        $providerId=(int)($voice['provider_config_id'] ?? 0);
        if (!$providerId) throw new ApiError(422,'scegli_provider_audio');
        $provider=Gateway::configFor($owner,$providerId);
        if (($provider['id'] ?? null)!=$providerId) throw new ApiError(404,'provider_audio_non_trovato');
        try { return AudioService::run($provider,$voice,$operation,$in); }
        catch (ProviderException $e) { throw new ApiError(422,'errore_audio_provider',$e->getMessage()); }
    }
}
