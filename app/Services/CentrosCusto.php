<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
/**
 * Escopo complementar por centro de custo da linha de compra (ITEMPROCESSOCOMPRA.IPCOCC).
 * Regra: a consulta traz compras do requisitante configurado OU do centro de custo configurado (união).
 * Configuração privada em storage/centros-custo.json; somente inteiros validados entram no SQL.
 */
final class CentrosCusto {
    public const ARQUIVO='/storage/centros-custo.json';
    public const SEM_CC=-1;            // sentinela: IN (-1) não coincide com nenhum CC (CC 0 = compra sem CC)
    public const LIMITE_CENTROS=300;   // CCs efetivos após incluir filhos
    public const LIMITE_LINHAS=5000;   // linhas de compra trazidas apenas pelo escopo de CC

    public static function validar(mixed $value): int {
        if(!is_int($value) && !is_string($value)) throw new RuntimeException('Informe o código numérico do centro de custo no SGA.');
        $text=trim((string)$value);
        if(!preg_match('/^[0-9]{1,10}$/D',$text) || (int)$text<1 || (int)$text>2147483647) throw new RuntimeException('Informe um código de centro de custo entre 1 e 2147483647.');
        return (int)$text;
    }
    /** @return array{centros:list<array{codigo:int,nome:string,caminho:string,incluir_filhos:bool}>,expandidos:list<int>,atualizado_em:?string} */
    public static function config(): array {
        $file=ROOT.self::ARQUIVO;
        if(!is_file($file)) return ['centros'=>[],'expandidos'=>[],'atualizado_em'=>null];
        $c=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($c) || !is_array($c['centros']??null) || !is_array($c['expandidos']??null)) throw new RuntimeException('Configuração de centros de custo inválida.');
        $centros=[];
        foreach($c['centros'] as $cc) { $cod=self::validar($cc['codigo']??''); $centros[]=['codigo'=>$cod,'nome'=>(string)($cc['nome']??''),'caminho'=>(string)($cc['caminho']??''),'incluir_filhos'=>(bool)($cc['incluir_filhos']??false),'efetivos'=>array_values(array_unique(array_map([self::class,'validar'],is_array($cc['efetivos']??null)&&$cc['efetivos']?$cc['efetivos']:[$cod])))]; }
        return ['centros'=>$centros,'expandidos'=>array_values(array_unique(array_map([self::class,'validar'],$c['expandidos']))),'atualizado_em'=>$c['atualizado_em']??null];
    }
    /** CCs efetivos (já com filhos) usados nas consultas e na validação local. */
    public static function lista(): array { return self::config()['expandidos']; }
    public static function aplicarSql(string $sql,?array $ids=null): string {
        $ids=$ids??self::lista();
        $list=$ids?implode(',',array_map([self::class,'validar'],$ids)):(string)self::SEM_CC;
        return str_replace('{{CENTROS_CUSTO_SGA}}',$list,$sql);
    }
    /** Expande os CCs configurados (com filhos pelo caminho CCSTCOD) consultando o SGA. */
    public static function expandir(array $centros,callable $reader): array {
        return self::expandirConfig(['centros'=>$centros,'expandidos'=>[]],$reader)['expandidos'];
    }
    /** Recalcula a união dos CCs efetivos e os efetivos de cada entrada (usados nos filtros por origem). */
    public static function expandirConfig(array $c,callable $reader): array {
        if(!$c['centros']) { $c['expandidos']=[]; return $c; }
        $diretos=[];$arvores=[];
        foreach($c['centros'] as $cc) { $id=self::validar($cc['codigo']); $diretos[]=$id; if($cc['incluir_filhos']) $arvores[]="c.CCSTCOD LIKE '%+".$id."+%'"; }
        $where='c.CCCOD IN ('.implode(',',$diretos).')'.($arvores?' OR '.implode(' OR ',$arvores):'');
        $rows=$reader('SELECT DISTINCT c.CCCOD AS codigo,c.CCSTCOD AS caminho FROM dbo.CENTROCUSTO c WHERE '.$where);
        $ids=[];
        foreach($rows as $r) $ids[self::validar($r['codigo']??'')]=(string)($r['caminho']??'');
        if(count($ids)>self::LIMITE_CENTROS) throw new RuntimeException('O escopo resultaria em '.count($ids).' centros de custo (limite '.self::LIMITE_CENTROS.'). Desmarque "incluir filhos" ou escolha um nível mais baixo.');
        foreach($c['centros'] as $i=>$cc) {
            $ef=[];
            foreach($ids as $cod=>$path) if($cod===$cc['codigo'] || ($cc['incluir_filhos'] && str_contains($path,'+'.$cc['codigo'].'+'))) $ef[]=$cod;
            sort($ef); $c['centros'][$i]['efetivos']=$ef?:[$cc['codigo']];
        }
        $all=array_keys($ids); sort($all); $c['expandidos']=$all;
        return $c;
    }
    /** Linhas de compra que entrariam somente pelo escopo de CC (fora dos requisitantes). */
    public static function contarLinhas(array $ids,array $requisitantes,callable $reader): int {
        if(!$ids) return 0;
        $req=$requisitantes?implode(',',array_map([Requisitantes::class,'validar'],$requisitantes)):'-1';
        $rows=$reader('SELECT COUNT(*) AS n FROM dbo.ITEMPROCESSOCOMPRA i WHERE i.IPCOCC IN ('.implode(',',array_map([self::class,'validar'],$ids)).') AND i.IPCOFUNREQ NOT IN ('.$req.')');
        return (int)($rows[0]['n']??0);
    }
    /** Atualiza a expansão antes de cada sincronização (novos filhos criados no SGA passam a valer). */
    public static function atualizarExpansao(callable $reader): array {
        return self::alterar(static fn(array $c): array => self::expandirConfig($c,$reader))['expandidos'];
    }
    public static function adicionar(mixed $value,bool $filhos,?callable $reader=null): array {
        $id=self::validar($value);
        $reader=$reader??static fn(string $sql)=>Integracao::executarSql($sql);
        $rows=$reader('SELECT c.CCCOD AS codigo,c.CCNOME AS nome,c.CCSTCOD AS caminho,c.CCBLOQ AS bloqueado,c.CCTEMFILHO AS tem_filho FROM dbo.CENTROCUSTO c WHERE c.CCCOD='.$id);
        if(count($rows)!==1 || (int)$rows[0]['codigo']!==$id) throw new RuntimeException('Centro de custo não encontrado no Minerion.');
        $info=$rows[0];
        $novo=false;$linhas=0;
        $result=self::alterar(static function(array $c) use($id,$filhos,$info,$reader,&$novo,&$linhas): array {
            foreach($c['centros'] as $i=>$cc) if($cc['codigo']===$id) {
                if($cc['incluir_filhos']===$filhos) return $c;
                $c['centros'][$i]['incluir_filhos']=$filhos; $novo=true; break;
            }
            if(!$novo) { $c['centros'][]=['codigo'=>$id,'nome'=>trim((string)$info['nome']),'caminho'=>trim((string)$info['caminho']),'incluir_filhos'=>$filhos]; $novo=true; }
            $c=self::expandirConfig($c,$reader);
            $linhas=self::contarLinhas($c['expandidos'],Requisitantes::lista(),$reader);
            if($linhas>self::LIMITE_LINHAS) throw new RuntimeException('Este escopo traria '.$linhas.' linhas de compra de outros requisitantes (limite '.self::LIMITE_LINHAS.'). Escolha um centro de custo mais específico.');
            return $c;
        });
        return ['codigo'=>$id,'nome'=>trim((string)$info['nome']),'adicionado'=>$novo,'linhas_extras'=>$linhas,'bloqueado'=>strtoupper(trim((string)$info['bloqueado']))==='S','efetivos'=>count($result['expandidos'])];
    }
    public static function remover(mixed $value,?callable $reader=null): array {
        $id=self::validar($value);
        $reader=$reader??static fn(string $sql)=>Integracao::executarSql($sql);
        $removido=false;
        $result=self::alterar(static function(array $c) use($id,$reader,&$removido): array {
            $antes=count($c['centros']);
            $c['centros']=array_values(array_filter($c['centros'],static fn(array $cc):bool=>$cc['codigo']!==$id));
            $removido=count($c['centros'])!==$antes;
            if($removido && !$c['centros'] && !Requisitantes::lista()) throw new RuntimeException('Mantenha pelo menos um requisitante ou um centro de custo na consulta.');
            if($removido) $c=self::expandirConfig($c,$reader);
            return $c;
        });
        return ['codigo'=>$id,'removido'=>$removido,'efetivos'=>count($result['expandidos'])];
    }
    private static function alterar(callable $fn): array {
        $lock=fopen(ROOT.'/storage/centros-custo.lock','c');
        if(!$lock) throw new RuntimeException('Não foi possível salvar os centros de custo.');
        try {
            if(!flock($lock,LOCK_EX)) throw new RuntimeException('Não foi possível bloquear os parâmetros.');
            $before=self::config(); $after=$fn($before);
            if($after!==$before) {
                $after['atualizado_em']=date('c');
                $file=ROOT.self::ARQUIVO;
                if(file_put_contents($file.'.tmp',json_encode($after,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX)===false || !rename($file.'.tmp',$file)) throw new RuntimeException('Não foi possível salvar os centros de custo.');
            }
            return $after;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    /**
     * Condição SQL local (MariaDB) para "dentro do escopo atual". Colunas vêm do código, nunca da interface.
     * @return array{0:string,1:list<int>}
     */
    public static function condicaoLocal(string $colRequisitante,string $colCentroCustoLocal): array {
        foreach([$colRequisitante,$colCentroCustoLocal] as $col) if(!preg_match('/^[a-z_][a-z0-9_.]*$/D',$col)) throw new RuntimeException('Coluna de escopo inválida.');
        $req=Requisitantes::lista(); $cc=self::lista();
        $parts=[];
        if($req) $parts[]=$colRequisitante.' IN ('.implode(',',array_fill(0,count($req),'?')).')';
        if($cc) $parts[]=$colCentroCustoLocal.' IN (SELECT id FROM centros_custo WHERE sga_codigo IN ('.implode(',',array_fill(0,count($cc),'?')).'))';
        return [$parts?'('.implode(' OR ',$parts).')':'(1=0)',[...$req,...$cc]];
    }
    /**
     * Origens do escopo atual para filtros e totais: cada requisitante e cada centro de custo configurado.
     * @return list<array{chave:string,tipo:string,codigo:int,rotulo:string,sql:string,args:list<mixed>}>
     * Usa os aliases c (compras_origem/vw_compras_custos) e cc (centros_custo da compra).
     */
    public static function origens(string $ccAlias='cc'): array {
        if(!preg_match('/^[a-z_]+$/D',$ccAlias)) throw new RuntimeException('Alias inválido.');
        $out=[];
        foreach(Requisitantes::lista() as $rid) {
            try { $nome=\App\Core\Database::one('SELECT nome FROM requisitantes_sga WHERE codigo=?',[$rid])['nome']??'Requisitante'; } catch(\Throwable) { $nome='Requisitante'; } // rótulo apenas; sem banco (testes portáteis) segue genérico
            $out[]=['chave'=>'req:'.$rid,'tipo'=>'Requisitante','codigo'=>$rid,'efetivos'=>[],'rotulo'=>$rid.' · '.trim((string)$nome),'sql'=>'c.requisitante_codigo=?','args'=>[$rid]];
        }
        foreach(self::config()['centros'] as $c) {
            $out[]=['chave'=>'cc:'.$c['codigo'],'tipo'=>'Centro de custo','codigo'=>$c['codigo'],'efetivos'=>$c['efetivos'],'rotulo'=>$c['codigo'].' · '.$c['nome'].($c['incluir_filhos']?' (com filhos: '.count($c['efetivos']).' CC)':''),
                'sql'=>$ccAlias.'.sga_codigo IN ('.implode(',',array_fill(0,count($c['efetivos']),'?')).')','args'=>$c['efetivos']];
        }
        return $out;
    }
    public static function origem(string $chave,string $ccAlias='cc'): ?array {
        foreach(self::origens($ccAlias) as $o) if($o['chave']===$chave) return $o;
        return null;
    }
    /** Patrimônio (payload SGA) ligado a uma origem específica: requisitante candidato ou CC candidato. */
    public static function patrimonioNaOrigem(array $payload,array $origem): bool {
        if($origem['tipo']==='Requisitante') return in_array($origem['codigo'],array_map('intval',$payload['requisitantes_candidatos']??[$payload['requisitante_id']??0]),true);
        return (bool)array_intersect(array_map('intval',$payload['centros_candidatos']??[$payload['centro_custo_compra_id']??0]),$origem['efetivos']);
    }
    /** Registro de patrimônio (payload SGA) ligado ao escopo atual por requisitante ou CC candidato. */
    public static function patrimonioNoEscopo(array $payload): bool {
        $reqs=array_map('intval',$payload['requisitantes_candidatos']??[$payload['requisitante_id']??0]);
        $ccs=array_map('intval',$payload['centros_candidatos']??[$payload['centro_custo_compra_id']??0]);
        return (bool)array_intersect($reqs,Requisitantes::lista()) || (bool)array_intersect($ccs,self::lista());
    }
    /** Linha dentro do escopo combinado: requisitante configurado OU CC efetivo. */
    public static function noEscopo(int $requisitante,int $centro,?array $requisitantes=null,?array $centros=null): bool {
        return in_array($requisitante,$requisitantes??Requisitantes::lista(),true) || ($centro>0 && in_array($centro,$centros??self::lista(),true));
    }
}
