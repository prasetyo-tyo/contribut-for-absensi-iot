<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

include_once '../config/database.php';
require_once dirname(__DIR__, 2) . '/shared/card_security.php';

$debugLogPath = __DIR__ . '/upload_foto_absen_debug.log';
@file_put_contents(
    $debugLogPath,
    sprintf(
        "[%s] %s IP=%s UA=%s QUERY=%s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['REMOTE_ADDR'] ?? '-',
        $_SERVER['HTTP_USER_AGENT'] ?? '-',
        $_SERVER['QUERY_STRING'] ?? '-'
    ),
    FILE_APPEND
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$absenId = isset($_GET['absen_id']) ? (int) $_GET['absen_id'] : 0;
$uid = isset($_GET['uid']) ? trim((string) $_GET['uid']) : '';
$status = isset($_GET['status']) ? strtoupper(trim((string) $_GET['status'])) : '';
$timestamp = isset($_GET['ts']) ? (string) $_GET['ts'] : '';
$signature = isset($_GET['sig']) ? strtolower((string) $_GET['sig']) : '';

if (
    $absenId <= 0 ||
    $uid === '' ||
    !in_array($status, ['IN', 'OUT'], true) ||
    !camera_verify_upload_signature($absenId, $uid, $status, $timestamp, $signature)
) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid signature or payload"]);
    exit;
}

$rawImage = file_get_contents('php://input');
if ($rawImage === false || $rawImage === '') {
    @file_put_contents($debugLogPath, sprintf("[%s] EMPTY_BODY\n", date('Y-m-d H:i:s')), FILE_APPEND);
    http_response_code(400);
    echo json_encode(["error" => "Image body is required"]);
    exit;
}

if (strlen($rawImage) > 5 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(["error" => "Image too large"]);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->buffer($rawImage);
$contentLength = $_SERVER['CONTENT_LENGTH'] ?? '-';
$transferEncoding = $_SERVER['HTTP_TRANSFER_ENCODING'] ?? '-';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '-';
@file_put_contents(
    $debugLogPath,
    sprintf(
        "[%s] CONTENT_LENGTH=%s BODY_LEN=%d TE=%s CT=%s MIME=%s CONNECTION_STATUS=%d ABORTED=%d\n",
        date('Y-m-d H:i:s'),
        $contentLength,
        strlen($rawImage),
        $transferEncoding,
        $contentType,
        $mimeType ?: '-',
        connection_status(),
        connection_aborted()
    ),
    FILE_APPEND
);
$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
];

if (!isset($allowedMimeTypes[$mimeType])) {
    http_response_code(415);
    echo json_encode(["error" => "Only JPEG images are supported"]);
    exit;
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

$stmt = $db->prepare("SELECT id, uid, status FROM data_absen WHERE id = :id AND LOWER(uid) = LOWER(:uid) AND UPPER(status) = :status LIMIT 1");
$stmt->bindParam(':id', $absenId, PDO::PARAM_INT);
$stmt->bindParam(':uid', $uid);
$stmt->bindParam(':status', $status);
$stmt->execute();
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$attendance) {
    @file_put_contents(
        $debugLogPath,
        sprintf("[%s] ATTENDANCE_NOT_FOUND absen_id=%d uid=%s status=%s\n", date('Y-m-d H:i:s'), $absenId, $uid, $status),
        FILE_APPEND
    );
    http_response_code(404);
    echo json_encode(["error" => "Attendance record not found"]);
    exit;
}

$captureTime = date('Y-m-d H:i:s', (int) $timestamp);
$datePath = date('Y/m/d', (int) $timestamp);
$baseDir = dirname(__DIR__, 2) . '/uploads/absen/' . $datePath;

if (!is_dir($baseDir) && !mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
    @file_put_contents(
        $debugLogPath,
        sprintf("[%s] MKDIR_FAILED path=%s\n", date('Y-m-d H:i:s'), $baseDir),
        FILE_APPEND
    );
    http_response_code(500);
    echo json_encode(["error" => "Failed to create upload directory"]);
    exit;
}

$fileName = sprintf(
    'absen_%d_%s_%s.jpg',
    $absenId,
    strtolower($status),
    date('Ymd_His', (int) $timestamp)
);

$relativePath = 'uploads/absen/' . $datePath . '/' . $fileName;
$absolutePath = dirname(__DIR__, 2) . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

try {
    $db->beginTransaction();

    $existingStmt = $db->prepare("SELECT id, foto_path FROM data_absen_foto WHERE absen_id = :absen_id LIMIT 1");
    $existingStmt->bindParam(':absen_id', $absenId, PDO::PARAM_INT);
    $existingStmt->execute();
    $existingPhoto = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if (file_put_contents($absolutePath, $rawImage) === false) {
        throw new RuntimeException('Failed to save image file');
    }

    if ($existingPhoto) {
        $updateStmt = $db->prepare("UPDATE data_absen_foto SET foto_path = :foto_path, captured_at = :captured_at WHERE absen_id = :absen_id");
        $updateStmt->bindParam(':foto_path', $relativePath);
        $updateStmt->bindParam(':captured_at', $captureTime);
        $updateStmt->bindParam(':absen_id', $absenId, PDO::PARAM_INT);
        $updateStmt->execute();

        $oldPath = dirname(__DIR__, 2) . '/' . str_replace('/', DIRECTORY_SEPARATOR, $existingPhoto['foto_path']);
        if (
            $existingPhoto['foto_path'] !== $relativePath &&
            is_file($oldPath)
        ) {
            @unlink($oldPath);
        }
    } else {
        $insertStmt = $db->prepare("INSERT INTO data_absen_foto (absen_id, uid, status, foto_path, captured_at) VALUES (:absen_id, :uid, :status, :foto_path, :captured_at)");
        $insertStmt->bindParam(':absen_id', $absenId, PDO::PARAM_INT);
        $insertStmt->bindParam(':uid', $uid);
        $insertStmt->bindParam(':status', $status);
        $insertStmt->bindParam(':foto_path', $relativePath);
        $insertStmt->bindParam(':captured_at', $captureTime);
        $insertStmt->execute();
    }

    $db->commit();
    @file_put_contents(
        $debugLogPath,
        sprintf("[%s] OK absen_id=%d path=%s\n", date('Y-m-d H:i:s'), $absenId, $relativePath),
        FILE_APPEND
    );
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (isset($absolutePath) && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
    @file_put_contents(
        $debugLogPath,
        sprintf("[%s] EXCEPTION %s\n", date('Y-m-d H:i:s'), $e->getMessage()),
        FILE_APPEND
    );
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}

http_response_code(200);
echo json_encode([
    "status" => "OK",
    "absen_id" => $absenId,
    "foto_path" => $relativePath,
    "captured_at" => $captureTime,
]);
