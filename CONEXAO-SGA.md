# Conexão com o SGA (Minerion) — diagnóstico

Guia para resolver a falha "Falha ao consultar o SGA. Confira VPN, servidor, banco, credenciais e certificado." e outros erros da guia **Integração SGA**, tanto no Windows quanto no servidor Linux (Virtualmin).

## Como a aplicação conecta

- **Servidor:** `dbsalinas.c7i0siuee3bb.us-east-1.rds.amazonaws.com` (SQL Server 2019 em AWS RDS), porta **1433**, banco `Salinas`.
- **Configuração:** a guia Integração grava `storage/sga.json`. A senha fica cifrada (libsodium) com o `APP_KEY` do `.env` daquela instalação.
- **Código:** `app/Services/Integracao.php`, em `executarSql()`, tenta os drivers nesta ordem e usa o primeiro disponível:
  1. PDO `sqlsrv` (Microsoft);
  2. PDO `dblib` (FreeTDS, pacote `php-sybase`/`php8.2-sybase`, geralmente o mais fácil de instalar no Linux);
  3. PDO `odbc` com "ODBC Driver 18/17 for SQL Server" ou FreeTDS;
  4. só no Windows, a ponte `bin/sga-consultar.ps1` (System.Data.SqlClient).
- **Novas tentativas:** falhas de rede e DNS são repetidas até 3 vezes (espera de 1 s e 3 s). Senha, certificado e erro de SQL não são repetidos.
- **Erros:** cada falha informa a **etapa** (`dns`, `rede`, `driver`, `senha`, `tls`, `login`, `consulta`) e o detalhe técnico, sem a senha. Os registros ficam em:
  - `storage/logs/sga-conexao.log`: cada tentativa que falhou;
  - `storage/logs/php.log`: a mensagem completa e a causa de origem, por rota;
  - `storage/bridge-error.log`: os erros da ponte Windows;
  - a tabela "Últimas execuções" da guia Integração.

## Parâmetros da conexão

Em **Integração SGA → Parâmetros avançados**:

| Campo | Padrão | Uso |
|---|---|---|
| Porta | 1433 | porta do SQL Server; `servidor,porta` antigo é convertido sozinho |
| IP fixo | vazio | conecta neste IP em vez do nome; use quando o DNS do servidor não resolve o RDS para o IP interno |
| Tempo limite de login | 30 s | aumente em rotas lentas por VPN |
| Tempo limite da consulta | 120 s | as consultas reais levam de 6 s a 9 s |
| Criptografia | sim | TLS obrigatório; `não` só para teste |
| Arquivo CA | vazio | bundle da AWS para o FreeTDS validar o certificado (`ca_arquivo`) |
| Confiar no certificado | desmarcado | aceita o certificado sem validar a CA |

## Testar conexão por etapas

- **Na tela:** guia Integração, botão **Testar conexão por etapas**.
- **No terminal:** `php bin/sincronizar-sga.php --diagnostico`.

As etapas rodam em ordem e o teste para na primeira que falhar:
1. configuração;
2. sodium;
3. senha;
4. DNS, mostrando os IPs resolvidos;
5. TCP na porta, com limite de 5 s;
6. drivers;
7. login com `SELECT 1`;
8. versão.

Saída na estação Windows:

```text
dns        ok        90ms  dbsalinas...rds.amazonaws.com → 172.16.0.243
rede       ok       154ms  TCP 1433 acessível (limite de 5 s).
driver     ok         1ms  Disponíveis: powershell. Selecionado: powershell.
login      ok      1394ms  Login e SELECT 1 concluídos.
```

## Sincronização agendada (cron)

Prefira o cron ao botão da tela, porque ele não depende dos limites do PHP-FPM:

```bash
*/30 * * * * cd /home/<dominio>/<pasta-da-aplicacao> && php bin/sincronizar-sga.php >> storage/logs/cron-sga.log 2>&1
```

Como ele se comporta:
- uma trava (`storage/sincronizar-sga.lock`) impede duas execuções ao mesmo tempo;
- o código de saída é 0 quando dá certo e 1 quando falha;
- o resumo e as falhas vão para `storage/logs/sincronizar-sga.log`.

Use o mesmo PHP do site; no Virtualmin, por exemplo, `/usr/bin/php8.2`.

