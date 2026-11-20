<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
// Non caricare App prima dell'eccezione: l'autoloader deve risolvere ApiError da solo.
try{Ablet\Service\StudentMemory::save(1,1,[]);throw new RuntimeException('Validazione assente');}
catch(Ablet\Http\ApiError $e){if($e->status!==422)throw new RuntimeException('Stato errato');}
if(Ablet\Http\App::text(['x'=>'test'],'x')!=='test'||Ablet\Http\App::text([],'x')!=='')throw new RuntimeException('Testo alterato');
foreach([[],123,true,new stdClass()] as $bad){try{Ablet\Http\App::text(['x'=>$bad],'x');throw new RuntimeException('Tipo accettato');}catch(Ablet\Http\ApiError $e){if($e->status!==422)throw new RuntimeException('Stato errato');}}
echo "Validazione e autoload: 7 controlli superati.\n";
