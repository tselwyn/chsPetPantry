<?php
declare(strict_types=1);

/*
 * Checks that a PFPMS host serves the Station API the way tablets need it (docs/design/50-design-station.md §5.4).
 * Command line only. Run it on staging before any tablet is registered, and on production after every deploy:
 *
 *   php bin/station-smoke.php https://staging.example.org/
 *
 * It prints PASS or FAIL per check and exits 1 on any FAIL. It never sends a real credential. If the unknown-credential
 * check says the credential is missing, the web host removes the Authorization header: stop, tablets cannot work there
 * until the hosting is fixed.
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

function check(string $name, bool $ok, string $detail = ''): bool
{
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? "  ($detail)" : '') . "\n";
    return $ok;
}

// 1. The server answers, says which build it serves, has found its web root, and is never cached.
$ping = request('GET', $base . 'api/ping.php', ['X-PFPMS-Client' => 'station']);
$pingJson = is_array($ping['json']) ? $ping['json'] : [];
$results[] = check('api/ping.php answers 200 JSON with a build', $ping['status'] === 200 && isset($pingJson['build']), said($ping));
$results[] = check('the server found its web root (tablet proofs sign "api/ping.php" here)', ($pingJson['script_path'] ?? null) === 'api/ping.php',
    'script_path = ' . var_export($pingJson['script_path'] ?? null, true)
    . ($ping['status'] === 200 && ($pingJson['script_path'] ?? null) !== 'api/ping.php' ? '; set app.base_path in config' : ''));
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
    $code === 'device_credential_missing' => ': THE HOST STRIPS Authorization. Stop: tablets cannot work here.',
    $unknown['status'] === 429 => ': rate limited by earlier runs; wait 15 minutes and run again',
    default => '',
};
$results[] = check('a heartbeat with an unknown credential is 401 device_unknown (the header arrived)',
    $unknown['status'] === 401 && $code === 'device_unknown', said($unknown) . $hint);

$failed = count(array_filter($results, static fn(bool $ok): bool => !$ok));
echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
