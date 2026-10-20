<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Adaptador de leitura. O MariaDB da aplicação é uma conexão separada. */
final class Minerion
{
    public function __construct(private PDO $origem, private string $consultaArquivo) {}

    public static function conectar(string $consultaArquivo): self
    {
        foreach (['SGA_DSN', 'SGA_USERNAME', 'SGA_PASSWORD'] as $chave) {
            if (!getenv($chave)) {
                throw new RuntimeException('Configuração SGA incompleta.');
            }
        }
        if (!str_starts_with((string) getenv('SGA_DSN'), 'sqlsrv:')) {
            throw new RuntimeException('A conexão SGA precisa usar PDO SQL Server.');
        }
        $pdo = new PDO((string) getenv('SGA_DSN'), (string) getenv('SGA_USERNAME'),
            (string) getenv('SGA_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return new self($pdo, $consultaArquivo);
    }

    public function coletar(): array
    {
        $sql = file_get_contents($this->consultaArquivo);
        if ($sql === false) {
            throw new RuntimeException('Consulta de coleta não encontrada.');
        }
        // O arquivo SQL é controlado pelo projeto; nunca aceitar SQL da interface.
        return $this->origem->query($sql)->fetchAll();
    }

    public static function resumir(array $linhas, bool $restrito = true): array
    {
        $resultado = [];
        $vistos = [];
        foreach ($linhas as $linha) {
            $codigo = (int) $linha['requisitante_id'];
            $id = (int) $linha['origem_item_id'];
            if (($restrito && !in_array($codigo, [756, 772, 316, 1318], true)) || $id <= 0 || isset($vistos[$id])) {
                throw new RuntimeException('Coleta fora do escopo ou com chave duplicada.');
            }
            $vistos[$id] = true;
            $resultado[$codigo] ??= ['nome' => $linha['requisitante_nome'],
                'linhas' => 0, 'com_nf_positiva' => 0, 'candidatas_conferencia' => 0];
            $resultado[$codigo]['linhas']++;
            if ((int) $linha['vinculos_nf_positivos'] > 0) {
                $resultado[$codigo]['com_nf_positiva']++;
                if (empty($linha['cancelado_em'])) {
                    $resultado[$codigo]['candidatas_conferencia']++;
                }
            }
        }
        ksort($resultado);
        return $resultado;
    }
}