## Plano B: exportar numa máquina e importar no servidor

Quando o servidor não alcança o RDS, uma máquina que alcança (a estação Windows, por exemplo) gera o arquivo:

```bash
php bin/exportar-sga.php sga-export.json      # ou: Integração → Exportar dados do SGA (.json)
```

No servidor, use **Integração → Importar arquivo**. Validações antes de gravar qualquer coisa:
- formato e versão do arquivo;
- `sha256` dos dados (recusa arquivo alterado ou incompleto);
- tamanho de até 50 MiB;
- data de geração: recusa arquivo **mais antigo que a última carga concluída**.

Compras e patrimônios entram na mesma transação: ou entram os dois, ou nada muda.

## Salvaguardas de dados

- **Fotografia completa:** a sincronização direta e a importação por arquivo marcam como ausentes (`origem_presente=0`, conciliação pendente) as compras e os patrimônios que não voltaram.
- **Consulta parcial:** se a origem devolver menos da metade da base presente (com 100 ou mais registros), a carga é abortada e nada muda. Isso protege contra queda no meio da consulta ou filtro errado.
- **Atomicidade:** compras e patrimônios entram na mesma transação, e uma falha desfaz os dois. A falha fica registrada em "Últimas execuções".
- **Concorrência:** travas `GET_LOCK` no MariaDB e `flock` no cron.

## Resultado na estação Windows (04/11/2026, funcionando)

| Verificação | Resultado |
|---|---|
| DNS | `172.16.0.243`, **IP privado**: só é alcançável pela VPN ou de dentro da rede da AWS |
| TCP 1433 | aberto |
| Driver PHP | sem `pdo_sqlsrv`; usa a ponte PowerShell |
| `SELECT @@VERSION` | SQL Server 2019 Web Edition (15.0.4382.1) |
| Consultas reais | compras: 1474 linhas em 6 s; patrimônios: 2218 linhas em 9 s |
| Sincronização pela tela | ok |

Os erros vistos antes no log eram de tentativas **anteriores a salvar a conexão** ("Configure a conexão ao Minerion primeiro.").

## Resultado no Virtualmin (04/11/2026, falhando por rede)

No servidor (PHP 8.3 CGI, com `pdo_sqlsrv` e ODBC Driver 18 carregados):
- **DNS:** ok, resolve para o IP privado do RDS;
- **TCP 1433:** `113 — No route to host`, em três tentativas;
- **Resposta do gateway:** ICMP tipo 3, código 1 (destino inacessível);
- **PDO:** `HYT00 Login timeout expired`. Isso é consequência da falha de rede, não erro de senha.

Uma estação da mesma rede alcança o RDS. **A correção é de infraestrutura:** liberar o fluxo do IP do servidor até o RDS na porta 1433 no gateway ou VPN (rota, túnel ou política) e conferir o retorno. Trocar a senha, desativar TLS ou reinstalar o driver não resolve. Enquanto a rede não for liberada, use o **plano B** (exportar na estação e importar no servidor).

Atenção: a instalação do servidor tem código próprio, que não está no repositório. Compare e versione essas diferenças antes de atualizar o servidor com a `main`; não sobrescreva a instalação inteira.

## Checklist no servidor Linux (Virtualmin)

Rode os comandos no servidor, como o usuário do domínio quando indicado. Pare no primeiro item que falhar.

### 1. DNS e rede

```bash
getent hosts dbsalinas.c7i0siuee3bb.us-east-1.rds.amazonaws.com
nc -zv -w 5 dbsalinas.c7i0siuee3bb.us-east-1.rds.amazonaws.com 1433
```

- O nome precisa resolver. Se resolver para `172.16.x.x`, o servidor precisa estar na mesma VPC ou ter rota ou VPN até ela.
- Se `nc` der timeout:
  - libere a porta 1433 no **Security Group** do RDS para o IP privado do servidor (ou para o IP público dele, se o RDS for acessível publicamente);
  - confira o firewall local (`firewalld`/`ufw`/`iptables`) para saída na porta 1433.
- O Metabase funcionar "na mesma rede" não prova nada para o servidor Virtualmin. O que vale é este `nc` executado **no próprio servidor**.

### 2. Driver SQL Server no PHP **usado pelo site**

