<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class DocumentosCompra {
    public static function numero(mixed $value,bool $opcional=false): ?int {
        $text=trim((string)$value);
        if($opcional&&$text==='')return null;
        if(!preg_match('/^\d{1,10}$/D',$text)||(int)$text<1||(int)$text>2147483647)throw new RuntimeException('Informe um número válido de documento ou empresa.');
        return (int)$text;
    }
    public static function buscar(array $p,?callable $reader=null): array {
        $type=(string)($p['documento_tipo']??'');
        if(!in_array($type,['requisicao','processo'],true))throw new RuntimeException('Selecione Requisição de Compra ou Ordem / processo de compra.');
        $number=self::numero($p['documento_numero']??'');$company=self::numero($p['documento_empresa']??'',true);
        $read=$reader??static fn(string $sql)=>Integracao::executarSql($sql);
        $sql=(string)file_get_contents(ROOT.'/database/queries/minerion_coleta.sql');
        $scope='i.IPCOFUNREQ IN ({{REQUISITANTES_SGA}})';
        if($type==='processo') $where='(p.PCOCOD='.$number.' OR p.PCONUM='.$number.')';
        else $where="EXISTS (SELECT 1 FROM dbo.ITEMPENDENCIACOMPRA rc WHERE rc.IPCOMPCOM=$number AND (rc.IPCOMCOD=i.IPCOIPCOM OR EXISTS(SELECT 1 FROM dbo.PCOMITEMPROCESSOCOMPRA rel WHERE rel.PIPCOIPCO=i.IPCOCOD AND rel.PIPCOIPCOM=rc.IPCOMCOD)))";
        if($company!==null)$where.=' AND p.PCOEMP='.$company;
        if(!str_contains($sql,$scope))throw new RuntimeException('Consulta de documentos incompatível com a integração.');
        $sql=str_replace($scope,$where,$sql);
        $purchases=$read($sql);
        $rc=[];$notice=[];
        if($type==='requisicao') {
            $whereRc='pc.PCOMCOD='.$number.($company!==null?' AND pc.PCOMEMP='.$company:'');
            $rc=$read("SELECT rc.IPCOMCOD AS requisicao_item_id,pc.PCOMCOD AS requisicao_id,pc.PCOMEMP AS empresa_id,pc.PCOMFIL AS filial_id,pc.PCOMFUNREQ AS requisitante_id,fu.FUNNOME AS requisitante_nome,rc.IPCOMITEM AS produto_id,COALESCE(NULLIF(e.ESTQNOME,''),NULLIF(rc.IPCOMDESC,''),'Item sem descrição') AS produto_nome,CAST(rc.IPCOMQUANT AS decimal(18,4)) AS quantidade,CAST(rc.IPCOMVLESTIMADO AS decimal(18,4)) AS valor_estimado_registrado,pc.PCOMDATA AS data_requisicao FROM dbo.PENDENCIACOMPRA pc JOIN dbo.ITEMPENDENCIACOMPRA rc ON rc.IPCOMPCOM=pc.PCOMCOD LEFT JOIN dbo.ESTOQUE e ON e.ESTQCOD=rc.IPCOMITEM LEFT JOIN dbo.FUNCIONARIO fu ON fu.FUNCOD=pc.PCOMFUNREQ WHERE $whereRc ORDER BY rc.IPCOMSEQ,rc.IPCOMCOD");
            $notice[]='O valor estimado da RC é exibido como registrado no SGA, sem presumir que seja unitário ou total. Não é somado ao custo de pedidos do projeto.';
            $notice[]='Uma linha de processo pode atender mais de uma requisição. Confira o percentual antes de atribuir o valor inteiro da linha ao projeto.';
        }
        if(!$purchases&&!$rc)throw new RuntimeException('Documento não encontrado no SGA para os parâmetros informados.');
        $ids=array_unique(array_column($purchases,'origem_item_id'));
        if(count($ids)!==count($purchases))throw new RuntimeException('A origem retornou linhas de compra duplicadas.');
        if($type==='processo'&&count(array_unique(array_column($purchases,'processo_id')))>1)throw new RuntimeException('O número corresponde a mais de um processo. Informe a empresa e/ou o código interno do processo.');
        $companies=array_unique(array_merge(array_column($purchases,'empresa_id'),array_column($rc,'empresa_id')));
        if(count($companies)!==1)throw new RuntimeException('O documento contém empresas diferentes; informe a empresa para limitar a coleta.');
        return ['tipo'=>$type,'numero'=>$type==='processo'?(int)$purchases[0]['processo_id']:$number,'numero_informado'=>$number,'empresa_id'=>(int)reset($companies),'compras'=>$purchases,'requisicao_itens'=>$rc,'avisos'=>$notice,'coletado_em'=>date('Y-m-d H:i:s'),'fonte'=>'Consulta direta SGA'];
    }
    public static function preparar(array $doc): array {
        foreach($doc['compras'] as &$r) {
            $local=DB::one('SELECT c.id,COALESCE(SUM(a.percentual),0) percentual_total FROM compras_origem c LEFT JOIN compras_projetos a ON a.compra_id=c.id WHERE c.origem_item_id=? GROUP BY c.id',[$r['origem_item_id']]);
            $r['percentual_total']=$local['percentual_total']??'0.00';
        }unset($r);return $doc;
    }
    public static function confirmar(array $doc,array $selecionados,string $percent,string $motivo,int $projectId,int $actor): array {
        if(!$doc['compras']&&!$doc['requisicao_itens'])throw new RuntimeException('Documento sem itens.');
        $all=[];foreach($doc['compras'] as $row)$all[(int)$row['origem_item_id']]=$row;
        $ids=[];foreach($selecionados as $id){$id=self::numero($id);if(!isset($all[$id]))throw new RuntimeException('Item não pertence ao documento consultado.');$ids[$id]=$id;}
        if(!$ids&&!$doc['requisicao_itens'])throw new RuntimeException('Selecione pelo menos uma linha do processo.');
        if(trim($motivo)===''||mb_strlen($motivo)>500)throw new RuntimeException('Informe uma justificativa com até 500 caracteres.');
        $project=DB::one("SELECT id FROM projetos WHERE id=? AND status<>'arquivado'",[$projectId]);
        if(!$project)throw new RuntimeException('Projeto não encontrado ou arquivado.');
        // Importação pontual não invalida compras de outras requisições ou requisitantes.
        if($all)Integracao::importar(array_values($all),'sga_documento',$actor,false);
        return DB::transaction(function()use($doc,$ids,$percent,$motivo,$projectId,$actor){
            sort($ids,SORT_NUMERIC);
            foreach($ids as $source) DB::one('SELECT id FROM compras_origem WHERE origem_item_id=? FOR UPDATE',[$source]);
            $project=DB::one('SELECT status FROM projetos WHERE id=? FOR UPDATE',[$projectId]);
            if(!$project||$project['status']==='arquivado')throw new RuntimeException('Projeto arquivado durante a operação.');
            // Ordem estável evita inversão de locks entre dois lotes.
            sort($ids,SORT_NUMERIC);
            foreach($ids as $source){$c=DB::one('SELECT id FROM compras_origem WHERE origem_item_id=?',[$source]);Projetos::apontar(['compra_id'=>$c['id'],'projeto_id'=>$projectId,'percentual'=>$percent,'motivo'=>$motivo],$actor);}
            DB::run('INSERT INTO projetos_documentos(projeto_id,tipo,numero,empresa_id,documento_json,atualizado_por) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE documento_json=VALUES(documento_json),atualizado_por=VALUES(atualizado_por)',[$projectId,$doc['tipo'],$doc['numero'],$doc['empresa_id'],json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor]);
            DB::audit('projetos_documentos',$projectId.':'.$doc['tipo'].':'.$doc['numero'],'coletar_e_apontar',null,['itens'=>$ids,'percentual'=>$percent,'motivo'=>$motivo],$actor);
            return ['compras'=>count($ids),'requisicao_itens'=>count($doc['requisicao_itens'])];
        });
    }
}
