<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class Inventario {
    public static function qty(mixed $raw): string {
        $q=str_replace(',','.',trim((string)$raw));
        if(!preg_match('/^\d{1,12}(\.\d{1,4})?$/D',$q) || bccomp($q,'0',4)<=0) throw new RuntimeException('Informe quantidade positiva com até quatro casas decimais.');
        return bcadd($q,'0',4);
    }
    public static function text(array $p,string $key,int $max=180): string {
        $v=trim((string)($p[$key]??''));
        if($v==='' || mb_strlen($v)>$max) throw new RuntimeException('Preencha corretamente o campo '.str_replace('_',' ',$key).'.');
        return $v;
    }
    public static function ref(string $table,mixed $id): int {
        if(!in_array($table,['locais','centros_custo','responsaveis'],true)) throw new RuntimeException('Cadastro inválido.');
        $r=DB::one("SELECT id FROM $table WHERE id=? AND ativo=1",[(int)$id]);
        if(!$r) throw new RuntimeException('Selecione um cadastro ativo para '.str_replace('_',' ',$table).'.');
        return (int)$r['id'];
    }
    private static function key(array $p): string {
        $key=(string)($p['chave_operacao']??'');
        if(!preg_match('/^[a-f0-9-]{36}$/D',$key)) throw new RuntimeException('Reabra o formulário para gerar a operação.');
        return $key;
    }
    public static function triagem(array $p,int $actor): void {
        $dec=self::text($p,'triagem'); $control=self::text($p,'controle');
        if(!in_array($dec,['incluir','excluir','pendente'],true) || !in_array($control,['serializado','quantidade','servico','nao_classificado'],true)) throw new RuntimeException('Classificação inválida.');
        $why=self::text($p,'motivo',500);
        if($dec==='incluir' && !in_array($control,['serializado','quantidade'],true)) throw new RuntimeException('Para incluir no inventário selecione patrimônio ou quantidade.');
        DB::transaction(function() use($p,$actor,$dec,$control,$why) {
            $c=DB::one('SELECT * FROM compras_origem WHERE id=? FOR UPDATE',[(int)$p['compra_id']]);
            if(!$c) throw new RuntimeException('Compra não encontrada.');
            $product=DB::one('SELECT * FROM produtos WHERE id=? FOR UPDATE',[$c['produto_id']]);
            if($product['controle']!==$control && (DB::one('SELECT id FROM ativos WHERE produto_id=? AND situacao<>\'aguardando_conferencia\' LIMIT 1',[$product['id']]) || DB::one('SELECT id FROM lotes_estoque WHERE produto_id=? LIMIT 1',[$product['id']]))) throw new RuntimeException('O produto já possui inventário; seu tipo de controle não pode ser alterado.');
            DB::run('UPDATE produtos SET controle=?,categoria=?,unidade=?,incluir_parque=? WHERE id=?',[$control,mb_substr(trim((string)($p['categoria']??'')),0,100),mb_substr(trim((string)($p['unidade']??'')),0,20),$dec==='incluir'?1:($dec==='excluir'?0:null),$product['id']]);
            DB::run('UPDATE compras_origem SET triagem=?,justificativa_triagem=? WHERE id=?',[$dec,$why,$c['id']]);
            DB::audit('compras_origem',$c['id'],'triagem',$c,['triagem'=>$dec,'controle'=>$control,'motivo'=>$why],$actor);
        });
    }
    public static function incorporar(array $p,int $actor): void {
        $key=self::key($p); $q=self::qty($p['quantidade']??''); $why=self::text($p,'motivo',500);
        $local=self::ref('locais',$p['local_id']??0); $cc=self::ref('centros_custo',$p['cc_id']??0);
        DB::transaction(function() use($p,$actor,$key,$q,$why,$local,$cc) {
            $c=DB::one('SELECT * FROM compras_origem WHERE id=? FOR UPDATE',[(int)($p['compra_id']??0)]);
            if(!$c) throw new RuntimeException('Compra não encontrada.');
            if(DB::one('SELECT id FROM movimentacoes WHERE chave_operacao=?',[$key])) return;
            $prod=DB::one('SELECT * FROM produtos WHERE id=? FOR UPDATE',[$c['produto_id']]);
            if($c['triagem']!=='incluir' || $c['cancelado_em'] || !$c['origem_presente'] || $c['conciliacao_pendente'] || (int)$c['vinculos_nf_positivos']<1) throw new RuntimeException('Compra precisa estar incluída, com NF positiva e sem divergência de origem.');
            $new=bcadd($c['incorporada_quantidade'],$q,4);
            if(bccomp($new,$c['quantidade_vinculada_nf'],4)>0) throw new RuntimeException('Quantidade excede o faturamento disponível para incorporação.');
            $active=null; $lote=null;
            if($prod['controle']==='serializado') {
                if(bccomp($q,'1',4)!==0) throw new RuntimeException('Cadastre um patrimônio por vez, com quantidade 1.');
                $pat=self::text($p,'patrimonio',80); $serie=trim((string)($p['serie']??''));
                if(mb_strlen($serie)>150) throw new RuntimeException('Série muito longa.');
                DB::run("INSERT INTO ativos(produto_id,compra_origem_id,patrimonio,serie,local_atual_id,centro_custo_atual_id,conferido_em,conferido_por) VALUES(?,?,?,?,?,?,NOW(),?)",[$prod['id'],$c['id'],$pat,$serie?:null,$local,$cc,$actor]);
                $active=(int)DB::pdo()->lastInsertId();
            } elseif($prod['controle']==='quantidade') {
                if(empty($prod['unidade'])) throw new RuntimeException('Informe a unidade do produto na triagem antes de incorporar.');
                $ref='SGA-'.$c['origem_item_id'];
                DB::run('INSERT INTO lotes_estoque(produto_id,compra_origem_id,referencia) VALUES(?,?,?) ON DUPLICATE KEY UPDATE referencia=VALUES(referencia)',[$prod['id'],$c['id'],$ref]);
                $lote=(int)DB::one('SELECT id FROM lotes_estoque WHERE produto_id=? AND referencia=?',[$prod['id'],$ref])['id'];
                DB::run('INSERT INTO saldos_estoque(lote_id,local_id,centro_custo_id,quantidade) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE quantidade=quantidade+VALUES(quantidade)',[$lote,$local,$cc,$q]);
            } else throw new RuntimeException('Classifique o tipo de controle na triagem.');
            DB::run('INSERT INTO movimentacoes(chave_operacao,ativo_id,lote_id,tipo,quantidade,local_destino_id,cc_destino_id,motivo,autor_id) VALUES(?,?,?,\'inventario_inicial\',?,?,?,?,?)',[$key,$active,$lote,$q,$local,$cc,$why,$actor]);
            DB::run('UPDATE compras_origem SET incorporada_quantidade=? WHERE id=?',[$new,$c['id']]);
            DB::audit('compras_origem',$c['id'],'incorporar',['quantidade'=>$c['incorporada_quantidade']],['quantidade'=>$new,'ativo_id'=>$active,'lote_id'=>$lote],$actor);
        });
    }
    public static function moverAtivo(array $p,int $actor): void {
        $key=self::key($p); $type=self::text($p,'tipo'); $why=self::text($p,'motivo',500);
        if(!in_array($type,['alocacao','transferencia','devolucao','manutencao','baixa'],true)) throw new RuntimeException('Movimentação inválida.');
        $local=self::ref('locais',$p['local_id']??0); $cc=self::ref('centros_custo',$p['cc_id']??0);
        $resp=($p['responsavel_id']??'')!=='' ? self::ref('responsaveis',$p['responsavel_id']) : null;
        DB::transaction(function() use($p,$actor,$key,$type,$why,$local,$cc,$resp) {
            $a=DB::one('SELECT * FROM ativos WHERE id=? FOR UPDATE',[(int)$p['ativo_id']]);
            if(!$a) throw new RuntimeException('Ativo não encontrado.');
            if(DB::one('SELECT id FROM movimentacoes WHERE chave_operacao=?',[$key])) return;
            if($a['situacao']==='aguardando_conferencia') throw new RuntimeException('Confirme a conferência física deste patrimônio antes de movimentar.');
            if($a['situacao']==='baixado') throw new RuntimeException('Ativo baixado não pode ser movimentado.');
            if($type==='alocacao' && $a['situacao']!=='em_estoque') throw new RuntimeException('Somente bens em estoque podem ser alocados.');
            if($type==='devolucao' && !in_array($a['situacao'],['alocado','manutencao'],true)) throw new RuntimeException('Devolução exige bem alocado ou em manutenção.');
            $sit=match($type) {'alocacao'=>'alocado','devolucao'=>'em_estoque','manutencao'=>'manutencao','baixa'=>'baixado',default=>$a['situacao']};
            if($sit==='alocado' && !$resp) throw new RuntimeException('Selecione o responsável pela alocação.');
            if(in_array($sit,['em_estoque','baixado'],true)) $resp=null;
            DB::run('UPDATE ativos SET situacao=?,local_atual_id=?,centro_custo_atual_id=?,responsavel_atual_id=? WHERE id=?',[$sit,$local,$cc,$resp,$a['id']]);
            DB::run('INSERT INTO movimentacoes(chave_operacao,ativo_id,tipo,quantidade,local_origem_id,local_destino_id,cc_origem_id,cc_destino_id,responsavel_origem_id,responsavel_destino_id,motivo,autor_id) VALUES(?,?,?,1,?,?,?,?,?,?,?,?)',[$key,$a['id'],$type,$a['local_atual_id'],$local,$a['centro_custo_atual_id'],$cc,$a['responsavel_atual_id'],$resp,$why,$actor]);
            DB::audit('ativos',$a['id'],$type,$a,['situacao'=>$sit,'local_id'=>$local,'cc_id'=>$cc,'responsavel_id'=>$resp,'motivo'=>$why],$actor);
        });
    }
    public static function moverMaterial(array $p,int $actor): void {
        $key=self::key($p); $q=self::qty($p['quantidade']??''); $why=self::text($p,'motivo',500); $type=self::text($p,'tipo');
        if(!in_array($type,['transferencia','consumo','baixa'],true)) throw new RuntimeException('Tipo inválido para material.');
        $dest=$type==='transferencia'?self::ref('locais',$p['destino_id']??0):null;
        $ccDest=$type==='transferencia'?self::ref('centros_custo',$p['cc_destino_id']??0):null;
        DB::transaction(function() use($p,$actor,$key,$q,$why,$type,$dest,$ccDest) {
            $lote=(int)$p['lote_id']; $local=(int)$p['origem_id']; $cc=(int)$p['cc_origem_id'];
            $lot=DB::one('SELECT * FROM lotes_estoque WHERE id=? FOR UPDATE',[$lote]);
            if(!$lot) throw new RuntimeException('Lote não encontrado.');
            if(DB::one('SELECT id FROM movimentacoes WHERE chave_operacao=?',[$key])) return;
            $s=DB::one('SELECT * FROM saldos_estoque WHERE lote_id=? AND local_id=? AND centro_custo_id=? FOR UPDATE',[$lote,$local,$cc]);
            if(!$s || bccomp($s['quantidade'],$q,4)<0) throw new RuntimeException('Saldo insuficiente.');
            if($type==='transferencia' && $dest===$local && $ccDest===$cc) throw new RuntimeException('Destino deve ser diferente da origem.');
            DB::run('UPDATE saldos_estoque SET quantidade=quantidade-? WHERE lote_id=? AND local_id=? AND centro_custo_id=?',[$q,$lote,$local,$cc]);
            if($type==='transferencia') DB::run('INSERT INTO saldos_estoque(lote_id,local_id,centro_custo_id,quantidade) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE quantidade=quantidade+VALUES(quantidade)',[$lote,$dest,$ccDest,$q]);
            DB::run('INSERT INTO movimentacoes(chave_operacao,lote_id,tipo,quantidade,local_origem_id,local_destino_id,cc_origem_id,cc_destino_id,motivo,autor_id) VALUES(?,?,?,?,?,?,?,?,?,?)',[$key,$lote,$type,$q,$local,$dest,$cc,$ccDest,$why,$actor]);
            DB::audit('lotes_estoque',$lote,$type,$s,['quantidade_movida'=>$q,'destino_id'=>$dest,'cc_destino_id'=>$ccDest,'motivo'=>$why],$actor);
        });
    }
}
