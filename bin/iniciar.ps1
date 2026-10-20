param([switch]$SemNavegador)
$ErrorActionPreference = 'Stop'
$appRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$php = 'C:\xampp\php\php.exe'
$mariaHome = 'C:\xampp\mysql'
$storage = Join-Path $appRoot 'storage'
try {
    if (!(Test-Path -LiteralPath $php)) { throw 'PHP nao encontrado em C:\xampp\php.' }
    foreach ($folder in @($storage,(Join-Path $storage 'logs'),(Join-Path $storage 'sessions'))) {
        New-Item -ItemType Directory -Path $folder -Force | Out-Null
    }
    $dataDir = Join-Path $storage 'mariadb'
    $ini = Join-Path $storage 'mariadb.ini'
    $pidFile = Join-Path $storage 'mariadb-process.pid'
    if (!(Test-Path -LiteralPath (Join-Path $dataDir 'mysql'))) {
        Write-Host 'Preparando banco MariaDB isolado para Salinas TI...'
        & (Join-Path $mariaHome 'bin\mysql_install_db.exe') "--datadir=$dataDir" --port=3308 --silent
        if ($LASTEXITCODE -ne 0) { throw 'Falha ao preparar MariaDB.' }
    }
    $mariaBase = $mariaHome.Replace('\','/')
    $mariaData = $dataDir.Replace('\','/')
    $mariaLog = (Join-Path $storage 'logs\mariadb.log').Replace('\','/')
    $mariaPid = (Join-Path $storage 'mariadb-server.pid').Replace('\','/')
    @"
[mysqld]
basedir=$mariaBase
datadir=$mariaData
port=3308
bind-address=127.0.0.1
skip-name-resolve
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
max_allowed_packet=32M
innodb_buffer_pool_size=64M
log-error=$mariaLog
pid-file=$mariaPid
"@ | Set-Content -LiteralPath $ini -Encoding ASCII
    $dbReady=$false
    if (Test-Path -LiteralPath $pidFile) {
        $saved=[int](Get-Content -LiteralPath $pidFile)
        $running=Get-CimInstance Win32_Process -Filter "ProcessId=$saved" -ErrorAction SilentlyContinue
        if ($running -and $running.CommandLine -and $running.CommandLine.Contains($ini)) { $dbReady=$true }
    }
    if (!$dbReady) {
        $check = New-Object Net.Sockets.TcpClient
        try { $check.Connect('127.0.0.1',3308); throw 'Porta 3308 ocupada por outro processo. Nenhum banco existente foi alterado.' }
        catch [Net.Sockets.SocketException] {} finally { $check.Dispose() }
        $db = Start-Process -FilePath (Join-Path $mariaHome 'bin\mysqld.exe') -ArgumentList @("--defaults-file=`"$ini`"") -WindowStyle Hidden -PassThru
        $db.Id | Set-Content -LiteralPath $pidFile -Encoding ASCII
        $ready=$false
        for($attempt=0;$attempt -lt 30;$attempt++) {
            $tcp = New-Object Net.Sockets.TcpClient
            try { $tcp.Connect('127.0.0.1',3308); $ready=$true; break } catch { Start-Sleep -Milliseconds 500 } finally { $tcp.Dispose() }
        }
        if (!$ready) { throw 'MariaDB nao iniciou. Consulte storage/logs/mariadb.log.' }
    }
    & $php (Join-Path $appRoot 'bin\instalar.php')
    if($LASTEXITCODE -ne 0) { throw 'A instalacao PHP informou falha.' }
    $webPid = Join-Path $storage 'php-process.pid'
    $webReady=$false
    if(Test-Path -LiteralPath $webPid) {
        $saved=[int](Get-Content -LiteralPath $webPid)
        $running=Get-CimInstance Win32_Process -Filter "ProcessId=$saved" -ErrorAction SilentlyContinue
        if($running -and $running.CommandLine -and $running.CommandLine.Contains($appRoot)) { $webReady=$true }
    }
    if(!$webReady) {
        $tcp=New-Object Net.Sockets.TcpClient
        try { $tcp.Connect('127.0.0.1',2288); throw 'Porta 2288 ocupada por outro processo.' }
        catch [Net.Sockets.SocketException] {} finally { $tcp.Dispose() }
        $public=Join-Path $appRoot 'public'
        $router=Join-Path $public 'router.php'
        $web=Start-Process -FilePath $php -ArgumentList @('-d','display_errors=Off','-d','log_errors=On','-d',"error_log=`"$storage/logs/php.log`"",'-d','upload_max_filesize=256M','-d','post_max_size=270M','-d','memory_limit=512M','-d','max_execution_time=180','-S','127.0.0.1:2288','-t',"`"$public`"","`"$router`"") -WorkingDirectory $appRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $storage 'logs\web-out.log') -RedirectStandardError (Join-Path $storage 'logs\web-error.log')
        $web.Id | Set-Content -LiteralPath $webPid -Encoding ASCII
    }
    $healthy=$false
    for($attempt=0;$attempt -lt 20;$attempt++) {
        try { $health=Invoke-RestMethod -Uri 'http://127.0.0.1:2288/saude' -TimeoutSec 2; if($health.status -eq 'ok') { $healthy=$true; break } } catch { Start-Sleep -Milliseconds 500 }
    }
    if(!$healthy) { throw 'Aplicacao nao respondeu. Consulte storage/logs.' }
    Write-Host 'Salinas TI pronto: http://localhost:2288'
    Write-Host 'Leia ACESSO-LOCAL.txt para o primeiro login.'
    if(!$SemNavegador) { Start-Process 'http://localhost:2288' }
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
