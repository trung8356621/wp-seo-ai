<#
.SYNOPSIS
  Trigger or dry-run the wp-seo-ai GitHub Release pipeline.

.DESCRIPTION
  Default: local dry-run via php bin/release.php (no tag/push/release).
  -Dispatch: starts .github/workflows/release.yml via authenticated `gh`.

.EXAMPLE
  ./scripts/release.ps1
  ./scripts/release.ps1 patch
  ./scripts/release.ps1 -Bump minor -DryRun
  ./scripts/release.ps1 -Bump patch -Dispatch
  ./scripts/release.ps1 -Bump patch -Dispatch -Execute
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet("patch", "minor", "major")]
    [string]$Bump = "patch",

    [switch]$DryRun,

    [switch]$Dispatch,

    [switch]$Execute
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot

if (-not $PSBoundParameters.ContainsKey("DryRun") -and -not $Execute -and -not $Dispatch) {
    $DryRun = $true
}

if ($Execute -and $DryRun) {
    Write-Error "Use either -DryRun or -Execute, not both."
    exit 1
}

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Error "php executable not found in PATH"
    exit 1
}

function Invoke-LocalRelease {
    param([string]$ModeBump, [bool]$IsExecute)
    $argsList = @("$root\bin\release.php", "--bump=$ModeBump")
    if ($IsExecute) {
        $argsList += "--execute"
        Write-Host "WARNING: local --execute will commit/tag/push/release from this machine." -ForegroundColor Yellow
    } else {
        $argsList += "--dry-run"
    }
    & php @argsList
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

if ($Dispatch) {
    $gh = Get-Command gh -ErrorAction SilentlyContinue
    if (-not $gh) {
        Write-Error "gh CLI not found. Install GitHub CLI and authenticate (gh auth login)."
        exit 1
    }

    $dryValue = if ($Execute) { "false" } else { "true" }
    Write-Host "Dispatching workflow release.yml (bump=$Bump dry_run=$dryValue)"
    & gh workflow run release.yml --repo trung8356621/wp-seo-ai -f "bump=$Bump" -f "dry_run=$dryValue"
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
    Write-Host "Dispatched. Watch: gh run watch --repo trung8356621/wp-seo-ai"
    exit 0
}

# Local path (default dry-run). Prefer Actions for real releases.
Invoke-LocalRelease -ModeBump $Bump -IsExecute:([bool]$Execute)
