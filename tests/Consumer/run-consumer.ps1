param([string]$Composer = 'composer')
if (-not (Get-Command $Composer -ErrorAction SilentlyContinue)) {
    throw "Composer executable not found: $Composer"
}
$packageRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$protocolRoot = (Resolve-Path (Join-Path $packageRoot '../../protocol/pasp/v1/conformance')).Path
$consumerRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('openexit-php-consumer-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $consumerRoot | Out-Null
try {
    $manifest = @{
        name = 'openexit/consumer-proof'
        require = @{ 'openexit/openexit' = '*' }
        repositories = @(@{ type = 'path'; url = $packageRoot; options = @{ symlink = $false } })
        config = @{ 'allow-plugins' = @{} }
        'minimum-stability' = 'dev'
        'prefer-stable' = $true
    } | ConvertTo-Json -Depth 8
    $composerJson = Join-Path $consumerRoot 'composer.json'
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($composerJson, $manifest, $utf8NoBom)
    Push-Location $consumerRoot
    try {
        & $Composer install --no-interaction --prefer-dist
        if ($LASTEXITCODE -ne 0) { throw 'Composer consumer install failed' }
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'consumer.php') -Destination (Join-Path $consumerRoot 'consumer.php')
        $env:OPENEXIT_PROTOCOL_FIXTURES = $protocolRoot
        & php (Join-Path $consumerRoot 'consumer.php')
        if ($LASTEXITCODE -ne 0) { throw 'Consumer proof failed' }
    } finally { Pop-Location }
} finally {
    if (Test-Path -LiteralPath $consumerRoot) { Remove-Item -LiteralPath $consumerRoot -Recurse -Force }
}
