# Indicadores de TI · Visão Executiva

**Revisão:** 20/10/2026 (Horário de Mato Grosso – UTC-4). Menu **Indicadores → Visão Executiva de TI** (`/indicadores`).

Painel no padrão dos **Indicadores do Salinas Access Gate**:
- cartões com meta;
- gráficos gerados no servidor;
- **Modo TV**;
- atualização automática.

O conteúdo espelha a página **Visão Executiva** do *SGM | Dashboard de TI* no Power BI. Nesta primeira etapa:
- os valores das fontes externas (Zabbix, Microsoft 365, controles, licenças e rádios) entram por **lançamento manual** ou **CSV**;
- o parque e as compras vêm da própria aplicação.

## O que o painel mostra

| Bloco | Conteúdo | Origem |
|---|---|---|
| Indicadores com meta (10 cartões) | Disponibilidade da rede, links de internet, problemas críticos, Secure Score, cobertura de MFA, conformidade Intune, alertas altos, controles implementados, licenças vencidas/a vencer e rádios em manutenção. Valor em verde quando atende a meta e em vermelho quando não atende; "—" quando não há medição | Lançamento ou CSV |
| Parque e compras | Ativos no parque, ativos aguardando conferência, patrimônios ambíguos, bens sem número no SGA, compras no período e última sincronização com o SGA (vermelho quando falhou ou tem mais de 24 h) | Aplicação (escopo atual da consulta SGA) |
| Disponibilidade diária | Linhas diárias de hosts críticos e de links; os dias sem medição aparecem como falha na linha | Lançamento ou CSV |
| Controles por status | Rosca com implementados, em implantação e a avaliar | Lançamento ou CSV |
| Riscos em aberto por nível | Crítico, Alto, Médio e Baixo, contando riscos abertos e em tratamento | Registro de riscos |
| Riscos altos em aberto | Riscos altos e críticos, ordenados pelo nível | Registro de riscos |
| Parque por situação | Ativos com compra no período, por situação e valor | Aplicação |

### Regras de cálculo
- **Período padrão:** últimos 30 dias.
- **Média no período:** usada pelas disponibilidades (rede e links).
- **Última medição até o fim do período:** usada pelos demais indicadores, que são fotografias.
- **Comparação:** mostra a variação contra o período anterior de mesma duração (em p.p. para percentuais), em verde quando melhora e em vermelho quando piora.
- **Lançamentos:** um por indicador e por dia. Lançar de novo a mesma data substitui o valor e registra a mudança na auditoria.

## Modo TV e atualização
- **Modo TV** (`/indicadores?tv=1`): tela sem o menu lateral, para o telão da sala de TI. Usa o layout comum (`Views/tv.php`, `assets/tv.css` e `assets/tv.js`), o mesmo do Modo TV de Ativos e custos (`/?tv=1`). Mostra logotipo, título, relógio, seletor de tema (claro, automático ou escuro) e botão de tela cheia. Os filtros ficam recolhidos.
- **Atualização automática:** desligada, 1, 5 (padrão) ou 15 minutos.
  - Só o conteúdo é recarregado (`?parcial=1`); a página inteira não recarrega.
  - Com a aba em segundo plano, a atualização pausa.
  - Se uma atualização falhar, aparece um aviso e os dados anteriores continuam na tela.
- **Segurança:** gráficos em SVG sem `style` nem script inline, compatíveis com a CSP da aplicação. O tema segue o seletor Claro · Automático · Escuro, que fica no topo das telas.

## Dados dos indicadores (`/indicadores-dados`)

| Ação | Quem | Observação |
|---|---|---|
| Lançar medição | admin, operador_ti | Data dd/mm/aaaa (futura não é aceita); valor com vírgula ou ponto; percentual de 0 a 100 |
| Importar CSV de medições | admin, operador_ti | Colunas `codigo;data;valor;observacao` |
| Importar CSV de riscos | admin, operador_ti | Colunas `codigo;risco;nivel;classificacao;responsavel;situacao`. O código identifica o risco: importar de novo atualiza |
| Incluir ou atualizar risco | admin, operador_ti | Nível de 1 a 25 (impacto × probabilidade). Sem classificação: ≥ 20 Crítico, ≥ 12 Alto, ≥ 6 Médio, abaixo disso Baixo |
| Alterar meta | admin | Operador ≥ ou ≤ e valor |
| Remover medição | admin, operador_ti | Fica registrada na auditoria |
| Ver o painel | todos os perfis | O perfil consulta não vê nem acessa os Dados dos indicadores |

