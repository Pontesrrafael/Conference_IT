# Centros de custo nas consultas Minerion

**Revisão:** 20/10/2026 (Horário de Mato Grosso – UTC-4).

## Regra

A sincronização traz as linhas de compra que atendem a **pelo menos uma** das condições (união):

1. o requisitante (`ITEMPROCESSOCOMPRA.IPCOFUNREQ`) está em **Parâmetros da consulta — requisitantes SGA**; **ou**
2. o centro de custo **da linha de compra** (`ITEMPROCESSOCOMPRA.IPCOCC`) está em **Parâmetros da consulta — centros de custo SGA**.

O CC da linha de compra é o mesmo que a aplicação já guarda como "CC original da compra". O CC contábil da nota fiscal (`ITEMNOTAFISCALENTRADA.INFECC`) **não** é usado no filtro. No levantamento de 20/10/2026, ele era diferente do CC da compra em 125 das 1.442 linhas do escopo de requisitantes.

## Como usar

Em **Integração Minerion → Parâmetros da consulta — centros de custo SGA**:

1. Informe o código do CC no SGA (`CENTROCUSTO.CCCOD`, por exemplo `1290`).
2. Marque **Incluir centros de custo filhos** quando quiser incluir toda a hierarquia abaixo dele. Os filhos são identificados pelo caminho `CENTROCUSTO.CCSTCOD` (ex.: `+47+1290+`).
3. Clique em **Adicionar centro de custo**. A aplicação confere o CC no SGA, calcula quantos CCs efetivos resultam e quantas linhas de **outros requisitantes** passam a entrar. Depois execute **Consultar SGA e sincronizar agora**.

Use **Remover da consulta** para retirar um CC. O histórico já importado é preservado. As telas Painel, Patrimônios e Compras mostram por padrão só o escopo atual. Para ver o histórico, escolha **Todo o histórico importado**.

## Proteções

| Proteção | Comportamento |
|---|---|
| Permissão | Somente administrador. Formulário com CSRF; adição e remoção registradas na auditoria (`integracao / centros_custo`). |
| Entrada | Apenas inteiros de 1 a 2147483647. O CC precisa existir no SGA. |
| Volume | Até **300** CCs efetivos (após filhos) e **5.000** linhas de outros requisitantes. CCs genéricos, como o 514 "GESTÃO DE ESTOQUE" (18.995 linhas), são recusados sem alterar a configuração. |
| Compra sem CC | CC 0 significa "sem CC". Quando não há CC configurado, o SQL recebe o sentinela `-1`, que não coincide com nenhuma linha. |
| CC bloqueado | É aceito, mas a mensagem avisa que está bloqueado no SGA (`CCBLOQ='S'`). |
| Filhos novos | A expansão é recalculada no início de cada sincronização: um filho criado depois no SGA passa a valer. |
| Ausência na origem | A marcação "não retornou na última consulta" vale só para registros do escopo combinado atual. Registros de requisitantes ou CCs removidos e coletas por documento não são marcados. |
| Busca por RC/ordem | Continua trazendo todos os itens do documento, sem filtro de requisitante ou CC. |

## Arquivos

- Configuração privada: `storage/centros-custo.json` (fora do Git; incluir no backup junto de `storage/requisitantes.json`).
- Serviço: `app/Services/CentrosCusto.php`.
- Marcador nas consultas: `{{CENTROS_CUSTO_SGA}}` em `database/queries/minerion_coleta.sql` e `minerion_patrimonios.sql`. Ele é preenchido somente com inteiros validados. Não execute o template sem preencher o marcador.
- Teste portátil: `php bin/verificar-centros-custo.php`. Usa um SGA simulado e não acessa banco nem rede.

## Validação no SGA real (Metabase, 20/10/2026)

| Verificação | Resultado |
|---|---|
| Consulta de compras com CCs 1241, 1290 e 1353 | 1.552 linhas = 1.442 dos requisitantes + **110 pelo CC** (P2025_11, de outros 10 requisitantes) |
| Consulta de patrimônios com os mesmos CCs | Executou: 1.859 linhas candidatas, 239 imobilizados |
| Expansão do CC 47 "05 - INVESTIMENTOS" com filhos | 46 CCs, incluindo os projetos P2025 e P2026 |
| Contagem prévia de linhas extras | 110, igual à consulta completa |

## Exemplos úteis

| CC | Nome no SGA | Observação |
|---|---|---|
| 1290 | P2026_44 - REDE DE FIBRA OPTICA - CENTRAL | Anel óptico; hoje 100% coberto pelos requisitantes |
| 1353 | P2026_44 - CIRCUITO COMPLETO DE FIBRA OTICA - OPERACAO | Idem |
| 1241 | P2025_11 - ESCRITORIO ADMINISTRATIVO - CENTRAL | Traz 110 linhas que hoje não aparecem |
| 52 | 07 - SOFTWARES E HARDWARES | CC de investimento em TI |
