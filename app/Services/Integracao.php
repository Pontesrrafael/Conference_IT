<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class Integracao {
    private static function padroes(): array {
        return ['servidor'=>'','banco'=>'','usuario'=>'','porta'=>1433,'ip'=>'','timeout_login'=>30,'timeout_consulta'=>120,'criptografia'=>'sim','ca_arquivo'=>'','confiar_certificado'=>false];
    }
    public static function config(bool $secrets=false): array {
        $file=ROOT.'/storage/sga.json';
        try {
            $raw=is_file($file)?file_get_contents($file):'{}';
            if($raw===false) throw new RuntimeException();
            $stored=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($stored)) throw new RuntimeException();
        } catch(\Throwable $e) {
            throw new SgaFalha('login','Não foi possível ler a configuração do SGA.','Verifique storage/sga.json, seu formato JSON e as permissões de leitura.');
        }
        // Lista explícita: nem senha em texto nem campos desconhecidos chegam à tela.
        $c=array_replace(self::padroes(),array_intersect_key($stored,self::padroes()));
        $c=self::validarConfig($c);
        if($secrets && isset($stored['senha_cifrada'])) {
            try {
                $key=self::chave();
                $cipher=base64_decode((string)$stored['senha_cifrada'],true);
                if($cipher===false || strlen($cipher)<40) throw new RuntimeException();
                $pass=sodium_crypto_secretbox_open(substr($cipher,24),substr($cipher,0,24),$key);
                if($pass===false) throw new RuntimeException();
                $c['senha']=$pass;
            } catch(\Throwable $e) {
                throw new SgaFalha('senha','Não foi possível ler a senha armazenada.','Confira sodium e APP_KEY nesta instalação; informe a senha novamente e salve a conexão.');
            }
        }
        return $c;
    }
    private static function chave(): string {
        if(!function_exists('sodium_crypto_secretbox')) throw new SgaFalha('senha','Extensão sodium indisponível.','Habilite sodium no PHP usado pela aplicação.');
        $hex=(string)getenv('APP_KEY');
        if(strlen($hex)!==64 || !ctype_xdigit($hex)) throw new SgaFalha('senha','APP_KEY inválida.','A chave deve conter 64 caracteres hexadecimais.');
        return hex2bin($hex);
    }
    private static function validarConfig(array $c): array {
        // Compatibilidade: configurações antigas gravavam "host,porta" no campo servidor.
        if(is_string($c['servidor']) && preg_match('/^\s*([^,\s]+)\s*,\s*(\d{1,5})\s*$/',$c['servidor'],$m)) { $c['servidor']=$m[1]; $c['porta']=(int)$m[2]; }
        foreach(['servidor'=>200,'banco'=>128,'usuario'=>128,'ip'=>45,'ca_arquivo'=>1024] as $key=>$max) {
            if(!is_string($c[$key]) || strlen($c[$key])>$max || preg_match('/[\x00-\x1f\x7f]/',$c[$key])) throw new SgaFalha('login','Configuração inválida.','Verifique o campo '.$key.'.');
            $c[$key]=trim($c[$key]);
        }
        if($c['servidor']!=='' && !filter_var($c['servidor'],FILTER_VALIDATE_IP) && !filter_var($c['servidor'],FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) throw new SgaFalha('dns','Servidor inválido.','Informe somente o nome DNS ou IP; use o campo porta separadamente.');
        if($c['ip']!=='' && !filter_var($c['ip'],FILTER_VALIDATE_IP)) throw new SgaFalha('dns','IP inválido.','Informe um endereço IPv4 ou IPv6 válido.');
        // PDO_DBLIB não dispõe de escape para delimitadores do DSN.
        if(strpbrk($c['banco'],';{}')!==false) throw new SgaFalha('login','Banco inválido.','O nome do banco não pode conter delimitadores de conexão.');
        foreach(['porta'=>[1,65535],'timeout_login'=>[5,120],'timeout_consulta'=>[1,86400]] as $key=>[$min,$max]) {
            $value=filter_var($c[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
            if($value===false) throw new SgaFalha('login','Configuração inválida.',$key.' deve ser um inteiro entre '.$min.' e '.$max.'.');
            $c[$key]=$value;
        }
        if(!in_array($c['criptografia'],['sim','nao'],true)) throw new SgaFalha('tls','Criptografia inválida.','Escolha sim ou nao.');
        $c['confiar_certificado']=filter_var($c['confiar_certificado'],FILTER_VALIDATE_BOOLEAN);
        return $c;
    }
    public static function salvar(array $p): void {
        $old=self::config();
        $c=self::validarConfig(array_replace($old,array_intersect_key($p,self::padroes()),['confiar_certificado'=>$p['confiar_certificado']??false]));
        if($c['servidor']==='' || $c['banco']==='' || $c['usuario']==='') throw new RuntimeException('Preencha servidor, banco e usuário.');
        $senha=(string)($p['senha']??''); if($senha==='') $senha=self::config(true)['senha']??'';
        if($senha==='') throw new RuntimeException('Informe a senha da conexão.');
        $nonce=random_bytes(24);
        $c['senha_cifrada']=base64_encode($nonce.sodium_crypto_secretbox($senha,$nonce,self::chave()));
        $file=ROOT.'/storage/sga.json';
        if(file_put_contents($file.'.tmp',json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX)===false || !rename($file.'.tmp',$file)) throw new RuntimeException('Não foi possível salvar a configuração.');
    }
    public static function consultar(string $tipo='compras'): array {
        $arquivo=match($tipo){'compras'=>'minerion_coleta.sql','patrimonios'=>'minerion_patrimonios.sql',default=>throw new RuntimeException('Consulta inválida.')};
        return self::executarSql((string)file_get_contents(ROOT.'/database/queries/'.$arquivo));
    }
    public static function executarSql(string $sql): array {
        try {
            $c=self::config(true);
            self::exigirConfig($c);
            $drivers=self::driversDisponiveis();
            if(!$drivers['selecionado']) throw new SgaFalha('driver','Nenhum driver SQL Server disponível.','Habilite pdo_sqlsrv, pdo_dblib/FreeTDS ou pdo_odbc com ODBC Driver 18/17 ou FreeTDS.');
        } catch(\Throwable $e) {
            $failure=$e instanceof SgaFalha?$e:self::falha($e,'login',$c??[]);
            self::registrarFalha($failure,'preparação'); throw $failure;
        }
        // Falha de carregamento permite o próximo driver. Senha/TLS/SQL não.
        foreach($drivers['disponiveis'] as $index=>$driver) {
            for($tentativa=1;$tentativa<=3;$tentativa++) {
                try { return self::consultarDriver($driver,$c,$sql); }
                catch(\Throwable $e) {
                    $failure=$e instanceof SgaFalha?$e:self::falha($e,'consulta',$c);
                    self::registrarFalha($failure,$driver.' tentativa '.$tentativa.'/3');
                    if($failure->etapa==='driver' && isset($drivers['disponiveis'][$index+1])) break;
                    if($tentativa===3 || !self::transitoria($failure)) throw $failure;
                    sleep($tentativa===1?1:3);
                }
            }
        }
        throw new SgaFalha('consulta','Falha na consulta SGA.','Tentativas esgotadas.');
    }
    /** Ordem de preferência; selecionado é o primeiro disponível, sem abrir conexão. */
    public static function driversDisponiveis(): array {
        $pdo=class_exists(\PDO::class)?\PDO::getAvailableDrivers():[];
        $odbc=in_array('odbc',$pdo,true)?self::driversOdbc():[];
        $candidatos=[];
        foreach(['sqlsrv','dblib'] as $name) if(in_array($name,$pdo,true)) $candidatos[]=$name;
        foreach($odbc as $name) $candidatos[]='odbc:'.$name;
        $ponte=PHP_OS_FAMILY==='Windows' && function_exists('proc_open') && is_file(self::powershell()) && is_file(ROOT.'/bin/sga-consultar.ps1');
        if($ponte) $candidatos[]='powershell';
        return ['pdo'=>$pdo,'odbc'=>$odbc,'powershell'=>$ponte,'disponiveis'=>$candidatos,'selecionado'=>$candidatos[0]??null,
            'observacoes'=>['PDO_ODBC no PHP 8.2 não expõe os atributos de timeout. FreeTDS usa freetds.conf; Microsoft ODBC usa os limites do driver (ConnectTimeout requer 18.7+). Prefira sqlsrv ou dblib para aplicar ambos os tempos.',
                'ca_arquivo é aplicado somente ao FreeTDS. Drivers Microsoft usam a CA instalada no sistema.']];
    }
    private static function driversOdbc(): array {
        $names=[];
        if(PHP_OS_FAMILY==='Windows' && function_exists('proc_open')) {
            // Registro da arquitetura do PHP, sem iniciar PowerShell nem acessar o SGA.
            $reg=(string)getenv('SystemRoot').'/System32/reg.exe';
            if(is_file($reg)) {
                $proc=proc_open([$reg,'query','HKLM\\SOFTWARE\\ODBC\\ODBCINST.INI\\ODBC Drivers',PHP_INT_SIZE===8?'/reg:64':'/reg:32'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
                if(is_resource($proc)) {
                    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]); proc_close($proc);
                    preg_match_all('/^\s*(ODBC Driver (?:18|17) for SQL Server|FreeTDS)\s+REG_SZ\s+Installed\s*$/mi',(string)$out,$m); $names=$m[1];
                }
            }
        } elseif(PHP_OS_FAMILY!=='Windows') {
            $dir=getenv('ODBCSYSINI')?:'/etc'; $ini=getenv('ODBCINSTINI')?:'odbcinst.ini';
            $paths=[$dir.'/'.$ini,'/etc/odbcinst.ini','/usr/local/etc/odbcinst.ini'];
            $userHome=getenv('HOME'); if($userHome) $paths[]=$userHome.'/.odbcinst.ini';
            foreach(array_unique($paths) as $path) {
                if(!is_readable($path)) continue;
                $contents=(string)file_get_contents($path);
                preg_match_all('/^\s*\[([^\]\r\n]+)\]/m',$contents,$m); $names=array_merge($names,$m[1]);
            }
        }
        $out=[];
        foreach(['ODBC Driver 18 for SQL Server','ODBC Driver 17 for SQL Server','FreeTDS'] as $wanted) foreach($names as $name) if(strcasecmp(trim($name),$wanted)===0) { $out[]=trim($name); break; }
        return $out;
    }
    private static function exigirConfig(array $c): void {
        if($c['servidor']==='' || $c['banco']==='' || $c['usuario']==='') throw new SgaFalha('login','Configure a conexão ao Minerion primeiro.','Servidor, banco e usuário são obrigatórios.');
        if(empty($c['senha'])) throw new SgaFalha('senha','Informe a senha da conexão.','Salve a conexão nesta instalação.');
    }
    private static function endereco(array $c): string {
        $host=$c['ip']!==''?$c['ip']:$c['servidor'];
        return str_contains($host,':')?'['.$host.']':$host;
    }
    private static function odbcValor(string $value): string { return '{'.str_replace('}','}}',$value).'}'; }
    private static function consultarDriver(string $driver,array $c,string $sql): array {
        if($driver==='powershell') return self::ponte($c,$sql);
        $options=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC];
        $encrypt=$c['criptografia']==='sim'?'yes':'no'; $trust=$c['confiar_certificado']?'yes':'no';
        $host=self::endereco($c); $env=[]; $file=null; $phase='driver';
        try {
            $freetds=$driver==='dblib' || strcasecmp($driver,'odbc:FreeTDS')===0;
            if($freetds) {
                // Um arquivo por chamada evita interferência entre requisições FPM.
                // Nunca contém credenciais e é removido mesmo se o login falhar.
                [$file,$env]=self::freeTds($c);
            }
            if($driver==='sqlsrv') {
                $dsn='sqlsrv:Server='.$host.','.$c['porta'].';Database='.$c['banco'].';Encrypt='.$encrypt.';TrustServerCertificate='.$trust.';LoginTimeout='.$c['timeout_login'];
                $options[\PDO::SQLSRV_ATTR_QUERY_TIMEOUT]=$c['timeout_consulta'];
            } elseif($driver==='dblib') {
                $dsn='dblib:host='.$host.':'.$c['porta'].';dbname='.$c['banco'].';charset=UTF-8;version=7.4';
                $options[\PDO::DBLIB_ATTR_CONNECTION_TIMEOUT]=$c['timeout_login'];
                $options[\PDO::DBLIB_ATTR_QUERY_TIMEOUT]=$c['timeout_consulta'];
            } else {
                $name=substr($driver,5);
                $dsn='odbc:Driver='.self::odbcValor($name).';Database='.self::odbcValor($c['banco']).';';
                if($freetds) $dsn.='Servername=salinas_sga;TDS_Version=7.4;ClientCharset=UTF-8;Timeout='.$c['timeout_consulta'].';';
                else $dsn.='Server='.self::odbcValor('tcp:'.$host.','.$c['porta']).';Encrypt='.$encrypt.';TrustServerCertificate='.$trust.';';
                // PDO_ODBC 8.2 ignora ATTR_TIMEOUT; não anunciar limite fictício.
            }
            $phase='login';
            $pdo=new \PDO($dsn,$c['usuario'],$c['senha'],$options);
            $phase='consulta';
            $statement=$pdo->query($sql);
            // Consultas Minerion podem ter SET/CTE antes do conjunto de resultados.
            while($statement->columnCount()===0 && $statement->nextRowset()) {}
            $rows=$statement->columnCount()>0?$statement->fetchAll():[];
            $statement->closeCursor();
            return $rows;
        } catch(\Throwable $e) { throw $e instanceof SgaFalha?$e:self::falha($e,$phase,$c); }
        finally {
            foreach($env as $name=>$value) putenv($value===false?$name:$name.'='.$value);
            if($file!==null && is_file($file)) unlink($file);
        }
    }
    private static function freeTds(array $c): array {
        $ca=$c['ca_arquivo'];
        if($ca!=='' && (!is_file($ca) || !is_readable($ca))) throw new SgaFalha('tls','Arquivo de CA indisponível.','Verifique o caminho ca_arquivo e as permissões do usuário do PHP.');
        if(strpbrk($ca,';#[]')!==false) throw new SgaFalha('tls','Caminho de CA inválido.','Não use delimitadores de freetds.conf no caminho.');
        $host=$c['ip']!==''?$c['ip']:$c['servidor'];
        $settings='tds version = 7.4'."\n".'client charset = UTF-8'."\n".'port = '.$c['porta']."\n".'connect timeout = '.$c['timeout_login']."\n".'timeout = '.$c['timeout_consulta']."\n".'encryption = '.($c['criptografia']==='sim'?'require':'off')."\n".'ca file = '.($c['confiar_certificado']?'':($ca!==''?$ca:'system'))."\n".'check certificate hostname = '.($c['confiar_certificado']?'no':'yes')."\n";
        $body="[global]\n".$settings."\n[salinas_sga]\nhost = ".$host."\n".$settings."\n[".$host."]\nhost = ".$host."\n".$settings;
        $file=tempnam(ROOT.'/storage','sga-tds-');
        if($file===false) throw new SgaFalha('driver','Não foi possível configurar FreeTDS.','Verifique a permissão de escrita em storage.');
        if(file_put_contents($file,$body,LOCK_EX)===false) { unlink($file); throw new SgaFalha('driver','Não foi possível configurar FreeTDS.','Falha ao gravar configuração temporária.'); }
        $env=[];
        foreach(['FREETDSCONF'=>$file,'FREETDS'=>$file,'TDSVER'=>'7.4','TDSPORT'=>(string)$c['porta']] as $key=>$value) {
            $env[$key]=getenv($key); putenv($key.'='.$value);
        }
        return [$file,$env];
    }
    private static function powershell(): string { return (string)getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe'; }
    private static function ponte(array $c,string $sql): array {
        $proc=proc_open([self::powershell(),'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',ROOT.'/bin/sga-consultar.ps1'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT,null,['bypass_shell'=>true]);
        if(!is_resource($proc)) throw new SgaFalha('driver','Não foi possível iniciar a ponte SQL Server.','Verifique proc_open e o Windows PowerShell.');
        try {
            $input=json_encode(['config'=>$c,'sql'=>$sql],JSON_THROW_ON_ERROR);
            for($offset=0;$offset<strlen($input);$offset+=$written) {
                $written=fwrite($pipes[0],substr($input,$offset));
                if($written===false || $written===0) throw new RuntimeException('Falha ao enviar solicitação à ponte.');
            }
            fclose($pipes[0]);
            // PS limita stderr a 3 KiB e nunca escreve resultados em caso de erro,
            // evitando deadlock nos pipes anônimos síncronos do PHP no Windows.
            $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error=self::limpar((string)stream_get_contents($pipes[2]),$c); fclose($pipes[2]);
            $code=proc_close($proc);
            if($error!=='') self::logArquivo(ROOT.'/storage/bridge-error.log',$error);
            if($code!==0) {
                $phase=str_contains($error,'etapa=consulta')?'consulta':'login';
                throw self::falha(new RuntimeException($error!==''?$error:'Ponte terminou com código '.$code.' sem detalhe no stderr.'),$phase,$c);
            }
            $data=json_decode(trim((string)$out,"\xEF\xBB\xBF \r\n"),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data) || !array_is_list($data)) throw new RuntimeException('Resposta inválida do SGA.');
            return $data;
        } catch(\Throwable $e) { throw $e instanceof SgaFalha?$e:self::falha($e,'consulta',$c); }
        finally {
            foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
            if(is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
        }
    }
    private static function limpar(string $text,array $c=[]): string {
        foreach([$c['senha']??'',(string)getenv('APP_KEY'),$c['senha_cifrada']??''] as $secret) {
            if($secret==='') continue;
            $text=str_replace(array_unique([$secret,str_replace('}','}}',$secret),str_replace("'","''",$secret),rawurlencode($secret)]),'[oculto]',$text);
        }
        $text=preg_replace('/\b(password|pwd|senha|senha_cifrada)\s*[=:]\s*(?:\{(?:[^}]|}})*\}|"[^"]*"|\x27[^\x27]*\x27|[^;\r\n]*)/iu','$1=[oculto]',$text)??'Detalhe indisponível.';
        $text=preg_replace('/[\x00-\x1f\x7f]+/',' ',$text)??'';
        return mb_substr(trim($text),0,4000,'UTF-8');
    }
    private static function falha(\Throwable $e,string $phase,array $c=[]): SgaFalha {
        $detail=self::limpar($e->getMessage(),$c);
        if($e instanceof \PDOException && is_array($e->errorInfo)) $detail=self::limpar($detail.' SQLSTATE='.($e->errorInfo[0]??'').' código='.($e->errorInfo[1]??''),$c);
        $stage=$phase;
        if(preg_match('/28000|18456|18452|login failed|falha (?:de|no) login|authentication failed|password|senha incorreta/i',$detail)) $stage='senha';
        elseif(preg_match('/certificate|certificado|unknown ca|wrong version number|unsupported protocol/i',$detail)) $stage='tls';
        elseif(preg_match('/getaddrinfo|name or service not known|no such host|host.*not found|nome.*desconhecido|could not resolve/i',$detail)) $stage='dns';
        elseif(preg_match('/IM00[23]|could not find driver|can.t open lib|driver.*not found|invalid connection string|invalid attribute|not supported|unsupported/i',$detail)) $stage='driver';
        elseif(preg_match('/0800[16]|08S01|HYT0[01]|timeout|timed out|tempo limite|tempo de espera|2000[239]|20009|1006[01]|connection.*(?:refused|reset|closed)|network|unreachable|TCP Provider|transport-level/i',$detail)) $stage='rede';
        elseif(preg_match('/\bssl\b|\btls\b|handshake|secure channel/i',$detail)) $stage='tls';
        $message=match($stage) {
            'dns'=>'Não foi possível resolver o servidor SGA.', 'rede'=>'Não foi possível concluir a comunicação com o SGA.',
            'driver'=>'Driver SQL Server indisponível ou incompatível.', 'senha'=>'O SGA recusou as credenciais da conexão.',
            'tls'=>'Falha na negociação TLS com o SGA.', 'login'=>'Não foi possível abrir a conexão com o SGA.',
            default=>'Falha ao executar a consulta no SGA.'
        };
        return new SgaFalha($stage,$message,$detail);
    }
    private static function transitoria(SgaFalha $e): bool { return in_array($e->etapa,['rede','dns'],true); }
    private static function logArquivo(string $path,string $message): void {
        $dir=dirname($path);
        if(!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) { error_log('SGA: não foi possível criar o diretório de logs.'); return; }
        if(@file_put_contents($path,date('c').' '.$message.PHP_EOL,FILE_APPEND|LOCK_EX)===false) error_log('SGA: não foi possível gravar o log.');
    }
    private static function registrarFalha(SgaFalha $e,string $context): void {
        self::logArquivo(ROOT.'/storage/logs/sga-conexao.log',$context.' ['.$e->etapa.'] '.$e->getMessage());
    }
    /** Cada etapa mantém o contrato mesmo quando uma falha impede as seguintes. */
    public static function diagnosticar(): array {
        $result=[]; $c=[]; $failed=false;
        $steps=[
            'config'=>static function() use (&$c): string {
                if(!is_file(ROOT.'/storage/sga.json')) throw new SgaFalha('login','Configure a conexão ao Minerion primeiro.','storage/sga.json ausente.');
                $c=self::config();
                if($c['servidor']==='' || $c['banco']==='' || $c['usuario']==='') throw new RuntimeException('Servidor, banco e usuário são obrigatórios.');
                return 'Configuração presente; destino '.($c['ip']?:$c['servidor']).':'.$c['porta'].'.';
            },
            'sodium'=>static function(): string { if(!function_exists('sodium_crypto_secretbox_open')) throw new RuntimeException('Extensão sodium indisponível neste PHP.'); return 'Extensão sodium disponível.'; },
            'senha'=>static function() use (&$c): string { $c=self::config(true); self::exigirConfig($c); return 'Senha decifrada com a chave desta instalação.'; },
            'dns'=>static function() use (&$c): string {
                $host=$c['ip']?:$c['servidor'];
                if(filter_var($host,FILTER_VALIDATE_IP)) return 'Destino IP '.$host.'; resolução DNS dispensada.';
                $ips=[];
                if(function_exists('dns_get_record')) foreach(@dns_get_record($host,DNS_A|DNS_AAAA)?:[] as $record) if(isset($record['ip']) || isset($record['ipv6'])) $ips[]=$record['ip']??$record['ipv6'];
                if(!$ips) { $ip=gethostbyname($host); if($ip!==$host) $ips[]=$ip; }
                if(!$ips) throw new RuntimeException('DNS não retornou endereços para '.$host.'.');
                return $host.' → '.implode(', ',array_unique($ips));
            },
            'rede'=>static function() use (&$c): string {
                $socket=@fsockopen('tcp://'.self::endereco($c),$c['porta'],$errno,$error,5);
                if($socket===false) throw new RuntimeException('TCP '.$c['porta'].' indisponível: '.$error.' ('.$errno.').');
                fclose($socket); return 'TCP '.$c['porta'].' acessível (limite de 5 s).';
            },
            'driver'=>static function(): string {
                $d=self::driversDisponiveis();
                if(!$d['selecionado']) throw new RuntimeException('Nenhum driver disponível. Instale pdo_sqlsrv, pdo_dblib ou pdo_odbc com driver SQL Server.');
                return 'Disponíveis: '.implode(', ',$d['disponiveis']).'. Selecionado: '.$d['selecionado'].'. '.implode(' ',$d['observacoes']);
            },
            'login'=>static function(): string { $rows=self::executarSql('SELECT 1 AS ok'); if((int)($rows[0]['ok']??0)!==1) throw new RuntimeException('SELECT 1 não retornou o resultado esperado.'); return 'Login e SELECT 1 concluídos.'; },
            'versao'=>static function(): string { $rows=self::executarSql('SELECT @@VERSION AS versao'); if(empty($rows[0]['versao'])) throw new RuntimeException('Versão não retornada pelo SGA.'); return mb_substr(preg_replace('/\s+/u',' ',(string)$rows[0]['versao']),0,300,'UTF-8'); }
        ];
        foreach($steps as $stage=>$run) {
            if($failed) { $result[]=['etapa'=>$stage,'ok'=>null,'detalhe'=>'Não executado: uma etapa crítica anterior falhou.','ms'=>0]; continue; }
            $start=hrtime(true);
            try { $detail=$run(); $ok=true; }
            catch(\Throwable $e) { $ok=false; $failed=true; $detail=self::limpar($e->getMessage(),$c); }
            $result[]=['etapa'=>$stage,'ok'=>$ok,'detalhe'=>self::limpar($detail,$c),'ms'=>(int)round((hrtime(true)-$start)/1000000)];
        }
        return $result;
    }
    public static function sincronizar(?int $actor): array {
        try {
            // Ler e validar ambas as origens antes de abrir a transação local.
            $compras=self::consultar(); $patrimonios=self::consultar('patrimonios');
            if(!PatrimoniosSga::normalizar($patrimonios)) throw new RuntimeException('Consulta patrimonial vazia; sincronização não iniciada.');
            Minerion::resumir($compras);
            if(!$compras) throw new RuntimeException('Consulta de compras vazia; sincronização não iniciada.');
            // Database::transaction reutiliza a transação ativa nos importadores.
            // Uma falha patrimonial também desfaz compras, auditoria e marcadores.
            return DB::transaction(static function() use($compras,$patrimonios,$actor): array {
                $n=self::importar($compras,'sga',$actor);
                $stats=PatrimoniosSga::importar($patrimonios,'sga',$actor);
                return ['compras'=>$n,'patrimonios'=>$stats];
            });
        } catch(\Throwable $e) {
            $failure=$e instanceof SgaFalha?$e:self::falha($e,'consulta');
            self::registrarFalha($failure,'sincronização; carga anterior preservada');
            // Fora da transação revertida, inclusive para falhas antes da importação.
            try { DB::run("INSERT INTO sincronizacoes(situacao,fonte,fim,mensagem) VALUES('falhou','sga',NOW(),?)",[mb_substr('['.$failure->etapa.'] '.$failure->getMessage(),0,500,'UTF-8')]); }
            catch(\Throwable $logError) { self::logArquivo(ROOT.'/storage/logs/sga-conexao.log','Não foi possível registrar a falha na tabela sincronizacoes; detalhe original registrado acima.'); }
            throw $failure;
        }
    }
    public static function importar(array $rows,string $fonte,?int $actor,bool $escopoRestrito=true): int {
        Minerion::resumir($rows,$escopoRestrito);
        if(!$rows) throw new RuntimeException('A consulta retornou vazia. A coleta anterior foi preservada para conferência.');
        $lock=DB::one("SELECT GET_LOCK('salinas_ti_sync',0) AS ok");
        if((int)$lock['ok']!==1) throw new RuntimeException('Já existe uma sincronização em andamento.');
        $id=null;
        try {
            DB::run("INSERT INTO sincronizacoes(situacao,fonte) VALUES('executando',?)",[$fonte]); $id=(int)DB::pdo()->lastInsertId();
            DB::transaction(function() use($rows,$id,$actor,$fonte,$escopoRestrito) {
                foreach($rows as $r) {
                    if(!$escopoRestrito) DB::run('INSERT INTO requisitantes_sga(codigo,nome,coletar) VALUES(?,?,0) ON DUPLICATE KEY UPDATE nome=VALUES(nome)',[(int)$r['requisitante_id'],$r['requisitante_nome']?:'Não informado']);
                    $old=DB::one('SELECT * FROM compras_origem WHERE origem_item_id=? FOR UPDATE',[$r['origem_item_id']]);
                    $pid=(int)$r['produto_id'];
                    if($pid>0) {
                        $nome=$r['produto_nome'] ?: 'Produto SGA '.$pid.' — descrição não identificada';
                        DB::run('INSERT INTO produtos(sga_codigo,nome) VALUES(?,?) ON DUPLICATE KEY UPDATE nome=VALUES(nome)',[$pid,$nome]);
                        $product=DB::one('SELECT id FROM produtos WHERE sga_codigo=?',[$pid]);
                    } elseif($old) {
                        $product=['id'=>$old['produto_id']];
                    } else {
                        DB::run('INSERT INTO produtos(nome) VALUES(?)',['Linha SGA '.$r['origem_item_id'].' — produto não identificado; conferir na origem']);
                        $product=['id'=>(int)DB::pdo()->lastInsertId()];
                    }
                    $cc=null;
                    if((int)$r['centro_custo_compra_id']>0 && $r['centro_custo_compra_nome']) {
                        DB::run('INSERT INTO centros_custo(sga_codigo,nome) VALUES(?,?) ON DUPLICATE KEY UPDATE nome=VALUES(nome)',[$r['centro_custo_compra_id'],$r['centro_custo_compra_nome']]);
                        $cc=DB::one('SELECT id FROM centros_custo WHERE sga_codigo=?',[$r['centro_custo_compra_id']])['id'];
                    }
                    $date=empty($r['processo_data'])?null:substr(str_replace('T',' ',$r['processo_data']),0,19);
                    $cancel=empty($r['cancelado_em'])?null:substr(str_replace('T',' ',$r['cancelado_em']),0,19);
                    $pending=$old ? (bool)$old['conciliacao_pendente'] : false;
                    if($old && (bccomp((string)$old['quantidade_vinculada_nf'],(string)$r['quantidade_vinculada_nf'],4)!==0 || ($old['cancelado_em']??null)!==$cancel) && bccomp((string)$old['incorporada_quantidade'],'0',4)>0) $pending=true;
                    $v=[$r['processo_id'],$r['empresa_id'],$r['filial_id'],$r['requisitante_id'],$product['id'],$cc,$date,$cancel,$r['quantidade_solicitada'],$r['quantidade_faturada_cadastro'],$r['quantidade_cancelada'],$r['quantidade_vinculada_nf'],$r['valor_unitario_pedido'],$r['vinculos_nf_positivos'],$id,json_encode($r,JSON_UNESCAPED_UNICODE),$pending?1:0];
                    if($old) DB::run('UPDATE compras_origem SET processo_id=?,empresa_id=?,filial_id=?,requisitante_codigo=?,produto_id=?,centro_custo_compra_id=?,processo_data=?,cancelado_em=?,quantidade_solicitada=?,quantidade_faturada=?,quantidade_cancelada=?,quantidade_vinculada_nf=?,valor_unitario_pedido=?,vinculos_nf_positivos=?,ultima_sincronizacao_id=?,payload_json=?,conciliacao_pendente=?,origem_presente=1 WHERE id=?',[...$v,$old['id']]);
                    else DB::run('INSERT INTO compras_origem(processo_id,empresa_id,filial_id,requisitante_codigo,produto_id,centro_custo_compra_id,processo_data,cancelado_em,quantidade_solicitada,quantidade_faturada,quantidade_cancelada,quantidade_vinculada_nf,valor_unitario_pedido,vinculos_nf_positivos,ultima_sincronizacao_id,payload_json,conciliacao_pendente,origem_item_id) VALUES('.implode(',',array_fill(0,18,'?')).')',[...$v,$r['origem_item_id']]);
                    if($fonte==='sga_documento' && !$old) DB::run('UPDATE compras_origem SET entrada_documento=1 WHERE origem_item_id=?',[$r['origem_item_id']]);
                }
                if(in_array($fonte,['sga','arquivo'],true)) {
                    // Fotografia completa: o que não voltou fica ausente. Retorno abaixo da metade da base presente indica consulta parcial.
                    $presentes=(int)DB::one('SELECT COUNT(*) n FROM compras_origem WHERE origem_presente=1 AND entrada_documento=0')['n'];
                    if($presentes>=100 && count($rows)<$presentes*0.5) throw new RuntimeException('A origem retornou '.count($rows).' compras, menos da metade das '.$presentes.' atuais. Carga abortada por suspeita de consulta parcial; nada foi alterado.');
                    DB::run('UPDATE compras_origem SET origem_presente=0,conciliacao_pendente=1 WHERE ultima_sincronizacao_id<>? AND entrada_documento=0 AND origem_presente=1',[$id]);
                }
                DB::run("UPDATE sincronizacoes SET situacao='concluida',fim=NOW(),linhas_lidas=? WHERE id=?",[count($rows),$id]);
                DB::audit('sincronizacoes',$id,'importar',null,['fonte'=>$fonte,'linhas'=>count($rows)],$actor);
            });
            return count($rows);
        } catch(\Throwable $e) {
            if($id) {
                try { DB::run("UPDATE sincronizacoes SET situacao='falhou',fim=NOW(),mensagem=? WHERE id=?",[mb_substr(self::limpar('Importação interrompida; dados anteriores preservados. '.$e->getMessage()),0,500,'UTF-8'),$id]); }
                catch(\Throwable $logError) { self::logArquivo(ROOT.'/storage/logs/sga-conexao.log','Não foi possível atualizar o registro da importação que falhou.'); }
            }
            throw $e;
        } finally { DB::run("SELECT RELEASE_LOCK('salinas_ti_sync')"); }
    }
}
