ALTER TABLE ativos MODIFY situacao ENUM('aguardando_conferencia','em_estoque','alocado','manutencao','baixado') NOT NULL DEFAULT 'em_estoque';
ALTER TABLE ativos MODIFY local_atual_id BIGINT UNSIGNED NULL;
ALTER TABLE ativos MODIFY centro_custo_atual_id BIGINT UNSIGNED NULL;
ALTER TABLE ativos MODIFY conferido_em DATETIME NULL;
ALTER TABLE ativos MODIFY conferido_por BIGINT UNSIGNED NULL;
ALTER TABLE ativos ADD empresa_patrimonio INT NOT NULL DEFAULT 3;
ALTER TABLE ativos DROP INDEX patrimonio;
ALTER TABLE ativos ADD UNIQUE empresa_patrimonio_numero(empresa_patrimonio,patrimonio);

CREATE TABLE patrimonios_sga (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 imobilizado_item_id INT NOT NULL UNIQUE,
 bem_id INT NOT NULL,
 empresa_id INT NOT NULL,
 patrimonio VARCHAR(80) NULL,
 nome_bem VARCHAR(500) NOT NULL,
 localizacao_sga VARCHAR(500) NULL,
 centro_custo_sga VARCHAR(200) NULL,
 candidatos_json LONGTEXT NOT NULL,
 payload_json LONGTEXT NOT NULL,
 situacao ENUM('associado','ambiguo','sem_numero','conflito') NOT NULL,
 motivo VARCHAR(500) NULL,
 ativo_id BIGINT UNSIGNED NULL,
 compra_manual_id BIGINT UNSIGNED NULL,
 origem_presente BOOLEAN NOT NULL DEFAULT TRUE,
 fonte VARCHAR(30) NOT NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ativo_id) REFERENCES ativos(id),
 FOREIGN KEY(compra_manual_id) REFERENCES compras_origem(id)
) ENGINE=InnoDB;
