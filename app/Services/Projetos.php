<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class Projetos {
    public static function salvar(array $p,int $actor): int {
        $codigo=Inventario::text($p,'codigo',40); $nome=Inventario::text($p,'nome');
        $status=(string)($p['status']??'planejado');
        if(!in_array($status,['planejado','em_andamento','pausado','concluido','arquivado'],true)) throw new RuntimeException('Situação inválida para projeto.');
        $budget=trim((string)($p['orcamento']??''));
        if($budget!=='') {
            $budget=str_replace(',','.',$budget);
            if(!preg_match('/^\d{1,12}(\.\d{1,2})?$/D',$budget)) throw new RuntimeException('Informe o orçamento sem separador de milhar, com até duas casas decimais.');
            $budget=bcadd($budget,'0',2);
        } else $budget=null;
        $dates=[];
        foreach(['inicio','fim'] as $key) {
            $date=trim((string)($p[$key]??''));
            if($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))) throw new RuntimeException('Data de projeto inválida.');
            $dates[$key]=$date?:null;
        }
        if($dates['inicio'] && $dates['fim'] && $dates['fim']<$dates['inicio']) throw new RuntimeException('O término deve ser igual ou posterior ao início.');
        $cc=empty($p['cc_id'])?null:Inventario::ref('centros_custo',$p['cc_id']);
        $descricao=trim((string)($p['descricao']??'')); $gestor=trim((string)($p['gestor']??''));
        if(mb_strlen($descricao)>5000 || mb_strlen($gestor)>180) throw new RuntimeException('Descrição ou responsável excede o tamanho permitido.');
        return DB::transaction(function() use($p,$actor,$codigo,$nome,$status,$budget,$dates,$cc,$descricao,$gestor) {
            $id=(int)($p['projeto_id']??0);
            $old=$id?DB::one('SELECT * FROM projetos WHERE id=? FOR UPDATE',[$id]):null;
            if($id && !$old) throw new RuntimeException('Projeto não encontrado.');
            if($old && (int)($p['versao']??0)!==(int)$old['versao']) throw new RuntimeException('Este projeto foi atualizado em outra sessão. Reabra o formulário.');
            $values=[$codigo,$nome,$descricao,$gestor,$cc,$status,$budget,$dates['inicio'],$dates['fim']];
            if($old) DB::run('UPDATE projetos SET codigo=?,nome=?,descricao=?,gestor=?,centro_custo_id=?,status=?,orcamento=?,inicio=?,fim=?,versao=versao+1 WHERE id=?',[...$values,$id]);
            else { DB::run('INSERT INTO projetos(codigo,nome,descricao,gestor,centro_custo_id,status,orcamento,inicio,fim,criado_por) VALUES(?,?,?,?,?,?,?,?,?,?)',[...$values,$actor]); $id=(int)DB::pdo()->lastInsertId(); }
            DB::audit('projetos',$id,$old?'editar':'criar',$old,DB::one('SELECT * FROM projetos WHERE id=?',[$id]),$actor);
            return $id;
        });
    }
    public static function apontar(array $p,int $actor): void {
        $project=(int)($p['projeto_id']??0); $compra=(int)($p['compra_id']??0);
        $percent=str_replace(',','.',trim((string)($p['percentual']??'')));
        if(!preg_match('/^\d{1,3}(\.\d{1,2})?$/D',$percent) || bccomp($percent,'0',2)<=0 || bccomp($percent,'100',2)>0) throw new RuntimeException('O percentual deve ser maior que zero e no máximo 100, com até duas casas decimais.');
        $reason=Inventario::text($p,'motivo',500);
        DB::transaction(function() use($p,$actor,$project,$compra,$percent,$reason) {
            // Todas as alterações de rateio serializam pelo mesmo registro de compra.
            $c=DB::one('SELECT * FROM compras_origem WHERE id=? FOR UPDATE',[$compra]);
            if(!$c) throw new RuntimeException('Compra não encontrada.');
            $proj=DB::one('SELECT * FROM projetos WHERE id=? FOR UPDATE',[$project]);
            if(!$proj || $proj['status']==='arquivado') throw new RuntimeException('Selecione um projeto não arquivado.');
            if($c['cancelado_em'] || !$c['origem_presente'] || $c['conciliacao_pendente']) throw new RuntimeException('Regularize a divergência de origem antes de apontar esta compra.');
            $base=DB::one('SELECT base_pedido FROM vw_compras_custos WHERE id=?',[$compra]);
            if(bccomp((string)$base['base_pedido'],'0',4)<0) throw new RuntimeException('Valor de origem negativo exige conciliação.');
            $old=DB::one('SELECT * FROM compras_projetos WHERE compra_id=? AND projeto_id=?',[$compra,$project]);
            // Percentual anterior protege contra edição silenciosa de um vínculo em outra sessão.
            if(($p['percentual_anterior']??'')!=='' && (!$old || bccomp((string)$old['percentual'],(string)$p['percentual_anterior'],2)!==0)) throw new RuntimeException('O apontamento mudou em outra sessão. Recarregue a página.');
            $sum=DB::one('SELECT COALESCE(SUM(percentual),0) total FROM compras_projetos WHERE compra_id=? AND projeto_id<>?',[$compra,$project])['total'];
            if(bccomp(bcadd((string)$sum,$percent,2),'100',2)>0) throw new RuntimeException('O rateio excede 100% da compra. Já há '.$sum.'% em outros projetos.');
            DB::run('INSERT INTO compras_projetos(compra_id,projeto_id,percentual,motivo,atualizado_por) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE percentual=VALUES(percentual),motivo=VALUES(motivo),atualizado_por=VALUES(atualizado_por)',[$compra,$project,$percent,$reason,$actor]);
            DB::audit('compras_projetos',$compra.':'.$project,$old?'editar_rateio':'apontar',$old,['projeto_id'=>$project,'percentual'=>$percent,'motivo'=>$reason],$actor);
        });
    }
    public static function desvincular(array $p,int $actor): void {
        $reason=Inventario::text($p,'motivo',500); $id=(int)($p['apontamento_id']??0);
        DB::transaction(function() use($p,$id,$reason,$actor) {
            $hint=DB::one('SELECT compra_id FROM compras_projetos WHERE id=?',[$id]);
            if(!$hint) throw new RuntimeException('O vínculo já foi removido.');
            DB::one('SELECT id FROM compras_origem WHERE id=? FOR UPDATE',[$hint['compra_id']]);
            $row=DB::one('SELECT * FROM compras_projetos WHERE id=? FOR UPDATE',[$id]);
            if(!$row) throw new RuntimeException('O vínculo já foi removido.');
            if(bccomp((string)$row['percentual'],(string)($p['percentual_anterior']??'-1'),2)!==0) throw new RuntimeException('O vínculo foi alterado; recarregue a página.');
            DB::run('DELETE FROM compras_projetos WHERE id=?',[$id]);
            DB::audit('compras_projetos',$id,'desvincular',$row,['motivo'=>$reason],$actor);
        });
    }
    public static function lista(?Periodo $periodo=null): array {
        [$dateSql,$dateArgs]=($periodo??new Periodo())->condicao('processo_data');
        return DB::all("SELECT p.*,(SELECT COALESCE(SUM(t.valor_atribuido),0) FROM vw_apontamentos_custos t WHERE t.projeto_id=p.id) custo_total,cc.nome centro_custo,COALESCE(a.custo,0) custo,COALESCE(a.linhas,0) linhas,COALESCE(a.alertas,0) alertas FROM projetos p LEFT JOIN centros_custo cc ON cc.id=p.centro_custo_id LEFT JOIN (SELECT projeto_id,SUM(valor_atribuido) custo,COUNT(*) linhas,SUM(alerta_origem) alertas FROM vw_apontamentos_custos WHERE 1=1".$dateSql." GROUP BY projeto_id) a ON a.projeto_id=p.id ORDER BY p.status='arquivado',p.atualizado_em DESC,p.id DESC",$dateArgs);
    }
    public static function detalhe(int $id,?Periodo $periodo=null): array {
        [$dateSql,$dateArgs]=($periodo??new Periodo())->condicao('a.processo_data');
        $project=DB::one('SELECT p.*,c.nome centro_custo FROM projetos p LEFT JOIN centros_custo c ON c.id=p.centro_custo_id WHERE p.id=?',[$id]);
        if(!$project) throw new RuntimeException('Projeto não encontrado.');
        $rows=DB::all('SELECT a.*,p.nome produto,r.nome requisitante FROM vw_apontamentos_custos a JOIN produtos p ON p.id=a.produto_id JOIN requisitantes_sga r ON r.codigo=a.requisitante_codigo WHERE a.projeto_id=?'.$dateSql.' ORDER BY a.processo_data DESC,a.id DESC',[$id,...$dateArgs]);
        $total='0.00'; $nf='0.00'; $alert='0.00'; $months=[]; $categories=[];
        foreach($rows as $row) {
            $value=(string)$row['valor_atribuido']; $total=bcadd($total,$value,2);
            if((int)$row['vinculos_nf_positivos']>0) $nf=bcadd($nf,$value,2);
            if($row['alerta_origem']) $alert=bcadd($alert,$value,2);
            $month=substr($row['processo_data']??'',0,7)?:'Sem data'; $months[$month]=bcadd($months[$month]??'0',$value,2);
        }
        ksort($months);
        $categories=DB::all("SELECT COALESCE(NULLIF(p.categoria,''),'Sem categoria') nome,SUM(a.valor_atribuido) valor FROM vw_apontamentos_custos a JOIN produtos p ON p.id=a.produto_id WHERE a.projeto_id=?".$dateSql." GROUP BY COALESCE(NULLIF(p.categoria,''),'Sem categoria') ORDER BY valor DESC",[$id,...$dateArgs]);
        $totalProjeto=(string)DB::one('SELECT COALESCE(SUM(valor_atribuido),0) total FROM vw_apontamentos_custos WHERE projeto_id=?',[$id])['total'];
        return ['totalProjeto'=>$totalProjeto,'project'=>$project,'apontamentos'=>$rows,'totalCusto'=>$total,'comNF'=>$nf,'valorAlerta'=>$alert,'mensal'=>$months,'categorias'=>$categories,'saldoOrcamento'=>$project['orcamento']===null?null:bcsub((string)$project['orcamento'],$totalProjeto,2)];
    }
}