O Virtualmin costuma rodar o PHP do domínio via PHP-FPM ou FCGI, numa versão que pode ser diferente da que `php` mostra no terminal.

```bash
php -v; php -m | grep -Ei 'sqlsrv|sodium|pdo'
ls /etc/php/*/fpm/pool.d/ 2>/dev/null; ls /etc/opt/remi/*/php-fpm.d/ 2>/dev/null   # descobrir a versão do domínio
```

Para confirmar o que o site carrega, crie temporariamente `public/info-sqlsrv.php` com `<?php echo implode(',', PDO::getAvailableDrivers());`, abra no navegador e **apague o arquivo em seguida**. A lista precisa conter `sqlsrv`.

Se faltar o driver, instale o ODBC Driver 18 da Microsoft e as extensões PECL para a **mesma versão do PHP-FPM do site**.

Debian/Ubuntu:

```bash
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | sudo gpg --dearmor -o /usr/share/keyrings/microsoft.gpg
# Adicione o repositório da sua versão (ver https://learn.microsoft.com/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server)
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev
sudo apt-get install -y php8.2-dev php-pear
sudo pecl -d php_suffix=8.2 install sqlsrv pdo_sqlsrv
printf 'extension=sqlsrv.so\n' | sudo tee /etc/php/8.2/mods-available/sqlsrv.ini
printf 'extension=pdo_sqlsrv.so\n' | sudo tee /etc/php/8.2/mods-available/pdo_sqlsrv.ini
sudo phpenmod -v 8.2 sqlsrv pdo_sqlsrv
sudo systemctl restart php8.2-fpm
```

RHEL/Alma/Rocky: `ACCEPT_EULA=Y dnf install msodbcsql18 unixODBC-devel`, depois `pecl install sqlsrv pdo_sqlsrv` para o PHP do site, habilitando os módulos no `php.d` correspondente.

A extensão `sodium` também é obrigatória (decifra a senha).

### 3. Certificado TLS

O ODBC Driver 18 exige criptografia e valida o certificado. Com o RDS há duas saídas:

- marcar **"Confiar no certificado do servidor de teste"** na guia Integração e salvar de novo; ou
- instalar o bundle de CA da AWS (`https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem`) no repositório de certificados do sistema e manter a validação.

Mensagens como `SSL Provider: certificate verify failed` indicam este item.

### 4. Senha cifrada e `APP_KEY`

Se `storage/sga.json` foi **copiado** de outra máquina, a senha foi cifrada com outro `APP_KEY`, e o erro é "Não foi possível ler a senha armazenada.". Nesse caso, abra a guia Integração **no servidor**, informe a senha de novo e clique em **Salvar conexão**. Não copie o `.env` nem o `sga.json` entre instalações.

Confira também as permissões: o usuário do PHP-FPM do domínio precisa ler `.env` e ler e gravar em `storage/`.

### 5. Teste isolado pelo terminal

Na raiz da aplicação, como o usuário do domínio:

```bash
php bin/sincronizar-sga.php --diagnostico
```

A saída mostra a mensagem real (rede, login, certificado ou driver). Se funcionar no terminal e falhar no site, a diferença está no PHP-FPM do domínio: versão, extensões ou usuário (volte ao item 2).

## Erros comuns

| Mensagem | Causa provável | Item |
|---|---|---|
| Configure a conexão ao Minerion primeiro. | `storage/sga.json` ausente | salvar a conexão na tela |
| Nenhum driver SQL Server disponível. (etapa: driver) | Linux sem `sqlsrv`, `dblib` ou `odbc` | 2 |
| ...(etapa: rede) | Security Group, VPN, firewall ou rota | 1 |
| ...(etapa: dns) | DNS do servidor não resolve o RDS | 1, ou usar IP fixo |
| ...menos da metade ... consulta parcial | origem devolveu poucos registros | conferir a consulta e repetir |
| Arquivo gerado em ..., anterior à última carga | arquivo antigo | exportar de novo |
| Não foi possível ler a senha armazenada. | `APP_KEY` diferente ou `sga.json` copiado | 4 |
| Login timeout expired / TCP Provider | rede, Security Group ou VPN | 1 |
| certificate verify failed | TLS do ODBC 18 | 3 |
| Login failed for user | usuário ou senha | salvar a conexão de novo |
