# Relatórios de gastos por centro de custo

**Revisão:** 20/10/2026 (Horário de Mato Grosso – UTC-4). Menu **Relatórios** (`/relatorios`).

## O que o relatório mostra

Para os centros de custo escolhidos e o período selecionado:

| Bloco | Conteúdo |
|---|---|
| Resumo | Total do pedido **sem dupla contagem**, total com NF positiva, compras sem patrimônio e número de CCs |
| Gastos por centro de custo | Para cada CC: linhas, total do pedido, com NF, sem patrimônio, patrimônios (valor e quantidade, sem baixados) e bens do imobilizado sem número |
| Evolução mensal | Total do pedido por mês e por CC, com uma coluna de total sem dupla contagem |
| Linhas de compra | As 500 mais recentes, com produto, requisitante, CC, valor, NF e patrimônio. O CSV traz todas |

A regra de valor é a mesma do Painel (`Parque::SQL_COMPRAS`): custo pelo pedido no SGA (`valor_total_pedido`, ou quantidade × unitário), **canceladas fora**, com ou sem patrimônio. É custo de compra, não pagamento nem comprovação fiscal. Os totais de parque, compras e projetos podem representar as mesmas aquisições: não some esses totais.

## Como gerar

1. Marque um ou mais **centros de custo adicionados** (seleção múltipla). Se nenhum estiver marcado, o relatório usa todos.
2. (Somente administrador) Em **Novos centros de custo**, informe códigos CCCOD do SGA separados por vírgula, por exemplo `1241, 1353`. Marque **Incluir filhos**, se quiser.
3. Escolha o **período**: todo o histórico, hoje, 7 ou 30 dias, mês, ano ou personalizado. A data usada é a do processo de compra; o dia final entra por inteiro.
4. Com **Sincronizar com o SGA antes de gerar** marcado (padrão), a aplicação:
   1. confere **todos** os códigos novos no SGA. Se algum não existir, nada é alterado;
   2. inclui os novos no escopo da consulta (Integração), com os mesmos limites de 300 CCs efetivos e 5.000 linhas;
   3. roda a sincronização completa com o SGA;
   4. abre o relatório já com os CCs novos selecionados.
5. **Exportar CSV** gera o resumo por CC, o total geral e todas as linhas do filtro atual, com BOM UTF-8 e proteção contra fórmulas de planilha.

## Permissões

| Perfil | Pode |
|---|---|
| admin | Gerar, informar CCs novos, sincronizar e exportar |
| operador_ti | Gerar com os dados já importados e exportar |
| gestor, consulta | Ver o relatório (formulário GET) e exportar |

Inclusões feitas pelo relatório ficam na auditoria (`integracao / centros_custo / adicionar_relatorio`). Para retirar um CC, use **Integração → Remover da consulta**. O histórico importado é preservado.

## Exemplo de referência (SGA, 20/10/2026)

CC 1290 (P2026_44 - Rede de fibra óptica - Central): 21 linhas, 20 com NF, **R$ 115.085,10** no pedido e **R$ 114.845,10** com NF. São 7 bens no imobilizado, todos sem número de patrimônio (R$ 79.784,62).

## Testes

- `php bin/verificar-centros-custo.php` (portátil): leitura dos códigos novos (vírgula, ponto e vírgula e espaço), repetição, valores inválidos e limite de 20 códigos.
- `php bin/verificar-ponta-a-ponta.php`: tela, período, totais por CC, CSV com proteção, geração sem sincronizar, código inválido e bloqueio do perfil consulta.
