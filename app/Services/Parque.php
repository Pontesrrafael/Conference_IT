<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class Parque {
    public static function itens(string $q='',string $state=''): array {
        if(!in_array($state,['','alocado','em_estoque','manutencao','baixado','aguardando_conferencia','fora_uso'],true)) throw new RuntimeException('Selecione uma situação válida.');
        $where=' WHERE 1=1';$args=[];
        if($q!=='') { $where.=' AND (a.patrimonio LIKE ? OR p.nome LIKE ? OR CAST(c.origem_item_id AS CHAR)=?)';$args=["%$q%","%$q%",$q]; }
        if($state==='fora_uso') $where.=" AND a.situacao IN ('em_estoque','manutencao','baixado')";
        elseif($state!=='') { $where.=' AND a.situacao=?';$args[]=$state; }
        $rows=DB::all('SELECT a.*,p.nome produto,l.nome local_nome,cc.nome cc_nome,r.nome responsavel,c.origem_item_id,c.processo_data,c.quantidade_solicitada,c.base_pedido,s.payload_json imobilizado_json,s.situacao vinculo_sga,s.origem_presente origem_sga FROM ativos a JOIN produtos p ON p.id=a.produto_id LEFT JOIN locais l ON l.id=a.local_atual_id LEFT JOIN centros_custo cc ON cc.id=a.centro_custo_atual_id LEFT JOIN responsaveis r ON r.id=a.responsavel_atual_id LEFT JOIN vw_compras_custos c ON c.id=a.compra_origem_id LEFT JOIN patrimonios_sga s ON s.id=(SELECT s2.id FROM patrimonios_sga s2 WHERE s2.ativo_id=a.id ORDER BY s2.atualizado_em DESC,s2.id DESC LIMIT 1)'.$where.' ORDER BY a.id DESC',$args);
        foreach($rows as &$row) {
            $payload=$row['imobilizado_json']?json_decode($row['imobilizado_json'],true):[];
            $source=$payload['valor_aquisicao_imobilizado']??null;
            $row['valor_bem']=null; $row['base_custo']='Sem custo identificado';
            if($source!==null && is_numeric($source) && (float)$source>=0) {
                $row['valor_bem']=bcadd((string)$source,'0',2); $row['base_custo']='Imobilizado SGA';
            } elseif($row['base_pedido']!==null && bccomp((string)$row['base_pedido'],'0',4)>=0 && bccomp((string)($row['quantidade_solicitada']??0),'0',4)>0) {
                $row['valor_bem']=bcdiv((string)$row['base_pedido'],(string)$row['quantidade_solicitada'],2); $row['base_custo']='Estimado pelo pedido / quantidade';
            }
            $row['alerta_custo']=$row['vinculo_sga']==='conflito'||($row['origem_sga']!==null&&!(bool)$row['origem_sga']);
        }
        unset($row);return $rows;
    }
    public static function painel(Periodo $periodo): array {
        $all=self::itens();
        $stats=['parque'=>'0.00','parque_n'=>0,'uso'=>'0.00','uso_n'=>0,'fora'=>'0.00','fora_n'=>0,'baixado'=>'0.00','baixado_n'=>0,'pendente'=>'0.00','pendente_n'=>0,'sem_custo'=>0,'estimados'=>0,'alertas'=>0];
        $categories=[];$periodValue='0.00';$periodCount=0;
        foreach($all as $a) {
            $v=$a['valor_bem']??'0.00';$s=$a['situacao'];
            if($s==='baixado') { $stats['baixado']=bcadd($stats['baixado'],$v,2);$stats['baixado_n']++; }
            else { $stats['parque']=bcadd($stats['parque'],$v,2);$stats['parque_n']++; }
            if($s==='alocado') { $stats['uso']=bcadd($stats['uso'],$v,2);$stats['uso_n']++; }
            if(in_array($s,['em_estoque','manutencao','baixado'],true)) { $stats['fora']=bcadd($stats['fora'],$v,2);$stats['fora_n']++; }
            if($s==='aguardando_conferencia') { $stats['pendente']=bcadd($stats['pendente'],$v,2);$stats['pendente_n']++; }
            if($a['valor_bem']===null) $stats['sem_custo']++;
            if(str_starts_with($a['base_custo'],'Estimado')) $stats['estimados']++;
            if($a['alerta_custo']) $stats['alertas']++;
            $date=substr($a['processo_data']??'',0,10);
            $in=(!$periodo->ativo()||($date!==''&&(!$periodo->inicio||$date>=$periodo->inicio)&&(!$periodo->fim||$date<=$periodo->fim)));
            if($in) {
                $periodValue=bcadd($periodValue,$v,2);$periodCount++;
                $key=$a['situacao'];$categories[$key]??=['n'=>0,'valor'=>'0.00'];$categories[$key]['n']++;$categories[$key]['valor']=bcadd($categories[$key]['valor'],$v,2);
            }
        }
        $projects=Projetos::lista($periodo);$projectCost='0.00';foreach($projects as $p) $projectCost=bcadd($projectCost,(string)$p['custo'],2);
        $coverage=DB::one("SELECT COALESCE(SUM(situacao='sem_numero'),0) sem_numero,COALESCE(SUM(situacao='ambiguo'),0) ambiguos FROM patrimonios_sga");
        return ['parqueStats'=>$stats,'parquePorSituacao'=>$categories,'parquePeriodoValor'=>$periodValue,'parquePeriodoN'=>$periodCount,'projetosPainel'=>$projects,'custoProjetosPeriodo'=>$projectCost,'coberturaParque'=>$coverage];
    }
}
