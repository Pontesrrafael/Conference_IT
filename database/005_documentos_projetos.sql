ALTER TABLE compras_origem ADD entrada_documento BOOLEAN NOT NULL DEFAULT FALSE;
CREATE TABLE projetos_documentos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 projeto_id BIGINT UNSIGNED NOT NULL,
 tipo ENUM('requisicao','processo') NOT NULL,
 numero INT NOT NULL,
 empresa_id INT NOT NULL,
 documento_json LONGTEXT NOT NULL,
 atualizado_por BIGINT UNSIGNED NOT NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(projeto_id,tipo,numero,empresa_id),
 FOREIGN KEY(projeto_id) REFERENCES projetos(id),
 FOREIGN KEY(atualizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;
