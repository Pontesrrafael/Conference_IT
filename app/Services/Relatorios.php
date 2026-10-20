<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
/**
 * Relatórios de gastos por centro de custo e período.
 * - Fonte: compras importadas do SGA (custo pelo pedido, canceladas fora), mesma regra do Painel (Parque::SQL_COMPRAS).
 * - Seleção múltipla de CCs configurados; CCs novos são validados no SGA, entram no escopo e uma sincronização roda antes do relatório.
 * - Total geral usa a união das condições: uma linha que pertence a dois CCs selecionados (ex.: pai com filhos + filho) não é contada duas vezes.
 */
final class Relatorios {
    public const LIMITE_DETALHE=500;
    /** Lê "1290, 1353;802" e devolve inteiros validados, sem repetição. */
    public static function codigosNovos(string $texto): array {
        $texto=trim($texto); if($texto==='') return [];
        $out=[];
        foreach(preg_split('/[\s,;]+/',$texto,-1,PREG_SPLIT_NO_EMPTY) as $parte) $out[CentrosCusto::validar($parte)]=true;
        if(count($out)>20) throw new RuntimeException('Informe no máximo 20 centros de custo novos por relatório.');
        return array_keys($out);
    }
    /**
     * Inclui os CCs novos no escopo (validados no SGA, com limites) e sincroniza.
     * @return array{adicionados:list<array>,sincronizacao:array}
     */
    public static function prepararComSincronizacao(array $novos,bool $filhos,?int $actor,?callable $reader=null,?callable $sincronizar=null): array {
        $adicionados=[];
        $leitor=$reader??static fn(string $sql)=>Integracao::executarSql($sql);
        // Confere todos os códigos antes de alterar o escopo: uma lista com um código inválido não deixa inclusões parciais.
        if($novos) {
            $achados=array_map('intval',array_column($leitor('SELECT c.CCCOD AS codigo FROM dbo.CENTROCUSTO c WHERE c.CCCOD IN ('.implode(',',array_map([CentrosCusto::class,'validar'],$novos)).')'),'codigo'));
            $faltando=array_values(array_diff($novos,$achados));
            if($faltando) throw new RuntimeException('Centro(s) de custo não encontrado(s) no Minerion: '.implode(', ',$faltando).'. Nada foi alterado.');
        }
        foreach($novos as $id) { $r=CentrosCusto::adicionar($id,$filhos,$reader); $adicionados[]=$r; DB::audit('integracao','centros_custo','adicionar_relatorio',null,$r,$actor); }
        $sync=($sincronizar??static fn()=>Integracao::sincronizar($actor))();
        return ['adicionados'=>$adicionados,'sincronizacao'=>$sync];
    }
    /** Origens de CC escolhidas (chaves cc:X) entre as configuradas; vazio = todas as configuradas. */
    public static function selecionar(array $codigos): array {
        $todas=array_values(array_filter(CentrosCusto::origens(),static fn($o)=>$o['tipo']==='Centro de custo'));
        $codigos=array_map('intval',$codigos);
        return $codigos?array_values(array_filter($todas,static fn($o)=>in_array($o['codigo'],$codigos,true))):$todas;
    }
    /** $limite null = todas as linhas (exportação CSV). */
    public static function gerar(array $origens,Periodo $periodo,?int $limite=self::LIMITE_DETALHE): array {
        if(!$origens) return ['origens'=>[],'total'=>null,'meses'=>[],'mensal'=>[],'totalMes'=>[],'linhas'=>[],'detalheLimitado'=>false];
        [$dateSql,$dateArgs]=$periodo->condicao('c.processo_data');
        $base=' FROM vw_compras_custos c LEFT JOIN centros_custo cc ON cc.id=c.centro_custo_compra_id WHERE c.cancelado_em IS NULL AND ';
        $union='('.implode(' OR ',array_column($origens,'sql')).')'; $unionArgs=array_merge(...array_column($origens,'args'));
        $porCc=[]; $meses=[]; $mensal=[];
        foreach($origens as $o) {
            $row=DB::one(Parque::SQL_COMPRAS.$o['sql'].$dateSql,[...$o['args'],...$dateArgs])?:[];
            // Patrimônios (ativos) das compras do CC no período e bens do imobilizado sem número.
            $ativos=['n'=>0,'valor'=>'0.00'];
            foreach(Parque::itens('','',false,CentrosCusto::origem($o['chave'],'ccc')) as $a) {
                $d=substr((string)($a['processo_data']??''),0,10);
                if($periodo->ativo() && ($d==='' || ($periodo->inicio && $d<$periodo->inicio) || ($periodo->fim && $d>$periodo->fim))) continue;
                if($a['situacao']==='baixado') continue;
                $ativos['n']++; if($a['valor_bem']!==null) $ativos['valor']=bcadd($ativos['valor'],(string)$a['valor_bem'],2);
            }
            $sem=['n'=>0,'valor'=>'0.00'];
            foreach(DB::all("SELECT payload_json FROM patrimonios_sga WHERE situacao='sem_numero'") as $s) {
                $pl=json_decode((string)$s['payload_json'],true)?:[];
                if(!CentrosCusto::patrimonioNaOrigem($pl,$o)) continue;
                $d=substr((string)($pl['nota_data']??''),0,10);
                if($periodo->ativo() && ($d==='' || ($periodo->inicio && $d<$periodo->inicio) || ($periodo->fim && $d>$periodo->fim))) continue;
                $sem['n']++; if(isset($pl['valor_aquisicao_imobilizado']) && is_numeric($pl['valor_aquisicao_imobilizado'])) $sem['valor']=bcadd($sem['valor'],(string)$pl['valor_aquisicao_imobilizado'],2);
            }
            $porCc[]=$o+$row+['ativos'=>$ativos,'sem_numero'=>$sem];
            foreach(DB::all("SELECT DATE_FORMAT(c.processo_data,'%Y-%m') mes,COALESCE(SUM(c.base_pedido),0) total".$base.$o['sql'].$dateSql." GROUP BY mes ORDER BY mes",[...$o['args'],...$dateArgs]) as $m) {
                $mes=$m['mes']??'sem data'; $meses[$mes]=true; $mensal[$o['chave']][$mes]=(string)$m['total'];
            }
        }
        $total=DB::one(Parque::SQL_COMPRAS.$union.$dateSql,[...$unionArgs,...$dateArgs]);
        $mesesOrd=array_keys($meses); sort($mesesOrd);
        $totalMes=[];
        foreach(DB::all("SELECT DATE_FORMAT(c.processo_data,'%Y-%m') mes,COALESCE(SUM(c.base_pedido),0) total".$base.$union.$dateSql." GROUP BY mes",[...$unionArgs,...$dateArgs]) as $m) $totalMes[$m['mes']??'sem data']=(string)$m['total'];
        $detalheSql="SELECT c.id,c.origem_item_id,c.processo_id,c.processo_data,c.base_pedido,c.vinculos_nf_positivos,c.quantidade_solicitada,p.nome produto,r.nome requisitante,cc.sga_codigo cc_sga,cc.nome cc_nome,EXISTS(SELECT 1 FROM ativos a WHERE a.compra_origem_id=c.id) tem_patrimonio FROM vw_compras_custos c LEFT JOIN centros_custo cc ON cc.id=c.centro_custo_compra_id JOIN produtos p ON p.id=c.produto_id LEFT JOIN requisitantes_sga r ON r.codigo=c.requisitante_codigo WHERE c.cancelado_em IS NULL AND ".$union.$dateSql." ORDER BY c.processo_data DESC,c.id DESC".($limite!==null?' LIMIT '.((int)$limite+1):'');
        $linhas=DB::all($detalheSql,[...$unionArgs,...$dateArgs]);
        $limitado=$limite!==null && count($linhas)>$limite;
        return ['origens'=>$porCc,'total'=>$total,'meses'=>$mesesOrd,'mensal'=>$mensal,'totalMes'=>$totalMes,'linhas'=>$limitado?array_slice($linhas,0,$limite):$linhas,'detalheLimitado'=>$limitado];
    }
}
