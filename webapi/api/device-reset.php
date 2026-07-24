<?php
/**
 * device-reset.php
 * 
 * Trigger remote EEPROM wipe on ESP device.
 * 
 * POST ?mac=XX:XX:XX:XX:XX:XX
 * 
 * Sets reset_device=1 in device_config table.
 * ESP will wipe EEPROM + reboot on next poll.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Require session auth
session_start();
if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

require_once dirname(__DIR__, 2) . '/apps/config.php';

$mac = strtoupper(trim($_GET['mac'] ?? ''));

// Parse from POST body too
if (empty($mac) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $mac = strtoupper(trim($input['mac_address'] ?? ''));
}

if (empty($mac) || !preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $mac)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'MAC address tidak valid']);
    exit;
}

// Set reset_device flag
$escaped_mac = mysqli_real_escape_string($link, $mac);
$result = mysqli_query($link, "UPDATE device_config SET reset_device = 1 WHERE mac_address = '$escaped_mac'");

if ($result && mysqli_affected_rows($link) >= 0) {
    echo json_encode([
        'ok' => true,
        'message' => 'Reset command dikirim. ESP akan wipe EEPROM dan reboot pada poll berikutnya (maks 15 detik).'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Gagal set reset flag']);
}

mysqli_close($link);
?>
