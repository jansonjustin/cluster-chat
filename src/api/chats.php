<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);

switch ($method) {
    case 'GET':
        $agent_id = (int)($_GET['agent_id'] ?? 0);
        if ($id) {
            // Get single chat with messages
            $chat = $db->prepare("SELECT * FROM chats WHERE id = ?")->execute([$id]);
            $chat = $db->query("SELECT c.*, a.display_name as agent_name, a.avatar_color 
                                FROM chats c JOIN agents a ON c.agent_id = a.id 
                                WHERE c.id = $id")->fetch();
            if (!$chat) json_error('Chat not found', 404);
            $msgs = $db->prepare("SELECT * FROM messages WHERE chat_id = ? ORDER BY id ASC");
            $msgs->execute([$id]);
            $chat['messages'] = $msgs->fetchAll();
            json_response($chat);
        } elseif ($agent_id) {
            $stmt = $db->prepare("SELECT * FROM chats WHERE agent_id = ? ORDER BY updated_at DESC");
            $stmt->execute([$agent_id]);
            json_response($stmt->fetchAll());
        } else {
            json_response($db->query("SELECT c.*, a.display_name as agent_name 
                                      FROM chats c JOIN agents a ON c.agent_id = a.id 
                                      ORDER BY c.updated_at DESC LIMIT 100")->fetchAll());
        }
        break;

    case 'PATCH':
        if (!$id) json_error('id required');
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['title'])) {
            $db->prepare("UPDATE chats SET title = ? WHERE id = ?")->execute([trim($data['title']), $id]);
        }
        json_response(['ok' => true]);
        break;

    case 'DELETE':
        if (!$id) json_error('id required');
        $db->prepare("DELETE FROM chats WHERE id = ?")->execute([$id]);
        json_response(['ok' => true]);
        break;

    default:
        json_error('Method not allowed', 405);
}
