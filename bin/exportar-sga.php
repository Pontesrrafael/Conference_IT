<?php
declare(strict_types=1);
// Exporta compras e patrimônios do SGA para importar em outra instalação: php bin/exportar-sga.php saida.json
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\SgaArquivo;
$saida=$argv[1]??''; if($saida==='') { fwrite(STDERR,"Uso: php bin/exportar-sga.php saida.json\n"); exit(2); }
try { $json=SgaArquivo::exportar(); if(file_put_contents($saida,$json,LOCK_EX)===false) throw new RuntimeException('Não foi possível gravar '.$saida); echo 'Exportado: ',$saida,' (',number_format(strlen($json)/1048576,1),' MiB)',PHP_EOL; }
catch(Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL); exit(1); }
