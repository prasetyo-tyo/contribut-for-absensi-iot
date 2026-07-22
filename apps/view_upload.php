<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$file = isset($_GET['file']) ? trim((string) $_GET['file']) : '';
$file = str_replace('\\', '/', $file);
$file = preg_replace('#^\.\./#', '', $file);
$file = ltrim($file, '/');

if ($file === '' || strpos($file, '..') !== false) {
    http_response_code(400);
    exit('Invalid file');
}

$allowedPrefixes = [
    'uploads/absen/',
    'uploads/karyawan/',
];

$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (strpos($file, $prefix) === 0) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$uploadsRoot = realpath(dirname(__DIR__) . '/uploads');
$targetPath = realpath(dirname(__DIR__) . '/' . $file);

if ($uploadsRoot === false || $targetPath === false || strpos($targetPath, $uploadsRoot) !== 0 || !is_file($targetPath)) {
    http_response_code(404);
    exit('File not found');
}

$mimeType = '';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($targetPath);
} elseif (function_exists('mime_content_type')) {
    $mimeType = (string) mime_content_type($targetPath);
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(415);
    exit('Unsupported file');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($targetPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($targetPath);
