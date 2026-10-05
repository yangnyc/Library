# Requests the main public URLs of a running instance and prints status codes.
#   pwsh scripts/smoke.ps1 [-Base http://127.0.0.1:8000]
param([string]$Base = 'http://127.0.0.1:8000')

$paths = @(
    '/', '/en', '/ru', '/he', '/en/catalog', '/he/catalog?q=lighthouse', '/ru/catalog?q=%D0%BC%D0%B0%D1%8F%D0%BA%D0%B0',
    '/en/catalog?language=he&view=list', '/en/works/lighthouse-keepers-almanac', '/en/editions/almanakh-shomer-hamigdalor-he',
    '/he/editions/lighthouse-keepers-almanac-en', '/en/editions/short-guide-to-the-reading-room-en',
    '/en/people/demo-author', '/en/collections', '/ru/collections/demonstration-shelf', '/en/categories/poetry',
    '/en/help/reading', '/ru/help/kindle', '/he/help/offline', '/en/help/downloads', '/en/about', '/he/accessibility', '/ru/privacy',
    '/en/contact', '/en/rights', '/en/read/lighthouse-keepers-almanac-en', '/en/read/almanakh-shomer-hamigdalor-he/epub',
    '/en/read/short-guide-to-the-reading-room-en/pdf', '/en/offline', '/login', '/register', '/sitemap.xml', '/robots.txt',
    '/manifest.webmanifest', '/healthz', '/api/session', '/sw.js', '/en/nope', '/admin'
)

$failed = 0
foreach ($path in $paths) {
    $code = [int](curl.exe -s -o NUL -w '%{http_code}' --max-time 60 ($Base + $path))
    $expected = if ($path -eq '/en/nope') { 404 } elseif ($path -in '/', '/admin') { 302 } else { 200 }
    $mark = if ($code -eq $expected) { 'ok  ' } else { $failed++; 'FAIL' }
    '{0} {1} {2}' -f $mark, $code, $path
}
"$failed unexpected"
exit $failed
