param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$Version,

    [string]$Dist = ""
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Error "php executable not found in PATH"
    exit 1
}

$argsList = @("$root\bin\build-plugin-release.php", $Version)
if ($Dist -ne "") {
    $argsList += "--dist=$Dist"
}

& php @argsList
exit $LASTEXITCODE
