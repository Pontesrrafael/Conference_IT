# Exportação para Microsoft Project

Em **Projetos e custos**, use **Exportar Microsoft Project (XML)** no cartão de um projeto ou dentro de seu painel. O download é um arquivo XML de intercâmbio MSPDI (namespace `http://schemas.microsoft.com/project`), suportado pelo Microsoft Project desktop. Não é um MPP renomeado e não exige Office no servidor.

No Microsoft Project: **Arquivo > Abrir > Procurar**, selecione o XML e abra como **novo projeto** quando solicitado. Depois use **Salvar como > Projeto (*.mpp)** para continuar no formato nativo. Colunas de custos, notas e a tarefa resumo ajudam a conferir os dados importados.

O arquivo inclui o projeto completo, mesmo quando a página está filtrada por datas:

- Nome, código, responsável, centro de custo, situação e orçamento nas notas do resumo.
- Marcos de início e término previstos, quando cadastrados.
- Grupo **Compras SGA · custos atribuídos**, com uma linha por apontamento de compra.
- Custo fixo de cada linha igual ao total do pedido multiplicado pelo percentual de rateio já registrado na aplicação, truncado em centavos. Resumos apenas acumulam; não recebem outro custo fixo.
- Número da linha e processo SGA, requisitante, data, base, percentual, finalidade e alertas nas notas. Origem com divergência recebe o prefixo **[REVISAR]** e continua no total, como no painel.

Compras são marcos documentais de duração zero na data do processo. Não representam etapas executadas, entrega, pagamento ou conclusão física. Um calendário de todos os dias preserva também compras registradas em fins de semana; adapte o calendário quando elaborar seu cronograma. Não são inferidos predecessores, esforço, execução ou pagamentos. Defina essas informações no Project. Datas de compra ausentes recebem início previsto, menor data disponível ou data do cadastro como referência, explicitada nas notas.

Os custos são gerenciais de compras, em BRL. Orçamento permanece como referência nas notas e não é lançado como despesa adicional ou baseline de execução. Exportação não altera projetos, rateios, estoque ou áreas e não sincroniza de volta mudanças feitas no Project.

Validação: testes de XML/Unicode, estrutura do esquema oficial da Microsoft, custos, projeto vazio, download autenticado e escopo integral. O XSD oficial publicado para 2007 declara o namespace com `/2007`; na verificação, apenas o namespace do documento de teste é adaptado para validar sua estrutura. O arquivo exportado conserva o namespace MSPDI usado pelo Project. A abertura automática no Microsoft Project instalado não pôde ser confirmada: a ativação COM retornou `CO_E_SERVER_EXEC_FAILURE`. A exportação funciona sem essa automação.

Referências oficiais:

- https://support.microsoft.com/en-us/project/export-or-import-data-to-another-file-format
- https://learn.microsoft.com/en-us/office-project/xml-data-interchange/task-element?view=project-client-2016
- https://schemas.microsoft.com/project/2007/mspdi_pj12.xsd
