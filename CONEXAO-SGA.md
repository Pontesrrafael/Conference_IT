# Conexão com o SGA (Minerion) — diagnóstico

Guia para resolver a falha "Falha ao consultar o SGA. Confira VPN, servidor, banco, credenciais e certificado." e outros erros da guia **Integração SGA**, tanto no Windows quanto no servidor Linux (Virtualmin).

## Como a aplicação conecta

- **Servidor:** `dbsalinas.c7i0siuee3bb.us-east-1.rds.amazonaws.com` (SQL Server 2019 em AWS RDS), porta **1433**, banco `Salinas`.
- **Configuração:** a guia Integração grava `storage/sga.json`. A senha fica cifrada (libsodium) com o `APP_KEY` do `.env` daquela instalação.
- **Código:** `app/Services/Integracao.php`, em `executarSql()`:
  1. Se o PHP tem o driver PDO `sqlsrv`, conecta direto, com `Encrypt=yes` e `TrustServerCertificate` conforme a opção "Confiar no certificado".
  2. Sem o driver, **só no Windows**, usa a ponte `bin/sga-consultar.ps1` (System.Data.SqlClient). No Linux não existe ponte, e o erro é "Habilite pdo_sqlsrv neste PHP.".
- **Erros:** o `storage/logs/php.log` registra só a classe do erro (`RuntimeException codigo 0`), sem a mensagem. A mensagem real aparece na tela, em vermelho, depois da ação. Os erros da ponte Windows vão para `storage/bridge-error.log`.

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
php -r 'require "app/bootstrap.php"; try { var_dump(App\Services\Integracao::executarSql("SELECT 1 AS ok")); } catch (Throwable $e) { for ($x=$e; $x; $x=$x->getPrevious()) echo get_class($x), ": ", $x->getMessage(), PHP_EOL; }'
```

A saída mostra a mensagem real (rede, login, certificado ou driver). Se funcionar no terminal e falhar no site, a diferença está no PHP-FPM do domínio: versão, extensões ou usuário (volte ao item 2).

## Erros comuns

| Mensagem | Causa provável | Item |
|---|---|---|
| Configure a conexão ao Minerion primeiro. | `storage/sga.json` ausente | salvar a conexão na tela |
| Habilite pdo_sqlsrv neste PHP. | Linux sem driver | 2 |
| Não foi possível ler a senha armazenada. | `APP_KEY` diferente ou `sga.json` copiado | 4 |
| Login timeout expired / TCP Provider | rede, Security Group ou VPN | 1 |
| certificate verify failed | TLS do ODBC 18 | 3 |
| Login failed for user | usuário ou senha | salvar a conexão de novo |
