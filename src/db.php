<?php
define('DB_PATH', '/data/cluster-chat.db');
define('UPLOAD_PATH', '/data/uploads');

function get_db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
        init_db($db);
    }
    return $db;
}

function init_db(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            host_url TEXT NOT NULL,
            model_name TEXT NOT NULL,
            display_name TEXT NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('chat','tts','whisper','vision')),
            api_format TEXT NOT NULL DEFAULT 'ollama',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS agents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            display_name TEXT NOT NULL,
            chat_model_id INTEGER REFERENCES models(id) ON DELETE SET NULL,
            tts_model_id INTEGER REFERENCES models(id) ON DELETE SET NULL,
            whisper_model_id INTEGER REFERENCES models(id) ON DELETE SET NULL,
            vision_model_id INTEGER REFERENCES models(id) ON DELETE SET NULL,
            system_prompt TEXT DEFAULT '',
            avatar_color TEXT DEFAULT '#00c8ff',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            agent_id INTEGER NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
            title TEXT NOT NULL DEFAULT 'New Chat',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id INTEGER NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
            role TEXT NOT NULL CHECK(role IN ('user','assistant','system')),
            content TEXT NOT NULL DEFAULT '',
            media_path TEXT DEFAULT NULL,
            media_type TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_chats_agent ON chats(agent_id);
        CREATE INDEX IF NOT EXISTS idx_messages_chat ON messages(chat_id);
    ");

    // Migration: widen the api_format CHECK constraint to include 'wyoming'.
    // SQLite can't ALTER a CHECK constraint, so we do the rename dance.
    // Safe to run repeatedly — checks if 'wyoming' is already accepted first.
    try {
        $db->exec("INSERT INTO models (host_url, model_name, display_name, type, api_format)
                   VALUES ('__migrate_test__','__migrate_test__','__migrate_test__','chat','wyoming')");
        $db->exec("DELETE FROM models WHERE host_url = '__migrate_test__'");
    } catch (PDOException $e) {
        // wyoming not accepted — recreate table without the restrictive CHECK
        $db->exec("
            PRAGMA foreign_keys=OFF;
            BEGIN;
            CREATE TABLE models_new (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                host_url     TEXT NOT NULL,
                model_name   TEXT NOT NULL,
                display_name TEXT NOT NULL,
                type         TEXT NOT NULL CHECK(type IN ('chat','tts','whisper','vision')),
                api_format   TEXT NOT NULL DEFAULT 'ollama',
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            INSERT INTO models_new SELECT * FROM models;
            DROP TABLE models;
            ALTER TABLE models_new RENAME TO models;
            COMMIT;
            PRAGMA foreign_keys=ON;
        ");
    }

    // Seed default models if table is empty
    $count = $db->query('SELECT COUNT(*) FROM models')->fetchColumn();
    if ($count == 0) {
        $db->exec("
            INSERT INTO models (host_url, model_name, display_name, type, api_format) VALUES
            ('http://friendlyai:11434', 'llama3.2:latest', 'Llama 3.2', 'chat', 'ollama'),
            ('http://friendlyai:11434', 'llava:latest', 'LLaVA Vision', 'vision', 'ollama');

            INSERT INTO agents (display_name, chat_model_id, system_prompt, avatar_color) VALUES
            ('Cluster Assistant', 1, 'You are a helpful assistant for a homelab Docker Swarm cluster environment. Be concise and technical when appropriate.', '#00c8ff');
        ");
    }
}

function json_response(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

function sanitize_title(string $content): string {
    $title = preg_replace('/\s+/', ' ', substr(strip_tags($content), 0, 60));
    return trim($title) ?: 'New Chat';
}
