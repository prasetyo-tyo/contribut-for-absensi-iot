<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$logPath = __DIR__ . '/camera_debug_runtime.log';
$device = isset($_GET['device']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $_GET['device']) : 'unknown';
$outletId = isset($_GET['outlet_id']) ? (int) $_GET['outlet_id'] : 0;
$stage = isset($_GET['stage']) ? substr(trim((string) $_GET['stage']), 0, 100) : '-';
$detail = isset($_GET['detail']) ? substr(trim((string) $_GET['detail']), 0, 500) : '-';
$ts = isset($_GET['ts']) ? (string) $_GET['ts'] : (string) time();

$line = sprintf(
    "[%s] device=%s outlet_id=%d stage=%s detail=%s ip=%s ts=%s\n",
    date('Y-m-d H:i:s'),
    $device,
    $outletId,
    str_replace(["\r", "\n"], ' ', $stage),
    str_replace(["\r", "\n"], ' ', $detail),
    $_SERVER['REMOTE_ADDR'] ?? '-',
    $ts
);

$ok = @file_put_contents($logPath, $line, FILE_APPEND);

if ($ok === false) {
    http_response_code(500);
    echo json_encode(['status' => 'ERROR', 'message' => 'Failed to write log']);
    exit;
}

http_response_code(200);
echo json_encode(['status' => 'OK']);
