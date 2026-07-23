<?php
/**
 * scan-register.php
 * 
 * Endpoint untuk menerima data scan kartu RFID dari ESP8266
 * dalam mode "reader" untuk registrasi karyawan baru.
 * 
 * POST JSON: { "uid_fisik": "D005A55F", "token_kartu": "abc123..." }
 * 
 * Menyimpan ke tabel rfid_scan_queue agar web form bisa
 * mengambil data via polling (scan-poll.php).
 * 
 * ESP mengirim request ini SAAT kartu di-tap pada reader.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once dirname(__DIR__, 2) . '/shared/card_security.php';

// Database connection
require_once dirname(__DIR__, 2) . '/apps/config.php';

// Parse input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || empty($input['uid_fisik'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'uid_fisik diperlukan']);
    exit;
}

$uid_fisik = strtoupper(trim($input['uid_fisik']));
$token_kartu = isset($input['token_kartu']) ? trim($input['token_kartu']) : '';

// Normalize values
$uid_fisik = card_normalize_value($uid_fisik);
if (!empty($token_kartu)) {
    $token_kartu = card_normalize_value($token_kartu);
}

// ═══════════════════════════════════════════════════════════════
// TOKEN KARTU
//
// Token berasal dari Block 2 kartu (dibaca oleh ESP saat scan).
// Jika token kosong atau sama dengan UID fisik, server auto-generate
// token unik 16 hex chars.
//
// NANTI: setelah write_kartu.ino diisi token unik, token akan berbeda
// dari UID fisik dan dikirim oleh ESP langsung.
// ═══════════════════════════════════════════════════════════════
if (empty($token_kartu) || card_normalize_value($token_kartu) === $uid_fisik) {
    // Generate 16 karakter hex unik (8 bytes random)
    $token_kartu = strtoupper(bin2hex(random_bytes(8)));
}

// Build internal UID (opsional, untuk referensi)
$internal_uid = '';
if (!empty($uid_fisik) && !empty($token_kartu)) {
    $internal_uid = card_build_internal_uid($uid_fisik, $token_kartu);
}

// Simpan ke rfid_scan_queue
// Auto-delete scans lebih dari 5 detik yang lama (stale data)
$cleanup_sql = "DELETE FROM rfid_scan_queue WHERE scanned_at < DATE_SUB(NOW(), INTERVAL 10 SECOND)";
mysqli_query($link, $cleanup_sql);

// Hapus scan sebelumnya yang belum diambil
$cleanup_old = "DELETE FROM rfid_scan_queue WHERE is_consumed = 0";
mysqli_query($link, $cleanup_old);

// Insert scan baru
$sql = "INSERT INTO rfid_scan_queue (uid_fisik, token_kartu, internal_uid, scanned_at, is_consumed) 
        VALUES (?, ?, ?, NOW(), 0)";

if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "sss", $uid_fisik, $token_kartu, $internal_uid);
    
    if (mysqli_stmt_execute($stmt)) {
        $insert_id = mysqli_insert_id($link);
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'scan_id' => $insert_id,
            'uid_fisik' => $uid_fisik,
            'token_kartu' => $token_kartu,
            'internal_uid' => $internal_uid,
            'message' => 'Scan berhasil. Data tersimpan di queue.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Gagal menyimpan scan: ' . mysqli_error($link)]);
    }
    
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Gagal menyiapkan query: ' . mysqli_error($link)]);
}

mysqli_close($link);
?>
