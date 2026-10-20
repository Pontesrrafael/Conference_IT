-- SQL Server / Minerion SGA. Uma linha por IPCOCOD; não representa saldo físico.
-- Escopo preenchido pela configuração de requisitantes da aplicação.
SELECT i.IPCOCOD AS origem_item_id, p.PCOCOD AS processo_id, p.PCONUM AS processo_numero,
 p.PCOEMP AS empresa_id,p.PCOFIL AS filial_id,p.PCOSIT AS processo_situacao,
 CASE WHEN p.PCODATA > '19000101' THEN p.PCODATA END AS processo_data,
 CASE WHEN p.PCODATACANC > '19000101' THEN p.PCODATACANC END AS cancelado_em,
 i.IPCOFUNREQ AS requisitante_id,f.FUNNOME AS requisitante_nome,
 i.IPCOITEM AS produto_id,e.ESTQNOME AS produto_nome,
 i.IPCOCC AS centro_custo_compra_id,cc.CCNOME AS centro_custo_compra_nome,
 CAST(i.IPCOQUANT AS decimal(18,4)) AS quantidade_solicitada,
 CAST(i.IPCOQUANTFAT AS decimal(18,4)) AS quantidade_faturada_cadastro,
 CAST(i.IPCOQUANTCANC AS decimal(18,4)) AS quantidade_cancelada,
 CAST(i.IPCOQUANTENT AS decimal(18,4)) AS quantidade_entregue_cadastro,
 CAST(i.IPCOUNIT AS decimal(18,4)) AS valor_unitario_pedido,
 CAST(i.IPCOTOTAL AS decimal(18,4)) AS valor_total_pedido,
 COALESCE(n.vinculos_positivos,0) AS vinculos_nf_positivos,
 COALESCE(n.quantidade_vinculada_nf,0) AS quantidade_vinculada_nf,
 n.primeira_nota_data,n.ultima_nota_data
FROM dbo.ITEMPROCESSOCOMPRA i
JOIN dbo.PROCESSOCOMPRA p ON p.PCOCOD=i.IPCOPCO
LEFT JOIN dbo.FUNCIONARIO f ON f.FUNCOD=i.IPCOFUNREQ
LEFT JOIN dbo.ESTOQUE e ON e.ESTQCOD=i.IPCOITEM
LEFT JOIN dbo.CENTROCUSTO cc ON cc.CCCOD=i.IPCOCC
OUTER APPLY (
 SELECT COUNT(*) AS vinculos_positivos,
 CAST(SUM(v.FIPCOQUANT) AS decimal(18,4)) AS quantidade_vinculada_nf,
 MIN(CASE WHEN nf.NFEDATA > '19000101' THEN nf.NFEDATA END) AS primeira_nota_data,
 MAX(CASE WHEN nf.NFEDATA > '19000101' THEN nf.NFEDATA END) AS ultima_nota_data
 FROM dbo.FATITEMPROCESSOCOMPRA v
 JOIN dbo.ITEMNOTAFISCALENTRADA ni ON ni.INFECOD=v.FIPCOINFE
 JOIN dbo.NOTAFISCALENTRADA nf ON nf.NFECOD=ni.INFENFE
 WHERE v.FIPCOIPCO=i.IPCOCOD AND v.FIPCOQUANT>0 AND nf.NFESIT=1
) n
WHERE i.IPCOFUNREQ IN ({{REQUISITANTES_SGA}})
ORDER BY i.IPCOCOD
