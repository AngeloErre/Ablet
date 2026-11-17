<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use Ablet\Llm\{Http, ProviderException, Gateway, Cooldown};
use Ablet\Support\{Database, Secrets};

$base = getenv('MOCK') ?: 'http://127.0.0.1:8102';
$pdo = Database::pdo(); $n = 0;
function verify(string $name, bool $ok): void { global $n; if (!$ok) { throw new RuntimeException($name); } $n++; echo "✓ $name\n"; }
function failure(string $model, int $retries = 2, int $timeout = 3): ProviderException {
    global $base;
    Http::setBudget(15);
    try { Http::request('POST', $base . '/chat/completions', ['Authorization'=>'Bearer sk-test-SEGRETO-1234'], ['model'=>$model], $timeout, $retries); }
    catch (ProviderException $e) { return $e; }
    throw new RuntimeException('Errore simulato non rilevato');
}
$log = sys_get_temp_dir() . '/ablet_mock_requests.log'; file_put_contents($log, '');
$e = failure('quota-test');
verify('429 distinto e Retry-After conservato', $e->kind === 'rate_limited' && $e->httpStatus === 429 && $e->retryAfter === 12);
verify('429 non ripetuto nonostante due retry configurati', count(file($log)) === 1);
verify('chiave rimossa dal dettaglio errore', !str_contains($e->getMessage(), 'sk-test-SEGRETO-1234'));
$e = failure('busy-test');
verify('503 distinto senza attesa bloccante con Retry-After', $e->kind === 'service_unavailable' && $e->retryAfter === 12 && count(file($log)) === 2);
$e = failure('date-test');
verify('Retry-After in formato data HTTP', $e->retryAfter >= 23 && $e->retryAfter <= 25);
$e = failure('gemini-quota-test');
verify('RetryInfo Gemini arrotondato per eccesso', $e->retryAfter === 8);
$before = count(file($log)); $e = failure('retry-test', 1);
verify('errore transitorio con un solo retry configurato', $e->kind === 'service_unavailable' && count(file($log)) === $before + 2);
$e = failure('timeout-test', 0, 1);
verify('timeout distinto dalle quote', $e->kind === 'timeout' && $e->httpStatus === null);
usleep(400000);
$owner = (int)$pdo->query("SELECT id FROM users WHERE login='demo.psicologo' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO provider_configs (provider_key,label,base_url,model_name,api_key_enc,timeout_s,max_retries,owner_id) VALUES ('openai_compatible','Reliability test',?,'quota-test',?,3,2,?)")
    ->execute([$base, Secrets::encrypt('sk-test-SEGRETO-1234'), $owner]);
$id=(int)$pdo->lastInsertId();
try {
    $cfg=Gateway::configFor($owner,$id); Http::setBudget(15);
    try { Gateway::call($cfg,'prova',null,'JSON',[],[]); } catch (ProviderException $e) { verify('Gateway propaga la quota', $e->kind === 'rate_limited'); }
    $before=count(file($log));
    try { Gateway::call($cfg,'prova',null,'JSON',[],[]); } catch (ProviderException $e) { verify('Attesa condivisa conserva il tempo residuo', $e->retryAfter > 0); }
    verify('Durante il cooldown nessuna nuova chiamata remota', count(file($log)) === $before);
    $st=$pdo->prepare('SELECT status FROM llm_calls WHERE provider_config_id=? ORDER BY id DESC LIMIT 1');$st->execute([$id]);
    verify('Rinvio locale distinto nel registro tecnico', $st->fetchColumn() === 'cooldown');
    $pdo->prepare("UPDATE rate_limits SET window_start=UTC_TIMESTAMP(3)-INTERVAL 1 SECOND WHERE bucket=?")->execute(["llm-wait:$id:429"]);
    Cooldown::check($id); verify('Cooldown scaduto consente nuovo tentativo', true);
    Cooldown::record($id, new ProviderException('service_unavailable','',503));
    try { Cooldown::check($id); } catch (ProviderException $e) { verify('503 senza indicazione usa attesa di 10 secondi', $e->httpStatus === 503 && $e->retryAfter <= 10 && $e->retryAfter > 0); }
    $pdo->prepare('DELETE FROM rate_limits WHERE bucket IN (?,?)')->execute(["llm-wait:$id:429","llm-wait:$id:503"]);
    // Due richieste con lo stesso cookie: il test lento non deve trattenere il lock PHP.
    $pdo->prepare("UPDATE provider_configs SET model_name='timeout-test', max_retries=0 WHERE id=?")->execute([$id]);
    $app=getenv('APP') ?: 'http://127.0.0.1:8101'; $jar=tempnam(sys_get_temp_dir(),'abl-lock');
    $req=static function(string $path, ?array $body=null, ?string $csrf=null) use ($app,$jar) {
        $ch=curl_init($app.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>8]);
        if ($body!==null) {curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json','X-CSRF-Token: '.$csrf]);}
        $out=curl_exec($ch);curl_close($ch);return json_decode((string)$out,true);
    };
    $me=$req('/api/me'); $login=$req('/api/login',['login'=>'demo.psicologo','password'=>'Prova-demo.psicologo'],$me['csrf']);
    verify('Accesso per prova concorrente',isset($login['csrf']));
    file_put_contents($log,'');
    $slow=curl_init($app.'/api/psy/providers/'.$id.'/test');
    curl_setopt_array($slow,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_POSTFIELDS=>'{}',CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$login['csrf']],CURLOPT_TIMEOUT=>8]);
    $mh=curl_multi_init();curl_multi_add_handle($mh,$slow);curl_multi_exec($mh,$running);
    // Attende che il provider simulato sia stato effettivamente contattato.
    $start=microtime(true);$observed=false;
    while (microtime(true)-$start<2) {
        curl_multi_exec($mh,$running);clearstatcache(true,$log);
        if (str_ends_with((string)file_get_contents($log),"timeout-test\n")) {$observed=true;break;}
        usleep(20000);
    }
    verify('Prova lenta avviata prima della lettura concorrente',$observed);
    $start=microtime(true);$live=$req('/api/me');$elapsed=microtime(true)-$start;
    verify('Stesso cookie: sessione accessibile mentre il provider risponde',isset($live['user']['role']) && $elapsed<1.5);
    do {curl_multi_exec($mh,$running);if($running)curl_multi_select($mh,0.1);}while($running);
    verify('Prova lenta termina con risposta JSON',is_array(json_decode(curl_multi_getcontent($slow),true)));
    curl_multi_remove_handle($mh,$slow);curl_close($slow);curl_multi_close($mh);unlink($jar);
} finally {
    $pdo->prepare('DELETE FROM llm_calls WHERE provider_config_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM provider_configs WHERE id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM rate_limits WHERE bucket IN (?,?)')->execute(["llm-wait:$id:429","llm-wait:$id:503"]);
}
echo "Affidabilità provider: $n verifiche superate.\n";
