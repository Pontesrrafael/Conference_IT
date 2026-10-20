<?php
if(PHP_SAPI!=='cli') exit(1);
$data=json_decode(file_get_contents(dirname(__DIR__).'/storage/maria-admin.json'),true,512,JSON_THROW_ON_ERROR);
$db=new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4','root',$data['senha'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SHUTDOWN');
