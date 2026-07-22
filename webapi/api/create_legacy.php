<?php
/**
 * Legacy Absensi Endpoint
 *
 * Endpoint ini mendukung firmware ESP8266/ESP32-CAM yang hanya mengirim
 * parameter `uid` (data block 2 RFID) tanpa HMAC signature.
 *
 * Format request:
 *   GET /webapi/api/create_legacy.php?uid=<UID_VALUE>&outlet_id=<OPTIONAL>
 *
 * Strategi Lookup:
 *   1. Coba lookup WHERE uid = <input>     (mode legacy registrasi)
 *   2. Fallback ke WHERE token_kartu = <input> (mode secure registrasi)
 *   Ini mendukung kedua cara registrasi karyawan.
 *
 * CATATAN KEAMANAN:
 *   Endpoint ini TIDAK melakukan verifikasi HMAC signature.
 *   Gunakan create.php (dengan parameter uid_fisik, token, ts, sig) untuk
 *   keamanan produksi yang lebih baik.
 *
 * DEBUG LOGGING:
 *   Semua request dicatat ke tabel data_debug_log untuk debugging.
 *
 * Compatible with firmware: webapi/scan.ino
 */

date_default_timezone_set('Asia/Jakarta');
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$startTime = microtime(true);

include_once '../config/database.php';
include_once '../class/absensi.php';

define('DEFAULT_OUTLET_ID', 1);

// ---- Validasi parameter ----
if (!isset($_GET['uid']) || trim($_GET['uid']) === '') {
    http_response_code(400);
    echo json_encode([
        "error" => "Parameter 'uid' diperlukan.",
        "usage" => "GET ?uid=<UID_VALUE>&outlet_id=<OPTIONAL>",
    ]);
    exit;
}

$rawUid = trim($_GET['uid']);
// Bersihkan null bytes, whitespace, dan karakter non-printable dari firmware
$cleanUid = preg_replace('/[\x00-\x1F\x7F]/', '', $rawUid);
$normalizedUid = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $cleanUid));

// ---- Inisialisasi koneksi & model ----
$database = new Database();
$db = $database->getConnection();

// ---- Variabel debug logging ----
$debugEndpoint = 'create_legacy';
$debugMatchResult = 'NO_MATCH';
$debugMatchedField = null;
$debugMatchedId = null;
$debugMatchedNip = null;
$debugMatchedNama = null;
$debugResponseCode = 200;
$debugResponseBody = '';
$debugNotes = null;

// ---- Lookup karyawan: coba uid dulu, fallback ke token_kartu (LIKE untuk toleransi padding) ----
$sql = "SELECT id, uid, nama, nip FROM data_karyawan
        WHERE status_karyawan = 'AKTIF'
          AND (
            uid = :uid1
            OR token_kartu = :uid2
            OR TRIM(TRIM(NULL FROM token_kartu)) = :uid3
            OR token_kartu LIKE CONCAT(:uid4, '%')
          )
        LIMIT 0,1";
$stmt = $db->prepare($sql);
$stmt->bindParam(":uid1", $normalizedUid);
$stmt->bindParam(":uid2", $normalizedUid);
$stmt->bindParam(":uid3", $normalizedUid);
$stmt->bindParam(":uid4", $normalizedUid);
$stmt->execute();
$karyawan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$karyawan) {
    $debugMatchResult = 'NO_MATCH';
    $debugNotes = 'Kartu tidak ditemukan di database. Raw: [' . bin2hex($rawUid) . '] Hex: [' . bin2hex($cleanUid) . ']';

    http_response_code(200);
    echo json_encode([
        "waktu"   => date("H:i:s"),
        "nama"    => "Invalid",
        "nip"     => null,
        "uid"     => $normalizedUid,
        "status"  => "INVALID",
        "outlet_id" => null,
        "absen_id"  => null,
        "message"   => "Kartu belum terdaftar.",
        "camera_triggered" => false,
        "mode" => "legacy"
    ]);

    // Log ke tabel data_invalid
    $now = date("H:i:s");
    $today = date("Y-m-d");
    $outletId = isset($_GET['outlet_id']) && ctype_digit((string) $_GET['outlet_id'])
        ? (int) $_GET['outlet_id']
        : DEFAULT_OUTLET_ID;
    $logSql = "INSERT INTO data_invalid (tanggal, waktu, uid, outlet_id, status)
               VALUES (:tanggal, :waktu, :uid, :outlet_id, 'INVALID')";
    $logStmt = $db->prepare($logSql);
    $logStmt->bindParam(":tanggal", $today);
    $logStmt->bindParam(":waktu", $now);
    $logStmt->bindParam(":uid", $normalizedUid);
    $logStmt->bindValue(":outlet_id", $outletId, PDO::PARAM_INT);
    $logStmt->execute();

    // Log debug
    $duration = (int) ((microtime(true) - $startTime) * 1000);
    _writeDebugLog($db, $debugEndpoint, $rawUid, $normalizedUid, $debugMatchResult,
        $debugMatchedField, $debugMatchedId, $debugMatchedNip, $debugMatchedNama,
        $debugResponseCode, $debugResponseBody, $duration, $debugNotes);

    exit;
}

