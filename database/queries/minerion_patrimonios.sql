-- Identificação conservadora por nota fiscal e descrição exata sem sufixo NCM.
-- IBAINFE referencia o cabeçalho NFECOD, não a linha INFECOD.
WITH notas_escopo AS (
 SELECT DISTINCT ni.INFENFE
 FROM dbo.ITEMPROCESSOCOMPRA i
 JOIN dbo.FATITEMPROCESSOCOMPRA f ON f.FIPCOIPCO=i.IPCOCOD AND f.FIPCOQUANT>0
 JOIN dbo.ITEMNOTAFISCALENTRADA ni ON ni.INFECOD=f.FIPCOINFE
 WHERE i.IPCOFUNREQ IN ({{REQUISITANTES_SGA}})
), candidatos AS (
 SELECT DISTINCT a.IBAICOD AS imobilizado_item_id,a.IBAIBAI AS bem_id,
 CAST(a.IBAIVALOR AS decimal(18,2)) AS valor_aquisicao_imobilizado,
 a.IBAINUMPAT AS patrimonio_numerico,a.IBAINUMPATST AS patrimonio_texto,
 a.IBAINFDESC AS descricao_imobilizado,a.IBAINFE AS nota_id,
 a.IBAINFNUM AS numero_nota,a.IBAILOCALIZACAO AS localizacao_sga,
 a.IBAIBAIXADATA AS baixa_data,a.IBAINFDATA AS nota_data,
 b.BAINOME AS nome_bem,b.BAIEMP AS empresa_bem_id,b.BAIFIL AS filial_bem_id,b.BAICC AS centro_custo_bem_id,cc.CCNOME AS centro_custo_bem,
 i.IPCOCOD AS origem_item_id,i.IPCOFUNREQ AS requisitante_id,fu.FUNNOME AS requisitante,
 i.IPCOITEM AS produto_id,e.ESTQNOME AS produto,
 ni.INFECOD AS nota_item_id,ni.INFEITEM AS produto_nota_id,
 CAST(f.FIPCOQUANT AS decimal(18,4)) AS quantidade_vinculo,
 CAST(i.IPCOQUANTFAT AS decimal(18,4)) AS quantidade_faturada,
 nf.NFESIT AS nota_situacao
 FROM dbo.ITEMBEMATIVOIMOB a
 JOIN notas_escopo ns ON ns.INFENFE=a.IBAINFE
 JOIN dbo.ITEMNOTAFISCALENTRADA ni ON ni.INFENFE=a.IBAINFE
 JOIN dbo.FATITEMPROCESSOCOMPRA f ON f.FIPCOINFE=ni.INFECOD AND f.FIPCOQUANT>0
 JOIN dbo.ITEMPROCESSOCOMPRA i ON i.IPCOCOD=f.FIPCOIPCO
 JOIN dbo.NOTAFISCALENTRADA nf ON nf.NFECOD=ni.INFENFE
 LEFT JOIN dbo.ESTOQUE e ON e.ESTQCOD=ni.INFEITEM
 LEFT JOIN dbo.FUNCIONARIO fu ON fu.FUNCOD=i.IPCOFUNREQ
 LEFT JOIN dbo.BEMATIVOIMOB b ON b.BAICOD=a.IBAIBAI
 LEFT JOIN dbo.CENTROCUSTO cc ON cc.CCCOD=b.BAICC
 CROSS APPLY (SELECT LTRIM(RTRIM(CASE WHEN CHARINDEX(' [NCM:',a.IBAINFDESC)>0 THEN LEFT(a.IBAINFDESC,CHARINDEX(' [NCM:',a.IBAINFDESC)-1) ELSE a.IBAINFDESC END)) AS descricao_base) d
 WHERE d.descricao_base<>'' AND
 (d.descricao_base=LTRIM(RTRIM(e.ESTQNOME)) OR d.descricao_base=LTRIM(RTRIM(ni.INFEXMLXPROD)))
)
SELECT * FROM candidatos ORDER BY imobilizado_item_id DESC,origem_item_id,nota_item_id
