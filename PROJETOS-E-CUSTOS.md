# Projetos e custos

Disponível em http://localhost:2288/projetos.

## Como usar

1. Abra Projetos e custos e clique em Novo projeto. Informe código, nome, responsável, situação, datas e orçamento opcional. O centro de custo de referência é informativo e não muda o CC da compra ou do ativo.
2. Abra o painel do projeto e pesquise compras. Informe o percentual da linha destinado ao projeto e o motivo. Também é possível apontar na coluna Projetos da tela Compras SGA.
3. Uma compra destinada integralmente ao projeto usa 100%. Para compartilhar entre projetos, use por exemplo 60% e 40%. A aplicação impede ultrapassar 100% por linha de compra.
4. O painel mostra valor atribuído, orçamento, saldo ou excesso, compras com vínculo de NF, custos por mês da compra, distribuição por categoria e detalhamento dos apontamentos. A visão geral consolida os projetos do filtro selecionado.
5. Edite percentuais ou desvincule com justificativa. A alteração fica auditada. Arquivar o projeto conserva os custos e impede novos apontamentos.
6. Use Exportar custos CSV no painel para obter o detalhamento. Para imprimir, use a impressão do navegador; formulários e navegação são ocultados.

## Base dos custos

Moeda: reais (BRL). Base de cada linha = valor_total_pedido da coleta original do SGA, quando disponível. Se ausente, usa quantidade solicitada × valor unitário do pedido e identifica essa alternativa no detalhamento.

Valor atribuído = base da linha × percentual / 100, truncado em duas casas decimais. O resíduo de centavos não é distribuído automaticamente. Nenhum rateio pode superar 100% da compra, inclusive sob chamadas simultâneas: todas bloqueiam o registro da compra durante a alteração.

Este é um controle gerencial pelo valor de pedido. Não é uma apuração de pagamentos, custo contábil realizado ou soma dos valores fiscais das notas. O indicador de compras com NF positiva continua usando a base de pedido, sem apresentar essa parcela como valor faturado.

As datas dos gráficos são as dos processos de compra, não datas de pagamento nem do apontamento. As categorias vêm da triagem dos produtos; itens sem categoria ficam em Sem categoria. Alterar a categoria atual reorganiza o agrupamento sem mudar o rateio.

Os percentuais ficam gravados e o custo acompanha a base da última coleta. Se o SGA atualizar preços, o painel reflete os novos valores na atualização seguinte. Vínculos com cancelamento, origem ausente, conciliação pendente ou base negativa ficam sinalizados e continuam no total para revisão; não são silenciosamente excluídos. Novos apontamentos em compras com divergência são bloqueados.

## Persistência e permissões

Migração 003_projetos.sql: tabelas projetos e compras_projetos, mais views de cálculo. Uma compra pode estar em diversos projetos; uma linha por par compra/projeto. Uma nova submissão para o mesmo par substitui o percentual, sem duplicar o vínculo. Formulários de edição carregam o valor/versão anterior para detectar alterações de outra sessão.

Admin e operador TI podem cadastrar e apontar; demais perfis apenas consultam. A restrição por CC individual continua fora do escopo desta versão, conforme o guia local. Todos os projetos são internos, exigem login e não têm link público de compartilhamento.

## Validação — 19/10/2026, America/Cuiaba

- Backup completo do banco salvo em storage/backups/antes-projetos-<data-hora>.sql antes da migração. Cópias das telas anteriores em storage/backups/projetos-telas-<data-hora>.
- Migração aplicada com sucesso no MariaDB local; aplicação mantida na porta 2288.
- Testes em banco temporário separado: migrações, rateios 60/40, reenvio idempotente, bloqueio acima de 100%, cálculos decimais, saldo orçamentário, validação de datas/orçamento, edição desatualizada, desvinculação, arquivamento, auditoria e preservação após sincronização SGA.
- Painel preenchido com compras, orçamento e gráficos renderizado sob tratamento de avisos PHP como falhas, em dados temporários. Sem erro.
- Menu, visão geral de projetos e formulário de cadastro inspecionados no navegador com a identidade visual Salinas Gold.
- Nenhum projeto fictício foi inserido no banco de uso. Inventário e configurações SGA preservados.
