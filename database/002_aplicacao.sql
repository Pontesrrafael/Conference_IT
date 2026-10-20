ALTER TABLE compras_origem ADD payload_json LONGTEXT NULL;
ALTER TABLE compras_origem ADD incorporada_quantidade DECIMAL(18,4) NOT NULL DEFAULT 0;
ALTER TABLE compras_origem ADD conciliacao_pendente BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE compras_origem ADD origem_presente BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE sincronizacoes ADD fonte VARCHAR(30) NOT NULL DEFAULT 'sga';
CREATE TABLE login_tentativas (
 chave CHAR(64) PRIMARY KEY, tentativas INT UNSIGNED NOT NULL DEFAULT 0, bloqueado_ate DATETIME NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
