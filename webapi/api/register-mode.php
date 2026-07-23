<?php
/**
 * register-mode.php
 * 
 * Endpoint untuk mengelola mode ESP (absen vs register).
 * 
 * GET  ?check=1     → cek mode saat ini
 * POST {"mode":"register"} → set mode ke register
 * POST {"mode":"absen"}    → set mode ke absen
 * 
 * Mode disimpan di tabel app_settings dengan key 'esp_mode'.
 * Default: 'absen' (mode absensi normal).
 * 
 * Flow:
 * - Admin buka form Tambah Karyawan → POST mode=register
 * - ESP sebelum kirim scan → GET check=1 → cek mode
 * - Jika register → kirim ke scan-register.php
 * - Jika absen   → kirim ke create_legacy.php / create.php
 * - Admin tutup/navigasi form → POST mode=absen
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

function getMode($link) {
    $sql = "SELECT setting_value FROM app_settings WHERE setting_key = 'esp_mode' LIMIT 1";
    $result = mysqli_query($link, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['setting_value'];
    }
    return 'absen'; // default
}

function setMode($link, $mode) {
    $mode = ($mode === 'register') ? 'register' : 'absen';
    
    // UPSERT mode
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('esp_mode', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $mode);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
    // Cek mode saat ini
    $mode = getMode($link);
    
    // Mode register timeout: jika sudah > 120 detik sejak terakhir di-set, auto-reset ke absen
    // Gunakan TIMESTAMPDIFF MySQL agar timezone konsisten (Hindari bug timezone PHP vs MySQL)
    if ($mode === 'register') {
        $sql = "SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) as age_seconds
                FROM app_settings WHERE setting_key = 'esp_mode' LIMIT 1";
        $result = mysqli_query($link, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $ageSeconds = (int)$row['age_seconds'];
            if ($ageSeconds > 120) {
                // Auto-reset ke absen setelah 2 menit
                setMode($link, 'absen');
                $mode = 'absen';
            }
        }
    }
    
    echo json_encode([
        'ok' => true,
        'mode' => $mode
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    $newMode = $input['mode'] ?? 'absen';
    
    if (setMode($link, $newMode)) {
        echo json_encode([
            'ok' => true,
            'mode' => $newMode,
            'message' => "Mode diubah ke: $newMode"
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Gagal set mode']);
    }
    
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request. Gunakan ?check=1 (GET) atau POST {"mode":"register"|"absen"}']);
}

mysqli_close($link);
?>
