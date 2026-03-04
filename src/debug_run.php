<?php
// debug_run.php — SSE stream that replicates exactly what stream.php does
// and reports every decision, PHP setting, and curl event.

while (ob_get_level()) ob_end_clean();
if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', '0');
ob_implicit_flush(true);
ignore_user_abort(false);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Content-Encoding: identity');
header('X-Accel-Buffering: no');
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
    apache_setenv('dont-vary', '1');
}

function dbg(string $text, string $cls = ''): void {
    echo 'data: ' . json_encode(['text' => $text, 'cls' => $cls]) . "\n\n";
    flush();
}

$host  = rtrim($_GET['host']  ?? '', '/');
$model = $_GET['model'] ?? '';
$fmt   = $_GET['fmt']   ?? 'ollama';

if (!$host || !$model) { dbg('Missing host or model', 'err'); exit; }

dbg("=== Cluster Chat Stream Debug ===", 'ok');
dbg(date('Y-m-d H:i:s') . " UTC");
dbg("");

// ── PHP environment ──────────────────────────────────────────────────────
dbg("── PHP Environment ──", 'warn');
dbg("PHP version:            " . PHP_VERSION);
dbg("output_buffering:       " . (ini_get('output_buffering') ?: 'Off'));
dbg("zlib.output_compression:" . (ini_get('zlib.output_compression') ?: 'Off'));
dbg("curl.cainfo:            " . (ini_get('curl.cainfo') ?: '(not set)'));
dbg("curl extension:         " . (extension_loaded('curl') ? 'loaded' : 'MISSING'));
dbg("curl version:           " . (curl_version()['version'] ?? 'unknown'));
dbg("SSL version:            " . (curl_version()['ssl_version'] ?? 'unknown'));
dbg("");

// ── CA cert ──────────────────────────────────────────────────────────────
dbg("── CA Certificate ──", 'warn');
$cainfo = ini_get('curl.cainfo');
if ($cainfo) {
    if (file_exists($cainfo)) {
        dbg("curl.cainfo file EXISTS: $cainfo", 'ok');
        // Show subject of the cert
        $cert_content = file_get_contents($cainfo);
        if (strpos($cert_content, 'CERTIFICATE') !== false) {
            dbg("File contains PEM certificate data", 'ok');
        } else {
            dbg("WARNING: File exists but may not be valid PEM", 'warn');
        }
    } else {
        dbg("curl.cainfo set but FILE NOT FOUND: $cainfo", 'err');
    }
} else {
    dbg("curl.cainfo not set — using system bundle", 'warn');
}
dbg("");

// ── DNS resolution ────────────────────────────────────────────────────────
dbg("── DNS Resolution ──", 'warn');
$parsed = parse_url($host);
$hostname = $parsed['host'] ?? $host;
dbg("Resolving: $hostname");
$ip = gethostbyname($hostname);
if ($ip === $hostname) {
    dbg("DNS FAILED — could not resolve $hostname", 'err');
} else {
    dbg("Resolved to: $ip", 'ok');
}
dbg("");

// ── TCP connect test ──────────────────────────────────────────────────────
dbg("── TCP Connect ──", 'warn');
$port = $parsed['port'] ?? (($parsed['scheme'] ?? 'http') === 'https' ? 443 : 80);
dbg("Connecting to $hostname:$port ...");
$sock = @fsockopen(($parsed['scheme'] === 'https' ? 'ssl://' : '') . $hostname, $port, $errno, $errstr, 5);
if ($sock) {
    dbg("TCP connect OK", 'ok');
    fclose($sock);
} else {
    dbg("TCP connect FAILED: $errstr ($errno)", 'err');
}
dbg("");

