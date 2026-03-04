<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('POST only', 405);

if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);

$uploaded = [];
$files = $_FILES['files'] ?? $_FILES['file'] ?? null;
if (!$files) json_error('No files uploaded');

// Normalize single vs multiple file upload
$normalized = [];
if (is_array($files['name'])) {
    for ($i = 0; $i < count($files['name']); $i++) {
        $normalized[] = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
    }
} else {
    $normalized[] = $files;
}

foreach ($normalized as $file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Upload error: ' . $file['error']);
    }
    $max_size = 50 * 1024 * 1024; // 50MB
    if ($file['size'] > $max_size) json_error('File too large (max 50MB)');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safe_name = bin2hex(random_bytes(8)) . '.' . ($ext ?: 'bin');
    $dest = UPLOAD_PATH . '/' . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_error('Failed to save file');
    }

    $uploaded[] = [
        'name' => $file['name'],
        'path' => $safe_name,
        'type' => $file['type'],
        'size' => $file['size'],
        'url'  => '/uploads/' . $safe_name,
    ];
}

json_response(['files' => $uploaded]);
