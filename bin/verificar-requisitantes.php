<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
$source=dirname(__DIR__);
$temp=sys_get_temp_dir().'/salinas-requisitantes-'.bin2hex(random_bytes(6));
mkdir($temp.'/storage',0700,true);mkdir($temp.'/database/queries',0700,true);
define('ROOT',$temp);
spl_autoload_register(static function(string $class) use($source): void {
 if(str_starts_with($class,'App\\')) require $source.'/app/'.str_replace('\\','/',substr($class,4)).'.php';
});
use App\Services\Requisitantes as R;
use App\Services\Minerion;
use App\Services\PatrimoniosSga;
use App\Services\DocumentosCompra;
$check=static function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);};
$reject=static function(callable $fn)use($check):void{$caught=false;try{$fn();}catch(RuntimeException){$caught=true;}$check($caught,'Entrada indevida aceita.');};
try {
 $check(R::lista()===R::PADRAO,'Escopo inicial alterado.');
 foreach(['','0','-1','1.5','1 OR 1=1','2147483648',[]] as $value)$reject(fn()=>R::validar($value));
 $reject(fn()=>R::adicionar('999999',fn($sql)=>[]));
 $check(!is_file(ROOT.'/storage/requisitantes.json'),'Falha alterou a configuração.');
 $reader=static function(string $sql)use($check):array{$check(str_ends_with($sql,'FUNCOD=999999'),'Consulta do funcionário incorreta.');return [['codigo'=>999999,'nome'=>'Requisitante sintético']];};
 $r=R::adicionar('999999',$reader);$check($r['adicionado']&&in_array(999999,R::lista(),true),'ID não foi salvo.');
 $r=R::adicionar('999999',$reader);$check(!$r['adicionado']&&count(R::lista())===5,'ID duplicado.');
 foreach(['minerion_coleta.sql','minerion_patrimonios.sql'] as $name) {
  $sql=file_get_contents($source.'/database/queries/'.$name);
  $expanded=R::aplicarSql($sql);$check(str_contains($expanded,'IN (756,772,316,1318,999999)')&&!str_contains($expanded,'{{'),'Escopo não aplicado: '.$name);
  copy($source.'/database/queries/'.$name,ROOT.'/database/queries/'.$name);
 }
 $row=['requisitante_id'=>999999,'origem_item_id'=>123,'requisitante_nome'=>'Sintético','vinculos_nf_positivos'=>1,'cancelado_em'=>null];
 $check(isset(Minerion::resumir([$row])[999999]),'Novo ID recusado pela importação.');
 $row['requisitante_id']=888888;$reject(fn()=>Minerion::resumir([$row]));
 $check(isset(Minerion::resumir([$row],false)[888888]),'Documento pontual indevidamente limitado.');
 $asset=['imobilizado_item_id'=>1,'bem_id'=>1,'empresa_bem_id'=>1,'origem_item_id'=>123,'requisitante_id'=>999999,'patrimonio_texto'=>'SINTETICO'];
 $check(count(PatrimoniosSga::normalizar([$asset]))===1,'Novo ID recusado no patrimônio.');
 $asset['requisitante_id']=888888;$check(PatrimoniosSga::normalizar([$asset])===[],'Patrimônio fora do escopo aceito.');
 $doc=DocumentosCompra::buscar(['documento_tipo'=>'processo','documento_numero'=>'123'],static function(string $sql)use($check):array{
  $check(!str_contains($sql,'{{')&&!str_contains($sql,'i.IPCOFUNREQ IN ('),'Documento manteve filtro de requisitante.');
  return [['origem_item_id'=>1,'processo_id'=>123,'empresa_id'=>1]];
 });
 $check($doc['numero']===123,'Busca por documento alterada.');
 $removed=R::remover(756);$check($removed['removido']&&!in_array(756,R::lista(),true),'ID inicial voltou após remoção.');
 $check(!R::remover(756)['removido'],'Remoção repetida alterou a lista.');
 foreach(['minerion_coleta.sql','minerion_patrimonios.sql'] as $name) $check(str_contains(R::aplicarSql(file_get_contents($source.'/database/queries/'.$name)),'IN (772,316,1318,999999)'),'ID removido continua na consulta.');
 foreach([772,316,1318] as $id) R::remover($id);
 $reject(fn()=>R::remover(999999));$check(R::lista()===[999999],'Último ID removido.');
 R::adicionar(756,fn($sql)=>[['codigo'=>756,'nome'=>'Sintético']]);$check(R::lista()===[999999,756],'Não foi possível reincluir ID removido.');
 echo "OK: remoção seletiva persistente, IDs iniciais, repetição, bloqueio do último ID, reinclusão;  IDs inválidos e inexistentes, persistência, duplicidade, consultas de compras/patrimônios, validação do novo ID e documentos fora do escopo. Dados sintéticos isolados.\n";
} finally {
 foreach(['storage/requisitantes.json','storage/requisitantes.lock','storage/requisitantes.json.tmp','database/queries/minerion_coleta.sql','database/queries/minerion_patrimonios.sql'] as $file)if(is_file($temp.'/'.$file))unlink($temp.'/'.$file);
 rmdir($temp.'/database/queries');rmdir($temp.'/database');rmdir($temp.'/storage');rmdir($temp);
}