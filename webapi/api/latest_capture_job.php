<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

include_once '../config/database.php';
require_once dirname(__DIR__, 2) . '/shared/card_security.php';

$debugLogPath = __DIR__ . '/latest_capture_job_debug.log';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '-';
@file_put_contents(
    $debugLogPath,
    sprintf(
        "[%s] %s IP=%s UA=%s\n",
        date('Y-m-d H:i:s'),
        $requestUri,
        $remoteAddr,
        $_SERVER['HTTP_USER_AGENT'] ?? '-'
    ),
    FILE_APPEND
);

try {
    $outletId = isset($_GET['outlet_id']) ? (int) $_GET['outlet_id'] : 0;
    $timestamp = isset($_GET['ts']) ? (string) $_GET['ts'] : '';
    $signature = isset($_GET['sig']) ? strtolower((string) $_GET['sig']) : '';

    if ($outletId <= 0 || !camera_verify_job_signature($outletId, $timestamp, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature or payload']);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new RuntimeException('Database connection failed');
    }

    $sql = "SELECT data_absen.id, data_absen.uid, data_absen.status, data_absen.outlet_id, data_absen.created_at
            FROM data_absen
            LEFT JOIN data_absen_foto ON data_absen.id = data_absen_foto.absen_id
            WHERE data_absen.outlet_id = :outlet_id
              AND data_absen_foto.id IS NULL
              AND data_absen.created_at >= (NOW() - INTERVAL 15 MINUTE)
            ORDER BY data_absen.id DESC
            LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':outlet_id', $outletId, PDO::PARAM_INT);
    $stmt->execute();
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'No pending capture job']);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'OK',
        'absen_id' => (int) $job['id'],
        'uid' => $job['uid'],
        'scan_status' => $job['status'],
        'outlet_id' => (int) $job['outlet_id'],
        'created_at' => $job['created_at'],
        'ts' => (string) time(),
    ]);
} catch (Throwable $e) {
    @file_put_contents(
        $debugLogPath,
        sprintf("[%s] EXCEPTION %s\n", date('Y-m-d H:i:s'), $e->getMessage()),
        FILE_APPEND
    );
    http_response_code(500);
    echo json_encode([
        'error' => 'Server exception',
        'message' => $e->getMessage(),
    ]);
}
