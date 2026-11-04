$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$connection = $null
$command = $null
$reader = $null
$request = $null
$phase = 'login'
try {
    $request = [Console]::In.ReadToEnd() | ConvertFrom-Json
    Add-Type -AssemblyName System.Data
    $builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
    $hostSga = [string]$request.config.servidor
    if ($request.config.ip) { $hostSga = [string]$request.config.ip }
    if ($hostSga.Contains(':') -and -not $hostSga.StartsWith('[')) { $hostSga = '[' + $hostSga + ']' }
    $port = 1433
    if ($request.config.porta) { $port = [int]$request.config.porta }
    $loginTimeout = 30
    if ($request.config.timeout_login) { $loginTimeout = [int]$request.config.timeout_login }
    $queryTimeout = 120
    if ($request.config.timeout_consulta) { $queryTimeout = [int]$request.config.timeout_consulta }
    if ($port -lt 1 -or $port -gt 65535 -or $loginTimeout -lt 5 -or $loginTimeout -gt 120 -or $queryTimeout -lt 1 -or $queryTimeout -gt 86400) { throw 'Porta ou timeout fora da faixa permitida.' }
    $builder['Data Source'] = 'tcp:' + $hostSga + ',' + $port
    $builder['Initial Catalog'] = $request.config.banco
    $builder['User ID'] = $request.config.usuario
    $builder['Password'] = $request.config.senha
    $builder['Encrypt'] = ($request.config.criptografia -ne 'nao')
    $builder['TrustServerCertificate'] = [bool]$request.config.confiar_certificado
    $builder['Connect Timeout'] = $loginTimeout
    $builder['ConnectRetryCount'] = 0 # Retentativas controladas no PHP, no máximo três.
    $builder['Application Name'] = 'Salinas TI - leitura'
    $connection = New-Object System.Data.SqlClient.SqlConnection($builder.ConnectionString)
    $connection.Open()
    $phase = 'consulta'
    $command = $connection.CreateCommand()
    $command.CommandText = $request.sql
    $command.CommandTimeout = $queryTimeout
    $reader = $command.ExecuteReader()
    $rows = New-Object 'System.Collections.Generic.List[object]'
    while ($reader.Read()) {
        $row = [ordered]@{}
        for ($i=0; $i -lt $reader.FieldCount; $i++) {
            $value = $reader.GetValue($i)
            if ($value -is [DBNull]) { $value = $null }
            elseif ($value -is [DateTime]) { $value = $value.ToString('yyyy-MM-ddTHH:mm:ss') }
            $row[$reader.GetName($i)] = $value
        }
        $rows.Add($row)
    }
    $reader.Close()
    $json = ConvertTo-Json -InputObject @($rows.ToArray()) -Depth 8 -Compress
    [Console]::Out.Write($json)
} catch {
    $failure = $_.Exception.GetBaseException()
    $detail = 'etapa=' + $phase + '; ' + $failure.GetType().Name + ': ' + $failure.Message
    if ($failure -is [System.Data.SqlClient.SqlException]) { $detail += '; SQLNumber=' + $failure.Number }
    if ($request -and $request.config.senha) {
        $secret = [string]$request.config.senha
        $detail = $detail.Replace($secret, '[oculto]').Replace($secret.Replace('}', '}}'), '[oculto]').Replace($secret.Replace("'", "''"), '[oculto]')
    }
    $detail = [regex]::Replace($detail, '(?i)\b(password|pwd|senha)\s*[=:]\s*(?:\{(?:[^}]|}})*\}|"[^"]*"|[^;\r\n]*)', '$1=[oculto]')
    $detail = [regex]::Replace($detail, '[\x00-\x1f\x7f]+', ' ')
    # Mantém stderr abaixo da capacidade do pipe enquanto o PHP drena stdout.
    while ([Text.Encoding]::UTF8.GetByteCount($detail) -gt 3000) { $detail = $detail.Substring(0, $detail.Length - 1) }
    [Console]::Error.WriteLine($detail)
    exit 1
} finally {
    if ($reader) { $reader.Dispose() }
    if ($command) { $command.Dispose() }
    if ($connection) { $connection.Dispose() }
}
