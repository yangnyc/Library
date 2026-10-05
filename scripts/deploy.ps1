# Uploads the application to the VPS (ssh alias "vps") and refreshes it there.
#   pwsh scripts/deploy.ps1 [-Build] [-Target vps]
# Ships the code and the compiled assets in public/build; the server keeps its own
# .env, storage/, public/media and vendor/ (installed there with composer --no-dev).
param([switch]$Build, [string]$Target = 'vps')

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent

if ($Build) {
    Push-Location $root
    try { npm run build; if ($LASTEXITCODE) { throw 'Asset build failed.' } } finally { Pop-Location }
}
if (-not (Test-Path "$root\public\build\manifest.json")) { throw 'public/build is missing: run with -Build first.' }

# Password login without a prompt: the helper reads the encrypted credential in ~/.ssh.
if (-not $env:SSH_ASKPASS) { $env:SSH_ASKPASS = "$env:USERPROFILE\.ssh\vps-askpass.cmd"; $env:SSH_ASKPASS_REQUIRE = 'force' }

$archive = Join-Path $env:TEMP 'library-release.tgz'
tar -czf $archive -C $root `
    --exclude=public/media --exclude=public/storage --exclude=public/hot `
    --exclude='bootstrap/cache/*.php' --exclude=database/*.sqlite `
    app bootstrap config database lang public resources/views routes artisan composer.json composer.lock
if ($LASTEXITCODE) { throw 'Could not build the release archive.' }
'Archive: {0:N1} MB' -f ((Get-Item $archive).Length / 1MB)

$remote = @'
set -euo pipefail
APP=/var/www/library
TMP=$(mktemp -d)
trap 'rm -rf "$TMP" /tmp/library-release.tgz' EXIT
tar -xzf /tmp/library-release.tgz -C "$TMP"

rsync -rlt --delete --chmod=D755,F644 \
    --exclude=/.env --exclude=/storage --exclude=/vendor \
    --exclude=/public/media --exclude=/public/storage --exclude=/bootstrap/cache \
    "$TMP"/ "$APP"/

# Only these are writable by the web server; the code itself stays read-only to it.
sudo mkdir -p "$APP"/storage/app/{public,private,library} "$APP"/storage/framework/{cache/data,sessions,views} \
    "$APP"/storage/logs "$APP"/bootstrap/cache "$APP"/public/media/covers
sudo chown -R www-data:www-data "$APP"/storage "$APP"/bootstrap/cache "$APP"/public/media
ln -sfn "$APP"/storage/app/public "$APP"/public/storage

cd "$APP"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --quiet
artisan() { sudo -u www-data php artisan "$@"; }
artisan package:discover --ansi >/dev/null
artisan migrate --force
artisan db:seed --force
artisan optimize >/dev/null
sudo systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
echo "Deployed: $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/healthz) from /healthz"
'@

# ssh is started from a local folder: the askpass helper cannot run from a network share.
Push-Location $env:TEMP
try {
    scp -q $archive "${Target}:/tmp/library-release.tgz"
    if ($LASTEXITCODE) { throw 'Upload failed.' }
    $remote.Replace("`r", '') | ssh $Target 'bash -s'
    if ($LASTEXITCODE) { throw 'Remote deployment step failed.' }
} finally {
    Pop-Location
    Remove-Item $archive -ErrorAction SilentlyContinue
}
