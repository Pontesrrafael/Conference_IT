<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$dir=ROOT.'/storage/backups'; if(!is_dir($dir)) mkdir($dir,0700,true);
$root=json_decode((string)file_get_contents(ROOT.'/storage/maria-admin.json'),true,512,JSON_THROW_ON_ERROR);
$file=$dir.'/antes-projetos-'.date('Ymd-His').'.sql';
$proc=proc_open(['C:/xampp/mysql/bin/mysqldump.exe','--host=127.0.0.1','--port=3308','--user=root','--single-transaction','--routines','--triggers','--default-character-set=utf8mb4','salinas_ti'],[0=>['pipe','r'],1=>['file',$file,'w'],2=>['pipe','w']],$pipes,ROOT,['MYSQL_PWD'=>$root['senha'],'SystemRoot'=>(string)getenv('SystemRoot')],['bypass_shell'=>true]);
if(!is_resource($proc)) exit(1);
fclose($pipes[0]); $error=stream_get_contents($pipes[2]); fclose($pipes[2]); $code=proc_close($proc);
if($code!==0 || filesize($file)<100) { fwrite(STDERR,'Falha no backup; migração não deve prosseguir.'.PHP_EOL); exit(1); }
echo 'Backup salvo: '.basename($file).PHP_EOL;