### Regras da importação CSV
- **Formato do arquivo:** até 2 MB e 5.000 linhas. Separador `;` ou `,`, detectado pelo cabeçalho. Aceita UTF-8 (com ou sem BOM) ou Windows-1252.
- **Tudo ou nada:** todas as linhas são conferidas antes de gravar. Com qualquer erro, **nada** é importado, e a mensagem lista até 10 erros com o número da linha.
- **Modelos para baixar:** `/indicadores-modelo?tipo=medicoes` e `/indicadores-modelo?tipo=riscos`.

### Códigos dos indicadores

| Código | Indicador | Unidade | Meta inicial (Power BI) | Cálculo |
|---|---|---|---|---|
| `disponibilidade_rede` | Disponibilidade da rede (hosts críticos) | % | ≥ 99,50 | média |
| `links_internet` | Links de internet | % | ≥ 98,00 | média |
| `problemas_criticos` | Problemas críticos (Zabbix) | qtd. | ≤ 0 | última |
| `secure_score` | Microsoft Secure Score | % | ≥ 70,00 | última |
| `cobertura_mfa` | Cobertura de MFA | % | ≥ 99,00 | última |
| `conformidade_intune` | Conformidade Intune | % | ≥ 95,00 | última |
| `alertas_altos` | Alertas altos abertos (Defender) | qtd. | ≤ 0 | última |
| `controles_implementados` | Controles implementados | % | ≥ 80,00 | última |
| `licencas_vencer` | Licenças vencidas/a vencer | qtd. | ≤ 0 | última |
| `radios_manutencao` | Rádios em manutenção | % | ≤ 5,00 | última |
| `controles_status_implementado` | Controles · implementados (rosca) | qtd. | — | última |
| `controles_status_implantacao` | Controles · em implantação (rosca) | qtd. | — | última |
| `controles_status_avaliar` | Controles · a avaliar (rosca) | qtd. | — | última |

## Instalação
- A migração `database/007_indicadores_ti.sql` é aplicada pelo `bin/instalar.php`, que roda no **iniciar.ps1**. Basta fechar e abrir a aplicação.
- Enquanto a migração não roda, o painel mostra o parque e as compras, junto com um aviso; a tela de dados pede a migração.

## Testes
- `php bin/verificar-indicadores.php` (portátil, sem banco): conversão de números e datas, metas ≥/≤, formatação, CSV (BOM, separador, Windows-1252, cabeçalho), modelos e gráficos SVG sem `style`.
- `php bin/verificar-ponta-a-ponta.php` (88 verificações), inclui:
  - painel, Modo TV, conteúdo parcial e ausência de `style` no HTML;
  - lançamento, recusa de percentual acima de 100 e de data futura;
  - CSV com erro que não grava nada, CSV válido com BOM e importação de riscos com classificação calculada;
  - meta alterada pelo administrador, cartões refletindo lançamentos e meta, risco tratado fora do painel e escape de HTML;
  - modelo CSV, remoção auditada e bloqueios do perfil consulta.

## Próximas etapas (fora desta entrega)
- Demais páginas do Power BI:
  - Segurança M365;
  - Conformidade & Controles;
  - Disponibilidade & Rede;
  - Ativos & Inventário;
  - Rádios & Telecom;
  - Licenças & Contratos;
  - Endpoints & Patches;
  - Saúde das Fontes.
- Coleta direta e agendada, em modo leitura, com credenciais cifradas na Integração:
  - API do Zabbix;
  - UniFi Site Manager;
  - Microsoft Graph (Secure Score, Entra ID, Intune e Defender);
  - NinjaOne.
- Tabela "Problemas ativos no Zabbix", que depende da API do Zabbix.
