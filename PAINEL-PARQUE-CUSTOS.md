# Objetivo do painel: parque, fora de uso e projetos

O painel principal passa a priorizar três perguntas: custo do parque cadastrado; bens fora de uso; custos atribuídos aos projetos.

## Custo do parque

Valor de aquisição dos ativos não baixados cadastrados, incluindo os identificados documentalmente que aguardam conferência física. Prioridade: ITEMBEMATIVOIMOB.IBAIVALOR do SGA, associado pelo item de imobilizado do levantamento. Na ausência desse campo, a estimativa é total do pedido / quantidade solicitada, explicitamente identificada. Não se repete o total de uma compra em cada patrimônio. Valor nulo é ausência de custo, não zero. Zero informado na origem permanece zero.

Consulta do campo de valor executada via Metabase em 19/10/2026, no Minerion SGA. Valores completos preservados em evidencias/custos-imobilizado.json, incorporados aos registros patrimoniais já identificados. A consulta de próximas sincronizações passou a incluir o mesmo campo.

Resultado na instalação: R$ 154.689,10 em 67 patrimônios não baixados associados, todos com valor do imobilizado disponível. Os 67 ainda aguardam conferência física. Não é o valor de mercado, valor depreciado ou prova de existência atual do parque inteiro. Os 139 registros sem número, 4 ambiguidades e equipamentos ainda não identificados não estão nesse total.

Materiais por quantidade continuam no estoque de materiais e não são somados ao parque de equipamentos. Compras requisitadas não viram automaticamente ativos.

## Não ativos / fora de uso

Definição confirmada pelo usuário: equipamentos em estoque, em manutenção ou baixados. Não é uma avaliação de comunicação na rede.

Em uso significa situação Alocado registrada no inventário local. Aguardando conferência é uma situação desconhecida; não entra em fora de uso. Baixados ficam fora do valor do parque, mas fazem parte do filtro amplo de fora de uso e têm indicador próprio.

Na guia Ativos há filtro por situação e custo individual com indicação da fonte. O custo dos itens da busca corresponde às linhas visíveis, inclusive baixados quando selecionados.

## Projetos e período

O painel também mostra o custo das compras atribuídas a projetos no período, com acesso ao detalhamento. São os valores de pedido rateados, conforme o módulo de projetos. Esses custos podem conter os mesmos equipamentos do parque, portanto não se somam aos custos patrimoniais.

O total do parque e seus indicadores de situação são globais, independentemente do filtro de datas. O período filtra os custos dos projetos e a seção de equipamentos comprados, pela data do processo SGA. Essa distinção é exibida na própria tela.

## Validação

Backup do banco e das telas anteriores preservado em storage/backups. Testes em banco temporário: prioridade do valor do imobilizado, custo individual em vez de duplicação do pedido, soma sem multiplicação por junção, separação de baixados, filtro fora de uso excluindo registros pendentes e manutenção do total global quando o período muda. Os testes anteriores de estoque, patrimônios, projetos e datas também passaram.

Não foram criadas movimentações físicas nem alteradas situações de uso para preencher o painel. A próxima etapa operacional é conferir os bens e registrar alocações/estoque/manutenção/baixas, além de cadastrar os projetos e apontar suas compras.
