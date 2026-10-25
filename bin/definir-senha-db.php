<?php
declare(strict_types=1);
/*
 * Define ou troca a senha do usuário do banco da aplicação (salinas_ti@127.0.0.1) com segurança.
 *
 * Uso (no Windows, com a instância MariaDB da aplicação rodando):
 *   C:\xampp\php\php.exe bin\definir-senha-db.php                 gera uma senha forte (64 caracteres)
 *   C:\xampp\php\php.exe bin\definir-senha-db.php --informar      digita a senha (16 a 100 caracteres; letras, números e ! @ # % ^ * _ + = . , : -)
 *   acrescente --mostrar para exibir a nova senha uma única vez (ex.: cadastrar no HeidiSQL ou no gerenciador de senhas)
 *
 * Passos: confere a senha atual do .env, faz backup do .env em storage/backups, grava o .env novo em arquivo temporário,
 * altera a senha no MariaDB (ALTER USER), troca o .env e testa a conexão. Se algo falhar depois do ALTER USER, a senha
 * anterior é restaurada. A troca fica na auditoria, sem a senha. Depois, reinicie a aplicação (parar.ps1 e iniciar.ps1).
 */
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$args=array_slice($argv,1);
$falhar=static function(string $msg): never { fwrite(STDERR,'FALHOU: '.$msg.PHP_EOL); exit(1); };
foreach($args as $a) if(!in_array($a,['--informar','--mostrar'],true)) $falhar('Opção desconhecida: '.$a.'. Use --informar e/ou --mostrar.');
$envFile=ROOT.'/.env';
if(!is_file($envFile)) $falhar('Arquivo .env não encontrado. Rode o iniciar.ps1 (instalar.php) primeiro.');
$env=(string)file_get_contents($envFile);
$cfg=parse_ini_string($env,false,INI_SCANNER_RAW);
$usuario=(string)($cfg['DB_USERNAME']??''); $atual=(string)($cfg['DB_PASSWORD']??''); $host=(string)($cfg['DB_HOST']??'127.0.0.1'); $porta=(string)($cfg['DB_PORT']??'3308'); $banco=(string)($cfg['DB_DATABASE']??'salinas_ti');
if(!preg_match('/^[a-z0-9_]{1,32}$/',$usuario)) $falhar('DB_USERNAME ausente ou inválido no .env.');
if(!preg_match('/^(127\.0\.0\.1|localhost)$/',$host)) $falhar('Este script só altera o banco local da aplicação (DB_HOST 127.0.0.1).');

// 1. Nova senha: gerada (padrão) ou informada.
if(in_array('--informar',$args,true)) {
    fwrite(STDOUT,'Nova senha (o texto aparece na tela; confira se ninguém está olhando): ');
    $nova=trim((string)fgets(STDIN));
    fwrite(STDOUT,'Repita a nova senha: '); $rep=trim((string)fgets(STDIN));
    if(!hash_equals($nova,$rep)) $falhar('As senhas não conferem. Nada foi alterado.');
    if(!preg_match('/^[A-Za-z0-9!@#%^*_+=.,:\-]{16,100}$/',$nova)) $falhar('Use de 16 a 100 caracteres: letras, números e ! @ # % ^ * _ + = . , : - (sem espaço, aspas, ponto e vírgula ou cifrão). Nada foi alterado.');
    if(!preg_match('/[A-Z]/',$nova) || !preg_match('/[a-z]/',$nova) || !preg_match('/\d/',$nova)) $falhar('Inclua letras maiúsculas, minúsculas e números. Nada foi alterado.');
} else $nova=bin2hex(random_bytes(32));
if(hash_equals($atual,$nova)) $falhar('A nova senha é igual à atual. Nada foi alterado.');

