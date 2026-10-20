# Filtros de datas nos painéis

Aplicados no painel geral (/), na visão de projetos (/projetos) e no painel individual (/projeto?id=...).

Opções: todo o histórico, hoje, últimos 7 dias, últimos 30 dias, mês atual, ano atual e personalizado. Os atalhos terminam no dia atual, no fuso America/Cuiaba. Últimos 7 dias significa hoje e os seis dias anteriores. Mês/ano atuais vão do primeiro dia até hoje.

Para informar datas manualmente, selecionar Personalizado. É possível definir somente início ou somente fim. O fim é inclusivo: as consultas usam limite exclusivo à meia-noite do dia seguinte para incluir registros de todo o último dia. Datas inválidas e intervalos invertidos são rejeitados. Limpar remove o filtro.

O painel geral filtra compras pela data do processo SGA e ativos pela data da compra de origem. Os totais de ativos representam a situação atual desses bens, não uma reconstrução histórica do estoque. Ativos sem compra de origem só entram na visão sem filtro de data. A última sincronização é informação geral da carga e não é reduzida pelo período.

Nos projetos, o período usa a data do processo de compra, incluindo custos, vínculos, categorias, gráfico mensal, alertas e busca de compras para apontamento. Projetos continuam listados mesmo sem compras no período, com valor zero. Orçamento, previsão de início/término e situação do projeto são os atuais; o saldo orçamentário considera custos de todo o histórico. O painel informa separadamente o total histórico e o valor do período.

O filtro segue nos links da lista para o projeto e na exportação CSV. As buscas por texto preservam o período aplicado. O CSV de custos inclui colunas com as datas inicial/final.

Validação: testes em banco temporário para períodos rápidos, datas inválidas, intervalo invertido, inclusão de 23:59:59 do último dia, exclusão da meia-noite seguinte, intervalo com somente fim, período sem dados, consistência entre lista e detalhe de projetos e preservação do saldo global do orçamento. Testes anteriores de projetos, estoque e patrimônios também passaram.

Verificação no navegador: mês atual de 01/10/2026 a 19/10/2026 mostrou 28 linhas de compra e 1 com NF positiva, contra 1441 e 587 sem filtro. Nenhum registro de uso foi alterado por esta atualização.
