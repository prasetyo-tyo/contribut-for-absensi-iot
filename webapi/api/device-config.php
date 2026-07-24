<?php
/**
 * device-config.php
 * 
 * Endpoint utama untuk ESP8266 polling config.
 * ESP poll setiap 15 detik.
 * 
 * GET ?mac=XX:XX:XX:XX:XX:XX
 * 
 * Response (normal):
 * { "ok": true, "config_version": 3, "scan_wifi": false, "wifi_pending": false, "mode": "absen", "outlet_id": 5 }
 * 
 * Response (scan command):
 * { "ok": true, "scan_wifi": true, "config_version": 3 }
 * 
 * Response (WiFi change):
 * { "ok": true, "wifi_pending": true, "wifi_ssid": "...", "wifi_password": "...", "config_version": 4 }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__, 2) . '/apps/config.php';

$mac = strtoupper(trim($_GET['mac'] ?? ''));

if (empty($mac)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'mac parameter required']);
    exit;
}

// Update last_seen_at
$escaped_mac = mysqli_real_escape_string($link, $mac);
mysqli_query($link, "UPDATE device_config SET last_seen_at = NOW() WHERE mac_address = '$escaped_mac'");

// Ambil config
$sql = "SELECT dc.*, do.nama_outlet
        FROM device_config dc
        LEFT JOIN data_outlet do ON dc.outlet_id = do.id
        WHERE dc.mac_address = ? LIMIT 1";

$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, "s", $mac);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$row) {
    // Device belum register — minta register dulu
    echo json_encode([
        'ok' => true,
        'registered' => false,
        'scan_wifi' => false,
        'wifi_pending' => false
    ]);
    mysqli_close($link);
    exit;
}

$response = [
    'ok' => true,
    'registered' => true,
    'device_id' => $row['device_id'],
    'outlet_id' => $row['outlet_id'] ? (int)$row['outlet_id'] : null,
    'nama_outlet' => $row['nama_outlet'],
    'config_version' => (int)$row['wifi_config_version'],
    'current_mode' => $row['current_mode'] ?? 'absen'
];

// Cek scan command
$response['scan_wifi'] = ((int)$row['scan_command'] === 1);

// Cek WiFi pending — hanya kirim jika config_version > applied_version
if ((int)$row['wifi_pending'] === 1 && (int)$row['wifi_applied_version'] < (int)$row['wifi_config_version']) {
    $response['wifi_pending'] = true;
    $response['wifi_ssid'] = $row['wifi_pending_ssid'];
    $response['wifi_password'] = $row['wifi_pending_password'];
} else {
    $response['wifi_pending'] = false;
}

echo json_encode($response);

mysqli_close($link);
?>
