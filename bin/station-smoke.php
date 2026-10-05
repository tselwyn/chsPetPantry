<?php
declare(strict_types=1);

/*
 * Checks that a PFPMS host serves the Station API the way tablets need it (docs/design/50-design-station.md §5.4).
 * Command line only. Run it on staging before any tablet is registered, and on production after every deploy:
 *
 *   php bin/station-smoke.php https://staging.example.org/
 *
 * It prints PASS or FAIL per check (what it saw and, on a FAIL only, what is likely wrong) and exits 1 on any FAIL. It
 * never sends a real credential. If the unknown-credential check says the credential is missing, the web host removes
 * the Authorization header: stop, tablets cannot work there until the hosting is fixed.
 *
 * Step 5 (S2: the Station's shell, worker and every file it precaches, GET and compared with the hashes the worker
 * verifies) needs Apache (.htaccess): on php -S the cache and nosniff checks report FAIL/WARN by design. While
 * app.maintenance is on, sw.php answers 503: that is one FAIL, and the checks that need the worker are skipped.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = $argv[1] ?? '';
if (!preg_match('~^https?://~i', $base)) {
    fwrite(STDERR, "Usage: php bin/station-smoke.php <base URL of public/, e.g. https://staging.example.org/>\n");
    exit(2);
}
$base = rtrim($base, '/') . '/';
$basePath = rtrim((string) (parse_url($base, PHP_URL_PATH) ?: '/'), '/') . '/';
/** @var list<bool> $results one per check */
$results = [];

/** @return array{status: int, headers: array<string, string>, cookies: list<string>, json: mixed, raw: string, error: string} */
function request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'ignore_errors' => true,
        'content' => $body ?? '', 'timeout' => 20, 'follow_location' => 0]]);
    $stream = @fopen($url, 'r', false, $context);
    $error = $stream === false ? (string) (error_get_last()['message'] ?? 'no response') : '';
    $meta = $stream !== false ? stream_get_meta_data($stream) : [];
    $raw = $stream !== false ? (string) stream_get_contents($stream) : '';
    if ($stream !== false) {
        fclose($stream);
    }
    $status = 0;
    $out = [];
    $cookies = [];
    foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
        $line = (string) $line;
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
            $status = (int) $m[1];
            $out = [];
            $cookies = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $out[$name] = trim($value);
            if ($name === 'set-cookie') {
                $cookies[] = trim($value);
            }
        }
    }
    return ['status' => $status, 'headers' => $out, 'cookies' => $cookies, 'json' => json_decode($raw, true), 'raw' => $raw, 'error' => $error];
}

/** "status 401, device_unknown", or the network error when there was no answer. */
function said(array $r): string
{
    if ($r['status'] === 0) {
        return 'no response: ' . $r['error'];
    }
    return "status {$r['status']}, " . (is_array($r['json']) ? (string) ($r['json']['error'] ?? 'ok') : 'not JSON');
}

/**
 * Prints one PASS or FAIL line. $detail is what was seen and is printed either way; $hint (what is likely wrong, or
 * what to do) is printed only on a FAIL, so a passing line never reads like a problem.
 */
function check(string $name, bool $ok, string $detail = '', string $hint = ''): bool
{
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . shown($detail, $ok ? '' : $hint) . "\n";
    return $ok;
}

/** A check that is reported but never fails the run (PASS or WARN; $hint only on a WARN). */
function warn(string $name, bool $ok, string $detail = '', string $hint = ''): void
{
    echo ($ok ? 'PASS  ' : 'WARN  ') . $name . shown($detail, $ok ? '' : $hint) . "\n";
}

/** "  (detail: hint)", "  (detail)", "  (hint)" or ''. */
function shown(string $detail, string $hint): string
{
    $text = implode(': ', array_filter([$detail, $hint], static fn(string $part): bool => $part !== ''));
    return $text !== '' ? "  ($text)" : '';
}

// 1. The server answers, says which build it serves, has found its web root, and is never cached.
$ping = request('GET', $base . 'api/ping.php', ['X-PFPMS-Client' => 'station']);
$pingJson = is_array($ping['json']) ? $ping['json'] : [];
$results[] = check('api/ping.php answers 200 JSON with a build', $ping['status'] === 200 && isset($pingJson['build']), said($ping));
$results[] = check('the server found its web root (tablet proofs sign "api/ping.php" here)', ($pingJson['script_path'] ?? null) === 'api/ping.php',
    'script_path = ' . var_export($pingJson['script_path'] ?? null, true), $ping['status'] === 200 ? 'set app.base_path in config' : '');
