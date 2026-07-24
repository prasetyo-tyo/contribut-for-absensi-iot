<?php
/**
 * device-wifi-scan.php
 * 
 * Dashboard command endpoint untuk scan WiFi.
 * 
 * POST { "device_id":"ALAT-01", "action":"scan" } → kirim perintah scan ke ESP
 * GET  ?device_id=ALAT-01                          → ambil hasil scan
 * 
 * Flow:
 * 1. Admin klik "Scan WiFi" → POST action=scan
 * 2. Server set scan_command=1 di device_config
 * 3. ESP polling (device-config.php) → lihat scan_command=1
 * 4. ESP WiFi scan → POST results ke device-scan-report.php
 * 5. Admin poll GET → ambil scan_results dari database
 */

session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/apps/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ─── POST: Kirim perintah scan ───────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $deviceId = trim($input['device_id'] ?? '');

    if ($action === 'scan' && !empty($deviceId)) {
        // Set scan_command = 1, reset hasil sebelumnya
        $sql = "UPDATE device_config 
                SET scan_command = 1, 
                    scan_requested_at = NOW(), 
                    scan_results = NULL,
                    scan_completed_at = NULL
                WHERE device_id = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $deviceId);
            $ok = mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            echo json_encode([
                'ok' => $ok && $affected > 0,
                'message' => $ok 
                    ? 'Perintah scan dikirim. ESP akan scan dalam 15-20 detik.' 
                    : 'Device tidak ditemukan'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Query failed']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action atau device_id']);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ─── GET: Ambil hasil scan ───────────────────
    $deviceId = trim($_GET['device_id'] ?? '');

    if (!empty($deviceId)) {
        $sql = "SELECT scan_results, scan_command, scan_completed_at, scan_requested_at,
                       last_seen_at, wifi_ssid, wifi_signal, mac_address
                FROM device_config WHERE device_id = ? LIMIT 1";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $deviceId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if ($row) {
                echo json_encode([
                    'ok' => true,
                    'scan_command_active' => (int)$row['scan_command'] === 1,
                    'scan_results' => json_decode($row['scan_results'] ?? 'null', true),
                    'scan_requested_at' => $row['scan_requested_at'],
                    'scan_completed_at' => $row['scan_completed_at'],
                    'last_seen_at' => $row['last_seen_at'],
                    'current_wifi' => $row['wifi_ssid'],
                    'wifi_signal' => $row['wifi_signal'],
                    'mac_address' => $row['mac_address']
                ]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Device tidak ditemukan']);
            }
        }
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'device_id required']);
    }
}

mysqli_close($link);
?>
