<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\Integracao;
use App\Core\Database as DB;
try {
    $data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    Integracao::salvar($data);
    DB::audit('integracao','sga','configurar',null,['servidor'=>$data['servidor'],'banco'=>$data['banco']],null);
    $sync=Integracao::sincronizar(null);
    echo json_encode(['status'=>'ok','resultado'=>$sync],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Falha na configuração ou consulta SGA.').PHP_EOL);
    exit(1);
}
