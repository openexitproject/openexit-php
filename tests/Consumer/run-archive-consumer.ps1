param(
    [string]$Composer = 'composer',
    [string]$ArchivePath = ''
)
if (-not (Get-Command $Composer -ErrorAction SilentlyContinue)) {
    throw "Composer executable not found: $Composer"
}
$packageRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$repositoryRoot = (Resolve-Path (Join-Path $packageRoot '../..')).Path
$protocolRoot = Join-Path $repositoryRoot 'protocol/pasp/v1/conformance'
if (-not $ArchivePath) {
    $archive = Get-ChildItem (Join-Path $packageRoot 'dist') -Filter *.zip | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $archive) { throw 'Build the Composer archive first with: composer archive --format=zip --dir=dist' }
    $ArchivePath = $archive.FullName
}
$ArchivePath = (Resolve-Path -LiteralPath $ArchivePath).Path
$packageManifest = Get-Content -LiteralPath (Join-Path $packageRoot 'composer.json') -Raw | ConvertFrom-Json
$consumerRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('openexit-php-archive-consumer-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $consumerRoot | Out-Null
try {
    $releaseMetadata = @{
        name = $packageManifest.name
        version = '0.1.0'
        require = $packageManifest.require
        autoload = $packageManifest.autoload
        dist = @{ type = 'zip'; url = ([System.Uri]::new($ArchivePath)).AbsoluteUri }
    }
    $consumerManifest = @{
        name = 'openexit/archive-consumer-proof'
        require = @{ 'openexit/openexit' = '0.1.0' }
        repositories = @(@{ type = 'package'; package = $releaseMetadata })
        config = @{ 'allow-plugins' = @{} }
    } | ConvertTo-Json -Depth 12
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText((Join-Path $consumerRoot 'composer.json'),$consumerManifest,$utf8NoBom)
    Push-Location $consumerRoot
    try {
        & $Composer install --no-interaction --prefer-dist
        if ($LASTEXITCODE -ne 0) { throw 'Archive Composer consumer install failed' }
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'consumer.php') -Destination (Join-Path $consumerRoot 'consumer.php')
        $env:OPENEXIT_PROTOCOL_FIXTURES = $protocolRoot
        & php (Join-Path $consumerRoot 'consumer.php')
        if ($LASTEXITCODE -ne 0) { throw 'Archive consumer proof failed' }
    } finally { Pop-Location }
} finally {
    if (Test-Path -LiteralPath $consumerRoot) { Remove-Item -LiteralPath $consumerRoot -Recurse -Force }
}
