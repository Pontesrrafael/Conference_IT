<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
final class Requisitantes {
    public const PADRAO = [756,772,316,1318];
    public static function validar(mixed $value): int {
        if(!is_int($value) && !is_string($value)) throw new RuntimeException('Informe um ID numérico de requisitante do SGA.');
        $text=trim((string)$value);
        if(!preg_match('/^[0-9]{1,10}$/D',$text) || (int)$text<1 || (int)$text>2147483647) throw new RuntimeException('Informe um ID de requisitante entre 1 e 2147483647.');
        return (int)$text;
    }
    public static function lista(): array {
        $file=ROOT.'/storage/requisitantes.json';
        $saved=is_file($file)?json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR):self::PADRAO;
        if(!is_array($saved)) throw new RuntimeException('Configuração de requisitantes inválida.');
        return array_values(array_unique(array_map([self::class,'validar'],$saved)));
    }
    public static function aplicarSql(string $sql,?array $ids=null): string {
        $ids=$ids??self::lista();
        if(!$ids && !CentrosCusto::lista()) throw new RuntimeException('Configure pelo menos um requisitante ou um centro de custo.');
        // -1 não coincide com nenhum FUNCOD: escopo só por centro de custo.
        return CentrosCusto::aplicarSql(str_replace('{{REQUISITANTES_SGA}}',$ids?implode(',',array_map([self::class,'validar'],$ids)):'-1',$sql));
    }
    public static function remover(mixed $value): array {
        $id=self::validar($value);
        $lock=fopen(ROOT.'/storage/requisitantes.lock','c');
        if(!$lock) throw new RuntimeException('Não foi possível salvar os requisitantes.');
        try {
            if(!flock($lock,LOCK_EX)) throw new RuntimeException('Não foi possível bloquear os parâmetros.');
            $before=self::lista();
            $ids=array_values(array_filter($before,static fn(int $v):bool=>$v!==$id));
            $removed=count($ids)!==count($before);
            if(!$ids && !CentrosCusto::config()['centros']) throw new RuntimeException('Mantenha pelo menos um requisitante ou um centro de custo na consulta.');
            if($removed) {
                $file=ROOT.'/storage/requisitantes.json';
                if(file_put_contents($file.'.tmp',json_encode($ids,JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($file.'.tmp',$file)) throw new RuntimeException('Não foi possível salvar os requisitantes.');
            }
            return ['codigo'=>$id,'removido'=>$removed,'ids'=>$ids];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function adicionar(mixed $value,?callable $reader=null): array {
        $id=self::validar($value);
        $reader=$reader??static fn(string $sql)=>Integracao::executarSql($sql);
        $rows=$reader('SELECT FUNCOD AS codigo, FUNNOME AS nome FROM dbo.FUNCIONARIO WHERE FUNCOD='.$id);
        if(count($rows)!==1 || (int)$rows[0]['codigo']!==$id) throw new RuntimeException('ID não encontrado no cadastro de funcionários do Minerion.');
        $lock=fopen(ROOT.'/storage/requisitantes.lock','c');
        if(!$lock) throw new RuntimeException('Não foi possível salvar os requisitantes.');
        try {
            if(!flock($lock,LOCK_EX)) throw new RuntimeException('Não foi possível bloquear os parâmetros.');
            $ids=self::lista(); $novo=!in_array($id,$ids,true);
            if($novo) {
                if(count($ids)>=200) throw new RuntimeException('Limite de 200 requisitantes atingido.');
                $ids[]=$id;
                $file=ROOT.'/storage/requisitantes.json';
                if(file_put_contents($file.'.tmp',json_encode($ids,JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($file.'.tmp',$file)) throw new RuntimeException('Não foi possível salvar os requisitantes.');
            }
            return ['codigo'=>$id,'nome'=>(string)$rows[0]['nome'],'adicionado'=>$novo,'ids'=>$ids];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
