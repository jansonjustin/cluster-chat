<?php
// Serves uploaded files from the data volume
$file = basename($_GET['file'] ?? '');
if (!$file) { http_response_code(400); exit; }

$path = '/data/uploads/' . $file;
if (!file_exists($path)) { http_response_code(404); exit; }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
readfile($path);
