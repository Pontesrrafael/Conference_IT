$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$connection = $null
try {
    $request = [Console]::In.ReadToEnd() | ConvertFrom-Json
    Add-Type -AssemblyName System.Data
    $builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
    $builder['Data Source'] = $request.config.servidor
    $builder['Initial Catalog'] = $request.config.banco
    $builder['User ID'] = $request.config.usuario
    $builder['Password'] = $request.config.senha
    $builder['Encrypt'] = if ($null -eq $request.config.usar_ssl) { $true } else { [bool]$request.config.usar_ssl }
    $builder['TrustServerCertificate'] = [bool]$request.config.confiar_certificado
    $builder['Connect Timeout'] = 12
    $builder['Application Name'] = 'Salinas TI - leitura'
    $connection = New-Object System.Data.SqlClient.SqlConnection($builder.ConnectionString)
    $connection.Open()
    $command = $connection.CreateCommand()
    $command.CommandText = $request.sql
    $command.CommandTimeout = 60
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
    [Console]::Error.WriteLine('Falha na consulta SQL Server: ' + $failure.GetType().Name + ': ' + $failure.Message)
    exit 1
} finally { if ($connection) { $connection.Dispose() } }
