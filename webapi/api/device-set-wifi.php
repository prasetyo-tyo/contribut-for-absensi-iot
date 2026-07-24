<?php
/**
 * device-set-wifi.php
 * 
 * Dashboard mengirim WiFi baru ke ESP melalui server.
 * 
 * POST {
 *   "device_id": "ALAT-01",
 *   "wifi_ssid": "WiFi-Baru",
 *   "wifi_password": "password123"
 * }
 * 
 * Flow:
 * 1. Admin pilih SSID + isi password
 * 2. POST ke endpoint ini
 * 3. Server set wifi_pending=1, wifi_config_version++
 * 4. ESP polling (device-config.php) → deteksi wifi_pending
 * 5. ESP simpan ke EEPROM → reboot → connect WiFi baru
 * 6. ESP poll lagi → server update wifi_applied_version
 */

session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/apps/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$deviceId = trim($input['device_id'] ?? '');
$wifiSsid = trim($input['wifi_ssid'] ?? '');
$wifiPassword = trim($input['wifi_password'] ?? '');

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'device_id required']);
    exit;
}

if (empty($wifiSsid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'wifi_ssid required']);
    exit;
}

// Update pending WiFi + naikkan version
$sql = "UPDATE device_config 
        SET wifi_pending = 1,
            wifi_pending_ssid = ?,
            wifi_pending_password = ?,
            wifi_config_version = wifi_config_version + 1
        WHERE device_id = ?";

if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "sss", $wifiSsid, $wifiPassword, $deviceId);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($ok && $affected > 0) {
        echo json_encode([
            'ok' => true,
            'message' => "Perintah ganti WiFi ke '$wifiSsid' sudah dikirim. "
                        . "ESP akan reconnect dalam 15-30 detik."
        ]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Device tidak ditemukan atau tidak ada perubahan']);
    }
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}

mysqli_close($link);
?>
