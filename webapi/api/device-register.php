<?php
/**
 * device-register.php
 * 
 * ESP8266 mendaftarkan diri ke server saat boot.
 * 
 * POST { "mac_address": "AC:67:B2:12:34:56", "firmware_version": "1.3" }
 * GET  ?mac=AC:67:B2:12:34:56
 * 
 * Response:
 * { "ok": true, "device_id": "ALAT-01", "outlet_id": 5, ... }
 * atau
 * { "ok": true, "device_id": null, "assigned": false }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__, 2) . '/apps/config.php';

// Parse MAC address
$mac = '';
$firmware = '';
$deviceId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $mac = strtoupper(trim($input['mac_address'] ?? ''));
    $firmware = trim($input['firmware_version'] ?? '');
    $deviceId = trim($input['device_id'] ?? '');
} elseif (isset($_GET['mac'])) {
    $mac = strtoupper(trim($_GET['mac']));
    $firmware = trim($_GET['fv'] ?? '');
    $deviceId = trim($_GET['did'] ?? '');
}

// Validate MAC format (AC:67:B2:12:34:56)
if (empty($mac) || !preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $mac)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'MAC address tidak valid. Format: XX:XX:XX:XX:XX:XX']);
    exit;
}

// UPSERT device_config — register atau update last_seen_at
if (!empty($deviceId)) {
    // ESP kirim device_id → simpan ke kolom device_id
    // Juga link ke data_outlet jika ada outlet dengan device_id tersebut
    $sql = "INSERT INTO device_config (mac_address, device_id, outlet_id, firmware_version, last_seen_at)
            VALUES (?, ?, (SELECT id FROM data_outlet WHERE device_id = ? LIMIT 1), ?, NOW())
            ON DUPLICATE KEY UPDATE
                device_id = VALUES(device_id),
                outlet_id = COALESCE(VALUES(outlet_id), outlet_id),
                firmware_version = VALUES(firmware_version),
                last_seen_at = NOW()";
    $stmt = mysqli_prepare($link, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ssss", $mac, $deviceId, $deviceId, $firmware);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $ok = false;
    }
} else {
    // ESP tanpa device_id — hanya register MAC
    $sql = "INSERT INTO device_config (mac_address, device_id, outlet_id, firmware_version, last_seen_at)
            VALUES (?, NULL, NULL, ?, NOW())
            ON DUPLICATE KEY UPDATE
                firmware_version = VALUES(firmware_version),
                last_seen_at = NOW()";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "ss", $mac, $firmware);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $ok = false;
    }
}

// Ambil data device setelah register
$sql2 = "SELECT dc.device_id, dc.outlet_id, dc.wifi_ssid, dc.wifi_config_version,
                dc.wifi_pending, do.nama_outlet
         FROM device_config dc
         LEFT JOIN data_outlet do ON dc.outlet_id = do.id
         WHERE dc.mac_address = ? LIMIT 1";

$stmt2 = mysqli_prepare($link, $sql2);
mysqli_stmt_bind_param($stmt2, "s", $mac);
mysqli_stmt_execute($stmt2);
$result = mysqli_stmt_get_result($stmt2);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt2);

if ($row) {
    echo json_encode([
        'ok' => true,
        'device_id' => $row['device_id'],
        'outlet_id' => $row['outlet_id'] ? (int)$row['outlet_id'] : null,
        'assigned' => !empty($row['device_id']),
        'nama_outlet' => $row['nama_outlet'],
        'wifi_ssid' => $row['wifi_ssid'],
        'config_version' => (int)$row['wifi_config_version'],
        'wifi_pending' => (int)$row['wifi_pending']
    ]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Gagal register device']);
}

mysqli_close($link);
?>
