<?php
declare(strict_types=1);
// Sincronização para cron: php bin/sincronizar-sga.php [--diagnostico]
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\Integracao;
$log=ROOT.'/storage/logs/sincronizar-sga.log';
if(!is_dir(dirname($log))) mkdir(dirname($log),0700,true);
$registrar=function(string $m) use($log): void { file_put_contents($log,date(DATE_ATOM).' '.$m.PHP_EOL,FILE_APPEND|LOCK_EX); };
if(in_array('--diagnostico',$argv,true)) {
    $falhou=false;
    foreach(Integracao::diagnosticar() as $p) { $st=$p['ok']===null?'--':($p['ok']?'ok':'FALHOU'); if($p['ok']===false) $falhou=true; printf("%-10s %-6s %5sms  %s\n",$p['etapa'],$st,(string)($p['ms']??''),$p['detalhe']); }
    exit($falhou?1:0);
}
$lock=fopen(ROOT.'/storage/sincronizar-sga.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { echo "Sincronização já em andamento; nada feito.\n"; exit(0); }
try {
    $r=Integracao::sincronizar(null);
    $m=$r['compras'].' compras; patrimônios: '.$r['patrimonios']['associado'].' associados, '.$r['patrimonios']['ambiguo'].' ambíguos, '.$r['patrimonios']['sem_numero'].' sem número.';
    echo $m,PHP_EOL; $registrar('ok '.$m); exit(0);
} catch(Throwable $e) {
    $chain=[]; for($x=$e;$x;$x=$x->getPrevious()) $chain[]=get_class($x).': '.($x instanceof App\Services\SgaFalha?'['.$x->etapa.'] ':'').$x->getMessage();
    $m=implode(' <- ',$chain); fwrite(STDERR,$m.PHP_EOL); $registrar('falha '.$m); exit(1);
} finally { flock($lock,LOCK_UN); fclose($lock); }
