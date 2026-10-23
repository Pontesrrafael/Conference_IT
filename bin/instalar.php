<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Database as DB;
use App\Services\Integracao;
$storage=ROOT.'/storage';
foreach(['','/sessions','/logs'] as $dir) if(!is_dir($storage.$dir)) mkdir($storage.$dir,0700,true);
try {
    $rootFile=$storage.'/maria-admin.json';
    $rootPass=is_file($rootFile)?json_decode((string)file_get_contents($rootFile),true,512,JSON_THROW_ON_ERROR)['senha']:'';
    $admin=new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4','root',$rootPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE DATABASE IF NOT EXISTS salinas_ti CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    if(!is_file(ROOT.'/.env')) {
        $pass=bin2hex(random_bytes(24)); $key=bin2hex(random_bytes(32));
        $admin->exec("CREATE USER IF NOT EXISTS 'salinas_ti'@'127.0.0.1' IDENTIFIED BY ".$admin->quote($pass));
        $admin->exec("ALTER USER 'salinas_ti'@'127.0.0.1' IDENTIFIED BY ".$admin->quote($pass));
        $admin->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON salinas_ti.* TO 'salinas_ti'@'127.0.0.1'");
        $env="APP_NAME=\"Salinas Gestão de Ativos\"\nAPP_URL=\"http://localhost:2288\"\nAPP_KEY=$key\nDB_HOST=127.0.0.1\nDB_PORT=3308\nDB_DATABASE=salinas_ti\nDB_USERNAME=salinas_ti\nDB_PASSWORD=$pass\n";
        if(file_put_contents(ROOT.'/.env',$env,LOCK_EX)===false) throw new RuntimeException('Falha ao gravar ambiente.');
        foreach(parse_ini_string($env,false,INI_SCANNER_RAW) as $k=>$v) putenv($k.'='.$v);
    }
    $admin->exec('USE salinas_ti');
    $admin->exec('CREATE TABLE IF NOT EXISTS schema_migrations(nome VARCHAR(100) PRIMARY KEY, aplicado_em DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    foreach(glob(ROOT.'/database/00*.sql') as $file) {
        $check=$admin->prepare('SELECT nome FROM schema_migrations WHERE nome=?'); $check->execute([basename($file)]);
        if($check->fetchColumn()) continue;
        $delimiter=';'; $buffer='';
        foreach(file($file) as $line) {
            if(preg_match('/^DELIMITER\s+(\S+)/i',trim($line),$m)) { $delimiter=$m[1]; continue; }
            $buffer.=$line;
            if(str_ends_with(rtrim($buffer),$delimiter)) {
                $statement=substr(rtrim($buffer),0,-strlen($delimiter));
                if(trim($statement)!=='') $admin->exec($statement);
                $buffer='';
            }
        }
        $admin->prepare('INSERT INTO schema_migrations(nome) VALUES(?)')->execute([basename($file)]);
        echo 'Migracao aplicada: '.basename($file).PHP_EOL;
    }
    if(!(int)DB::one('SELECT COUNT(*) n FROM usuarios')['n']) {
        $pw='SG-'.bin2hex(random_bytes(8)).'!';
        DB::run("INSERT INTO usuarios(nome,email,senha_hash,perfil) VALUES('Administrador TI','admin@salinas.local',?,'admin')",[password_hash($pw,PASSWORD_BCRYPT,['cost'=>12])]);
        file_put_contents(ROOT.'/ACESSO-LOCAL.txt',"SALINAS GESTAO DE ATIVOS - TESTE LOCAL\r\n\r\nEndereco: http://localhost:2288\r\nUsuario: admin@salinas.local\r\nSenha inicial: $pw\r\n\r\nTroque a senha em Minha conta apos entrar.\r\nEste arquivo nao acompanha o Git.\r\n");
        DB::run("INSERT INTO locais(nome,tipo) VALUES('Estoque TI - conferir local','almoxarifado')");
    }
    if(!(int)DB::one('SELECT COUNT(*) n FROM compras_origem')['n'] && is_file(ROOT.'/evidencias/coleta-minerion.json')) {
        $rows=json_decode((string)file_get_contents(ROOT.'/evidencias/coleta-minerion.json'),true,512,JSON_THROW_ON_ERROR)['linhas'];
        $n=Integracao::importar($rows,'evidencia',null); echo "$n linhas da coleta inicial importadas para triagem.".PHP_EOL;
    }
    if($rootPass==='') {
        $new=bin2hex(random_bytes(32));
        // Gravar antes de trocar para permitir retomada caso haja interrupção.
        file_put_contents($rootFile,json_encode(['senha'=>$new]));
        foreach($admin->query("SELECT User,Host FROM mysql.user WHERE User='root'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $admin->exec('ALTER USER '.$admin->quote($row['User']).'@'.$admin->quote($row['Host']).' IDENTIFIED BY '.$admin->quote($new));
        }
    }
    echo 'Instalacao verificada. Acesso: http://localhost:2288'.PHP_EOL;
} catch(Throwable $e) {
    // Log detalhado local sem senha, para diagnóstico de migração.
    file_put_contents($storage.'/logs/instalacao.log',date('c').' '.get_class($e).' '.$e->getCode().' '.$e->getMessage().PHP_EOL,FILE_APPEND);
    fwrite(STDERR,'Falha na instalacao. Consulte storage/logs/instalacao.log.'.PHP_EOL); exit(1);
}
