<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class PatrimoniosSga {
    public static function normalizar(array $rows): array {
        $permitidos=Requisitantes::lista();
        $groups=[];
        foreach($rows as $r) {
            $id=(int)($r['imobilizado_item_id']??0);
            if($id<=0 || (int)($r['bem_id']??0)<=0 || (int)($r['empresa_bem_id']??0)<=0) throw new RuntimeException('Identidade patrimonial inválida na origem.');
            $groups[$id]??=['row'=>$r,'compras'=>[],'req'=>[]];
            if((int)$groups[$id]['row']['bem_id']!==(int)$r['bem_id'] || (int)$groups[$id]['row']['empresa_bem_id']!==(int)$r['empresa_bem_id']) throw new RuntimeException('Dados patrimoniais contraditórios na origem.');
            foreach($r['compras_candidatas']??[$r['origem_item_id']] as $v) { if((int)$v<=0) throw new RuntimeException('Compra candidata inválida.'); $groups[$id]['compras'][(int)$v]=true; }
            foreach($r['requisitantes_candidatos']??[$r['requisitante_id']] as $v) $groups[$id]['req'][(int)$v]=true;
        }
        $out=[];
        foreach($groups as $g) {
            if(!array_intersect(array_keys($g['req']),$permitidos)) continue;
            $r=$g['row']; $r['compras_candidatas']=array_keys($g['compras']); $r['requisitantes_candidatos']=array_keys($g['req']);
            $text=trim((string)($r['patrimonio_texto']??''));
            $r['patrimonio']=$text!==''?$text:((int)($r['patrimonio_numerico']??0)>0?(string)$r['patrimonio_numerico']:null);
            if($r['patrimonio']!==null && mb_strlen($r['patrimonio'])>80) throw new RuntimeException('Número de patrimônio excede o tamanho permitido.');
            $out[]=$r;
        }
        return $out;
    }
    public static function importar(array $rows,string $fonte,?int $actor): array {
        $rows=self::normalizar($rows);
        if(!$rows) throw new RuntimeException('Consulta patrimonial vazia. O levantamento anterior foi preservado.');
        if((int)DB::one("SELECT GET_LOCK('salinas_ti_patrimonios',0) ok")['ok']!==1) throw new RuntimeException('A identificação patrimonial já está em andamento.');
        try {
            return DB::transaction(function() use($rows,$fonte,$actor) {
                $stats=['associado'=>0,'ambiguo'=>0,'sem_numero'=>0,'conflito'=>0];
                $seen=[];
                foreach($rows as $r) {
                    $source=(int)$r['imobilizado_item_id']; $seen[]=$source;
                    $old=DB::one('SELECT * FROM patrimonios_sga WHERE imobilizado_item_id=? FOR UPDATE',[$source]);
                    $state=$r['patrimonio']===null?'sem_numero':(count($r['compras_candidatas'])===1?'associado':'ambiguo');
                    $values=[$r['bem_id'],$r['empresa_bem_id'],$r['patrimonio'],mb_substr((string)$r['nome_bem'],0,500),mb_substr((string)($r['localizacao_sga']??''),0,500),mb_substr((string)($r['centro_custo_bem']??''),0,200),json_encode($r['compras_candidatas']),json_encode($r,JSON_UNESCAPED_UNICODE),$state,$fonte];
                    if($old) DB::run('UPDATE patrimonios_sga SET bem_id=?,empresa_id=?,patrimonio=?,nome_bem=?,localizacao_sga=?,centro_custo_sga=?,candidatos_json=?,payload_json=?,situacao=?,fonte=?,origem_presente=1,atualizado_em=NOW() WHERE id=?',[...$values,$old['id']]);
                    else DB::run('INSERT INTO patrimonios_sga(bem_id,empresa_id,patrimonio,nome_bem,localizacao_sga,centro_custo_sga,candidatos_json,payload_json,situacao,fonte,imobilizado_item_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[...$values,$source]);
                    $stage=DB::one('SELECT * FROM patrimonios_sga WHERE imobilizado_item_id=?',[$source]);
                    self::associar($stage,$actor); $updated=DB::one('SELECT situacao FROM patrimonios_sga WHERE id=?',[$stage['id']]); $stats[$updated['situacao']]++;
                }
                if($fonte==='sga') {
                    $scope=Requisitantes::lista();
                    $purchases=DB::all('SELECT origem_item_id FROM compras_origem WHERE requisitante_codigo IN ('.implode(',',array_fill(0,count($scope),'?')).')',$scope);
                    $purchaseIds=array_column($purchases,'origem_item_id');
                    $missing=DB::all('SELECT id,candidatos_json,payload_json FROM patrimonios_sga WHERE imobilizado_item_id NOT IN ('.implode(',',array_fill(0,count($seen),'?')).')',$seen);
                    foreach($missing as $stage) {
                        $payload=json_decode($stage['payload_json'],true,512,JSON_THROW_ON_ERROR);
                        $reqs=$payload['requisitantes_candidatos']??[$payload['requisitante_id']??0];
                        $candidates=json_decode($stage['candidatos_json'],true,512,JSON_THROW_ON_ERROR);
                        if(!array_intersect($scope,$reqs) && !array_intersect($purchaseIds,$candidates)) continue;
                        DB::run("UPDATE patrimonios_sga SET origem_presente=0,situacao='conflito',motivo='Registro não retornou na última consulta; conferir origem.' WHERE id=?",[$stage['id']]);
                    }
                }
                DB::audit('patrimonios_sga','coleta','sincronizar',null,['fonte'=>$fonte,'resumo'=>$stats],$actor);
                return $stats;
            });
        } finally { DB::run("SELECT RELEASE_LOCK('salinas_ti_patrimonios')"); }
    }
    private static function associar(array $stage,?int $actor): void {
        $candidates=json_decode($stage['candidatos_json'],true,512,JSON_THROW_ON_ERROR);
        $finish=static function(string $status,?string $reason) use($stage): void { DB::run('UPDATE patrimonios_sga SET situacao=?,motivo=? WHERE id=?',[$status,$reason,$stage['id']]); };
        if(!$stage['patrimonio']) { $finish($stage['ativo_id']?'conflito':'sem_numero','Sem número válido de patrimônio na origem.'); return; }
        $payload=json_decode($stage['payload_json'],true,512,JSON_THROW_ON_ERROR);
        if(!empty($payload['baixa_data']) && substr((string)$payload['baixa_data'],0,10)>'1900-01-01') { $finish('conflito','Há baixa registrada no imobilizado; conferir situação antes de associar.'); return; }
        if(isset($payload['nota_situacao']) && (int)$payload['nota_situacao']!==1) { $finish('conflito','Situação da nota diferente da observada no levantamento inicial; conferir origem.'); return; }
        $manual=$stage['compra_manual_id']?DB::one('SELECT * FROM compras_origem WHERE id=?',[$stage['compra_manual_id']]):null;
        if($manual && in_array((int)$manual['origem_item_id'],array_map('intval',$candidates),true)) $compra=$manual;
        elseif(count($candidates)===1) $compra=DB::one('SELECT * FROM compras_origem WHERE origem_item_id=?',[$candidates[0]]);
        else { $finish($stage['ativo_id']?'conflito':'ambiguo','Mais de uma linha de compra candidata; escolha após conferir a nota.'); return; }
        if(!$compra || (int)$compra['empresa_id']!==(int)$stage['empresa_id']) { $finish('conflito','Compra ausente ou empresa diferente; conferir vínculo.'); return; }
        $c=DB::one('SELECT * FROM compras_origem WHERE id=? FOR UPDATE',[$compra['id']]);
        if(!$c['origem_presente'] || $c['cancelado_em'] || $c['conciliacao_pendente']) { $finish('conflito','Compra com divergência na origem.'); return; }
        $a=$stage['ativo_id']?DB::one('SELECT * FROM ativos WHERE id=? FOR UPDATE',[$stage['ativo_id']]):DB::one('SELECT * FROM ativos WHERE empresa_patrimonio=? AND patrimonio=? FOR UPDATE',[$stage['empresa_id'],$stage['patrimonio']]);
        if($a && ((int)$a['compra_origem_id']!==(int)$c['id'] || (int)$a['produto_id']!==(int)$c['produto_id'] || (int)$a['empresa_patrimonio']!==(int)$stage['empresa_id'] || $a['patrimonio']!==$stage['patrimonio'])) { $finish('conflito','O patrimônio já está ligado a outro registro ou mudou na origem; vínculo anterior preservado.'); return; }
        if($a && DB::one('SELECT id FROM patrimonios_sga WHERE ativo_id=? AND bem_id<>? LIMIT 1',[$a['id'],$stage['bem_id']])) { $finish('conflito','Número repetido para bens diferentes na mesma empresa; conferir cadastro SGA.'); return; }
        if(!$a) {
            DB::run("INSERT INTO ativos(produto_id,compra_origem_id,patrimonio,situacao,empresa_patrimonio) VALUES(?,?,?,'aguardando_conferencia',?)",[$c['produto_id'],$c['id'],$stage['patrimonio'],$stage['empresa_id']]);
            $id=(int)DB::pdo()->lastInsertId();
            DB::audit('ativos',$id,'identificar_sga',null,['imobilizado_item_id'=>$stage['imobilizado_item_id'],'compra_id'=>$c['id'],'empresa'=>$stage['empresa_id'],'patrimonio'=>$stage['patrimonio'],'situacao'=>'aguardando_conferencia'],$actor);
        } else $id=(int)$a['id'];
        DB::run("UPDATE patrimonios_sga SET ativo_id=?,situacao='associado',motivo=NULL WHERE id=?",[$id,$stage['id']]);
    }
    public static function escolher(array $p,int $actor): void {
        $reason=Inventario::text($p,'motivo',500);
        DB::transaction(function() use($p,$actor,$reason) {
            $s=DB::one('SELECT * FROM patrimonios_sga WHERE id=? FOR UPDATE',[(int)($p['patrimonio_sga_id']??0)]);
            if(!$s || !$s['origem_presente']) throw new RuntimeException('Registro de origem não disponível.');
            if($s['ativo_id']) throw new RuntimeException('Este registro já tem um ativo; revise o vínculo existente.');
            $origem=(int)($p['origem_item_id']??0); $candidates=json_decode($s['candidatos_json'],true,512,JSON_THROW_ON_ERROR);
            if(!in_array($origem,array_map('intval',$candidates),true)) throw new RuntimeException('Escolha uma das compras candidatas da nota.');
            $c=DB::one('SELECT id FROM compras_origem WHERE origem_item_id=?',[$origem]);
            if(!$c) throw new RuntimeException('Compra não encontrada na aplicação.');
            DB::run('UPDATE patrimonios_sga SET compra_manual_id=? WHERE id=?',[$c['id'],$s['id']]); $s['compra_manual_id']=$c['id'];
            self::associar($s,$actor);
            DB::audit('patrimonios_sga',$s['id'],'escolher_compra',$s,['compra_id'=>$c['id'],'motivo'=>$reason],$actor);
        });
    }
    public static function conferir(array $p,int $actor): void {
        $local=Inventario::ref('locais',$p['local_id']??0); $cc=Inventario::ref('centros_custo',$p['cc_id']??0); $why=Inventario::text($p,'motivo',500);
        $serie=trim((string)($p['serie']??'')); if(mb_strlen($serie)>150) throw new RuntimeException('Série muito longa.');
        DB::transaction(function() use($p,$actor,$local,$cc,$why,$serie) {
            $hint=DB::one('SELECT compra_origem_id FROM ativos WHERE id=?',[(int)($p['ativo_id']??0)]);
            if(!$hint) throw new RuntimeException('Ativo não encontrado.');
            $c=DB::one('SELECT * FROM compras_origem WHERE id=? FOR UPDATE',[$hint['compra_origem_id']]);
            $a=DB::one('SELECT * FROM ativos WHERE id=? FOR UPDATE',[(int)$p['ativo_id']]);
            if($a['situacao']!=='aguardando_conferencia') throw new RuntimeException('Este ativo já foi conferido.');
            $source=DB::one('SELECT id FROM patrimonios_sga WHERE ativo_id=? AND situacao=\'associado\' AND origem_presente=1 LIMIT 1',[$a['id']]);
            if(!$source || !$c || $c['cancelado_em'] || !$c['origem_presente'] || $c['conciliacao_pendente'] || (int)$c['vinculos_nf_positivos']<1) throw new RuntimeException('Origem precisa ser conciliada antes da conferência.');
            if($c['triagem']==='excluir') throw new RuntimeException('A compra está excluída na triagem. Revise a classificação.');
            $prod=DB::one('SELECT * FROM produtos WHERE id=? FOR UPDATE',[$a['produto_id']]);
            if(!in_array($prod['controle'],['serializado','nao_classificado'],true)) throw new RuntimeException('Produto tem controle de quantidade/serviço. Revise a classificação antes de confirmar o bem.');
            $new=bcadd($c['incorporada_quantidade'],'1',4);
            if(bccomp($new,$c['quantidade_vinculada_nf'],4)>0) throw new RuntimeException('Quantidade faturada já incorporada. Confira os vínculos existentes.');
            DB::run("UPDATE produtos SET controle='serializado',incluir_parque=1 WHERE id=?",[$a['produto_id']]);
            DB::run("UPDATE compras_origem SET triagem='incluir',incorporada_quantidade=? WHERE id=?",[$new,$c['id']]);
            DB::run("UPDATE ativos SET situacao='em_estoque',serie=?,local_atual_id=?,centro_custo_atual_id=?,conferido_em=NOW(),conferido_por=? WHERE id=?",[$serie?:null,$local,$cc,$actor,$a['id']]);
            $key=bin2hex(random_bytes(16)).'-000';
            DB::run("INSERT INTO movimentacoes(chave_operacao,ativo_id,tipo,quantidade,local_destino_id,cc_destino_id,motivo,autor_id) VALUES(?,?,'inventario_inicial',1,?,?,?,?)",[$key,$a['id'],$local,$cc,$why,$actor]);
            DB::audit('ativos',$a['id'],'conferir_patrimonio_sga',$a,['local_id'=>$local,'cc_id'=>$cc,'serie'=>$serie,'motivo'=>$why],$actor);
        });
    }
}