// ---- Tentukan field mana yang match ----
$debugMatchedId = $karyawan['id'] ?? null;
$debugMatchedNip = $karyawan['nip'] ?? null;
$debugMatchedNama = $karyawan['nama'] ?? null;
$debugMatchResult = 'MATCH';

// Cek field mana yang matched
$checkSql = "SELECT id FROM data_karyawan WHERE status_karyawan = 'AKTIF' AND uid = :uid LIMIT 1";
$checkStmt = $db->prepare($checkSql);
$checkStmt->bindParam(":uid", $normalizedUid);
$checkStmt->execute();
if ($checkStmt->fetch()) {
    $debugMatchedField = 'uid';
} else {
    $checkSql2 = "SELECT id FROM data_karyawan WHERE status_karyawan = 'AKTIF' AND token_kartu = :tk LIMIT 1";
    $checkStmt2 = $db->prepare($checkSql2);
    $checkStmt2->bindParam(":tk", $normalizedUid);
    $checkStmt2->execute();
    if ($checkStmt2->fetch()) {
        $debugMatchedField = 'token_kartu';
    } else {
        $debugMatchedField = 'token_kartu LIKE';
    }
}

// ---- Inisialisasi model Absensi ----
$item = new Absensi($db);
$item->uid = $karyawan['uid'];  // Gunakan uid dari database (bukan raw input)
$item->secure_mode = false;
$item->physical_uid = $normalizedUid;
$item->card_token = '';
$item->outlet_id = isset($_GET['outlet_id']) && ctype_digit((string) $_GET['outlet_id'])
    ? (int) $_GET['outlet_id']
    : DEFAULT_OUTLET_ID;

// ---- Proses absensi ----
if ($item->createData()) {
    $response = [
        "waktu"   => $item->waktu,
        "nama"    => $item->nama,
        "nip"     => $item->nip,
        "uid"     => $item->uid,
        "status"  => $item->status,
        "outlet_id" => $item->outlet_id,
        "absen_id"  => $item->absen_id,
        "message"   => $item->message,
        "camera_triggered" => false,
        "mode" => "legacy"
    ];

    $debugResponseCode = 200;
    $debugResponseBody = json_encode($response);

    http_response_code(200);
    echo $debugResponseBody;
} else {
    $debugMatchResult = 'ERROR';
    $debugResponseCode = 500;
    $debugResponseBody = json_encode([
        "error" => "Gagal memproses absensi.",
        "uid"   => $item->uid
    ]);
    $debugNotes = 'createData() returned false';

    http_response_code(500);
    echo $debugResponseBody;
}

// ---- Log debug ke database ----
$duration = (int) ((microtime(true) - $startTime) * 1000);
_writeDebugLog($db, $debugEndpoint, $rawUid, $normalizedUid, $debugMatchResult,
    $debugMatchedField, $debugMatchedId, $debugMatchedNip, $debugMatchedNama,
    $debugResponseCode, $debugResponseBody, $duration, $debugNotes);

// ---- Helper: tulis debug log ----
function _writeDebugLog($db, $endpoint, $rawInput, $normalizedInput, $matchResult,
    $matchedField, $matchedId, $matchedNip, $matchedNama,
    $responseCode, $responseBody, $durationMs, $notes)
{
    try {
        $sql = "INSERT INTO data_debug_log
            (tanggal, waktu, endpoint, raw_input, normalized_input,
             match_result, matched_field, matched_id, matched_nip, matched_nama,
             request_params, response_code, response_body,
             ip_address, user_agent, duration_ms, notes)
            VALUES
            (:tanggal, :waktu, :endpoint, :raw_input, :normalized_input,
             :match_result, :matched_field, :matched_id, :matched_nip, :matched_nama,
             :request_params, :response_code, :response_body,
             :ip_address, :user_agent, :duration_ms, :notes)";

        $stmt = $db->prepare($sql);
        $stmt->bindParam(":tanggal", date("Y-m-d"));
        $stmt->bindParam(":waktu", date("H:i:s"));
        $stmt->bindParam(":endpoint", $endpoint);
        $stmt->bindParam(":raw_input", $rawInput);
        $stmt->bindParam(":normalized_input", $normalizedInput);
        $stmt->bindParam(":match_result", $matchResult);
        $stmt->bindValue(":matched_field", $matchedField);
        $stmt->bindValue(":matched_id", $matchedId);
        $stmt->bindValue(":matched_nip", $matchedNip);
        $stmt->bindValue(":matched_nama", $matchedNama);
        $stmt->bindValue(":request_params", json_encode($_GET));
        $stmt->bindValue(":response_code", $responseCode);
        $stmt->bindValue(":response_body", $responseBody);
        $stmt->bindValue(":ip_address", $_SERVER['REMOTE_ADDR'] ?? null);
        $stmt->bindValue(":user_agent", substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
        $stmt->bindValue(":duration_ms", $durationMs);
        $stmt->bindValue(":notes", $notes);
        $stmt->execute();
    } catch (\Exception $e) {
        // Debug log harus silent — jangan sampai crash API
        error_log("[create_legacy] debug log error: " . $e->getMessage());
    }
}
?>
