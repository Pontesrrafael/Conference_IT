CREATE TABLE projetos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(40) NOT NULL UNIQUE,
 nome VARCHAR(180) NOT NULL,
 descricao TEXT NULL,
 gestor VARCHAR(180) NULL,
 centro_custo_id BIGINT UNSIGNED NULL,
 status ENUM('planejado','em_andamento','pausado','concluido','arquivado') NOT NULL DEFAULT 'planejado',
 orcamento DECIMAL(18,2) NULL,
 inicio DATE NULL, fim DATE NULL,
 criado_por BIGINT UNSIGNED NOT NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 versao INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(centro_custo_id) REFERENCES centros_custo(id),
 FOREIGN KEY(criado_por) REFERENCES usuarios(id),
 CHECK(orcamento IS NULL OR orcamento>=0),
 CHECK(fim IS NULL OR inicio IS NULL OR fim>=inicio)
) ENGINE=InnoDB;

CREATE TABLE compras_projetos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 compra_id BIGINT UNSIGNED NOT NULL,
 projeto_id BIGINT UNSIGNED NOT NULL,
 percentual DECIMAL(5,2) NOT NULL,
 motivo VARCHAR(500) NOT NULL,
 atualizado_por BIGINT UNSIGNED NOT NULL,
 atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE(compra_id,projeto_id),
 FOREIGN KEY(compra_id) REFERENCES compras_origem(id),
 FOREIGN KEY(projeto_id) REFERENCES projetos(id),
 FOREIGN KEY(atualizado_por) REFERENCES usuarios(id),
 CHECK(percentual>0 AND percentual<=100),
 INDEX(projeto_id)
) ENGINE=InnoDB;

CREATE VIEW vw_compras_custos AS
SELECT c.*,
 CAST(CASE WHEN JSON_VALID(c.payload_json) AND JSON_TYPE(JSON_EXTRACT(c.payload_json,'$.valor_total_pedido')) IN ('INTEGER','DOUBLE','DECIMAL','STRING')
 THEN JSON_UNQUOTE(JSON_EXTRACT(c.payload_json,'$.valor_total_pedido'))
 ELSE COALESCE(c.quantidade_solicitada*c.valor_unitario_pedido,0) END AS DECIMAL(18,4)) AS base_pedido,
 CASE WHEN JSON_VALID(c.payload_json) AND JSON_TYPE(JSON_EXTRACT(c.payload_json,'$.valor_total_pedido')) IN ('INTEGER','DOUBLE','DECIMAL','STRING') THEN 'total_pedido' ELSE 'quantidade_x_unitario' END AS fonte_custo
FROM compras_origem c;

CREATE VIEW vw_apontamentos_custos AS
SELECT a.*,TRUNCATE(c.base_pedido*a.percentual/100,2) AS valor_atribuido,
 c.origem_item_id,c.produto_id,c.processo_id,c.processo_data,c.requisitante_codigo,
 c.cancelado_em,c.origem_presente,c.conciliacao_pendente,c.vinculos_nf_positivos,c.base_pedido,c.fonte_custo,
 CASE WHEN c.cancelado_em IS NOT NULL OR c.origem_presente=0 OR c.conciliacao_pendente=1 OR c.base_pedido<0 THEN 1 ELSE 0 END AS alerta_origem
FROM compras_projetos a JOIN vw_compras_custos c ON c.id=a.compra_id;