// ── curl HEAD test (TLS + HTTP) ───────────────────────────────────────────
dbg("── curl TLS/HTTP Test ──", 'warn');
$test_url = $host . ($fmt === 'ollama' || $fmt === 'ollama-generate' ? '/api/tags' : '/v1/models');
dbg("GET $test_url");
$ch = curl_init($test_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_VERBOSE        => false,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
$tls  = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
curl_close($ch);

if ($err) {
    dbg("curl error: $err", 'err');
    if (strpos($err, 'SSL') !== false || strpos($err, 'certificate') !== false) {
        dbg("→ TLS/Certificate problem. CA cert may not be mounted or wrong path.", 'err');
    }
} else {
    dbg("HTTP $code — TLS verify result: $tls (0=ok)", $code < 400 ? 'ok' : 'err');
    if ($body) {
        $json = json_decode($body, true);
        if (isset($json['models'])) {
            $names = array_column($json['models'], 'name');
            dbg("Models on host: " . implode(', ', $names), 'ok');
            if (!in_array($model, $names)) {
                dbg("WARNING: '$model' not in model list!", 'warn');
            }
        }
    }
}
dbg("");

// ── Streaming test ────────────────────────────────────────────────────────
dbg("── Streaming Test ──", 'warn');
$test_msg = [['role' => 'user', 'content' => 'say hi in 3 words']];

if ($fmt === 'ollama-generate') {
    $stream_url = "$host/api/generate";
    $payload = ['model' => $model, 'prompt' => 'say hi in 3 words', 'stream' => true];
} elseif ($fmt === 'ollama') {
    $stream_url = "$host/api/chat";
    $payload = ['model' => $model, 'messages' => $test_msg, 'stream' => true];
} else {
    $stream_url = "$host/v1/chat/completions";
    $payload = ['model' => $model, 'messages' => $test_msg, 'stream' => true];
}

dbg("POST $stream_url");
dbg("Payload: " . json_encode($payload), 'dim');
dbg("");
dbg("Tokens received:", 'warn');

$token_count   = 0;
$first_token_t = null;
$start         = microtime(true);
$last_data     = microtime(true);
$raw_lines     = 0;

$ch = curl_init($stream_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_NOPROGRESS     => false,
    CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow, $ulTotal, $ulNow)
                                use (&$last_data, $start) {
        if (connection_aborted()) return 1;
        $idle = microtime(true) - $last_data;
        if ($idle > 5) {
            dbg(sprintf("  [%.1fs idle — waiting for model...]", microtime(true) - $start), 'dim');
            // Reset so we don't spam
            // (We can't mutate $last_data here without ref — use global trick)
        }
        return 0;
    },
    CURLOPT_WRITEFUNCTION  => function($ch, $data)
                              use ($fmt, &$token_count, &$first_token_t, &$last_data, &$raw_lines, $start) {
        $last_data = microtime(true);
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $raw_lines++;

            if ($fmt === 'ollama' || $fmt === 'ollama-generate') {
                $obj = json_decode($line, true);
                if (!$obj) {
                    dbg("  [unparseable line: " . substr($line,0,80) . "]", 'warn');
                    continue;
                }
                $token = $fmt === 'ollama'
                    ? ($obj['message']['content'] ?? '')
                    : ($obj['response'] ?? '');
                $done  = $obj['done'] ?? false;
                if ($token !== '') {
                    if ($first_token_t === null) {
                        $first_token_t = microtime(true);
                        dbg(sprintf("  First token in %.2fs", $first_token_t - $start), 'ok');
                    }
                    $token_count++;
                    if ($token_count <= 20) dbg("  tok[$token_count]: " . json_encode($token), 'tok');
                }
                if ($done) {
                    dbg(sprintf("  done:true received after %.2fs, %d tokens, %d raw lines",
                        microtime(true) - $start, $token_count, $raw_lines), 'ok');
                }
            } else {
                if ($line === 'data: [DONE]') {
                    dbg(sprintf("  [DONE] after %.2fs, %d tokens", microtime(true) - $start, $token_count), 'ok');
                    continue;
                }
                if (str_starts_with($line, 'data: ')) $line = substr($line, 6);
                $obj = json_decode($line, true);
                if (!$obj) continue;
                $token = $obj['choices'][0]['delta']['content'] ?? '';
                if ($token !== '') {
                    if ($first_token_t === null) {
                        $first_token_t = microtime(true);
                        dbg(sprintf("  First token in %.2fs", $first_token_t - $start), 'ok');
                    }
                    $token_count++;
                    if ($token_count <= 20) dbg("  tok[$token_count]: " . json_encode($token), 'tok');
                }
            }
        }
        return strlen($data);
    },
]);

curl_exec($ch);
$curl_err  = curl_error($ch);
$curl_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_errno = curl_errno($ch);
curl_close($ch);

dbg("");
dbg("── Results ──", 'warn');
if ($curl_err) {
    dbg("curl error ($curl_errno): $curl_err", 'err');
} else {
    dbg("HTTP response code: $curl_code", $curl_code === 200 ? 'ok' : 'err');
}
dbg("Total tokens: $token_count");
dbg("Raw lines received: $raw_lines");
dbg(sprintf("Total time: %.2fs", microtime(true) - $start));
if ($first_token_t) {
    dbg(sprintf("Time to first token: %.2fs", $first_token_t - $start));
}
dbg("");
dbg("=== Done ===", 'ok');
