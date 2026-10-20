<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
    if(in_array('--evidencia',$argv,true)) {
        $data=json_decode((string)file_get_contents(ROOT.'/evidencias/patrimonios-identificados.json'),true,512,JSON_THROW_ON_ERROR);
        $stats=App\Services\PatrimoniosSga::importar($data['registros'],'evidencia',null);
    } else $stats=App\Services\PatrimoniosSga::importar(App\Services\Integracao::consultar('patrimonios'),'sga',null);
    echo json_encode($stats,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
} catch(Throwable $e) { fwrite(STDERR,($e instanceof RuntimeException&&!$e instanceof PDOException?$e->getMessage():'Falha na importação patrimonial.').PHP_EOL); exit(1); }
