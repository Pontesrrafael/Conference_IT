<?php
declare(strict_types=1);
// Teste portátil do escopo por centro de custo: sem banco, sem SGA real, dados sintéticos isolados.
if(PHP_SAPI!=='cli') exit(1);
$source=dirname(__DIR__);
$temp=sys_get_temp_dir().'/salinas-cc-'.bin2hex(random_bytes(6));
mkdir($temp.'/storage',0700,true);mkdir($temp.'/database/queries',0700,true);
define('ROOT',$temp);
spl_autoload_register(static function(string $class) use($source): void {
 if(str_starts_with($class,'App\\')) require $source.'/app/'.str_replace('\\','/',substr($class,4)).'.php';
});
use App\Services\CentrosCusto as CC;
use App\Services\Requisitantes as R;
use App\Services\Minerion;
use App\Services\PatrimoniosSga;
use App\Services\DocumentosCompra;
$check=static function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);};
$reject=static function(callable $fn,string $msg='Entrada indevida aceita.')use($check):void{$caught=false;try{$fn();}catch(RuntimeException){$caught=true;}$check($caught,$msg);};
// SGA simulado: árvore 900 > 901 > 902; 950 isolado; 514 genérico com muitas linhas.
$tree=[900=>'+1+900+',901=>'+1+900+901+',902=>'+1+900+901+902+',950=>'+1+950+',514=>'+1+514+'];
$lines=[900=>3,901=>2,902=>1,950=>10,514=>18995];
$queries=[];
$sga=static function(string $sql) use(&$tree,&$lines,&$queries): array {
 $queries[]=$sql;
 if(preg_match('/WHERE c\.CCCOD=(\d+)$/',$sql,$m)) { $id=(int)$m[1]; return isset($tree[$id])?[['codigo'=>$id,'nome'=>'CC sintético '.$id,'caminho'=>$tree[$id],'bloqueado'=>$id===950?'S':'N','tem_filho'=>'N']]:[]; }
 if(str_starts_with($sql,'SELECT DISTINCT c.CCCOD')) {
  preg_match('/c\.CCCOD IN \(([\d,]+)\)/',$sql,$m); $ids=array_map('intval',explode(',',$m[1]));
  preg_match_all("/LIKE '%\+(\d+)\+%'/",$sql,$f); $out=[];
  foreach($tree as $id=>$path) { if(in_array($id,$ids,true)) $out[]=['codigo'=>$id]; else foreach($f[1] as $p) if(str_contains($path,'+'.$p.'+')) $out[]=['codigo'=>$id]; }
  return $out;
 }
 if(str_starts_with($sql,'SELECT COUNT(*) AS n')) { preg_match('/IPCOCC IN \(([\d,]+)\)/',$sql,$m); $n=0; foreach(explode(',',$m[1]) as $id) $n+=$lines[(int)$id]??0; return [['n'=>$n]]; }
 throw new RuntimeException('SQL inesperado no teste: '.$sql);
};
try {
 foreach(['minerion_coleta.sql','minerion_patrimonios.sql'] as $name) copy($source.'/database/queries/'.$name,ROOT.'/database/queries/'.$name);
 // 1. Sem configuração: sentinela -1 (nunca CC 0, que significa compra sem CC).
 $check(CC::lista()===[],'Escopo inicial de CC deveria ser vazio.');
 foreach(['minerion_coleta.sql','minerion_patrimonios.sql'] as $name) {
  $sql=R::aplicarSql(file_get_contents($source.'/database/queries/'.$name));
  $check(str_contains($sql,'i.IPCOCC IN (-1)') && str_contains($sql,'IN (756,772,316,1318)') && !str_contains($sql,'{{'),'Template sem CC mal aplicado: '.$name);
 }
 // 2. Validação de entrada.
 foreach(['','0','-1','1.5','1 OR 1=1','2147483648',[]] as $value)$reject(fn()=>CC::validar($value));
 $reject(fn()=>CC::adicionar('777',false,$sga),'CC inexistente aceito.');
 $check(!is_file(ROOT.'/storage/centros-custo.json'),'Falha alterou a configuração.');
 // 3. Inclusão simples e com filhos.
 $r=CC::adicionar('950',false,$sga); $check($r['adicionado'] && $r['bloqueado'] && CC::lista()===[950] && $r['linhas_extras']===10,'Inclusão simples incorreta.');
 $r=CC::adicionar('900',true,$sga); $check(CC::lista()===[900,901,902,950] && $r['efetivos']===4,'Expansão de filhos incorreta.');
 $r=CC::adicionar('900',true,$sga); $check(!$r['adicionado'],'Repetição alterou a configuração.');
 $sql=R::aplicarSql(file_get_contents($source.'/database/queries/minerion_coleta.sql'));
 $check(str_contains($sql,'OR i.IPCOCC IN (900,901,902,950))'),'Consulta de compras sem os CCs.');
 // 4. Limite de volume: CC genérico recusado sem alterar a configuração.
 $before=file_get_contents(ROOT.'/storage/centros-custo.json');
 $reject(fn()=>CC::adicionar('514',false,$sga),'CC acima do limite de linhas aceito.');
 $check(file_get_contents(ROOT.'/storage/centros-custo.json')===$before,'Recusa alterou a configuração.');
 // 5. Nova filha criada no SGA passa a valer na atualização feita antes da sincronização.
 $tree[903]='+1+900+903+'; CC::atualizarExpansao($sga); $check(in_array(903,CC::lista(),true),'Filho novo não incorporado.');
 // 6. Importação: linha de outro requisitante entra pelo CC; CC 0 ou fora do escopo é recusado.
 $row=['requisitante_id'=>123456,'origem_item_id'=>1,'requisitante_nome'=>'Sintético','vinculos_nf_positivos'=>1,'cancelado_em'=>null,'centro_custo_compra_id'=>901];
 $check(isset(Minerion::resumir([$row])[123456]),'Linha do CC recusada.');
 foreach([0,514] as $cc){ $row['centro_custo_compra_id']=$cc; $reject(fn()=>Minerion::resumir([$row]),'Linha fora do escopo aceita (CC '.$cc.').'); }
 // 7. Patrimônio identificado pelo CC da compra.
 $asset=['imobilizado_item_id'=>1,'bem_id'=>1,'empresa_bem_id'=>1,'origem_item_id'=>1,'requisitante_id'=>123456,'centro_custo_compra_id'=>902,'patrimonio_texto'=>'SINTETICO'];
 $n=PatrimoniosSga::normalizar([$asset]); $check(count($n)===1 && $n[0]['centros_candidatos']===[902],'Patrimônio do CC recusado.');
 $asset['centro_custo_compra_id']=0; $check(PatrimoniosSga::normalizar([$asset])===[],'Patrimônio fora do escopo aceito.');
 // 8. Busca por documento continua sem filtro de escopo.
 DocumentosCompra::buscar(['documento_tipo'=>'processo','documento_numero'=>'5'],static function(string $sql)use($check):array{
  $check(!str_contains($sql,'{{') && !str_contains($sql,'IPCOFUNREQ IN (') && !str_contains($sql,'IPCOCC IN ('),'Documento manteve filtro de escopo.');
  return [['origem_item_id'=>1,'processo_id'=>5,'empresa_id'=>1]];
 });
 // 8b. Escopo só por centro de custo: requisitantes podem ser todos removidos enquanto houver CC.
 foreach(R::lista() as $rid) R::remover($rid);
 $check(R::lista()===[],'Não foi possível deixar o escopo só por centro de custo.');
 $sql=R::aplicarSql(file_get_contents($source.'/database/queries/minerion_coleta.sql'));
 $check(str_contains($sql,'IPCOFUNREQ IN (-1) OR i.IPCOCC IN (900,901,902,903,950)'),'Consulta só por CC incorreta.');
 $row['centro_custo_compra_id']=950; $row['requisitante_id']=756; $check(isset(Minerion::resumir([$row])[756]),'Linha do CC recusada sem requisitantes.');
 $row['centro_custo_compra_id']=0; $reject(fn()=>Minerion::resumir([$row]),'Linha sem CC aceita no escopo só por CC.');
 CC::remover(900,$sga); $reject(fn()=>CC::remover(950,$sga),'Último CC removido com escopo de requisitantes vazio.');
 $check(CC::lista()===[950],'Recusa alterou os centros de custo.');
 R::adicionar(756,fn($q)=>[['codigo'=>756,'nome'=>'Sintético']]); CC::adicionar('900',true,$sga);
 // 9. Remoção seletiva e retorno ao sentinela.
 $r=CC::remover(900,$sga); $check($r['removido'] && CC::lista()===[950],'Remoção com filhos incorreta.');
 $check(!CC::remover(900,$sga)['removido'],'Remoção repetida alterou a lista.');
 CC::remover(950,$sga); $check(CC::lista()===[] && str_contains(R::aplicarSql(file_get_contents($source.'/database/queries/minerion_coleta.sql')),'IPCOCC IN (-1)'),'Sentinela não restaurado.');
 foreach($queries as $q) $check(!preg_match('/;|--|\bUPDATE\b|\bDELETE\b|\bINSERT\b/i',$q),'Consulta não somente leitura gerada: '.$q);
 echo "OK: centros de custo — sentinela -1, validação, CC inexistente, inclusão simples/bloqueado, filhos por CCSTCOD, repetição, limite de linhas sem efeito colateral, novo filho na sincronização, importação e patrimônio pelo CC, CC 0 recusado, documentos sem filtro, escopo só por CC (requisitantes vazios, último CC protegido), remoção seletiva e consultas somente leitura. Dados sintéticos isolados.\n";
} finally {
 foreach(glob($temp.'/storage/*')?:[] as $f) unlink($f);
 foreach(glob($temp.'/database/queries/*')?:[] as $f) unlink($f);
 rmdir($temp.'/database/queries');rmdir($temp.'/database');rmdir($temp.'/storage');rmdir($temp);
}
