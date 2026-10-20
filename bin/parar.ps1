$ErrorActionPreference = 'Stop'
$appRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
foreach($name in @('php-process.pid','mariadb-process.pid')) {
    $file=Join-Path $appRoot "storage\$name"
    if(Test-Path -LiteralPath $file) {
        $saved=[int](Get-Content -LiteralPath $file)
        $proc=Get-CimInstance Win32_Process -Filter "ProcessId=$saved" -ErrorAction SilentlyContinue
        if($proc -and $proc.CommandLine -and $proc.CommandLine.Contains($appRoot)) {
            if($name -eq 'mariadb-process.pid') {
                & 'C:\xampp\php\php.exe' (Join-Path $appRoot 'bin\parar-db.php')
                if($LASTEXITCODE -ne 0) { throw 'Falha ao solicitar encerramento seguro do MariaDB.' }
            } else { Stop-Process -Id $saved }
        }
    }
}
Write-Host 'Processos Salinas TI encerrados.'
