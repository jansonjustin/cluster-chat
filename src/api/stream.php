<?php
require_once __DIR__ . '/../db.php';

// Kill every layer of buffering that could swallow SSE tokens:
// 1. PHP output buffer
while (ob_get_level()) ob_end_clean();
// 2. zlib compression (overrides Content-Encoding header if left on)
if (ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', '0');
}
// 3. Implicit flush so every echo() goes straight to the socket
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Content-Encoding: identity'); // Stop mod_deflate buffering the stream
header('X-Accel-Buffering: no');      // Traefik: honour X-Accel-Buffering, don't buffer
// Apache-specific: belt-and-suspenders mod_deflate kill
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
    apache_setenv('dont-vary', '1');
}

function sse(string $type, mixed $data): void {
    echo 'data: ' . json_encode(['type' => $type] + (array)$data) . "\n\n";
    flush();
}

function sse_error(string $msg): void {
    sse('error', ['message' => $msg]);
    exit;
}

try {
    $db = get_db();

    $input = json_decode(file_get_contents('php://input'), true);
    $chat_id   = (int)($input['chat_id'] ?? 0);
    $content   = trim($input['content'] ?? '');
    $files     = $input['files'] ?? [];     // [{path, type, name}]
    $agent_id  = (int)($input['agent_id'] ?? 0);

    if (!$content && !$files) sse_error('Message cannot be empty');

    // Create chat if needed
    if (!$chat_id) {
        if (!$agent_id) sse_error('agent_id required for new chat');
        $stmt = $db->prepare("INSERT INTO chats (agent_id, title) VALUES (?, ?)");
        $stmt->execute([$agent_id, sanitize_title($content)]);
        $chat_id = (int)$db->lastInsertId();
        sse('chat_created', ['chat_id' => $chat_id, 'title' => sanitize_title($content)]);
    } else {
        $chat = $db->prepare("SELECT agent_id FROM chats WHERE id = ?")->execute([$chat_id]);
        $row  = $db->query("SELECT agent_id FROM chats WHERE id = $chat_id")->fetch();
        if (!$row) sse_error('Chat not found');
        $agent_id = $row['agent_id'];
    }

    // Get agent + models
    $agent = $db->prepare("
        SELECT a.*,
               cm.host_url AS chat_host, cm.model_name AS chat_model, cm.api_format AS chat_format,
               vm.host_url AS vision_host, vm.model_name AS vision_model, vm.api_format AS vision_format
        FROM agents a
        LEFT JOIN models cm ON a.chat_model_id = cm.id
        LEFT JOIN models vm ON a.vision_model_id = vm.id
        WHERE a.id = ?
    ");
    $agent->execute([$agent_id]);
    $agent = $agent->fetch();
    if (!$agent) sse_error('Agent not found');

    // Determine if we have images and should use vision model
    $has_images = false;
    foreach ($files as $f) {
        if (str_starts_with($f['type'] ?? '', 'image/')) { $has_images = true; break; }
    }
    $use_vision = $has_images && $agent['vision_model'] && $agent['vision_host'];
    $host       = $use_vision ? rtrim($agent['vision_host'], '/') : rtrim($agent['chat_host'] ?? '', '/');
    $model      = $use_vision ? $agent['vision_model'] : $agent['chat_model'];
    $api_fmt    = $use_vision ? $agent['vision_format'] : $agent['chat_format'];

    if (!$host || !$model) sse_error('No chat model configured for this agent');

    // Save user message
    $media_path = $files ? json_encode($files) : null;
    $media_type = $files ? 'files' : null;
    $stmt = $db->prepare("INSERT INTO messages (chat_id, role, content, media_path, media_type) VALUES (?, 'user', ?, ?, ?)");
    $stmt->execute([$chat_id, $content, $media_path, $media_type]);
    $user_msg_id = (int)$db->lastInsertId();
    sse('user_saved', ['message_id' => $user_msg_id]);

    // Update chat timestamp
    $db->exec("UPDATE chats SET updated_at = CURRENT_TIMESTAMP WHERE id = $chat_id");

    // Build messages array
    $history = $db->prepare("SELECT role, content, media_path FROM messages WHERE chat_id = ? ORDER BY id ASC");
    $history->execute([$chat_id]);
    $history = $history->fetchAll();

    $messages = [];
    if ($agent['system_prompt']) {
        $messages[] = ['role' => 'system', 'content' => $agent['system_prompt']];
    }

    foreach ($history as $msg) {
        $msg_content = $msg['content'];
        $entry = ['role' => $msg['role']];

        // Handle vision: attach images for the last user message with images
        if ($msg['role'] === 'user' && $msg['media_path'] && $use_vision) {
            $attached = json_decode($msg['media_path'], true) ?? [];
            $parts = [['type' => 'text', 'text' => $msg_content]];
            foreach ($attached as $f) {
                if (str_starts_with($f['type'] ?? '', 'image/')) {
                    $imgPath = UPLOAD_PATH . '/' . basename($f['path']);
                    if (file_exists($imgPath)) {
                        $b64 = base64_encode(file_get_contents($imgPath));
                        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$f['type']};base64,$b64"]];
                    }
                }
            }
            $entry['content'] = $parts;
        } else {
            $entry['content'] = $msg_content;
        }

        if ($msg['role'] !== 'system') $messages[] = $entry;
    }

    // Detect client disconnect so we can abort the Ollama curl connection.
    // Without this, PHP holds the connection open after the browser leaves,
    // locking Ollama (especially with OLLAMA_MAX_LOADED_MODELS=1).
    ignore_user_abort(false);

    // Send an immediate heartbeat so the browser knows the connection is live
    // before the model starts generating (cold loads can take minutes).
    echo ": connected\n\n";
    flush();

    // Call the model API
    $full_response  = '';
    $last_heartbeat = time();

    // Shared write callback factory — sends SSE tokens and periodic heartbeat
    // pings (': ping') so the browser/Traefik don't drop a silent connection
    // during cold model loads.
    $make_writer = function(string $fmt) use (&$full_response, &$last_heartbeat): Closure {
        return function($ch, $data) use ($fmt, &$full_response, &$last_heartbeat): int {
            // Heartbeat every 15 s while waiting for first / next token
            if (time() - $last_heartbeat >= 15) {
                echo ": ping\n\n";
                flush();
                $last_heartbeat = time();
            }

            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line) continue;

                if ($fmt === 'ollama') {
                    $obj   = json_decode($line, true);
                    $token = $obj['message']['content'] ?? '';
                } elseif ($fmt === 'ollama-generate') {
                    $obj   = json_decode($line, true);
                    $token = $obj['response'] ?? '';
                } else {
                    // OpenAI SSE
                    if ($line === 'data: [DONE]') continue;
                    if (str_starts_with($line, 'data: ')) $line = substr($line, 6);
                    $obj   = json_decode($line, true);
                    $token = $obj['choices'][0]['delta']['content'] ?? '';
                }

                if ($token !== '') {
                    $full_response .= $token;
                    sse('token', ['content' => $token]);
                    $last_heartbeat = time(); // real traffic resets timer
                }
            }
            return strlen($data);
        };
    };

    // Build URL + payload for each API format
    if ($api_fmt === 'ollama-generate') {
        $url = "$host/api/generate";
        // Flatten chat history into a prompt string (generate has no messages array)
        $system_msg   = '';
        $prompt_parts = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $system_msg = $m['content']; continue; }
            $prefix = $m['role'] === 'user' ? 'User' : 'Assistant';
            $text   = is_string($m['content']) ? $m['content'] : json_encode($m['content']);
            $prompt_parts[] = "$prefix: $text";
        }
        $payload = array_filter([
            'model'  => $model,
            'prompt' => implode("\n\n", $prompt_parts),
            'system' => $system_msg ?: null,
            'stream' => true,
        ]);
    } elseif ($api_fmt === 'ollama') {
        $url     = "$host/api/chat";
        $payload = ['model' => $model, 'messages' => $messages, 'stream' => true];
    } else {
        // openai-compatible
        $url     = "$host/v1/chat/completions";
        $payload = ['model' => $model, 'messages' => $messages, 'stream' => true];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_WRITEFUNCTION  => $make_writer($api_fmt),

        // Progress callback fires every ~1s regardless of whether data is flowing.
        // This is the ONLY way to send heartbeats during a thinking model's silent
        // reasoning phase — the write callback never fires until Ollama finishes
        // buffering the entire <think> block and starts streaming real tokens.
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow, $ulTotal, $ulNow)
                                    use (&$last_heartbeat) {
            // Client disconnected — abort the Ollama transfer immediately.
            // This is critical: without it, PHP holds the curl connection open
            // after the browser leaves, freezing Ollama until it's restarted.
            if (connection_aborted()) {
                return 1; // non-zero = abort curl
            }
            if (time() - $last_heartbeat >= 10) {
                echo ": ping

";
                flush();
                $last_heartbeat = time();
            }
            return 0;
        },
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) sse_error('Model API error: ' . curl_error($ch));
    curl_close($ch);

    // Save assistant response
    $stmt = $db->prepare("INSERT INTO messages (chat_id, role, content) VALUES (?, 'assistant', ?)");
    $stmt->execute([$chat_id, $full_response]);
    $asst_msg_id = (int)$db->lastInsertId();

    // Auto-update chat title from first exchange if still default
    $chat_row = $db->query("SELECT title FROM chats WHERE id = $chat_id")->fetch();
    if ($chat_row['title'] === 'New Chat' && $content) {
        $new_title = sanitize_title($content);
        $db->prepare("UPDATE chats SET title = ? WHERE id = ?")->execute([$new_title, $chat_id]);
    }

    sse('done', ['message_id' => $asst_msg_id, 'chat_id' => $chat_id]);

} catch (Throwable $e) {
    sse_error($e->getMessage());
}
