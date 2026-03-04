<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST only', 405);

$agent_id = (int)($_POST['agent_id'] ?? 0);
$db = get_db();

// Find whisper model for agent, fall back to first available
$whisper = null;
if ($agent_id) {
    $stmt = $db->prepare("
        SELECT m.host_url, m.model_name, m.api_format
        FROM agents a JOIN models m ON a.whisper_model_id = m.id
        WHERE a.id = ?
    ");
    $stmt->execute([$agent_id]);
    $whisper = $stmt->fetch();
}
if (!$whisper) {
    $whisper = $db->query("SELECT host_url, model_name, api_format FROM models WHERE type='whisper' LIMIT 1")->fetch();
}
if (!$whisper) json_error('No whisper model configured');

$audio_file = $_FILES['audio'] ?? null;
if (!$audio_file || $audio_file['error'] !== UPLOAD_ERR_OK) json_error('No audio file');

$host   = rtrim($whisper['host_url'], '/');
$model  = $whisper['model_name'];
$format = $whisper['api_format'];

// ── onerahmet/openai-whisper-asr-webservice (/asr endpoint) ───────────────
// This image doesn't implement /v1/audio/transcriptions — it has its own
// /asr endpoint with query params and an 'audio_file' field.
if ($format === 'openai') {
    // Try OpenAI-compatible endpoint first (/v1/audio/transcriptions)
    // Used by: faster-whisper-server, whisper.cpp server, etc.
    $url = "$host/v1/audio/transcriptions";
    $post_data = [
        'file'            => new CURLFile($audio_file['tmp_name'], $audio_file['type'] ?: 'audio/webm', 'audio.webm'),
        'model'           => $model,
        'response_format' => 'json',
        'language'        => 'en',
    ];
} else {
    // onerahmet/openai-whisper-asr-webservice native /asr endpoint
    $url = "$host/asr?encode=true&task=transcribe&language=en&output=json";
    $post_data = [
        'audio_file' => new CURLFile($audio_file['tmp_name'], $audio_file['type'] ?: 'audio/webm', 'audio.webm'),
    ];
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$result = curl_exec($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($err) json_error("Transcription curl error: $err");
if ($code >= 400) json_error("Transcription HTTP error: $code — " . substr($result, 0, 200));

$data = json_decode($result, true);

// Different servers return text in different fields — try them all
$text = $data['text']       // OpenAI-compat + onerahmet
     ?? $data['transcript'] // some older servers
     ?? $data['result']     // rare variants
     ?? null;

// onerahmet sometimes returns {"text":"..."} nested under segments or directly
if ($text === null && isset($data[0]['text'])) {
    $text = implode(' ', array_column($data, 'text'));
}

if ($text === null || trim($text) === '') {
    // Return the raw response to help debug
    json_error('Empty transcription. Raw response: ' . substr($result, 0, 300));
}

json_response(['text' => trim($text)]);
