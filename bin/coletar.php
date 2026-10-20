<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/Services/Minerion.php';

use App\Services\Minerion;

$raiz = dirname(__DIR__);
try {
    // Somente resumo por padrão; a coleta completa já entregue fica fora de public/.
    if (in_array('--evidencia', $argv, true)) {
        $dados = json_decode((string) file_get_contents($raiz . '/evidencias/coleta-minerion.json'), true, 512, JSON_THROW_ON_ERROR);
        $linhas = $dados['linhas'];
    } else {
        if (is_file($raiz . '/.env')) {
            $env = parse_ini_file($raiz . '/.env', false, INI_SCANNER_RAW);
            if ($env === false) {
                throw new RuntimeException('Arquivo de configuração inválido.');
            }
            foreach ($env as $chave => $valor) {
                if (getenv($chave) === false) {
                    putenv($chave . '=' . $valor);
                }
            }
        }
        $linhas = Minerion::conectar($raiz . '/database/queries/minerion_coleta.sql')->coletar();
    }
    echo json_encode(Minerion::resumir($linhas), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $erro) {
    // Exceções de driver podem conter informações internas; não despejar a conexão.
    fwrite(STDERR, 'Falha na coleta. Verifique arquivo, configuração e acesso de leitura ao SGA.' . PHP_EOL);
    exit(1);
}
