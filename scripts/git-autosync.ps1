# Commits whatever changed in the project and pushes it to GitHub. Run daily by the
# "Library GitHub Sync" scheduled task.
#   pwsh scripts/git-autosync.ps1 [-Remote origin]
# Respects .gitignore, so .env, vendor/, node_modules/ and the databases never leave the machine.
param([string]$Remote = 'origin')

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$log = Join-Path $root 'storage\logs\git-autosync.log'

function Write-SyncLog([string]$message) {
    '{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message | Add-Content -Path $log -Encoding utf8
}

Push-Location $root
try {
    git add -A 2>&1 | Out-Null
    if ($LASTEXITCODE) { throw 'git add failed.' }

    git diff --cached --quiet
    if ($LASTEXITCODE) {
        git commit --quiet -m ('Daily sync {0}' -f (Get-Date -Format 'yyyy-MM-dd')) 2>&1 | Out-Null
        if ($LASTEXITCODE) { throw 'git commit failed.' }
    }

    # Pushes even when nothing new was committed, so a push that failed yesterday is retried.
    $branch = git rev-parse --abbrev-ref HEAD
    $output = git push $Remote $branch 2>&1 | Out-String
    if ($LASTEXITCODE) { throw "git push failed: $($output.Trim())" }

    Write-SyncLog ('OK {0} {1}' -f (git rev-parse --short HEAD), ($output.Trim() -split "`n")[-1].Trim())
} catch {
    Write-SyncLog "FAILED $($_.Exception.Message)"
    exit 1
} finally {
    Pop-Location
}
