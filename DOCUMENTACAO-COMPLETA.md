# Salinas TI — documentação completa e estado atual

**Revisão da documentação:** 20/10/2026, 11:15 (Horário de Mato Grosso – UTC-4), com verificação no navegador da instalação local (seção 11.4). Inclui requisitantes e centros de custo configuráveis, telas filtradas pelo escopo atual (seção 6.5) e a verificação geral (seção 11.3). Histórico na seção 15.

**Referência do levantamento:** 19/10/2026, 23:18:58, horário de Cuiabá (UTC−04:00). Os totais operacionais abaixo são uma fotografia dessa consulta e mudam com o uso.

## 1. Objetivo e escopo

Organizar o parque de TI da Salinas Gold, saber quanto custa o parque cadastrado, identificar itens fora de uso e acompanhar o custo das iniciativas e projetos. Os dados de compras são lidos do Minerion SGA; o inventário físico, os rateios e os apontamentos geográficos são mantidos em banco próprio.

Fora de uso significa **estoque, manutenção ou baixados**. A aplicação não faz descoberta de rede nem monitoramento de comunicação dos equipamentos. Situação aguardando conferência é desconhecida e não é classificada como fora de uso.

A referência de estrutura é o [Salinas Access Gate](https://github.com/Pontesrrafael/salinas-access-gate): PHP renderizado no servidor, MVC próprio, PDO, sessões, controles de acesso e pasta pública separada. O banco de inventário é independente do banco de portaria.

## 2. Onde o projeto está

| Elemento | Localização / referência |
|---|---|
| Instalação operacional local | `D:\Apps_SG\Est_Sg` |
| Endereço | http://localhost:2288 |
| Porta web | `2288`, escutando em `127.0.0.1` |
| DocumentRoot | `D:\Apps_SG\Est_Sg\public` |
| Banco da aplicação | MariaDB `salinas_ti`, em `127.0.0.1:3308` |
| Arquivos físicos do MariaDB | `D:\Apps_SG\Est_Sg\storage\mariadb` |
| Configuração privada | `.env`, `storage/sga.json`, `storage/requisitantes.json` e `storage/centros-custo.json` |
| Acesso inicial gerado | `ACESSO-LOCAL.txt` — privado e fora do Git |
| Mapas originais e prévias | `storage/mapas` — privado e fora do Git |
| Backups e logs | `storage/backups` e `storage/logs` |
| Código publicado | `https://github.com/Pontesrrafael/salinas-ti` |
| Cópia preparada para Git | Pasta `outputs/salinas-ti-github` do workspace desta entrega |
| Documentação consolidada local | `D:\Apps_SG\Est_Sg\DOCUMENTACAO-COMPLETA.md` |

O SQL Server do SGA é uma conexão externa, acessível pela rede/VPN. Endereço interno, usuário e senha não são publicados. O Metabase serviu para descobrir e verificar o esquema; a aplicação usa conexão direta e não depende operacionalmente do Metabase.

## 3. Estado atual verificado

### 3.1 Software e instalação

| Componente | Verificado nesta máquina |
|---|---|
| PHP | 8.2.12, XAMPP |
| MariaDB | 10.4.32 |
| Python geográfico | 3.12, com Rasterio 1.4.4 e Pillow 11.3.0 |
| Migrações aplicadas | 001 a 006 |
| Saúde web | Rota `/saude` respondeu `status: ok` ao final das verificações anteriores |
| Servidor | Servidor PHP local; não é serviço de produção |

### 3.2 Fotografia do banco operacional

| Indicador | Quantidade |
|---|---:|
| Linhas de compras importadas | 1.441 |
| Patrimônios cadastrados | 67 |
| Patrimônios aguardando conferência | 67 |
| Projetos cadastrados | 1 |
| Compras rateadas em projetos | 0 |
| Mapas enviados pelo usuário | 0 |
| Áreas desenhadas | 0 |
| Movimentações físicas registradas | 0 |

Esses números indicam que os módulos estão implementados, mas a conferência física e os apontamentos operacionais ainda precisam ser preenchidos. O projeto já cadastrado foi informado pelo usuário; nenhum projeto de teste foi deixado no banco real pelas rotinas de verificação.

No levantamento patrimonial anterior foram encontrados 67 vínculos únicos, quatro associações ambíguas e 139 registros sem número de patrimônio. O custo identificado então para os 67 bens foi R$ 154.689,10. Esse valor é histórico de aquisição dos bens associados, não valor de mercado, valor depreciado nem custo de todo o parque da empresa. Os dados detalhados dessa coleta ficam somente no ambiente privado.

## 4. Arquitetura e fluxo de dados

```text
Navegador
   └─ PHP / sessão / autorização / CSRF
       ├─ MariaDB próprio: inventário, projetos, mapas e auditoria
       ├─ SQL Server SGA: consultas de leitura pela rede/VPN
       └─ Python/Rasterio: GeoTIFF privado → prévia PNG WGS84

SGA → compras importadas → triagem → conferência física → patrimônio/estoque
                       └─ rateio até 100% → custos do projeto → CSV / Project XML
Patrimônios, compras e projetos → áreas desenhadas sobre o GeoTIFF
```

Não são executados comandos de escrita no SGA pelo código da integração. Isso não comprova que a conta provisória da origem tenha permissões somente de leitura; ainda é recomendada uma conta própria de leitura restrita.

## 5. Organização do código

```text
app/
  bootstrap.php                  ambiente, autoload, horário e escape
  Core/Database.php              PDO, transações e auditoria
  Controllers/Web.php            rotas, sessão, autorização e exportações
  Services/
    Integracao.php               configuração cifrada e importação de compras
    Minerion.php                 adaptador e validação do escopo
    Inventario.php               triagem, incorporação e movimentações
    PatrimoniosSga.php           conciliação do imobilizado com patrimônios
    Projetos.php                cadastro, rateios, orçamento e custos
    DocumentosCompra.php        busca por RC / ordem e apontamento em lote
    Requisitantes.php           escopo de requisitantes (storage/requisitantes.json)
    CentrosCusto.php            escopo por centro de custo, filhos e limites (storage/centros-custo.json)
    Relatorios.php              relatórios de gastos por CC e período, CCs novos + sincronização
    RelatorioPdf.php            layout do relatório em PDF (logotipo, resumo, tabelas paginadas)
    Pdf.php                     gerador de PDF sem dependências (Helvetica/WinAnsi, PNG)
  Assets/logo-salinas-gold-pdf.png  cópia do logotipo oficial para o PDF (bin/gerar-logo-pdf.py)
    Periodo.php                 filtros de datas
    Parque.php                  custos e situação do parque
    Mapas.php                   uploads, áreas, vínculos e GeoJSON
    ProjectXml.php              exportação Microsoft Project MSPDI
  Views/                        telas PHP
database/
  001_estrutura.sql ... 006_mapas.sql
  queries/                      SELECTs da origem SQL Server
bin/                            instalação, operação, conversão e verificações
public/
  index.php, router.php, .htaccess
  assets/                       CSS, JS, logos e fontes
storage/                        estado privado, fora da web e do Git
evidencias/                     amostras e provas locais, fora do Git
```

O diretório publicado inclui os testes, mas não as coletas privadas exigidas por parte deles. Os pacotes Python e o banco também não acompanham o Git.

## 6. Funcionalidades implementadas

### 6.1 Acesso e perfis

- Login, saída, troca da própria senha, expiração de sessão por inatividade e bloqueio temporário de tentativas de login.
- Perfis `admin` e `operador_ti` podem realizar as operações de edição previstas.
- Perfis `gestor` e `consulta` têm consulta global, sem edição. **Não existe isolamento de registros por centro de custo implementado nesta versão.**
- Somente administrador configura e executa a sincronização pela interface.
- Senhas de usuários têm hash bcrypt. A senha do SGA é cifrada com a chave da instalação, que precisa ser preservada para restauração.

Não há cadastro completo de usuários pela interface nem SSO/Active Directory. A auditoria registra as ações previstas nos serviços, não deve ser confundida com um registro de todas as leituras.

### 6.2 Integração e compras SGA

O escopo inicial usa os requisitantes **756, 772, 316 e 1318**. A partir da revisão de 20/10/2026, o administrador pode adicionar ou remover IDs (FUNCOD) em **Integração → Parâmetros da consulta**; a lista fica em `storage/requisitantes.json` e é aplicada às consultas de compras e patrimônios pelo marcador `{{REQUISITANTES_SGA}}`. Detalhes em [Requisitantes SGA](REQUISITANTES-SGA.md). O código 772 foi confirmado para Fernando Silva; 722 está excluído. Coletas pontuais por documento podem trazer todos os itens do documento solicitado, inclusive de outros requisitantes, e não são invalidadas apenas por estarem fora dos quatro códigos na sincronização geral.

**Escopo por centro de custo (20/10/2026).** Além dos requisitantes, o administrador pode incluir centros de custo do SGA. A consulta traz a linha de compra cujo requisitante **ou** cujo CC da linha (`IPCOCC`) está configurado, com opção de incluir os CCs filhos pelo caminho `CCSTCOD`. Os limites são 300 CCs efetivos e 5.000 linhas de outros requisitantes. Detalhes, proteções e validação no SGA real estão em [Centros de custo SGA](CENTROS-CUSTO-SGA.md).

Relações principais verificadas na origem:

| Informação | Origem |
|---|---|
| Compra / linha | `ITEMPROCESSOCOMPRA.IPCOCOD` |
| Processo | `ITEMPROCESSOCOMPRA.IPCOPCO` → `PROCESSOCOMPRA.PCOCOD` |
| Requisitante | `IPCOFUNREQ` → `FUNCIONARIO.FUNCOD` |
| Produto | `IPCOITEM` → `ESTOQUE.ESTQCOD` |
| CC da compra | `IPCOCC` → `CENTROCUSTO.CCCOD` |
| NF vinculada | `FATITEMPROCESSOCOMPRA` → item de NF → NF de entrada |
| Requisição | `PENDENCIACOMPRA` e `ITEMPENDENCIACOMPRA` |
| RC → processo | `IPCOIPCOM` e/ou ponte `PCOMITEMPROCESSOCOMPRA` |

A conexão usa PDO SQL Server quando disponível; nesta máquina existe também uma ponte PowerShell/.NET SqlClient. Os segredos são enviados à ponte por entrada padrão, não por argumentos da linha de comando. A importação local é idempotente pela chave da linha SGA, com trava de sincronização e preservação de estado físico/classificações existentes.

NF positiva significa vínculo com quantidade positiva e a situação usada na consulta (`NFESIT=1`). O significado funcional completo dessa situação e as devoluções fiscais ainda precisam de homologação. Campos de quantidade da origem não comprovam entrega ou localização atual.

### 6.3 Triagem, inventário e estoque

Produtos podem ser serializados, controlados por quantidade, serviços ou não classificados. A compra é inicialmente uma origem para triagem. Serviços não geram saldo físico.

Patrimônio exige identificação e conferência antes de movimentação física. Materiais têm lote, quantidade, local e CC; quantidades fracionárias são suportadas. Incorporação, alocação, transferência, devolução, manutenção, consumo e baixa aplicam as validações próprias de cada operação. Nem todo tipo existente no enum do banco possui uma tela genérica para lançamento.

Movimentos e estado/saldo são atualizados em transação. A aplicação bloqueia saldo negativo e recebimento acima da quantidade permitida. Chaves de operação evitam repetir uma incorporação no reenvio do formulário. Histórico físico e auditoria têm proteção de imutabilidade no banco.

O centro de custo original da compra é preservado. O CC atual decorre do inventário e dos movimentos, não é inferido automaticamente da requisição.

### 6.4 Patrimônios identificados no SGA

Registros de imobilizado são conciliados com as compras e com a empresa do patrimônio. Vínculo único pode cadastrar o bem em `aguardando_conferencia`, sem criar movimento ou saldo fictício. Casos ambíguos e conflitos exigem escolha/conferência. A chave de patrimônio é composta por empresa e número.

Uma nova coleta preserva as decisões e situações físicas locais. O custo do bem prioriza o valor de aquisição do imobilizado; quando não existe, usa estimativa unitária pelo pedido/quantidade, identificada na tela. Ausência de custo não é silenciosamente tratada como um custo conhecido igual a zero.

### 6.5 Parque e custos

**Compras do escopo no Painel (20/10/2026, 10:40).** O Painel ganhou o bloco **Compras do escopo atual**, com:
- total dos pedidos não cancelados, **com ou sem patrimônio**;
- total com NF positiva;
- **compras sem patrimônio** (linhas sem ativo vinculado);
- valor dos **bens do imobilizado sem número**;
- uma tabela por origem (cada requisitante e cada centro de custo).

O filtro **Centro de custo / origem**, na barra de período, restringe às compras, aos ativos e aos bens sem número de um CC adicionado ou de um requisitante. Para CCs com filhos, a aplicação guarda a lista dos **CCs efetivos de cada entrada**, recalculada a cada sincronização. Assim o filtro é exato também nos ativos e patrimônios. Os totais de parque, compras e projetos podem representar as mesmas aquisições: **não devem ser somados**. Referência no SGA para o CC 1290: 21 linhas, 20 com NF, R$ 115.085,10 no pedido (R$ 114.845,10 com NF).

**Escopo atual × histórico (20/10/2026, 09:25).** O painel (`/`), **Patrimônios** (`/ativos`), **Compras** (`/compras`) e as pendências de associação mostram por padrão **somente** o escopo atual da consulta. Isso inclui as compras dos requisitantes e centros de custo configurados em Integração, de **qualquer categoria** (não só TI). Para ver também o que foi importado de requisitantes ou CCs removidos, escolha **Todo o histórico importado** no filtro (`?escopo=todos`). Bens sem compra de origem sempre aparecem. Projetos e rateios não são filtrados pelo escopo, porque o vínculo é explícito. O histórico nunca é apagado.

O painel separa alocados em uso, estoque/manutenção/baixados, baixados fora do total principal e aguardando conferência. O total principal do parque exclui baixados e inclui os bens conhecidos aguardando conferência, com essa cobertura explícita.

O filtro de datas não altera indevidamente o total global cadastrado do parque. Ele filtra a seção de compras/equipamentos do período e os custos dos projetos pela data do processo SGA. A situação mostrada é a atual; não é uma reconstrução histórica da posição do bem naquela data.

**Custos de parque e custos de projetos podem representar as mesmas aquisições. Não devem ser somados.** Materiais de estoque controlados por quantidade também não são automaticamente somados como equipamentos do parque.

### 6.5.1 Relatórios de gastos por centro de custo

Menu **Relatórios** (`/relatorios`). Mostra gastos por centro de custo e por período, com:
- seleção múltipla dos CCs adicionados;
- campo para **informar códigos novos**. Ao gerar, a aplicação confere todos os códigos no SGA, inclui os novos no escopo e **sincroniza com o banco do SGA** antes de montar o relatório;
- resumo sem dupla contagem;
- tabela por CC (pedido, com NF, sem patrimônio, patrimônios e bens sem número);
- evolução mensal;
- linhas de compra;
- exportação CSV e **PDF com o logotipo oficial** (A4 paisagem, paginado, gerado no servidor sem bibliotecas).

Detalhes, permissões e referência de valores em [Relatórios](RELATORIOS.md).

### 6.6 Projetos e rateio

Cadastro de código, nome, descrição, responsável, CC de referência, situação, orçamento opcional e datas previstas. O código é único. Atualizações usam versão para detectar edição concorrente.

Uma compra pode atender vários projetos, mas a soma de percentuais não pode exceder 100%. O vínculo entre compra e projeto é único e pode ser ajustado ou retirado com justificativa. Os bloqueios são feitos no servidor, em transações com locks.

```text
base do pedido = total do pedido SGA, se disponível;
                 caso contrário, quantidade solicitada × valor unitário
custo atribuído = TRUNCATE(base do pedido × percentual / 100, 2)
custo do projeto = soma dos valores atribuídos às compras vinculadas
```

O orçamento é referência gerencial e não é lançado como uma segunda despesa. O saldo do orçamento considera todo o histórico do projeto mesmo quando o custo visível está filtrado por datas. Divergências e cancelamentos de origem permanecem sinalizados para revisão, em vez de apagar silenciosamente um custo já vinculado.

Os valores representam compras gerenciais, não pagamentos, fluxo de caixa ou comprovação fiscal de despesas.

### 6.7 Coleta por RC ou ordem/processo

O usuário informa tipo, número e, opcionalmente, empresa. A busca consulta os itens diretamente no SGA e apresenta a prévia. Em caso de número de processo ambíguo, é preciso restringir a busca ou usar o código interno.

Depois da conferência, o usuário seleciona linhas e percentual para atribuir ao projeto. A prévia fica na sessão por 15 minutos e a confirmação exige token e CSRF. Os itens recebidos na confirmação são validados contra a prévia do servidor.

Consultar RC e ordem da mesma aquisição reutiliza as mesmas linhas de compra, evitando duplicação de custo. Um lote de rateios é revertido integralmente se uma linha falhar. A importação dos dados da origem é uma etapa anterior separada e pode permanecer registrada mesmo que nenhum rateio seja aplicado.

Uma RC sem pedido pode ser associada como documento, mantendo os valores estimados separados. O valor estimado da RC é exibido como registrado no SGA, sem presumir se é unitário ou total. Não é somado automaticamente ao custo dos pedidos. Linhas de um processo podem atender várias RCs; o percentual precisa ser conferido.

### 6.8 Datas

Opções: todo histórico, hoje, últimos 7 ou 30 dias, mês, ano e intervalo personalizado. Horário de referência: Cuiabá. O dia final é incluído por inteiro por meio de um limite exclusivo no dia seguinte. Datas inválidas e intervalos invertidos são rejeitados.

CSV de projeto respeita o período escolhido. A coleta de documento é uma consulta explícita independente desse filtro. A exportação Microsoft Project é sempre integral.

### 6.9 Mapas GeoTIFF e áreas

Upload de `.tif`/`.tiff` até **256 MiB**, com projeção e transformação geográfica embutidas. O original fica privado. Rasterio gera uma prévia PNG de até 2.400 pixels, reprojetada para WGS84, com as coordenadas do contorno preservadas.

O mapa permite zoom, navegação, desenho por mouse/toque ou teclado e áreas com 3 a 100 vértices. Polígonos fora da imagem, degenerados, com vértices repetidos ou bordas cruzadas são rejeitados. Nome, descrição e contorno podem ser editados. Áreas são exportáveis em GeoJSON WGS84.

Uma área pode conter patrimônios individuais, compras e projetos inteiros. O patrimônio tem um único apontamento geográfico atual; apontá-lo em outra área substitui o anterior. Projetos e compras podem estar em várias áreas. Os itens de um projeto no mapa acompanham dinamicamente os rateios atuais.

O apontamento cartográfico não executa transferência física, não altera CC e não reparte financeiramente um projeto entre áreas. Custos de projetos em áreas são referências potencialmente repetidas. O total de patrimônios apontados na área inclui baixados e identifica ausência de custo.

Limitações: não são lidos arquivos auxiliares TFW/PRJ, imagens sem georreferenciamento embutido ou somente GCP/RPC, mapas que atravessem o antimeridiano, nem arquivos acima do limite. Imagens de câmera comuns precisam ser georreferenciadas em outro software. A prévia visual não é uma análise radiométrica: bandas não byte têm contraste por percentis 2–98 e, na ausência de RGB identificado, são usadas as primeiras bandas ou cinza. Não existe base cartográfica externa nem serviço de envio dessas imagens a terceiros.

O upload e a navegação foram verificados com imagens sintéticas WGS84 e SIRGAS/UTM. Ainda não foi enviado um GeoTIFF real da mineração ou do escritório na fotografia do banco deste documento.

### 6.10 Exportações

- Patrimônios CSV, com proteção contra fórmulas de planilha.
- Custos de projeto CSV, respeitando o filtro de datas.
- GeoJSON das áreas com coordenadas e referências dos vínculos.
- TIFF original de cada mapa, após login.
- **Microsoft Project XML (MSPDI):** projeto completo, responsável/notas, datas previstas e uma linha por compra, com o custo rateado lançado como custo fixo. O resumo acumula, sem lançar o mesmo custo fixo novamente.

No Project desktop, abrir o XML como novo projeto e salvar como `.mpp`. Compras são marcos documentais de duração zero na data do processo, não etapas de execução física concluída. Não são inventadas dependências, horas de trabalho, pagamentos ou percentuais de avanço. O cronograma precisa ser organizado no Project. A exportação não sincroniza alterações de volta à aplicação.

O XML e o download foram testados. A abertura automática na instalação Microsoft Project desta máquina não pôde ser confirmada: a ativação COM falhou com `CO_E_SERVER_EXEC_FAILURE`. A exportação em si não depende do Office e não gera `.mpp` nativamente.

## 7. Banco de dados

| Migração | Conteúdo |
|---|---|
| 001 | Usuários, requisitantes, CCs, locais, produtos, compras, ativos, lotes, saldos, movimentos e auditoria |
| 002 | Complementos da aplicação, payload de origem, conciliação e controle de login |
| 003 | Projetos, compras_projetos e views de custos |
| 004 | Patrimônios SGA, chave por empresa/número e situação aguardando conferência |
| 005 | Documentos de projeto e identificação de compras coletadas pontualmente |
| 006 | Mapas, áreas e vínculos cartográficos |

Tabelas de referência: `usuarios`, `requisitantes_sga`, `centros_custo`, `locais`, `produtos`, `responsaveis`. Operação: `compras_origem`, `sincronizacoes`, `ativos`, `lotes_estoque`, `saldos_estoque`, `movimentacoes`, `auditoria`, `login_tentativas`, `projetos`, `compras_projetos`, `patrimonios_sga`, `projetos_documentos`, `mapas`, `mapas_areas`, `mapas_vinculos`. Controle de instalação: `schema_migrations`.

Views: `vw_compras_custos` e `vw_apontamentos_custos`. Valores gerenciais usam DECIMAL/BCMath e truncamento em centavos; não são somados por float na regra de negócio. Imagens ficam no sistema de arquivos, com referência e metadados no banco.

## 8. Rotas e serviços de operação

| Rota | Finalidade |
|---|---|
| `/` | Parque e custos |
| `/projetos`, `/projeto?id=...` | Lista e painel de projetos |
| `/compras` | Triagem e conferência das compras |
| `/ativos`, `/estoque` | Patrimônios e materiais |
| `/mapas`, `/mapa?id=...` | Mapas e áreas |
| `/historico` | Movimentos e auditoria |
| `/cadastros` | Locais, centros de custo e responsáveis |
| `/integracao` | Configuração e sincronização, apenas administrador |
| `/sga-requisitante`, `/sga-requisitante-remover` | Inclui/remove requisitante do escopo (POST, admin, auditoria) |
| `/sga-centro-custo`, `/sga-centro-custo-remover` | Inclui/remove centro de custo do escopo (POST, admin, auditoria) |
| `/relatorios`, `/relatorio-gerar`, `/relatorio-exportar`, `/relatorio-pdf` | Relatório por CC e período; geração com CCs novos e sincronização (POST, admin); CSV; PDF |
| `/conta` | Troca da própria senha |
| `/saude` | Saúde do acesso ao banco local |
| `/projeto-exportar`, `/projeto-project` | CSV filtrado e Project XML integral |
| `/mapa-imagem`, `/mapa-original`, `/mapa-exportar` | Prévia privada, TIFF e GeoJSON |

As alterações são feitas por POST com CSRF; os serviços são chamados pelo controlador. Arquivos da pasta `storage` não são servidos diretamente. A rota de saúde é uma verificação local simples, não uma monitoria completa da integração SGA.

## 9. Instalação e uso em outra máquina

Requisitos desta versão: Windows, XAMPP em `C:\xampp`, PHP 8.2 com PDO MySQL, BCMath, mbstring, sodium, DOM e suporte a processos; MariaDB compatível; PowerShell/.NET para a ponte SQL Server; Python 3.12 e dependências geográficas quando usar mapas.

1. Clone o repositório em diretório próprio.
2. Execute `INSTALAR-E-INICIAR.bat` ou `bin/iniciar.ps1`.
3. O instalador cria o MariaDB próprio na porta 3308, aplica migrações ainda não registradas e gera credenciais aleatórias.
4. Leia o arquivo local de acesso inicial. Ele não existe no repositório e será diferente em cada instalação.
5. Configure a origem pela interface, conecte à VPN e valide uma consulta somente de leitura.
6. Configure Python e instale `requirements-geo.txt` em `storage/geo-runtime` para usar TIFF.

O instalador usa a instância MariaDB dedicada que o script prepara; não deve ser apontado inadvertidamente para um banco de terceiros. Não copie o `.env.example` vazio sobre o ambiente instalado: o instalador gera `.env` automaticamente em instalação nova. Em instalação manual, preencha todas as variáveis corretamente, inclusive `APP_KEY` e senha do banco.

O runtime Python da máquina de origem não é portátil nem incluído no Git. Defina `MAP_PYTHON` no novo `.env` antes de usar mapas. Os scripts locais têm caminhos XAMPP e portas definidos no código; adaptar para Linux, serviço Windows, Apache/IIS/Nginx ou outro layout exige homologação específica.

O servidor PHP embutido é para testes. Ele pode ficar ocupado durante uma importação ou conversão; ainda não existe fila/worker para processamento de mapas grandes ou sincronizações.

## 10. Backup, restauração e atualização

`bin/backup.php` usa `mysqldump`, com transação consistente, rotinas e triggers, e salva em `storage/backups`. O nome atual do arquivo começa com `antes-projetos-`, mas o dump cobre o banco `salinas_ti` inteiro. Essa rotina não é um agendamento nem um backup completo dos arquivos.

Além do dump, guardar em local privado e controlado:

- `.env`, especialmente `APP_KEY`, necessário para decifrar a conexão.
- `storage/sga.json`, `storage/requisitantes.json`, `storage/centros-custo.json` (escopo da consulta) e, quando necessário para a instância local, `storage/maria-admin.json`.
- `storage/mapas` com TIFFs e PNGs.
- Configurações, versão exata do código e dependências geográficas.
- Evidências internas, se a organização decidir mantê-las.

Não copiar a pasta do MariaDB em execução como substituto de um backup consistente. Ainda não existe restauração assistida na interface. Para restaurar, homologar a recuperação do dump com triggers e dos arquivos privados em uma instalação isolada antes de substituir o ambiente em uso.

Atualizações de código devem preservar `.env`, acesso inicial, banco, mapas, logs e backups. Executar novas migrações pelo instalador após backup. Nesta entrega foram criadas cópias do código alterado em `storage/backups/codigo-*` antes de aplicar os módulos.

## 11. Testes e evidências

### 11.1 Verificações executadas no ambiente local

- Lint PHP e verificação sintática do JavaScript.
- Migrações reais MariaDB, sincronização idempotente e preservação de inventário.
- Patrimônios, alocação, transferência, devolução, manutenção e baixa.
- Quantidade fracionária, consumo, bloqueios de saldo e incorporação excedente.
- Imutabilidade do histórico e divergências de origem.
- Projetos, rateio 60/40, limite 100%, rollback de lote, edição concorrente e orçamento.
- Associação patrimonial, ambiguidades, conferência e preservação de estado local.
- Períodos rápidos, data final inclusiva, intervalos inválidos, projeto vazio e custos globais.
- RC/processo, importação pontual fora do escopo de requisitantes e não duplicação RC/ordem.
- Requisitantes configuráveis (`bin/verificar-requisitantes.php`, portátil): IDs inválidos/inexistentes, duplicidade, remoção seletiva, bloqueio do último ID e aplicação nas consultas.
- GeoTIFF WGS84 e SIRGAS/UTM, rejeição de TIFF sem coordenadas e polígonos inválidos.
- Upload HTTP multipart, sessão, CSRF, imagem privada, vínculos de projeto e exportação GeoJSON.
- Interação no navegador: desenho de três vértices por teclado e zoom.
- Microsoft Project XML: estrutura oficial, Unicode, IDs, custo total, ausência de progresso inventado, download autenticado e projeto completo independente do filtro.

As verificações completas criam bancos temporários com nomes exclusivos, não usam o banco operacional como área de teste e descartam apenas suas próprias bases. A conta web não tem DDL; as rotinas CLI de teste usam a credencial administrativa privada para criar essas bases. Elas não devem ser executadas contra uma instalação cujo estado/configuração não foi conferido.

- Centros de custo configuráveis (`bin/verificar-centros-custo.php`, portátil): sentinela sem CC, filhos por `CCSTCOD`, limite de volume sem efeito colateral, novo filho na sincronização, importação e patrimônio pelo CC, CC 0 recusado e consultas somente leitura.

### 11.2 O que o clone permite testar

`bin/verificar-publicacao.php`, `bin/verificar-requisitantes.php` e `bin/verificar-centros-custo.php` são testes portáteis sem banco nem rede. `bin/verificar-ponta-a-ponta.php` precisa só da instância MariaDB da instalação: cria um banco temporário e usa dados sintéticos, sem evidências privadas. Os testes completos dependem de arquivos internos de `evidencias/`, além da instância MariaDB privada. Esses arquivos são excluídos da publicação para não divulgar compras, patrimônios e dados da empresa.

Exemplos de evidências locais necessárias: `coleta-minerion.json`, `patrimonios-identificados.json`, `custos-imobilizado.json` e documento de processo. O teste Project usa também o XSD oficial da Microsoft; o teste de mapas usa fixtures sintéticas criadas por `bin/criar-geotiff-teste.py`. O repositório não deve alegar que esses testes completos são autossuficientes em um clone limpo.


### 11.3 Verificação geral de 20/10/2026

Executada num ambiente de análise isolado (PHP 8.3, MariaDB 10.11, Python 3.13 com Rasterio). Ali o código foi instalado do zero pelo instalador da aplicação. Nenhum dado operacional foi usado: o SGA real foi consultado somente em leitura, pelo Metabase.

| Verificação | Resultado |
|---|---|
| Lint de todos os PHP e do JavaScript | Sem erros |
| Instalador `bin/instalar.php` em banco vazio | 6 migrações aplicadas, usuário inicial gerado |
| `verificar-publicacao` | OK |
| `verificar-requisitantes` | OK |
| `verificar-centros-custo` (novo) | OK |
| `verificar-relatorio-pdf` (novo, portátil) | OK |
| `verificar-ponta-a-ponta` (novo, HTTP) | **69 a 72 verificações OK** em três cenários de escopo: padrão, 768 + CC 1290 e só CC 1290. Inclui escopo atual × histórico em ativos, compras, painel e bens sem número, e o filtro por origem: saúde, cabeçalhos CSP/nosniff, CSRF, login e bloqueio de senha errada, 14 telas, 404, escape de HTML, cadastro com auditoria, projeto, rateio 60% e bloqueio acima de 100%, custo truncado (R$ 1.800,60), triagem, CSV com BOM e proteção contra fórmula, Project XML, mapas (upload GeoTIFF, prévia PNG, área, polígono cruzado recusado, vínculo e GeoJSON), perfil consulta sem escrita e log do PHP sem avisos |
| Sincronização com escopo por CC em MariaDB | Ausência marcada só no escopo combinado; coleta por documento preservada; linha fora do escopo recusada |
| SQL novo no SGA real (Metabase) | Compras e patrimônios executaram; contagens conferidas (ver guia de CC) |
| Revisão de segurança do código | SQL dinâmico só com listas fechadas ou parâmetros; uploads com extensão, tamanho e assinatura validados; saídas HTML escapadas; erros de banco não expostos |

**Corrigido nesta revisão:**
- A sincronização validava a lista bruta de patrimônios. Agora valida a lista já filtrada pelo escopo e aborta antes de importar as compras.
- O escopo era lido a cada linha importada. Agora é lido uma vez por importação.
- `bin/coletar.php` falhava porque carregava só `Minerion.php`. Agora usa o `bootstrap`.
- O caminho padrão do Python para mapas apontava para a máquina onde o código foi gerado (`C:/Users/rafae/...`). Agora exige `MAP_PYTHON` no `.env`, que a instalação local já define.

**Não executado:** as 8 suítes completas (`verificar-fluxos`, `-projetos`, `-parque`, `-periodos`, `-patrimonios`, `-documentos`, `-mapas`, `-project`). Elas dependem de `evidencias/coleta-minerion.json`, `patrimonios-identificados.json`, `documento-processo-29968.json`, `mspdi_pj12.xsd` e `exemplo-ms-project-teste.xml`, que **não existem na instalação atual**. A pasta `evidencias` local tem apenas os 3 TIFFs sintéticos. Essas suítes também comparam totais fixos (ex.: 1.441 linhas), então só valem com a coleta original.

### 11.4 Verificação no navegador da instalação local (20/10/2026, 09:20)

Rotina adotada: ao fim de cada solicitação, a instalação `http://localhost:2288` é aberta no navegador, a sincronização **Consultar SGA e sincronizar agora** é executada e as telas são conferidas.

| Item | Resultado observado |
|---|---|
| Escopo configurado | Requisitante **768** (TULIO SOARES REIS); nenhum centro de custo |
| Sincronização | Concluída: **196 compras**; patrimônios: **3 associados, 12 ambíguos, 9 sem número** |
| Patrimônios (`/ativos`), escopo atual | **3 ativos**, todos aguardando conferência, **R$ 5.028,79** (ex.: controlador de videowall, rack outdoor) |
| Patrimônios, todo o histórico | **70 ativos**, R$ 159.717,89 (67 do escopo anterior + 3 do 768) |
| Compras (`/compras`, todas), escopo atual × histórico | **196** × **1.638** linhas |
| Painel (`/`) | Rótulo "ESCOPO ATUAL DA CONSULTA"; R$ 5.028,79; 3 bens não baixados |
| Conexão SQL Server | Funcionando **sem SSL** (configuração salva às 08:20) |

Os 12 casos ambíguos do 768 aparecem em **Pendências de associação** e precisam ser conferidos e escolhidos manualmente.

**09:38 — escopo 768 + CC 1290 (com filhos).** A sincronização funcionou nas duas execuções (09:36 e 09:39): **217 compras** (196 do 768 + 21 do CC 1290), 71 com NF; patrimônios **3 associados, 12 ambíguos, 16 sem número**. Em Patrimônios continuam 3 ativos porque **os 7 bens do imobilizado ligados ao CC 1290 estão sem número de patrimônio no SGA** (rack 19", cabos ópticos AS120 24FO, DIO 24FO, cordão óptico, cruzeta e fita de aço). Conferido no SGA: `IBAINUMPAT=0` e `IBAINUMPATST` vazio. Por regra, a aplicação não cria ativo sem número.

**09:55 — escopo só CC 1290 (768 removido pela tela, agora permitido).** Sincronização: **21 compras; patrimônios 0 associados, 0 ambíguos, 7 sem número**. Compras SGA mostra 21 linhas, cada uma com "No escopo por: centro de custo 1290". Patrimônios mostra 0 ativos no escopo e os 7 bens sem número listados.

**10:45 — escopo CC 1290 (com filhos) + CC 802, Painel com filtro.** Sincronização: **421 compras** (21 do 1290 + 400 do 802); patrimônios **18 associados, 4 ambíguos, 36 sem número**. No Painel filtrado por **CC 1290**:
- compras: **R$ 115.085,10** no pedido e **R$ 114.845,10** com NF (20 de 21 linhas), **iguais ao SGA**;
- compras sem patrimônio: R$ 115.085,10;
- bens do imobilizado sem número: **R$ 79.784,62** (7 bens);
- custo do parque: R$ 0,00, porque o CC não tem patrimônio numerado.

Os 18 ativos associados vêm do CC 802.

**10:55 — Relatórios.** **Gerar relatório** com sincronização (SGA: 421 compras no escopo). O relatório do CC 1290 em todo o histórico deu **R$ 115.085,10** no pedido e **R$ 114.845,10** com NF, **iguais ao SGA**. Seleção múltipla 1290 + 802 no **ano atual**: **R$ 158.930,37** em 86 linhas, sendo 1290 = R$ 87.305,10 (13 linhas) e 802 = R$ 71.625,27 (73 linhas; patrimônios R$ 21.111,09 em 9 bens). A soma por CC fecha com o total geral, porque não há sobreposição entre os dois.

**11:20 — PDF.** Escopo: CCs 1290 (com filhos), 802, 1337 e 9. Sincronização: **2.665 compras**; patrimônios **37 associados, 18 ambíguos, 94 sem número**. PDF do CC 1290 gerado pela instalação local:
- `application/pdf`, 145 KB, 2 páginas, arquivo íntegro e logotipo incorporado uma vez;
- valores R$ 115.085,10 (pedido) e R$ 114.845,10 (com NF), iguais ao SGA;
- rodapé "Página 1 de 2" e "Página 2 de 2".

Na transferência para o computador, o PNG do logotipo recebeu um bloco extra de metadados de procedência (`caBX`). Os dados da imagem são idênticos (IDAT conferido) e o gerador ignora esse bloco.

Ajustes feitos depois dessa verificação:
- O painel **Atualizar compras** dizia só "Consulta os requisitantes configurados: 768", o que dava a impressão de que o CC fora ignorado. Agora mostra também os CCs.
- A tela **Patrimônios** ganhou a lista **Bens do imobilizado sem número de patrimônio**, filtrada pelo escopo, com descrição, NF, valor, compra e CC do bem.

## 12. Pendências e limites de homologação

| Item | Situação atual / próximo passo |
|---|---|
| Conexão direta com SGA | As tentativas locais anteriores expiraram pela rede/VPN. Consultas e relações foram verificadas pelo Metabase. Validar a conexão da aplicação na rede definitiva. |
| Inventário físico | Os 67 bens ainda aguardavam conferência no levantamento. Confirmar situação, local, CC e responsável. |
| Cobertura do parque | Resolver ambiguidades e registros sem patrimônio e incluir bens ainda fora da coleta. |
| Projeto e compras | Projeto existe, mas ainda sem rateios na fotografia atual. Informar RC/ordem, conferir itens e confirmar vínculos. |
| Mapas reais | Enviar imagem georreferenciada da mineração/escritório e conferir a precisão das áreas. |
| Microsoft Project | Fazer abertura manual do XML e revisar o cronograma no aplicativo desktop. |
| Regras fiscais | Homologar significado dos status e tratamento de devoluções; não calcular saldo líquido fiscal por suposição. |
| Acesso por CC | A **consulta** já pode ser filtrada por CC (seção 6.2), mas o **acesso** dos usuários ainda não é isolado por CC. Planejar antes de distribuir acesso amplo. |
| Usuários e autenticação | Cadastro completo/SSO/AD e políticas organizacionais ainda não implementados. |
| Produção | Substituir servidor de teste, homologar TLS, certificados, conta SQL de leitura, serviço e processos em segundo plano. |
| Backup | Definir retenção, proteção, agendamento e ensaiar restauração do banco e imagens. |
| Testes distribuídos | As evidências privadas das 8 suítes completas não estão na instalação atual. Recuperar de um backup ou gerar dataset sintético e trocar os totais fixos por totais calculados. `verificar-ponta-a-ponta` já cobre o fluxo principal sem evidências. |
| Escopo por CC | Validar na interface com a VPN: incluir 1241, sincronizar e conferir as 110 linhas extras. Definir os CCs oficiais da TI. |
| Bens sem número no SGA | 16 bens do escopo estão no imobilizado sem número de patrimônio (7 do CC 1290, rede óptica). Pedir à Contabilidade/Patrimônio o registro dos números e sincronizar de novo. |
| Sincronização com SSL | Em 20/10 a ponte SQL Server falhou com "cadeia de certificação não confiável" e a conexão foi salva **sem SSL**. Para homologar: reativar SSL e marcar "Confiar no certificado" (teste) ou instalar a CA do servidor SQL (produção). |

Esse estado é uma base funcional para testes, não comprovação de aceite de produção. Funcionalidade implementada e teste sintético aprovado não substituem conferência da rede, dos dados físicos ou das regras da operação.

## 13. Publicação no GitHub

Repositório escolhido: **Pontesrrafael/salinas-ti**, privado. Branch inicial: `main`. O código publicado é um snapshot revisado da instalação, com documentação atualizada. A instalação operacional permanece na pasta informada; ela não é automaticamente atualizada quando alguém altera o repositório.

Excluídos: `.env`, credenciais iniciais, `storage` operacional, banco, backups, logs, sessões, mapas reais, coletas e evidências internas. Incluídos: código, migrações, consultas, scripts, documentação, logos oficiais e fontes com a licença existente. A marca continua sendo da Salinas Gold e o repositório não concede licença de software livre implicitamente.

Publicação inicial confirmada na branch `main`: commit `d5d3d5ca207e0a02831963ad7cef762c08ff4c40`. O envio foi concluído e o hash local foi comparado com `refs/heads/main` no GitHub. Esta revisão documental registra essa confirmação; os commits posteriores de documentação aparecem no histórico do repositório.

O primeiro envio contém 85 arquivos revisados. A verificação de publicação não encontrou os segredos da instalação nem arquivos operacionais na cópia preparada. O `.gitignore` mantém essas exclusões para novos commits. O teste portátil e a sintaxe PHP/JavaScript passaram antes da publicação.

## 14. Referências

- [Salinas Access Gate](https://github.com/Pontesrrafael/salinas-access-gate)
- [Microsoft — formatos de importação e exportação no Project](https://support.microsoft.com/en-us/project/export-or-import-data-to-another-file-format)
- [Microsoft — esquema Project XML](https://schemas.microsoft.com/project/2007/)
- [Rasterio — reprojeção](https://rasterio.readthedocs.io/en/stable/topics/reproject.html)
- [GDAL — GeoTIFF](https://gdal.org/en/stable/drivers/raster/gtiff.html)

Guias específicos incluídos: [Relatórios](RELATORIOS.md), [Centros de custo SGA](CENTROS-CUSTO-SGA.md), [Requisitantes SGA](REQUISITANTES-SGA.md), [Mapas e áreas](MAPAS-E-AREAS.md), [Project XML](EXPORTAR-MICROSOFT-PROJECT.md), [Filtros de datas](FILTROS-DE-DATAS.md), [Projetos](PROJETOS-E-CUSTOS.md), [Parque e custos](PAINEL-PARQUE-CUSTOS.md), [Patrimônios SGA](ATIVOS-SGA.md) e [Identidade visual](IDENTIDADE-VISUAL.md). Em caso de divergência com um guia antigo, conferir primeiro o código atual e este documento consolidado.

## 15. Histórico de alterações

| Data (UTC-4) | Alteração | Arquivos principais |
|---|---|---|
| 19/10/2026 | Publicação inicial no GitHub (`d5d3d5c`) e registro do estado (`8a9cf77`) | — |
| 20/10/2026 | Requisitantes configuráveis pela interface, opção de SSL na conexão SQL Server e marcador `{{REQUISITANTES_SGA}}` (feito na base local antes desta revisão) | `Requisitantes.php`, `Web.php`, `app.php`, consultas SQL |
| 20/10/2026 | Revisão: sincronização valida os patrimônios filtrados antes de importar; escopo lido uma vez por importação | `Integracao.php` |
| 20/10/2026 | **Escopo por centro de custo** (união com requisitantes, filhos por `CCSTCOD`, limites 300 CCs / 5.000 linhas, auditoria, tela) | `CentrosCusto.php`, `Web.php`, `app.php`, `Minerion.php`, `PatrimoniosSga.php`, `DocumentosCompra.php`, consultas SQL |
| 20/10/2026 | Verificação geral: novos testes `verificar-centros-custo` e `verificar-ponta-a-ponta`; correção de `bin/coletar.php` e do caminho fixo do Python | `bin/`, `Mapas.php` |
| 20/10/2026 09:20 | Verificação no navegador e sincronização do escopo 768: 196 compras, 3 ativos associados, 12 ambíguos, 9 sem número (seção 11.4) | — |
| 20/10/2026 11:15 | **Relatório em PDF** com o **logotipo oficial** (placa branca na faixa verde, como na interface), A4 paisagem, cartões, tabelas paginadas com cabeçalho repetido e rodapé "Página X de Y". Gerado em PHP puro, sem bibliotecas. Teste portátil `verificar-relatorio-pdf`; ponta a ponta com 69 a 72 verificações | `Pdf.php`, `RelatorioPdf.php`, `Assets/`, `gerar-logo-pdf.py`, `Web.php`, `relatorios.php`, testes |
| 20/10/2026 10:50 | **Relatórios** por centro de custo e período: seleção múltipla, CCs novos conferidos no SGA (todos antes de alterar), inclusão no escopo e sincronização antes do relatório, resumo sem dupla contagem, mensal, detalhe e CSV. Consulta SQL validada no SGA. Ponta a ponta com 68 a 70 verificações | `Relatorios.php`, `relatorios.php`, `Web.php`, `app.php`, `Parque.php`, `RELATORIOS.md`, testes |
| 20/10/2026 10:40 | **Painel com compras do escopo** (com e sem patrimônio, com NF, bens sem número, tabela por origem) e **filtro por centro de custo/origem** no Painel; CCs efetivos guardados por entrada; filtros de Compras e Painel usam a mesma definição de origem. Ponta a ponta com 60 verificações nos três cenários de escopo | `Parque.php`, `CentrosCusto.php`, `Web.php`, `parque.php`, `periodo.php`, testes |
| 20/10/2026 09:55 | **Escopo só por centro de custo**: requisitantes podem ser todos removidos se houver CC (sentinela `-1`); o último item do escopo combinado é protegido. **Compras SGA** ganhou o filtro "Origem no escopo" (por requisitante ou por CC) e a etiqueta "No escopo por" em cada linha. Teste ponta a ponta com 57 verificações nos três cenários (padrão, 768 + CC 1290, só CC 1290) | `Requisitantes.php`, `CentrosCusto.php`, `PatrimoniosSga.php`, `Integracao.php`, `Web.php`, `app.php`, testes |
| 20/10/2026 09:45 | CC 1290 sincronizado (217 compras). Painel "Atualizar compras" mostra requisitantes **e** CCs; Patrimônios lista os bens do imobilizado sem número no escopo; teste ponta a ponta com 56 verificações | `app.php`, `ativos.php`, `Web.php`, `verificar-ponta-a-ponta.php` |
| 20/10/2026 09:25 | **Telas pelo escopo atual**: painel, ativos, compras e pendências mostram só o escopo configurado (qualquer categoria), com opção "Todo o histórico importado". Sincronização não aborta mais quando o escopo não tem bens no imobilizado. Teste ponta a ponta com 53 verificações, usando o escopo real da instalação | `Parque.php`, `CentrosCusto.php`, `Integracao.php`, `Web.php`, `app.php`, `ativos.php`, `parque.php`, `verificar-ponta-a-ponta.php` |
