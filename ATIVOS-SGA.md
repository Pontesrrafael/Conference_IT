# Associação de patrimônios do SGA

Disponível em http://localhost:2288/ativos.

## Resultado inicial

Levantamento salvo de 19/10/2026, obtido anteriormente no Minerion SGA via Metabase. Foram associados 67 patrimônios com vínculo documental único. Os 4 números com mais de uma compra candidata ficaram em Pendências de associação. Outros 139 registros sem número válido não geraram ativos.

Os ativos novos têm situação Aguardando conferência. A associação não soma estoque, não registra responsável, não confirma localização e não altera o rateio de projetos. Os campos de localização e centro de custo do cadastro SGA aparecem separadamente como referência.

## Conferência e casos ambíguos

Na lista de Ativos, use Conferir patrimônio para informar série, local atual, CC atual e motivo da conferência. Após confirmar, o mesmo registro passa a Em estoque, uma unidade é incorporada à compra e é criado o evento de inventário inicial. A aplicação bloqueia conferência repetida, quantidade acima do faturado, origem em conflito, compra excluída ou produto classificado como quantidade/serviço.

Em Pendências de associação, confira a nota e escolha uma das compras candidatas com justificativa. A escolha fica auditada e é preservada nas próximas coletas enquanto continuar compatível com os candidatos da origem.

## Próximas sincronizações

Consultar SGA e sincronizar agora, em Integração Minerion, passa a ler compras e imobilizado. A consulta patrimonial continua limitada às notas vinculadas aos quatro requisitantes e cruza nota e descrição exata, sem inferência aproximada. Ambas as consultas são lidas antes de iniciar a atualização local. O sucesso informa as contagens de patrimônios associados, ambíguos e sem número.

Não há agendamento periódico nesta alteração: a associação é automática a cada sincronização executada. A conexão direta depende da VPN e dos parâmetros já configurados. A carga inicial usou o levantamento salvo, sem afirmar que houve nova consulta direta ao SGA.

A identidade de origem usa o ID do item de imobilizado e preserva ID do bem, empresa e patrimônio. O índice de patrimônio passou a ser empresa + número, pois números iguais podem existir em empresas distintas. Mudança de etiqueta, bem diferente com mesmo número, compra incompatível, ausência na última consulta ou baixa registrada na origem são sinalizados; o ativo e a conferência anterior não são sobrescritos.

## Banco e validação

Migração 004_patrimonios_sga.sql aplicada. Tabela patrimonios_sga conserva candidatos, dados de origem e ligação ao ativo. Campos de localização e conferência são nulos para identificação documental pendente. Os indicadores de estoque físico excluem Aguardando conferência. CSV de ativos inclui a empresa e também os registros pendentes, identificados pela situação.

Backup feito antes da migração. Testes em banco temporário separado passaram para migração, 67/4/139 registros, importação repetida sem duplicação, associação sem saldo, bloqueio de movimentação antes da conferência, conferência única e quantidade controlada, preservação de série/local, resolução manual de ambiguidade, preservação dessa escolha na próxima coleta e sinalização de mudança de etiqueta. Os testes existentes de inventário e projetos também passaram.

Dados da instalação após a carga: 67 ativos aguardando conferência, 4 ambiguidades e nenhum conflito. Nenhum movimento físico foi criado pela importação inicial.
