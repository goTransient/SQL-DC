[CmdletBinding()]
param(
    [ValidateRange(1, 65535)]
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $repoRoot

$environmentNames = @(
    'SQLDC_BASE_URL',
    'SQLDC_GOOGLE_REDIRECT_URI',
    'SQLDC_GOOGLE_CLIENT_ID',
    'SQLDC_GOOGLE_CLIENT_SECRET',
    'DEV_MODE'
)
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

function Get-LocalSetting([string]$Name) {
    $processValue = [Environment]::GetEnvironmentVariable($Name, 'Process')
    if ($null -ne $processValue) {
        return $processValue
    }

    $envPaths = @(
        (Join-Path (Split-Path -Parent $repoRoot) '.env'),
        (Join-Path $repoRoot '.env')
    )
    foreach ($envPath in $envPaths) {
        if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
            continue
        }

        foreach ($line in [IO.File]::ReadAllLines($envPath)) {
            $trimmedLine = $line.Trim()
            if ($trimmedLine -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$' -and $Matches[1] -ceq $Name) {
                return $Matches[2].Trim()
            }
        }
    }

    return $null
}

try {
    $phpCommand = Get-Command php -ErrorAction Stop
    $phpPath = $phpCommand.Source
    if (-not $phpPath) {
        throw 'Could not find the PHP executable path.'
    }

    $phpVersionText = & $phpPath -r 'echo PHP_VERSION;'
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not determine the PHP version.'
    }
    $phpVersion = [version]($phpVersionText -replace '^(\d+\.\d+\.\d+).*$', '$1')
    if ($phpVersion -lt [version]'8.1') {
        throw "PHP 8.1 or newer is required. Found PHP $phpVersionText."
    }

    $moduleOutput = & $phpPath -m
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not inspect PHP extensions.'
    }
    $modules = @($moduleOutput | ForEach-Object { $_.Trim().ToLowerInvariant() })
    $requiredModules = @('pdo_sqlite', 'curl', 'openssl', 'zip', 'simplexml')
    $missingModules = @($requiredModules | Where-Object { $_ -notin $modules })
    if ($missingModules.Count -gt 0) {
        throw "Enable these PHP extensions before continuing: $($missingModules -join ', ')."
    }

    $baseUrl = "http://localhost:$Port"
    $redirectUri = "$baseUrl/api/google-callback.php"
    Write-Host "Local application URL: $baseUrl"
    Write-Host "Google OAuth callback URI: $redirectUri"
    Write-Host 'Register that exact callback URI in your Google OAuth client.'
    Write-Host ''

    $clientId = Get-LocalSetting 'SQLDC_GOOGLE_CLIENT_ID'
    if ([string]::IsNullOrWhiteSpace($clientId)) {
        $clientId = Read-Host 'Google OAuth web client ID'
    }
    if ([string]::IsNullOrWhiteSpace($clientId)) {
        throw 'A Google OAuth client ID is required. Add SQLDC_GOOGLE_CLIENT_ID to .env or enter it when prompted.'
    }
    if ($clientId -match '\s') {
        throw 'The Google OAuth client ID must not contain whitespace.'
    }

    $clientSecret = Get-LocalSetting 'SQLDC_GOOGLE_CLIENT_SECRET'
    if ([string]::IsNullOrWhiteSpace($clientSecret)) {
        $secureSecret = Read-Host 'Google OAuth web client secret' -AsSecureString
        if ($secureSecret.Length -eq 0) {
            throw 'A Google OAuth client secret is required. Add SQLDC_GOOGLE_CLIENT_SECRET to .env or enter it when prompted.'
        }
        $secretPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureSecret)
        try {
            $clientSecret = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($secretPointer)
        }
        finally {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($secretPointer)
        }
    }
    if ($clientSecret -match '\s') {
        throw 'The Google OAuth client secret must not contain whitespace.'
    }

    $email = Read-Host 'Google account email to allowlist (leave blank to skip provisioning)'
    $provisionAction = 'skip'
    if (-not [string]::IsNullOrWhiteSpace($email)) {
        if ($email -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') {
            throw 'Enter a valid email address or leave it blank.'
        }
        $provisionAction = Read-Host 'Provisioning action: add new account, activate existing account, or skip [add/activate/skip]'
        if ([string]::IsNullOrWhiteSpace($provisionAction)) {
            $provisionAction = 'skip'
        }
        $provisionAction = $provisionAction.Trim().ToLowerInvariant()
        if ($provisionAction -notin @('add', 'activate', 'skip')) {
            throw 'Choose add, activate, or skip.'
        }
    }

    [Environment]::SetEnvironmentVariable('SQLDC_BASE_URL', $baseUrl, 'Process')
    [Environment]::SetEnvironmentVariable('SQLDC_GOOGLE_REDIRECT_URI', $redirectUri, 'Process')
    [Environment]::SetEnvironmentVariable('SQLDC_GOOGLE_CLIENT_ID', $clientId.Trim(), 'Process')
    [Environment]::SetEnvironmentVariable('SQLDC_GOOGLE_CLIENT_SECRET', $clientSecret, 'Process')
    [Environment]::SetEnvironmentVariable('DEV_MODE', 'true', 'Process')

    if ($provisionAction -ne 'skip') {
        Write-Host "Provisioning $email ($provisionAction) in the local SQLite database..."
        & $phpPath 'api\provision-user.php' $provisionAction $email.Trim()
        if ($LASTEXITCODE -ne 0) {
            throw "Account provisioning failed with exit code $LASTEXITCODE."
        }
    }

    Write-Host ''
    Write-Host "Starting SQL-DC at $baseUrl. Press Ctrl+C to stop."
    & $phpPath -S "localhost:$Port"
    if ($LASTEXITCODE -ne 0) {
        throw "The PHP development server exited with code $LASTEXITCODE."
    }
}
finally {
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
    }
    $clientSecret = $null
    $secureSecret = $null
    Pop-Location
}
