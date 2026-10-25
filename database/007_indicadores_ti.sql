-- Indicadores de TI (Visão Executiva), espelhando o "SGM | Dashboard de TI" do Power BI.
-- Nesta etapa os valores externos (Zabbix, Microsoft 365, NinjaOne, controles, licenças, rádios) entram por
-- lançamento manual ou importação CSV; os dados do parque e das compras vêm da própria aplicação.
CREATE TABLE indicadores_ti (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(60) NOT NULL UNIQUE,
 nome VARCHAR(120) NOT NULL,
 unidade ENUM('percentual','quantidade') NOT NULL,
 meta_operador ENUM('>=','<=') NULL,
 meta DECIMAL(12,4) NULL,
 agregacao ENUM('media','ultimo') NOT NULL COMMENT 'media: média das medições no período (ex.: disponibilidade diária); ultimo: última medição até o fim do período (fotografia)',
 fonte VARCHAR(120) NOT NULL,
 grupo VARCHAR(40) NOT NULL DEFAULT 'executivo' COMMENT 'executivo = cartão da Visão Executiva; controles = status para o gráfico de controles',
 ordem SMALLINT NOT NULL DEFAULT 0,
 ativo TINYINT(1) NOT NULL DEFAULT 1,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE indicadores_ti_medicoes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 indicador_id INT UNSIGNED NOT NULL,
 data_ref DATE NOT NULL,
 valor DECIMAL(14,4) NOT NULL,
 origem ENUM('manual','csv') NOT NULL,
 observacao VARCHAR(300) NOT NULL DEFAULT '',
 registrado_por BIGINT UNSIGNED NULL,
 registrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_indicador_dia (indicador_id,data_ref),
 KEY ix_data (data_ref),
 FOREIGN KEY(indicador_id) REFERENCES indicadores_ti(id),
 FOREIGN KEY(registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE riscos_ti (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(30) NOT NULL UNIQUE,
 risco VARCHAR(300) NOT NULL,
 nivel TINYINT UNSIGNED NOT NULL COMMENT 'impacto x probabilidade (1 a 25)',
 classificacao ENUM('Baixo','Médio','Alto','Crítico') NOT NULL,
 responsavel VARCHAR(120) NOT NULL DEFAULT '',
 situacao ENUM('aberto','em_tratamento','tratado','aceito') NOT NULL DEFAULT 'aberto',
 atualizado_por BIGINT UNSIGNED NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CHECK (nivel BETWEEN 1 AND 25),
 FOREIGN KEY(atualizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

INSERT INTO indicadores_ti(codigo,nome,unidade,meta_operador,meta,agregacao,fonte,grupo,ordem) VALUES
('disponibilidade_rede','Disponibilidade da rede','percentual','>=',99.5,'media','Zabbix · hosts críticos','executivo',10),
('links_internet','Links de internet','percentual','>=',98,'media','Zabbix · links','executivo',20),
('problemas_criticos','Problemas críticos','quantidade','<=',0,'ultimo','Zabbix','executivo',30),
('secure_score','Microsoft Secure Score','percentual','>=',70,'ultimo','Microsoft 365','executivo',40),
('cobertura_mfa','Cobertura de MFA','percentual','>=',99,'ultimo','Entra ID','executivo',50),
('conformidade_intune','Conformidade Intune','percentual','>=',95,'ultimo','Intune','executivo',60),
('alertas_altos','Alertas altos abertos','quantidade','<=',0,'ultimo','Defender','executivo',70),
('controles_implementados','Controles implementados','percentual','>=',80,'ultimo','Catálogo de controles','executivo',80),
('licencas_vencer','Licenças vencidas/a vencer','quantidade','<=',0,'ultimo','Licenças e contratos','executivo',90),
('radios_manutencao','Rádios em manutenção','percentual','<=',5,'ultimo','Frota de rádios','executivo',100),
('controles_status_implementado','Controles · implementados','quantidade',NULL,NULL,'ultimo','Catálogo de controles','controles',110),
('controles_status_implantacao','Controles · em implantação','quantidade',NULL,NULL,'ultimo','Catálogo de controles','controles',120),
('controles_status_avaliar','Controles · a avaliar','quantidade',NULL,NULL,'ultimo','Catálogo de controles','controles',130);