$results[] = check('api/ping.php is sent with Cache-Control no-store', str_contains($ping['headers']['cache-control'] ?? '', 'no-store'),
    $ping['headers']['cache-control'] ?? 'no Cache-Control');
$proxy = $ping['headers']['x-proxy-cache'] ?? null;
$results[] = check('no proxy cache serves the API', $ping['status'] === 200 && ($proxy === null || in_array(strtoupper($proxy), ['BYPASS', 'MISS'], true)),
    $ping['status'] !== 200 ? said($ping) : ($proxy ?? 'no x-proxy-cache header'));

// 2. An Authorization header reaches PHP (its value is never read there).
$auth = request('GET', $base . 'api/ping.php', ['Authorization' => 'PFPMS-Device x', 'X-PFPMS-Client' => 'station']);
$received = is_array($auth['json']) ? ($auth['json']['authorization_received'] ?? null) : null;
$results[] = check('the Authorization header reaches PHP', $received === true, 'authorization_received = ' . var_export($received, true)
    . '; ' . said($auth) . (isset($auth['headers']['x-proxy-cache']) ? '; x-proxy-cache ' . $auth['headers']['x-proxy-cache'] : ''));

// 3. The Station's own session cookie is scoped to the API.
$session = request('GET', $base . 'api/session.php', ['X-PFPMS-Client' => 'station']);
$stationCookie = '';
foreach ($session['cookies'] as $cookie) {
    if (str_starts_with($cookie, 'PFPMSST=')) {
        $stationCookie = $cookie;
    }
}
$results[] = check("api/session.php sets the Station cookie with path {$basePath}api/",
    $session['status'] === 200 && preg_match('~;\s*path=' . preg_quote($basePath . 'api/', '~') . '\s*(;|$)~i', $stationCookie) === 1,
    $stationCookie !== '' ? $stationCookie : said($session) . ', no PFPMSST cookie');

// 4. Error answers carry their headers.
$get = request('GET', $base . 'api/device/heartbeat.php', ['X-PFPMS-Client' => 'station']);
$results[] = check('a GET of the heartbeat is 405 with Allow: POST', $get['status'] === 405 && str_contains($get['headers']['allow'] ?? '', 'POST'),
    said($get) . ', Allow ' . ($get['headers']['allow'] ?? 'missing'));

// 5. A device call without a credential is refused as missing.
$json = ['Content-Type' => 'application/json'];
$missing = request('POST', $base . 'api/device/heartbeat.php', $json, '{}');
$results[] = check('a heartbeat without a credential is 401 device_credential_missing',
    $missing['status'] === 401 && is_array($missing['json']) && ($missing['json']['error'] ?? null) === 'device_credential_missing', said($missing));

// 6. A well-formed but unknown credential arrives and is refused as unknown.
$unknown = request('POST', $base . 'api/device/heartbeat.php', $json + ['Authorization' => 'PFPMS-Device pfd1_' . str_repeat('A', 43)], '{}');
$code = is_array($unknown['json']) ? ($unknown['json']['error'] ?? '?') : 'not JSON';
$hint = match (true) {
    $code === 'device_credential_missing' => 'THE HOST STRIPS Authorization. Stop: tablets cannot work here.',
    $unknown['status'] === 429 => 'rate limited by earlier runs; wait 15 minutes and run again',
    default => '',
};
$results[] = check('a heartbeat with an unknown credential is 401 device_unknown (the header arrived)',
    $unknown['status'] === 401 && $code === 'device_unknown', said($unknown), $hint);

// 7. Step 5 of 50-design §5.4 (S2): the Station's files reach the tablets as the service worker will verify them (§5.7).
$build = $pingJson['build'] ?? null;
$sw = request('GET', $base . 'station/sw.php');
$h = $sw['headers'];
$maintenance = $sw['status'] === 503;
$results[] = check('station/sw.php is JavaScript and never stored', $sw['status'] === 200 && str_contains($h['content-type'] ?? '', 'javascript')
    && str_contains($h['cache-control'] ?? '', 'no-store'), said($sw) . ', ' . ($h['content-type'] ?? 'no Content-Type') . ', ' . ($h['cache-control'] ?? 'no Cache-Control'),
    $maintenance ? 'app.maintenance is on, so no worker is served; run this again once the deploy is finished' : '');
