<?php
/**
 * scan-poll.php
 * 
 * Endpoint untuk polling dari web form (data_karyawan-create.php).
 * Mengembalikan data scan kartu terbaru yang BELUM diambil (is_consumed = 0).
 * 
 * GET: scan-poll.php
 * 
 * Response: { ok: true, scan: { uid_fisik, token_kartu, internal_uid, scanned_at } }
 *           atau { ok: true, scan: null } jika tidak ada.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__, 2) . '/apps/config.php';

// Ambil scan terbaru yang belum diambil
$sql = "SELECT id, uid_fisik, token_kartu, internal_uid, scanned_at 
        FROM rfid_scan_queue 
        WHERE is_consumed = 0 
        ORDER BY id DESC 
        LIMIT 1";

$result = mysqli_query($link, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    
    // Auto-consume setelah diambil (bisa di-polling berkali-kali)
    $consume_sql = "UPDATE rfid_scan_queue SET is_consumed = 1 WHERE id = ?";
    if ($consume_stmt = mysqli_prepare($link, $consume_sql)) {
        mysqli_stmt_bind_param($consume_stmt, "i", $row['id']);
        mysqli_stmt_execute($consume_stmt);
        mysqli_stmt_close($consume_stmt);
    }
    
    echo json_encode([
        'ok' => true,
        'scan' => [
            'uid_fisik' => $row['uid_fisik'],
            'token_kartu' => $row['token_kartu'] ?? '',
            'internal_uid' => $row['internal_uid'] ?? '',
            'scanned_at' => $row['scanned_at']
        ]
    ]);
} else {
    // No scans pending
    echo json_encode([
        'ok' => true,
        'scan' => null
    ]);
}

mysqli_close($link);
?>
