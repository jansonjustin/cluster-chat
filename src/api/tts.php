<?php
error_reporting(0); // Binary output — any stray warning/notice corrupts the WAV header
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}

$db    = get_db();
$input = json_decode(file_get_contents('php://input'), true);
$text  = trim($input['text'] ?? '');
$agent_id = (int)($input['agent_id'] ?? 0);
$debug = (bool)($input['debug'] ?? false);

if (!$text) { http_response_code(400); echo 'No text'; exit; }

// Debug override — allows debug_tts.php to pass host/model directly
if (!empty($input['_debug_host'])) {
    $host   = rtrim($input['_debug_host'], '/');
    $model  = $input['_debug_model'] ?? 'en_US-amy-medium';
    $format = $input['_debug_format'] ?? 'wyoming';
} else {
    $tts = null;
    if ($agent_id) {
        $stmt = $db->prepare("
            SELECT m.host_url, m.model_name, m.api_format
            FROM agents a JOIN models m ON a.tts_model_id = m.id
            WHERE a.id = ?
        ");
        $stmt->execute([$agent_id]);
        $tts = $stmt->fetch();
    }
    if (!$tts) {
        $tts = $db->query("SELECT host_url, model_name, api_format FROM models WHERE type='tts' LIMIT 1")->fetch();
    }
    if (!$tts) { http_response_code(404); echo 'No TTS model configured'; exit; }

    $host   = rtrim($tts['host_url'], '/');
    $model  = $tts['model_name'];
    $format = $tts['api_format'];
}

// ── Wyoming TCP (Piper) ────────────────────────────────────────────────────
if ($format === 'wyoming') {

    // Parse host:port — support piper:10200 or tcp://piper:10200
    $addr = preg_replace('#^tcp://#', '', $host);
    if (strpos($addr, ':') !== false) {
        [$wy_host, $wy_port] = explode(':', $addr, 2);
    } else {
        $wy_host = $addr;
        $wy_port = 10200;
    }
    $wy_port = (int)$wy_port;

    // ── Connect ──────────────────────────────────────────────────────────
    $sock = @fsockopen($wy_host, $wy_port, $errno, $errstr, 5);
    if (!$sock) {
        http_response_code(502);
        echo "Wyoming connect failed: $errstr ($errno) — host=$wy_host port=$wy_port";
        exit;
    }
    stream_set_blocking($sock, false);

    // ── Send Synthesize event ─────────────────────────────────────────────
    // Must include voice name — sending voice:null causes Piper 1.8.0 to error.
    $synthesize = json_encode([
        'type'           => 'synthesize',
        'data'           => [
            'text'  => $text,
            'voice' => ['name' => $model, 'language' => 'en_US', 'speaker' => null],
        ],
        'data_length'    => 0,
        'payload_length' => 0,
    ]) . "\n";
    fwrite($sock, $synthesize);

    // ── Wyoming 1.8.0 wire format ─────────────────────────────────────────
    // Each event = JSON header line \n
    //              + data_length bytes   (JSON metadata: rate/width/channels)
    //              + payload_length bytes (raw PCM audio)

    $pcm      = '';
    $rate     = 22050;
    $width    = 2;
    $channels = 1;
    $got_stop = false;
    $deadline = time() + 30;
    $buf      = '';

    while (!feof($sock) && time() < $deadline) {
        $r = [$sock]; $w = null; $e = null;
        if (!stream_select($r, $w, $e, 5)) continue;
        $chunk = fread($sock, 65536);
        if ($chunk === false || $chunk === '') continue;
        $buf .= $chunk;

        // Parse all complete events from the buffer
        while (true) {
            // Step 1: find the JSON header line
            $nl = strpos($buf, "\n");
            if ($nl === false) break;
            $line = trim(substr($buf, 0, $nl));
            if ($line === '') { $buf = substr($buf, $nl + 1); continue; }

            $evt = json_decode($line, true);
            if (!$evt) { $buf = substr($buf, $nl + 1); continue; }

            $type      = $evt['type']           ?? '';
            $data_len  = (int)($evt['data_length']    ?? 0);
            $pay_len   = (int)($evt['payload_length'] ?? 0);
            $total     = $nl + 1 + $data_len + $pay_len;

            // Step 2: wait until we have ALL bytes for this event
            if (strlen($buf) < $total) break;

            // Step 3: extract the two payloads
            $data_json = substr($buf, $nl + 1, $data_len);
            $pcm_chunk = substr($buf, $nl + 1 + $data_len, $pay_len);
            $buf       = substr($buf, $total);

            // Step 4: handle the event
            if ($type === 'audio-start') {
                $meta     = json_decode($data_json, true) ?? [];
                $rate     = (int)($meta['rate']     ?? 22050);
                $width    = (int)($meta['width']    ?? 2);
                $channels = (int)($meta['channels'] ?? 1);
            } elseif ($type === 'audio-chunk') {
                $pcm .= $pcm_chunk;
            } elseif ($type === 'audio-stop') {
                $got_stop = true;
                break 2;
            } elseif ($type === 'error') {
                fclose($sock);
                http_response_code(502);
                echo 'Wyoming error from server';
                exit;
            }
        }
    }
    fclose($sock);

    if (!$pcm) {
        http_response_code(502);
        echo "Wyoming: no audio (got_stop=" . ($got_stop?'y':'n') . " timeout=" . (time()>=$deadline?'y':'n') . ")";
        exit;
    }

        // ── Build WAV ─────────────────────────────────────────────────────────
    $pcm_len    = strlen($pcm);  // NOT $data_len — that's the Wyoming protocol field
    $byte_rate  = $rate * $channels * $width;
    $block_align = $channels * $width;
    $wav = pack('A4VA4A4VvvVVvvA4V',
        'RIFF', 36 + $pcm_len, 'WAVE',
        'fmt ', 16, 1, $channels, $rate,
        $byte_rate, $block_align, $width * 8,
        'data', $pcm_len
    );
    header('Content-Type: audio/wav');
    header('Content-Length: ' . (44 + $pcm_len));
    header('Cache-Control: no-cache');
    echo $wav . $pcm;
    exit;
}

// ── OpenAI-compatible HTTP TTS ─────────────────────────────────────────────
$url     = "$host/v1/audio/speech";
$payload = json_encode([
    'model'           => $model,
    'input'           => $text,
    'voice'           => 'af_heart',
    'response_format' => 'mp3',
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);

$audio = curl_exec($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err   = curl_error($ch);
curl_close($ch);

if ($err || $code >= 400) {
    http_response_code(502);
    echo "TTS error: $err (HTTP $code)";
    exit;
}

header('Content-Type: ' . ($ct ?: 'audio/mpeg'));
header('Cache-Control: no-cache');
echo $audio;
