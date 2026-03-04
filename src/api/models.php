<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);

switch ($method) {
    case 'GET':
        $type = $_GET['type'] ?? '';
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM models WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) json_error('Not found', 404);
            json_response($row);
        } elseif ($type) {
            $stmt = $db->prepare("SELECT * FROM models WHERE type = ? ORDER BY display_name");
            $stmt->execute([$type]);
            json_response($stmt->fetchAll());
        } else {
            json_response($db->query("SELECT * FROM models ORDER BY type, display_name")->fetchAll());
        }
        break;

    case 'POST':
        $d = json_decode(file_get_contents('php://input'), true);
        foreach (['host_url','model_name','display_name','type'] as $f) {
            if (empty($d[$f])) json_error("$f required");
        }
        $valid_types = ['chat','tts','whisper','vision'];
        if (!in_array($d['type'], $valid_types)) json_error('Invalid type');
        $stmt = $db->prepare("INSERT INTO models (host_url, model_name, display_name, type, api_format) VALUES (?,?,?,?,?)");
        $stmt->execute([
            rtrim(trim($d['host_url']), '/'),
            trim($d['model_name']),
            trim($d['display_name']),
            $d['type'],
            in_array($d['api_format'] ?? '', ['ollama','ollama-generate','openai','wyoming']) ? $d['api_format'] : 'ollama',
        ]);
        $new_id = (int)$db->lastInsertId();
        json_response($db->query("SELECT * FROM models WHERE id = $new_id")->fetch(), 201);
        break;

    case 'PUT':
        if (!$id) json_error('id required');
        $d = json_decode(file_get_contents('php://input'), true);
        foreach (['host_url','model_name','display_name','type'] as $f) {
            if (empty($d[$f])) json_error("$f required");
        }
        $stmt = $db->prepare("UPDATE models SET host_url=?, model_name=?, display_name=?, type=?, api_format=? WHERE id=?");
        $stmt->execute([
            rtrim(trim($d['host_url']), '/'),
            trim($d['model_name']),
            trim($d['display_name']),
            $d['type'],
            in_array($d['api_format'] ?? '', ['ollama','ollama-generate','openai','wyoming']) ? $d['api_format'] : 'ollama',
            $id,
        ]);
        json_response($db->query("SELECT * FROM models WHERE id = $id")->fetch());
        break;

    case 'DELETE':
        if (!$id) json_error('id required');
        $db->prepare("DELETE FROM models WHERE id = ?")->execute([$id]);
        json_response(['ok' => true]);
        break;

    default:
        json_error('Method not allowed', 405);
}
