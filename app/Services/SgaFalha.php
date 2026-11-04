<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;

final class SgaFalha extends RuntimeException {
    public function __construct(public readonly string $etapa, string $mensagem, public readonly string $detalhe) {
        // Não encadear a exceção original: drivers podem incluir credenciais no texto.
        parent::__construct($mensagem.($detalhe!==''?' Detalhe: '.$detalhe:''));
    }
}
