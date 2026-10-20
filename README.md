# Salinas TI — parque de TI, projetos e áreas

Aplicação local em **PHP 8.2 e MariaDB**, baseada no padrão do [Salinas Access Gate](https://github.com/Pontesrrafael/salinas-access-gate), para acompanhar o parque de TI, compras do Minerion SGA, custos de projetos e apontamentos sobre mapas GeoTIFF.

**Estado:** versão funcional para testes e homologação local. A instalação atual está em `D:\Apps_SG\Est_Sg`, com acesso em **http://localhost:2288** e MariaDB separado na porta **3308**. Ainda não é uma implantação homologada para produção.

## Documentação completa

Leia [DOCUMENTACAO-COMPLETA.md](DOCUMENTACAO-COMPLETA.md) para saber onde o projeto está instalado, arquitetura, módulos, banco de dados, regras de custos, operação, testes executados e pendências atuais. O documento é a referência consolidada do estado da aplicação.

## Funcionalidades implementadas

- Login, perfis, sessão, CSRF e auditoria.
- Consulta e importação de compras do SQL Server do Minerion, com triagem e conferência física.
- Patrimônios, identificação no imobilizado SGA, resolução de ambiguidades e movimentações.
- Materiais por lote/local/centro de custo, transferência, consumo e baixa.
- Painel do parque com custo de aquisição e separação de equipamentos em uso, fora de uso e aguardando conferência.
- Projetos, orçamento, rateio de compras até 100%, custos e filtros de datas.
- Coleta por Requisição de Compra ou Ordem/processo, com conferência dos itens antes de atribuir custos.
- Exportação CSV e Microsoft Project XML, que pode ser aberto no Project desktop e salvo como `.mpp`.
- Upload privado de GeoTIFF até 256 MiB, desenho de áreas, vínculos de patrimônios/compras/projetos e exportação GeoJSON.
- Identidade visual Salinas Gold, com logos e fontes locais.

## Executar no Windows

1. Instale o XAMPP compatível com PHP 8.2 e MariaDB em `C:\xampp`.
2. Clone este repositório em uma pasta própria, por exemplo `D:\Apps_SG\Est_Sg`.
3. Execute `INSTALAR-E-INICIAR.bat`. O instalador prepara um MariaDB exclusivo em `storage/mariadb`, aplica as migrações e gera o ambiente e o acesso inicial.
4. Abra http://localhost:2288 e consulte `ACESSO-LOCAL.txt` **gerado nessa instalação**. Troque a senha em Minha conta.
5. Configure o SGA na guia Integração Minerion e confira o acesso pela VPN. Credenciais não fazem parte do repositório.

O exemplo `.env.example` é uma referência para instalação manual, não deve substituir o `.env` gerado. `INICIAR.bat` retoma a instalação e `PARAR.bat` encerra somente os processos registrados desta aplicação.

Para GeoTIFF, disponibilize Python 3.12 e instale as dependências privadas:

```powershell
& 'C:\caminho\python.exe' -m pip install --only-binary=:all: --target storage/geo-runtime -r requirements-geo.txt
```

Configure `MAP_PYTHON` no `.env` com o caminho do executável. Na máquina atual já existe Python e o leitor já está instalado; o runtime não é publicado no Git.

## Verificação

```powershell
& C:\xampp\php\php.exe bin/verificar-publicacao.php
```

Essa verificação é portátil e não acessa banco, SGA ou dados internos. Os testes completos `verificar-*.php` usam MariaDB temporário e evidências privadas locais, ausentes do Git; veja a documentação antes de executá-los. Consulte também os guias [Mapas](MAPAS-E-AREAS.md) e [Microsoft Project](EXPORTAR-MICROSOFT-PROJECT.md).

## Dados e segurança

O repositório contém código, migrações, consultas SQL, marca e documentação. Não contém `.env`, senhas, banco operacional, sessões, logs, backups, coletas de compras, mapas reais nem evidências com dados internos. A pasta `public/` é o único DocumentRoot.

O histórico local não representa um inventário físico completo da empresa. Compras não são incorporadas automaticamente ao estoque; custos de parque e projetos podem se sobrepor e não devem ser somados. Mudanças no Microsoft Project não voltam automaticamente à aplicação.

Uso interno Salinas Gold. Os recursos de marca e a fonte Author mantêm seus direitos e licenças originais. A publicação não concede licença aberta sobre o projeto ou sobre a identidade visual.


## Parâmetros de requisitantes

Veja [Requisitantes SGA](REQUISITANTES-SGA.md) para adicionar IDs pela interface e incluí-los nas consultas de compras e patrimônios.

Veja [Centros de custo SGA](CENTROS-CUSTO-SGA.md) para incluir centros de custo do SGA (com ou sem filhos) no escopo da consulta.

Verificação rápida sem dados internos: `php bin/verificar-publicacao.php`, `php bin/verificar-requisitantes.php` e `php bin/verificar-centros-custo.php`. Com o MariaDB da instalação ativo: `php bin/verificar-ponta-a-ponta.php`.
