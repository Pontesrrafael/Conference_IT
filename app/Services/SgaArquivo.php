<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
// Plano B: uma máquina que alcança o SGA exporta; o servidor sem rota importa o arquivo.
final class SgaArquivo {
    private const FORMATO='salinas-ti-sga';
    private const LIMITE=52428800;
    public static function exportar(): string {
        $compras=Integracao::consultar('compras'); $patrimonios=Integracao::consultar('patrimonios');
        if(!$compras || !$patrimonios) throw new RuntimeException('Consulta ao SGA vazia; exportação cancelada.');
        $hash=hash('sha256',json_encode([$compras,$patrimonios],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return json_encode(['formato'=>self::FORMATO,'versao'=>1,'gerado_em'=>date(DATE_ATOM),'origem'=>(string)gethostname(),'compras'=>$compras,'patrimonios'=>$patrimonios,'sha256'=>$hash],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    public static function importar(string $json,?int $actor): array {
        if(strlen($json)>self::LIMITE) throw new RuntimeException('Arquivo acima de 50 MiB.');
        try { $d=json_decode(trim($json,"\xEF\xBB\xBF \r\n"),true,512,JSON_THROW_ON_ERROR); }
        catch(\JsonException) { throw new RuntimeException('Arquivo inválido: não é um JSON exportado pelo Salinas TI.'); }
        if(!is_array($d) || ($d['formato']??'')!==self::FORMATO || (int)($d['versao']??0)!==1) throw new RuntimeException('Arquivo de formato ou versão desconhecidos.');
        $compras=$d['compras']??null; $patrimonios=$d['patrimonios']??null;
        if(!is_array($compras) || !is_array($patrimonios) || !$compras || !$patrimonios) throw new RuntimeException('Arquivo sem compras ou patrimônios; importação não iniciada.');
        $hash=hash('sha256',json_encode([$compras,$patrimonios],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if(!hash_equals((string)($d['sha256']??''),$hash)) throw new RuntimeException('Arquivo alterado ou incompleto (sha256 não confere).');
        // Um arquivo mais antigo que a última carga concluída faria a base regredir.
        $gerado=strtotime((string)($d['gerado_em']??''));
        if($gerado===false) throw new RuntimeException('Arquivo sem data de geração válida.');
        // UNIX_TIMESTAMP usa o fuso da sessão do banco, o mesmo de NOW(): evita erro de fuso entre PHP e MariaDB.
        $ultima=\App\Core\Database::one("SELECT UNIX_TIMESTAMP(MAX(inicio)) t FROM sincronizacoes WHERE situacao='concluida' AND fonte IN ('sga','arquivo')")['t']??null;
        if($ultima && $gerado<(int)$ultima) throw new RuntimeException('Arquivo gerado em '.date('d/m/Y H:i',$gerado).', anterior à última carga concluída ('.date('d/m/Y H:i',(int)$ultima).'). Exporte novamente.');
        if(!PatrimoniosSga::normalizar($patrimonios)) throw new RuntimeException('Arquivo sem patrimônios válidos; importação não iniciada.');
        // Mesma atomicidade da sincronização direta: compras e patrimônios entram juntos ou nada muda.
        [$n,$stats]=\App\Core\Database::transaction(static fn(): array=>[Integracao::importar($compras,'arquivo',$actor),PatrimoniosSga::importar($patrimonios,'arquivo',$actor)]);
        return ['compras'=>$n,'patrimonios'=>$stats,'gerado_em'=>(string)($d['gerado_em']??''),'origem'=>(string)($d['origem']??'')];
    }
}
