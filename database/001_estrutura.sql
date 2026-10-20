-- Proposta de migração MariaDB. Executar somente em banco novo salinas_ti.
-- Não executar no Minerion nem no banco do Access Gate.
SET NAMES utf8mb4;

CREATE TABLE usuarios (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nome VARCHAR(180) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE,
 senha_hash VARCHAR(255) NOT NULL,
 perfil ENUM('admin','operador_ti','gestor','consulta') NOT NULL,
 ativo BOOLEAN NOT NULL DEFAULT TRUE,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE requisitantes_sga (
 codigo INT PRIMARY KEY, nome VARCHAR(180) NOT NULL,
 coletar BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;
INSERT INTO requisitantes_sga(codigo,nome) VALUES
 (756,'RAFAEL RAMOS PONTES'),(772,'FERNANDO SILVA'),
 (316,'JEFFERSON LUIZ DA SILVA ASSUNCAO'),(1318,'JOSE RUBENS DE ARRUDA (TI)');

CREATE TABLE centros_custo (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sga_codigo INT UNIQUE, nome VARCHAR(200) NOT NULL, ativo BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE locais (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 pai_id BIGINT UNSIGNED NULL, nome VARCHAR(180) NOT NULL,
 tipo ENUM('unidade','predio','sala','almoxarifado','rack') NOT NULL,
 ativo BOOLEAN NOT NULL DEFAULT TRUE,
 FOREIGN KEY(pai_id) REFERENCES locais(id)
) ENGINE=InnoDB;

CREATE TABLE produtos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sga_codigo INT UNIQUE, nome VARCHAR(255) NOT NULL,
 categoria VARCHAR(100) NULL, unidade VARCHAR(20) NULL,
 controle ENUM('serializado','quantidade','servico','nao_classificado') NOT NULL DEFAULT 'nao_classificado',
 incluir_parque BOOLEAN NULL COMMENT 'NULL exige triagem; não classificar somente pelo requisitante'
) ENGINE=InnoDB;

CREATE TABLE sincronizacoes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, fim DATETIME NULL,
 situacao ENUM('executando','concluida','falhou') NOT NULL,
 linhas_lidas INT UNSIGNED NOT NULL DEFAULT 0,
 mensagem VARCHAR(500) NULL COMMENT 'Sem credenciais ou mensagens brutas de conexão'
) ENGINE=InnoDB;

CREATE TABLE compras_origem (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 origem_item_id INT NOT NULL UNIQUE COMMENT 'IPCOCOD, identidade validada nesta base',
 processo_id INT NOT NULL, empresa_id INT NULL, filial_id INT NULL,
 requisitante_codigo INT NOT NULL, produto_id BIGINT UNSIGNED NOT NULL,
 centro_custo_compra_id BIGINT UNSIGNED NULL,
 processo_data DATETIME NULL, cancelado_em DATETIME NULL,
 quantidade_solicitada DECIMAL(18,4) NOT NULL,
 quantidade_faturada DECIMAL(18,4) NOT NULL,
 quantidade_cancelada DECIMAL(18,4) NOT NULL,
 quantidade_vinculada_nf DECIMAL(18,4) NOT NULL,
 valor_unitario_pedido DECIMAL(18,4) NULL,
 vinculos_nf_positivos INT UNSIGNED NOT NULL,
 triagem ENUM('pendente','incluir','excluir') NOT NULL DEFAULT 'pendente',
 justificativa_triagem VARCHAR(500) NULL,
 ultima_sincronizacao_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(requisitante_codigo) REFERENCES requisitantes_sga(codigo),
 FOREIGN KEY(produto_id) REFERENCES produtos(id),
 FOREIGN KEY(centro_custo_compra_id) REFERENCES centros_custo(id),
 FOREIGN KEY(ultima_sincronizacao_id) REFERENCES sincronizacoes(id),
 INDEX(processo_id), INDEX(triagem)
) ENGINE=InnoDB;

CREATE TABLE responsaveis (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nome VARCHAR(180) NOT NULL, sga_funcionario_codigo INT NULL UNIQUE,
 ativo BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE ativos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 produto_id BIGINT UNSIGNED NOT NULL, compra_origem_id BIGINT UNSIGNED NULL,
 patrimonio VARCHAR(80) NOT NULL UNIQUE, serie VARCHAR(150) NULL UNIQUE,
 situacao ENUM('em_estoque','alocado','manutencao','baixado') NOT NULL DEFAULT 'em_estoque',
 local_atual_id BIGINT UNSIGNED NOT NULL,
 centro_custo_atual_id BIGINT UNSIGNED NOT NULL,
 responsavel_atual_id BIGINT UNSIGNED NULL,
 conferido_em DATETIME NOT NULL, conferido_por BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(produto_id) REFERENCES produtos(id),
 FOREIGN KEY(compra_origem_id) REFERENCES compras_origem(id),
 FOREIGN KEY(local_atual_id) REFERENCES locais(id),
 FOREIGN KEY(centro_custo_atual_id) REFERENCES centros_custo(id),
 FOREIGN KEY(responsavel_atual_id) REFERENCES responsaveis(id),
 FOREIGN KEY(conferido_por) REFERENCES usuarios(id),
 CHECK(situacao <> 'alocado' OR responsavel_atual_id IS NOT NULL),
 INDEX(local_atual_id,centro_custo_atual_id)
) ENGINE=InnoDB;

CREATE TABLE lotes_estoque (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 produto_id BIGINT UNSIGNED NOT NULL, compra_origem_id BIGINT UNSIGNED NULL,
 referencia VARCHAR(100) NOT NULL,
 FOREIGN KEY(produto_id) REFERENCES produtos(id),
 FOREIGN KEY(compra_origem_id) REFERENCES compras_origem(id),
 UNIQUE(produto_id,referencia)
) ENGINE=InnoDB;

CREATE TABLE saldos_estoque (
 lote_id BIGINT UNSIGNED NOT NULL, local_id BIGINT UNSIGNED NOT NULL,
 centro_custo_id BIGINT UNSIGNED NOT NULL,
 quantidade DECIMAL(18,4) NOT NULL DEFAULT 0,
 PRIMARY KEY(lote_id,local_id,centro_custo_id),
 FOREIGN KEY(lote_id) REFERENCES lotes_estoque(id),
 FOREIGN KEY(local_id) REFERENCES locais(id),
 FOREIGN KEY(centro_custo_id) REFERENCES centros_custo(id),
 CHECK(quantidade >= 0)
) ENGINE=InnoDB;

CREATE TABLE movimentacoes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chave_operacao CHAR(36) NOT NULL UNIQUE COMMENT 'Idempotência de POST/importação',
 ativo_id BIGINT UNSIGNED NULL, lote_id BIGINT UNSIGNED NULL,
 tipo ENUM('inventario_inicial','entrada','alocacao','transferencia','devolucao','consumo','manutencao','baixa','ajuste') NOT NULL,
 quantidade DECIMAL(18,4) NOT NULL,
 local_origem_id BIGINT UNSIGNED NULL, local_destino_id BIGINT UNSIGNED NULL,
 cc_origem_id BIGINT UNSIGNED NULL, cc_destino_id BIGINT UNSIGNED NULL,
 responsavel_origem_id BIGINT UNSIGNED NULL, responsavel_destino_id BIGINT UNSIGNED NULL,
 motivo VARCHAR(500) NOT NULL, autor_id BIGINT UNSIGNED NOT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ativo_id) REFERENCES ativos(id), FOREIGN KEY(lote_id) REFERENCES lotes_estoque(id),
 FOREIGN KEY(local_origem_id) REFERENCES locais(id), FOREIGN KEY(local_destino_id) REFERENCES locais(id),
 FOREIGN KEY(cc_origem_id) REFERENCES centros_custo(id), FOREIGN KEY(cc_destino_id) REFERENCES centros_custo(id),
 FOREIGN KEY(responsavel_origem_id) REFERENCES responsaveis(id), FOREIGN KEY(responsavel_destino_id) REFERENCES responsaveis(id),
 FOREIGN KEY(autor_id) REFERENCES usuarios(id),
 CHECK((ativo_id IS NOT NULL AND lote_id IS NULL) OR (ativo_id IS NULL AND lote_id IS NOT NULL)),
 CHECK(quantidade > 0), CHECK(ativo_id IS NULL OR quantidade = 1)
) ENGINE=InnoDB;

CREATE TABLE auditoria (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, autor_id BIGINT UNSIGNED NULL,
 entidade VARCHAR(80) NOT NULL, entidade_id VARCHAR(80) NOT NULL,
 acao VARCHAR(80) NOT NULL, antes_json LONGTEXT NULL, depois_json LONGTEXT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(autor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER movimentacoes_sem_update BEFORE UPDATE ON movimentacoes FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Movimentação imutável; registrar compensação'; END$$
CREATE TRIGGER movimentacoes_sem_delete BEFORE DELETE ON movimentacoes FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Movimentação imutável'; END$$
CREATE TRIGGER auditoria_sem_update BEFORE UPDATE ON auditoria FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Auditoria imutável'; END$$
CREATE TRIGGER auditoria_sem_delete BEFORE DELETE ON auditoria FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Auditoria imutável'; END$$
DELIMITER ;