// 2. Conexões: a atual do .env precisa funcionar; o root vem de storage/maria-admin.json (criado pelo instalar.php).
try { new PDO("mysql:host=$host;port=$porta;dbname=$banco;charset=utf8mb4",$usuario,$atual,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(PDOException) { $falhar('A senha atual do .env não conecta. Confira se o MariaDB da aplicação está rodando (iniciar.ps1). Nada foi alterado.'); }
$rootFile=ROOT.'/storage/maria-admin.json';
if(!is_file($rootFile)) $falhar('storage/maria-admin.json não encontrado.');
try { $root=new PDO("mysql:host=127.0.0.1;port=$porta;charset=utf8mb4",'root',json_decode((string)file_get_contents($rootFile),true,512,JSON_THROW_ON_ERROR)['senha'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
catch(Throwable) { $falhar('Não foi possível conectar como administrador do MariaDB. Nada foi alterado.'); }
$contas=$root->prepare('SELECT Host FROM mysql.user WHERE User=?'); $contas->execute([$usuario]); $hosts=$contas->fetchAll(PDO::FETCH_COLUMN);
if(!$hosts) $falhar("Usuário $usuario não existe no MariaDB. Nada foi alterado.");

// 3. Backup do .env e arquivo novo preparado antes de mexer no banco.
$bkDir=ROOT.'/storage/backups'; if(!is_dir($bkDir)) mkdir($bkDir,0700,true);
$bk=$bkDir.'/env-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.bak';
if(!copy($envFile,$bk)) $falhar('Não foi possível fazer o backup do .env. Nada foi alterado.');
$novoEnv=preg_replace('/^DB_PASSWORD=.*$/m','DB_PASSWORD='.$nova,$env,1,$n);
if($n!==1) $falhar('Linha DB_PASSWORD não encontrada no .env. Nada foi alterado.');
$tmp=$envFile.'.novo'; if(file_put_contents($tmp,$novoEnv,LOCK_EX)===false) $falhar('Não foi possível preparar o novo .env. Nada foi alterado.');

// 4. Troca no MariaDB, troca do .env e teste. Qualquer falha depois do ALTER USER restaura a senha anterior.
$alterar=static function(string $senha) use($root,$usuario,$hosts): void { foreach($hosts as $h) $root->exec('ALTER USER '.$root->quote($usuario).'@'.$root->quote((string)$h).' IDENTIFIED BY '.$root->quote($senha)); };
try {
    $alterar($nova);
    if(!rename($tmp,$envFile)) throw new RuntimeException('Não foi possível substituir o .env.');
    new PDO("mysql:host=$host;port=$porta;dbname=$banco;charset=utf8mb4",$usuario,$nova,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(Throwable $e) {
    try { $alterar($atual); } catch(Throwable) {}
    @copy($bk,$envFile); @unlink($tmp);
    $falhar('A troca não foi concluída e a senha anterior foi restaurada ('.($e instanceof RuntimeException?$e->getMessage():'erro do banco').').');
}
try {
    $db=new PDO("mysql:host=$host;port=$porta;dbname=$banco;charset=utf8mb4",$usuario,$nova,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->prepare('INSERT INTO auditoria(entidade,entidade_id,acao,antes_json,depois_json,autor_id) VALUES(?,?,?,?,?,NULL)')->execute(['banco_usuario',$usuario,'trocar_senha',null,json_encode(['origem'=>'bin/definir-senha-db.php','hosts'=>$hosts,'backup_env'=>basename($bk)])]);
} catch(Throwable) { fwrite(STDERR,"Aviso: senha trocada, mas o registro na auditoria falhou.\n"); }
echo "OK: senha do usuário $usuario trocada no MariaDB e no .env (backup em storage/backups/".basename($bk).").\n";
if(in_array('--mostrar',$args,true)) echo "Nova senha (exibida uma única vez; guarde no gerenciador de senhas): $nova\n";
echo "Reinicie a aplicação para o servidor web usar a nova senha: parar.ps1 e depois iniciar.ps1.\n";
echo "O backup do .env contém a senha antiga: apague-o quando não precisar mais (fica fora da web e do Git).\n";
