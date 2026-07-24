<?php
/**
 * device-scan-report.php
 * 
 * ESP mengirim hasil WiFi scan ke server.
 * 
 * POST {
 *   "mac_address": "AC:67:B2:12:34:56",
 *   "results": [
 *     {"ssid": "WiFi-A", "rssi": -45, "encr": "WPA2"},
 *     {"ssid": "WiFi-B", "rssi": -67, "encr": "WPA"}
 *   ]
 * }
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
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

require_once dirname(__DIR__, 2) . '/apps/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$mac = strtoupper(trim($input['mac_address'] ?? ''));
$results = $input['results'] ?? [];

if (empty($mac)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'mac_address required']);
    exit;
}

// Simpan hasil scan + clear scan_command
$resultsJson = json_encode($results);

$sql = "UPDATE device_config 
        SET scan_results = ?,
            scan_command = 0,
            scan_completed_at = NOW()
        WHERE mac_address = ?";

if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "ss", $resultsJson, $mac);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
} else {
    $ok = false;
    $affected = 0;
}

echo json_encode([
    'ok' => $ok && $affected > 0,
    'message' => 'Scan results saved',
    'networks_count' => count($results)
]);

mysqli_close($link);
?>
