<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Services\Periodo;
use App\Services\Mapas;
use App\Services\DocumentosCompra;
use App\Services\ProjectXml;
$check=static function(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);};
$reject=static function(callable $fn)use($check):void{$failed=false;try{$fn();}catch(RuntimeException){$failed=true;}$check($failed,'Entrada inválida foi aceita.');};
try{
    foreach(['bcmath','mbstring','dom','sodium','pdo_mysql'] as $extension)$check(extension_loaded($extension),'Extensão necessária: '.$extension);
    $period=Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2026-10-01','data_fim'=>'2026-10-19']);
    [$sql,$params]=$period->condicao('c.processo_data');$check($params===['2026-10-01 00:00:00','2026-10-20 00:00:00'],'Data final exclusiva incorreta.');
    $reject(fn()=>Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2026-02-30']));
    $reject(fn()=>Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2026-10-20','data_fim'=>'2026-10-01']));
    $reject(fn()=>DocumentosCompra::numero('1 OR 1=1'));$check(DocumentosCompra::numero('123')===123,'Documento numérico inválido.');
    $poly=Mapas::poligono('[[0,0],[1,0],[1,1],[0,1]]',[-56.10,-15.65,-56.09,-15.64]);
    $check(count($poly['geojson']['coordinates'][0])===5,'GeoJSON não fechado.');
    $reject(fn()=>Mapas::poligono('[[0,0],[1,1],[0,1],[1,0]]',[-56.10,-15.65,-56.09,-15.64]));
    $project=['id'=>1,'codigo'=>'DEMO','nome'=>'Teste & mapa <TI>','descricao'=>'Exemplo sintético','gestor'=>'Equipe TI','centro_custo'=>'Demonstração','status'=>'planejado','orcamento'=>'100.00','inicio'=>'2026-10-01','fim'=>'2026-10-31','criado_em'=>'2026-10-01 08:00:00'];
    $purchase=['id'=>1,'processo_data'=>'2026-10-10 08:00:00','origem_item_id'=>1,'processo_id'=>1,'requisitante'=>'Exemplo','base_pedido'=>'100.00','percentual'=>'50.00','valor_atribuido'=>'50.00','motivo'=>'Teste sintético','vinculos_nf_positivos'=>0,'alerta_origem'=>0,'produto'=>'Produto exemplo'];
    $xml=ProjectXml::montar(['project'=>$project,'apontamentos'=>[$purchase]]);$dom=new DOMDocument();$check($dom->loadXML($xml,LIBXML_NONET),'XML inválido.');
    $xp=new DOMXPath($dom);$xp->registerNamespace('p','http://schemas.microsoft.com/project');
    $check($xp->evaluate('string(//p:Task[p:OutlineLevel="2"]/p:FixedCost)')==='50.00','Custo rateado incorreto.');
    $check($xp->query('//p:ActualCost|//p:PercentComplete')->length===0,'Progresso ou pagamento inventado.');
    $check(str_contains($xml,'&amp;')&&str_contains($xml,'&lt;TI&gt;'),'Escape XML inválido.');
    echo "OK: dependências PHP, filtros, validação de documentos, polígonos/GeoJSON e Project XML sintético. Sem banco, SGA ou dados internos.\n";
}catch(Throwable $e){fwrite(STDERR,'FALHOU: '.$e->getMessage().PHP_EOL);exit(1);}
