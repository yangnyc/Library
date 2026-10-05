# Runs PHPUnit and prints a compact result: totals, then one trimmed line per failure.
#   pwsh scripts/test.ps1 [path-or-filter]
param([string]$Target = '')

$php = (Get-Command php -ErrorAction SilentlyContinue)?.Source
if (-not $php) { $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter php.exe -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName }
if (-not $php) { throw 'PHP is required to run the tests.' }

$arguments = @('vendor/phpunit/phpunit/phpunit', '--no-progress', '--colors=never')
if ($Target) {
    if (Test-Path (Join-Path (Split-Path $PSScriptRoot -Parent) $Target)) { $arguments += $Target }
    else { $arguments += @('--filter', $Target) }
}

Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    $raw = (& $php @arguments 2>&1 | ForEach-Object { "$_" }) -join "`n"
    $code = $LASTEXITCODE
} finally { Pop-Location }

# Some environments make PHPUnit print one JSON object instead of its usual report.
$json = $null
if ($raw.TrimStart().StartsWith('{')) { try { $json = $raw | ConvertFrom-Json } catch { } }

if ($json) {
    $failed = if ($null -ne $json.failed) { $json.failed } else { 0 }
    $errors = if ($null -ne $json.errors) { $json.errors } else { 0 }
    'tests: {0}  passed: {1}  failed: {2}  errors: {3}  assertions: {4}' -f $json.tests, $json.passed, $failed, $errors, $json.assertions
    foreach ($group in 'failure_details', 'error_details', 'failures', 'errors') {
        foreach ($item in @($json.$group)) {
            if (-not $item -or -not $item.PSObject.Properties['message']) { continue }
            $message = ("$($item.message)" -replace '\s+', ' ')
            if ($message.Length -gt 420) { $message = $message.Substring(0, 200) + ' … ' + $message.Substring($message.Length - 200) }
            '- {0} (line {1}): {2}' -f ($item.test -replace '^Tests\\', ''), $item.line, $message
        }
    }
} else {
    $lines = $raw -split "`n"
    $lines | Select-Object -Last 40 | ForEach-Object { if ($_.Length -gt 400) { $_.Substring(0, 400) + ' …' } else { $_ } }
}
exit $code
