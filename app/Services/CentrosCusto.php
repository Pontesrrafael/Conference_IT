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
        foreach($c['centros'] as $cc) $centros[]=['codigo'=>self::validar($cc['codigo']??''),'nome'=>(string)($cc['nome']??''),'caminho'=>(string)($cc['caminho']??''),'incluir_filhos'=>(bool)($cc['incluir_filhos']??false)];
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
        if(!$centros) return [];
        $diretos=[];$arvores=[];
        foreach($centros as $cc) { $id=self::validar($cc['codigo']); $diretos[]=$id; if($cc['incluir_filhos']) $arvores[]="c.CCSTCOD LIKE '%+".$id."+%'"; }
        $where='c.CCCOD IN ('.implode(',',$diretos).')'.($arvores?' OR '.implode(' OR ',$arvores):'');
        $rows=$reader('SELECT DISTINCT c.CCCOD AS codigo FROM dbo.CENTROCUSTO c WHERE '.$where);
        $ids=array_values(array_unique(array_map(static fn($r)=>self::validar($r['codigo']??''),$rows)));
        sort($ids);
        if(count($ids)>self::LIMITE_CENTROS) throw new RuntimeException('O escopo resultaria em '.count($ids).' centros de custo (limite '.self::LIMITE_CENTROS.'). Desmarque "incluir filhos" ou escolha um nível mais baixo.');
        return $ids;
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
        return self::alterar(static function(array $c) use($reader): array { $c['expandidos']=self::expandir($c['centros'],$reader); return $c; })['expandidos'];
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
            $expandidos=self::expandir($c['centros'],$reader);
            $linhas=self::contarLinhas($expandidos,Requisitantes::lista(),$reader);
            if($linhas>self::LIMITE_LINHAS) throw new RuntimeException('Este escopo traria '.$linhas.' linhas de compra de outros requisitantes (limite '.self::LIMITE_LINHAS.'). Escolha um centro de custo mais específico.');
            $c['expandidos']=$expandidos;
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
            if($removido) $c['expandidos']=$c['centros']?self::expandir($c['centros'],$reader):[];
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
    /** Linha dentro do escopo combinado: requisitante configurado OU CC efetivo. */
    public static function noEscopo(int $requisitante,int $centro,?array $requisitantes=null,?array $centros=null): bool {
        return in_array($requisitante,$requisitantes??Requisitantes::lista(),true) || ($centro>0 && in_array($centro,$centros??self::lista(),true));
    }
}
