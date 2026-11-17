<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Ablet\Domain\Agent\PromptCompiler;
use Ablet\Domain\Agent\PromptBudget;
use Ablet\Domain\Protocol\ProtocolValidator;
use Ablet\Service\AudioService;
$pass=0;$fail=0;
function check(string $name,bool $ok):void{global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'✓ ':'✗ ').$name."\n";}
$models=glob(ABLET_ROOT.'/resources/templates/base_*.json');
check('Cinque originali di base protetti',count($models)===5);
foreach($models as $file){$c=json_decode(file_get_contents($file),true);check('Modello valido: '.$c['title'],ProtocolValidator::validate($c)['errors']===[]&&!empty($c['system_template']));$full=PromptCompiler::compile($c);$c['prompt_budget']['economy']=true;$small=PromptCompiler::compile($c);check('Formato economico riduce i caratteri: '.$c['template_key'],strlen($small['prompt'])<strlen($full['prompt']));foreach(['forethought','performance','self_reflection'] as $phase)check('Fase preservata in modalità economica: '.$phase,str_contains($small['prompt'],$c['srl_procedures'][$phase]['steps'][0]));}
foreach(['pianificazione_autoregolazione','comprensione_testo'] as $key){$original=json_decode(file_get_contents(ABLET_ROOT.'/resources/templates/'.$key.'.json'),true);check('Originale storico protetto: '.$key,!empty($original['system_template']));check('Originale storico valido: '.$key,ProtocolValidator::validate($original)['errors']===[]);}
$c=['provider'=>['params'=>['max_output_tokens'=>600]]];
$e=PromptBudget::estimate(str_repeat('a',3000),$c,['provider_key'=>'gemini','model_name'=>'gemini-2.5-flash']);
check('Stima costo input della tariffa nota',abs($e['input_cost_usd']-0.0003)<0.0000001);
check('Riserva di ragionamento Gemini contabilizzata',$e['reserved_output_tokens']===4096);
$unknown=PromptBudget::estimate('test',$c,['provider_key'=>'openai_compatible','model_name'=>'gpt-4o-mini']);
check('Non attribuisce le tariffe OpenAI a un endpoint compatibile',$unknown['input_cost_usd']===null);
$c['prompt_budget']=['context_tokens'=>1024,'input_usd_million'=>1.2,'output_usd_million'=>3.4];
$e=PromptBudget::estimate(str_repeat('x',6000),$c,['provider_key'=>'openai','model_name'=>'custom']);
check('Overflow individuato',$e['fits']===false);
check('Tariffe personalizzate applicate',abs($e['input_cost_usd']-0.0024)<0.0000001);
$wav=AudioService::wav(str_repeat("\0\0",240));
check('Conversione PCM in WAV con header corretto',substr($wav,0,4)==='RIFF'&&substr($wav,8,4)==='WAVE'&&strlen($wav)===524);
check('WAV contiene la frequenza prevista',unpack('V',substr($wav,24,4))[1]===24000);
echo "Superati: $pass · Falliti: $fail\n";exit($fail?1:0);