if (!$maintenance) {
    $results[] = check('station/sw.php carries the Station CSP', str_contains($h['content-security-policy'] ?? '', 'trusted-types pfpms-sw'),
        $h['content-security-policy'] ?? 'no Content-Security-Policy');
}
if ($maintenance) {
    // No worker, so nothing below can be checked: each check would fail for this same reason, some with a hint that
    // blames something else (a proxy, the Station files). The FAIL above is the one report of it.
    warn('the worker, precache and shell checks', false, 'skipped: station/sw.php answered 503');
} elseif (str_contains($sw['raw'], 'SW_KILLED') && !str_contains($sw['raw'], 'const PRECACHE')) {
    warn('the precache checks', false, 'the kill switch (station.sw_kill) is on, so they were skipped');
} else {
    $workerBuild = preg_match('/^const BUILD = (".*?");$/m', $sw['raw'], $m) ? json_decode($m[1]) : null;
    $precache = preg_match('/^const PRECACHE = (\[.*\]);$/m', $sw['raw'], $m) ? json_decode($m[1], true) : null;
    $results[] = check('the worker serves the build api/ping.php reports', $workerBuild !== null && $workerBuild === $build,
        var_export($workerBuild, true) . ' vs ' . var_export($build, true));
    $results[] = check('the worker lists its precache', is_array($precache) && $precache !== [], is_array($precache) ? count($precache) . ' files' : 'no PRECACHE line');
    $results[] = check('the worker carries its code (js/sw-core.js)', str_contains($sw['raw'], 'var PFPMS_SW'), '', 'the server did not find its Station files');
    $empty = hash('sha256', '');
    $emptyFiles = is_array($precache) ? array_column(array_filter($precache, static fn($e): bool => ($e['sha256'] ?? '') === $empty), 'path') : [];
    $results[] = check('no precache file hashes as empty (the server found public/station or public_html/station)',
        is_array($precache) && $emptyFiles === [], implode(', ', $emptyFiles));
    foreach (is_array($precache) ? $precache : [] as $entry) {
        $path = (string) ($entry['path'] ?? '');
        $head = request('GET', $base . 'station/' . ($path === './' ? '' : $path));
        $type = strtolower($head['headers']['content-type'] ?? '');
        $results[] = check("station/$path is 200 with a " . ($entry['type'] ?? '?') . ' Content-Type',
            $head['status'] === 200 && str_contains($type, (string) ($entry['type'] ?? '?')), said($head) . ', ' . ($type ?: 'no Content-Type'));
        if ($path !== './') {
            // What a tablet installs: a stale copy (NGINX Direct Delivery, a CDN) or a wrong Station root fails every tablet's install.
            $results[] = check("station/$path is the file the worker verifies", hash('sha256', $head['raw']) === ($entry['sha256'] ?? ''), '',
                'a cache or proxy is serving another copy, or the server reads its files from the wrong directory');
            warn("station/$path is sent with nosniff", strtolower($head['headers']['x-content-type-options'] ?? '') === 'nosniff',
                $head['headers']['x-content-type-options'] ?? 'missing');
        }
        if (in_array($path, ['js/app.js', 'css/station.css'], true)) {
            $cc = strtolower($head['headers']['cache-control'] ?? '');
            $results[] = check("station/$path revalidates (the .htaccess rule is reached)",
                str_contains($cc, 'no-cache') || str_contains($cc, 'no-store') || str_contains($cc, 'max-age=0'), $cc ?: 'no Cache-Control',
                $cc === '' || str_contains($cc, 'max-age=6') ? 'NGINX Direct Delivery may serve it (switch it off)' : '');
            echo "      caching headers seen: x-proxy-cache=" . ($head['headers']['x-proxy-cache'] ?? '-') . ', server=' . ($head['headers']['server'] ?? '-')
                . ', expires=' . ($head['headers']['expires'] ?? '-') . ', cache-control=' . ($cc ?: '-') . "\n";
        }
    }
    $shell = request('GET', $base . 'station/');
    $sh = $shell['headers'];
    $shellCsp = $sh['content-security-policy'] ?? '';
    $results[] = check('the shell is never stored and carries the Station CSP', $shell['status'] === 200 && str_contains($sh['cache-control'] ?? '', 'no-store')
        && str_contains($shellCsp, "worker-src 'self'") && str_contains($shellCsp, 'trusted-types pfpms-sw'),
        said($shell) . ', ' . ($sh['cache-control'] ?? 'no Cache-Control'));
    $results[] = check('the shell is exactly the bytes the worker verifies', is_array($precache) && hash('sha256', $shell['raw']) === ($precache[0]['sha256'] ?? ''),
        '', 'a proxy or optimiser may be rewriting the page');
    $results[] = check('the shell names the build api/ping.php reports', is_string($build) && str_contains($shell['raw'], 'data-build="' . $build . '"'));
}

$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
