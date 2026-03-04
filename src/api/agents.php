<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);

$SELECT = "SELECT a.*,
    cm.display_name AS chat_model_name, cm.host_url AS chat_model_host, cm.model_name AS chat_model_model,
    tm.display_name AS tts_model_name,
    wm.display_name AS whisper_model_name,
    vm.display_name AS vision_model_name
FROM agents a
LEFT JOIN models cm ON a.chat_model_id = cm.id
LEFT JOIN models tm ON a.tts_model_id = tm.id
LEFT JOIN models wm ON a.whisper_model_id = wm.id
LEFT JOIN models vm ON a.vision_model_id = vm.id";

switch ($method) {
    case 'GET':
        if ($id) {
            $stmt = $db->prepare("$SELECT WHERE a.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) json_error('Not found', 404);
            json_response($row);
        } else {
            json_response($db->query("$SELECT ORDER BY a.display_name")->fetchAll());
        }
        break;

    case 'POST':
        $d = json_decode(file_get_contents('php://input'), true);
        if (empty($d['display_name'])) json_error('display_name required');
        $stmt = $db->prepare("INSERT INTO agents (display_name, chat_model_id, tts_model_id, whisper_model_id, vision_model_id, system_prompt, avatar_color)
                               VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($d['display_name']),
            $d['chat_model_id'] ?: null,
            $d['tts_model_id'] ?: null,
            $d['whisper_model_id'] ?: null,
            $d['vision_model_id'] ?: null,
            trim($d['system_prompt'] ?? ''),
            $d['avatar_color'] ?? '#00c8ff',
        ]);
        $new_id = (int)$db->lastInsertId();
        $stmt = $db->prepare("$SELECT WHERE a.id = ?");
        $stmt->execute([$new_id]);
        json_response($stmt->fetch(), 201);
        break;

    case 'PUT':
        if (!$id) json_error('id required');
        $d = json_decode(file_get_contents('php://input'), true);
        if (empty($d['display_name'])) json_error('display_name required');
        $stmt = $db->prepare("UPDATE agents SET display_name=?, chat_model_id=?, tts_model_id=?, whisper_model_id=?, vision_model_id=?, system_prompt=?, avatar_color=? WHERE id=?");
        $stmt->execute([
            trim($d['display_name']),
            $d['chat_model_id'] ?: null,
            $d['tts_model_id'] ?: null,
            $d['whisper_model_id'] ?: null,
            $d['vision_model_id'] ?: null,
            trim($d['system_prompt'] ?? ''),
            $d['avatar_color'] ?? '#00c8ff',
            $id,
        ]);
        $stmt = $db->prepare("$SELECT WHERE a.id = ?");
        $stmt->execute([$id]);
        json_response($stmt->fetch());
        break;

    case 'DELETE':
        if (!$id) json_error('id required');
        $db->prepare("DELETE FROM agents WHERE id = ?")->execute([$id]);
        json_response(['ok' => true]);
        break;

    default:
        json_error('Method not allowed', 405);
}
