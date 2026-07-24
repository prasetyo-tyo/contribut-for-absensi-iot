# Remote WiFi Config — Implementation Plan

> **For agentic workers:** Gunakan mode `code` atau `orchestrator` untuk implementasi plan ini task-by-task. Steps menggunakan checkbox syntax untuk tracking.

**Goal:** Mengizinkan admin mengelola WiFi ESP8266 dari dashboard — scan SSID, pilih WiFi, set password — tanpa perlu fisik ke ESP.

**Architecture:** ESP8266 muncul sebagai Access Point saat pertama kali boot (first-time setup) atau WiFi gagal connect. Setelah WiFi terkoneksi, ESP polling server per 15 detik untuk ambil perintah (scan WiFi, ganti WiFi). Dashboard admin mengirim perintah scan ke ESP, melihat hasil scan, memilih SSID + password, lalu ESP otomatis reconnect.

**Tech Stack:** ESP8266 (WiFiManager lib), PHP (mysqli), MySQL/MariaDB, SB Admin 2 Bootstrap template, JavaScript fetch API.

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `migrations/add_device_config.sql` | Tabel baru `device_config` + alter `data_outlet` |
| Create | `webapi/api/device-register.php` | ESP register MAC address ke server |
| Create | `webapi/api/device-config.php` | ESP poll config + command dari server |
| Create | `webapi/api/device-scan-report.php` | ESP kirim hasil scan WiFi ke server |
| Create | `webapi/api/device-wifi-scan.php` | Dashboard minta ESP scan WiFi + ambil hasil |
| Modify | `apps/data_outlet-create.php` | Tambah field Device ID |
| Modify | `apps/data_outlet-update.php` | Tambah field Device ID + WiFi info |
| Modify | `apps/data_outlet-index.php` | Tambah kolom Device ID + status koneksi |
| Create | `apps/device-wifi-config.php` | Halaman baru: scan WiFi + ganti WiFi per outlet |
| Rewrite | `webapi/scan/scan.ino` | WiFiManager + EEPROM + device polling |

---

## Task 1: Database Migration — device_config table

**Files:**
- Create: `migrations/add_device_config.sql`

- [ ] **Step 1: Buat file migration**

```sql
-- migrations/add_device_config.sql
-- ============================================================
-- Migration: Tabel device_config untuk Remote WiFi Config
-- 
-- Jalankan:
--   mysql -u user -p database_name < migrations/add_device_config.sql
-- ============================================================

-- 1. Tambah kolom device_id ke data_outlet
ALTER TABLE `data_outlet`
  ADD COLUMN `device_id` VARCHAR(50) DEFAULT NULL AFTER `kode_alat`,
  ADD UNIQUE KEY `uq_data_outlet_device_id` (`device_id`);

-- 2. Tabel device_config — konfigurasi ESP per device
CREATE TABLE IF NOT EXISTS `device_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `mac_address` varchar(20) NOT NULL COMMENT 'MAC unik ESP (format: AC:67:B2:XX:XX:XX)',
  `device_id` varchar(50) DEFAULT NULL COMMENT 'Label device (misal: ALAT-01)',
  `outlet_id` int unsigned DEFAULT NULL COMMENT 'FK ke data_outlet',
  
  -- WiFi config aktif
  `wifi_ssid` varchar(100) DEFAULT NULL COMMENT 'SSID WiFi yang sedang dipakai',
  `wifi_signal` int DEFAULT NULL COMMENT 'Signal strength terakhir (dBm)',
  
  -- WiFi pending (menunggu ESP apply)
  `wifi_pending` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ada WiFi baru menunggu diterapkan',
  `wifi_pending_ssid` varchar(100) DEFAULT NULL,
  `wifi_pending_password` varchar(255) DEFAULT NULL,
  `wifi_config_version` int NOT NULL DEFAULT 0 COMMENT 'Naik setiap kali config berubah',
  `wifi_applied_version` int NOT NULL DEFAULT 0 COMMENT 'Version terakhir yang diterapkan ESP',
  
  -- Scan command
  `scan_command` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ESP disuruh scan WiFi',
  `scan_results` JSON DEFAULT NULL COMMENT 'Hasil scan terakhir [{ssid, rssi, encr}]',
  `scan_requested_at` datetime DEFAULT NULL,
  `scan_completed_at` datetime DEFAULT NULL,
  
  -- Status
  `last_seen_at` datetime DEFAULT NULL COMMENT 'Last poll dari ESP',
  `firmware_version` varchar(20) DEFAULT NULL,
  `current_mode` enum('absen','register') DEFAULT 'absen',
  
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_mac` (`mac_address`),
  KEY `idx_device_outlet` (`outlet_id`),
  KEY `idx_device_id` (`device_id`),
  CONSTRAINT `fk_device_outlet`
    FOREIGN KEY (`outlet_id`) REFERENCES `data_outlet` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Hapus index lama 'esp_mode' dari app_settings jika ada
-- (mode sekarang disimpan di device_config.current_mode)
```

- [ ] **Step 2: Commit migration**

```bash
git add migrations/add_device_config.sql
git commit -m "feat: migration for remote WiFi config - device_config table"
```

---

## Task 2: API — Device Register (ESP → Server)

**Files:**
- Create: `webapi/api/device-register.php`

- [ ] **Step 1: Buat device-register.php**

```php
<?php
/**
 * device-register.php
 * 
 * ESP8266 mendaftarkan diri ke server saat boot pertama kali.
 * 
 * POST { "mac_address": "AC:67:B2:12:34:56", "firmware_version": "1.3" }
 * GET  ?mac=AC:67:B2:12:34:56
 * 
 * Response:
 * { "ok": true, "device_id": "ALAT-01", "outlet_id": 5, ... }
 * atau
 * { "ok": true, "device_id": null, "assigned": false }  // belum didaftarkan
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $mac = strtoupper(trim($input['mac_address'] ?? ''));
    $firmware = trim($input['firmware_version'] ?? '');
} elseif (isset($_GET['mac'])) {
    $mac = strtoupper(trim($_GET['mac']));
    $firmware = trim($_GET['fv'] ?? '');
}

// Validate MAC format (AC:67:B2:12:34:56)
if (empty($mac) || !preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', $mac)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'MAC address tidak valid. Format: XX:XX:XX:XX:XX:XX']);
    exit;
}

// UPSERT device_config
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
```

- [ ] **Step 2: Commit**

```bash
git add webapi/api/device-register.php
git commit -m "feat: device-register.php - ESP self-registration endpoint"
```

---

## Task 3: API — Device Config (ESP poll config + command)

**Files:**
- Create: `webapi/api/device-config.php`

- [ ] **Step 1: Buat device-config.php**

```php
<?php
/**
 * device-config.php
 * 
 * Endpoint utama untuk ESP8266 polling config.
 * 
 * GET ?mac=XX:XX:XX:XX:XX:XX
 * 
 * Response (normal):
 * {
 *   "ok": true,
 *   "config_version": 3,
 *   "scan_wifi": false,
 *   "wifi_pending": false,
 *   "mode": "absen",
 *   "server_url": "https://sbn-absensi...",
 *   "outlet_id": 5
 * }
 * 
 * Response (scan command):
 * {
 *   "ok": true,
 *   "scan_wifi": true,
 *   "config_version": 3
 * }
 * 
 * Response (WiFi change):
 * {
 *   "ok": true,
 *   "wifi_pending": true,
 *   "wifi_ssid": "WiFi Baru",
 *   "wifi_password": "password123",
 *   "config_version": 4
 * }
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
mysqli_query($link, "UPDATE device_config SET last_seen_at = NOW() WHERE mac_address = '" . mysqli_real_escape_string($link, $mac) . "'");

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
if ((int)$row['scan_command'] === 1) {
    $response['scan_wifi'] = true;
} else {
    $response['scan_wifi'] = false;
}

// Cek WiFi pending
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
```

- [ ] **Step 2: Commit**

```bash
git add webapi/api/device-config.php
git commit -m "feat: device-config.php - ESP config polling endpoint"
```

---

## Task 4: API — Device Scan Report (ESP kirim hasil scan)

**Files:**
- Create: `webapi/api/device-scan-report.php`

- [ ] **Step 1: Buat device-scan-report.php**

```php
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

// Simpan hasil scan
$resultsJson = json_encode($results);

$sql = "UPDATE device_config 
        SET scan_results = ?,
            scan_command = 0,
            scan_completed_at = NOW()
        WHERE mac_address = ?";

if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "ss", $resultsJson, $mac);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
} else {
    $ok = false;
}

echo json_encode([
    'ok' => $ok,
    'message' => 'Scan results saved'
]);

mysqli_close($link);
?>
```

- [ ] **Step 2: Commit**

```bash
git add webapi/api/device-scan-report.php
git commit -m "feat: device-scan-report.php - receive WiFi scan results from ESP"
```

---

## Task 5: API — Dashboard WiFi Scan Command

**Files:**
- Create: `webapi/api/device-wifi-scan.php`

- [ ] **Step 1: Buat device-wifi-scan.php**

```php
<?php
/**
 * device-wifi-scan.php
 * 
 * Dashboard memanggil endpoint ini untuk:
 * 1. POST {"device_id":"ALAT-01", "action":"scan"}  → kirim scan command
 * 2. GET  ?device_id=ALAT-01                         → ambil hasil scan
 * 
 * Flow:
 * - Admin klik "Scan WiFi" → POST action=scan
 * - Server set scan_command=1 di device_config
 * - ESP polling → lihat scan_command=1 → WiFi.scanNetworks() → POST hasil ke device-scan-report.php
 * - Admin poll GET → ambil scan_results dari database
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
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $deviceId = trim($input['device_id'] ?? '');

    if ($action === 'scan' && !empty($deviceId)) {
        // Set scan command
        $sql = "UPDATE device_config SET scan_command = 1, scan_requested_at = NOW(), scan_results = NULL WHERE device_id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $deviceId);
            $ok = mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);

            echo json_encode([
                'ok' => $ok && $affected > 0,
                'message' => $ok ? 'Scan command sent. ESP will scan within 15 seconds.' : 'Device not found'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Query failed']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action or device_id']);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
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
                    'scan_completed_at' => $row['scan_completed_at'],
                    'last_seen_at' => $row['last_seen_at'],
                    'current_wifi' => $row['wifi_ssid'],
                    'mac_address' => $row['mac_address']
                ]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Device not found']);
            }
        }
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'device_id required']);
    }
}

mysqli_close($link);
?>
```

- [ ] **Step 2: Commit**

```bash
git add webapi/api/device-wifi-scan.php
git commit -m "feat: device-wifi-scan.php - dashboard WiFi scan command endpoint"
```

---

## Task 6: API — Set WiFi dari Dashboard

**Files:**
- Create: `webapi/api/device-set-wifi.php`

- [ ] **Step 1: Buat device-set-wifi.php**

```php
<?php
/**
 * device-set-wifi.php
 * 
 * Dashboard mengirim WiFi baru ke ESP via server.
 * 
 * POST {
 *   "device_id": "ALAT-01",
 *   "wifi_ssid": "WiFi-Baru",
 *   "wifi_password": "password123"
 * }
 * 
 * Server set wifi_pending=1, wifi_config_version++.
 * ESP poll → deteksi pending → apply WiFi baru → reconnect → poll lagi → server clear pending.
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

if (empty($deviceId) || empty($wifiSsid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'device_id dan wifi_ssid required']);
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
            'message' => "WiFi baru disimpan. ESP akan reconnect ke '$wifiSsid' dalam 15 detik."
        ]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Device tidak ditemukan']);
    }
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}

mysqli_close($link);
?>
```

- [ ] **Step 2: Commit**

```bash
git add webapi/api/device-set-wifi.php
git commit -m "feat: device-set-wifi.php - set new WiFi config for ESP from dashboard"
```

---

## Task 7: Dashboard — Outlet Form + Device ID

**Files:**
- Modify: `apps/data_outlet-create.php` — tambah field device_id
- Modify: `apps/data_outlet-update.php` — tambah field device_id + WiFi info
- Modify: `apps/data_outlet-index.php` — tambah kolom device_id + status

- [ ] **Step 1: Update data_outlet-create.php — tambah field device_id**

Di [`apps/data_outlet-create.php`](apps/data_outlet-create.php:16), ubah POST handler:

```php
// Line 17-18, ubah dari:
$nama_outlet = trim($_POST['nama_outlet'] ?? '');
$kode_alat = trim($_POST['kode_alat'] ?? '');
$keterangan = trim($_POST['keterangan'] ?? '');

// Menjadi:
$nama_outlet = trim($_POST['nama_outlet'] ?? '');
$kode_alat = trim($_POST['kode_alat'] ?? '');
$keterangan = trim($_POST['keterangan'] ?? '');
$device_id = trim($_POST['device_id'] ?? '');
```

Ubah SQL INSERT (line 24):
```php
// Dari:
$sql = "INSERT INTO data_outlet (nama_outlet, kode_alat, keterangan) VALUES (?, ?, ?)";
// Menjadi:
$sql = "INSERT INTO data_outlet (nama_outlet, kode_alat, keterangan, device_id) VALUES (?, ?, ?, ?)";
```

Ubah bind_param (line 25):
```php
// Dari:
mysqli_stmt_bind_param($stmt, "sss", $nama_outlet, $kode_alat, $keterangan);
// Menjadi:
mysqli_stmt_bind_param($stmt, "ssss", $nama_outlet, $kode_alat, $keterangan, $device_id);
```

Tambah HTML field setelah Keterangan textarea:
```html
<div class="form-group">
    <label>Device ID</label>
    <input type="text" name="device_id" class="form-control" 
           value="<?php echo htmlspecialchars($device_id); ?>" 
           placeholder="Misal: ALAT-01">
    <small class="form-text text-muted">
        ID unik ESP8266. Lihat di serial monitor atau bodi alat.
        Format: ALAT-XX atau MAC tanpa spasi.
    </small>
</div>
```

- [ ] **Step 2: Update data_outlet-update.php — tambah device_id + WiFi info**

Di [`apps/data_outlet-update.php`](apps/data_outlet-update.php:16), ubah SELECT:
```php
// Dari:
$stmt = mysqli_prepare($link, "SELECT id, nama_outlet, kode_alat, keterangan FROM data_outlet WHERE id = ?");
// Menjadi:
$stmt = mysqli_prepare($link, "SELECT id, nama_outlet, kode_alat, keterangan, device_id FROM data_outlet WHERE id = ?");
```

Ubah POST handler (line 30) — tambah device_id:
```php
$device_id = trim($_POST['device_id'] ?? '');
```

Ubah SQL UPDATE:
```php
// Dari:
$update = mysqli_prepare($link, "UPDATE data_outlet SET nama_outlet = ?, kode_alat = ?, keterangan = ? WHERE id = ?");
mysqli_stmt_bind_param($update, "sssi", $nama_outlet, $kode_alat, $keterangan, $id);
// Menjadi:
$update = mysqli_prepare($link, "UPDATE data_outlet SET nama_outlet = ?, kode_alat = ?, keterangan = ?, device_id = ? WHERE id = ?");
mysqli_stmt_bind_param($update, "ssssi", $nama_outlet, $kode_alat, $keterangan, $device_id, $id);
```

Tambah HTML field + WiFi info display setelah Keterangan:
```html
<div class="form-group">
    <label>Device ID</label>
    <input type="text" name="device_id" class="form-control" 
           value="<?php echo htmlspecialchars($outlet['device_id'] ?? ''); ?>"
           placeholder="Misal: ALAT-01">
</div>

<?php
// Tampilkan WiFi status jika device sudah terdaftar
$deviceId = $outlet['device_id'] ?? '';
if (!empty($deviceId)) {
    $devSql = "SELECT mac_address, wifi_ssid, wifi_signal, last_seen_at, current_mode 
               FROM device_config WHERE device_id = ? LIMIT 1";
    $devStmt = mysqli_prepare($link, $devSql);
    mysqli_stmt_bind_param($devStmt, "s", $deviceId);
    mysqli_stmt_execute($devStmt);
    $devResult = mysqli_stmt_get_result($devStmt);
    $dev = mysqli_fetch_assoc($devResult);
    mysqli_stmt_close($devStmt);
    
    if ($dev) {
        $isOnline = $dev['last_seen_at'] && strtotime($dev['last_seen_at']) > strtotime('-60 seconds');
?>
<div class="card mt-3 border-<?php echo $isOnline ? 'success' : 'secondary'; ?>">
    <div class="card-header bg-<?php echo $isOnline ? 'success' : 'secondary'; ?> text-white">
        <i class="fas fa-wifi"></i> Status Device ESP
    </div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tr><td>MAC Address</td><td><?php echo htmlspecialchars($dev['mac_address'] ?? '-'); ?></td></tr>
            <tr><td>WiFi Saat Ini</td><td><?php echo htmlspecialchars($dev['wifi_ssid'] ?? '-'); ?></td></tr>
            <tr><td>Sinyal</td><td><?php echo ($dev['wifi_signal'] ?? 0) . ' dBm'; ?></td></tr>
            <tr><td>Mode</td><td><span class="badge badge-<?php echo $dev['current_mode'] === 'register' ? 'warning' : 'primary'; ?>"><?php echo strtoupper($dev['current_mode'] ?? 'absen'); ?></span></td></tr>
            <tr><td>Status</td><td><span class="badge badge-<?php echo $isOnline ? 'success' : 'danger'; ?>"><?php echo $isOnline ? 'ONLINE' : 'OFFLINE'; ?></span></td></tr>
            <tr><td>Last Seen</td><td><?php echo $dev['last_seen_at'] ?? '-'; ?></td></tr>
        </table>
        <a href="device-wifi-config.php?device_id=<?php echo urlencode($deviceId); ?>" class="btn btn-info btn-sm mt-2">
            <i class="fas fa-wifi"></i> Kelola WiFi
        </a>
    </div>
</div>
<?php
    }
}
?>
```

- [ ] **Step 3: Update data_outlet-index.php — tambah kolom Device ID + Status**

Di [`apps/data_outlet-index.php`](apps/data_outlet-index.php:16), ubah SELECT:
```php
// Dari:
$result = mysqli_query($link, "SELECT id, nama_outlet, kode_alat, keterangan, created_at FROM data_outlet ORDER BY id ASC");
// Menjadi:
$result = mysqli_query($link, "SELECT o.id, o.nama_outlet, o.kode_alat, o.keterangan, o.device_id, o.created_at,
    dc.mac_address, dc.wifi_ssid, dc.last_seen_at
    FROM data_outlet o
    LEFT JOIN device_config dc ON o.device_id = dc.device_id
    ORDER BY o.id ASC");
```

Tambah kolom header:
```html
<th>Device ID</th>
<th>WiFi</th>
<th>Status</th>
```

Tambah kolom data di while loop:
```php
<td><?php echo htmlspecialchars($row['device_id'] ?? '-'); ?></td>
<td><?php echo htmlspecialchars($row['wifi_ssid'] ?? '-'); ?></td>
<td>
<?php
$isOnline = $row['last_seen_at'] && strtotime($row['last_seen_at']) > strtotime('-60 seconds');
?>
<span class="badge badge-<?php echo $isOnline ? 'success' : 'secondary'; ?>">
    <?php echo $isOnline ? 'ONLINE' : 'OFFLINE'; ?>
</span>
</td>
```

- [ ] **Step 4: Commit**

```bash
git add apps/data_outlet-create.php apps/data_outlet-update.php apps/data_outlet-index.php
git commit -m "feat: outlet forms - add device_id field + WiFi status display"
```

---

## Task 8: Dashboard — Halaman WiFi Config (Scan + Ganti WiFi)

**Files:**
- Create: `apps/device-wifi-config.php`

- [ ] **Step 1: Buat device-wifi-config.php**

Halaman ini:
1. Tampilkan status device (online/offline, MAC, WiFi saat ini)
2. Tombol "Scan WiFi" — kirim scan command ke ESP, polling hasil
3. Tampilkan daftar SSID hasil scan (radio button)
4. Input password WiFi
5. Tombol "Terapkan" — kirim WiFi baru ke ESP

Kode lengkap (approximately 300 baris):

```php
<?php
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "config.php";

$deviceId = trim($_GET['device_id'] ?? '');
if (empty($deviceId)) {
    die('Device ID tidak valid.');
}

// Ambil info device
$stmt = mysqli_prepare($link, "SELECT dc.*, do.nama_outlet 
    FROM device_config dc LEFT JOIN data_outlet do ON dc.outlet_id = do.id 
    WHERE dc.device_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $deviceId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$device = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$device) {
    die('Device tidak ditemukan. Pastikan device sudah terdaftar di outlet.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Konfigurasi WiFi - <?php echo htmlspecialchars($device['device_id']); ?></title>
    <link rel="icon" href="../src/img/1.png" type="image/x-icon">
    <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito" rel="stylesheet">
    <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
<div id="wrapper">
    <?php include 'partial_sidebar.php'; ?>
    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php include 'partial_topbar.php'; ?>
            <div class="container-fluid">
                
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h1 class="h3 mb-0 text-gray-800">
                            <i class="fas fa-wifi"></i> Konfigurasi WiFi
                        </h1>
                        <p class="text-muted mb-0">
                            Device: <strong><?php echo htmlspecialchars($device['device_id']); ?></strong>
                            | Outlet: <?php echo htmlspecialchars($device['nama_outlet'] ?? '-'); ?>
                        </p>
                    </div>
                    <a href="data_outlet-update.php?id=<?php echo $device['outlet_id']; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>

                <!-- Status Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-info-circle"></i> Status Device
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>MAC Address:</strong><br>
                                <span id="dev-mac"><?php echo htmlspecialchars($device['mac_address'] ?? 'Belum terdaftar'); ?></span>
                            </div>
                            <div class="col-md-3">
                                <strong>WiFi Saat Ini:</strong><br>
                                <span id="dev-wifi" class="badge badge-info">
                                    <?php echo htmlspecialchars($device['wifi_ssid'] ?? 'N/A'); ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <strong>Status:</strong><br>
                                <span id="dev-status" class="badge badge-secondary">Memuat...</span>
                            </div>
                            <div class="col-md-3">
                                <strong>Last Seen:</strong><br>
                                <span id="dev-lastseen"><?php echo $device['last_seen_at'] ?? '-'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WiFi Scan Section -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-search"></i> Scan WiFi
                        </h6>
                        <button id="btn-scan" class="btn btn-primary btn-sm" onclick="startScan()">
                            <i class="fas fa-radar"></i> Scan WiFi Tersedia
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="scan-status" class="alert alert-info" style="display:none;"></div>
                        <div id="scan-results-container" style="display:none;">
                            <h6>Hasil Scan:</h6>
                            <div id="scan-results" class="list-group mb-3"></div>
                            
                            <!-- WiFi Password -->
                            <div class="form-group">
                                <label for="wifi-password"><strong>Password WiFi:</strong></label>
                                <div class="input-group">
                                    <input type="password" id="wifi-password" class="form-control" 
                                           placeholder="Masukkan password WiFi yang dipilih">
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" type="button" 
                                                onclick="togglePassword()">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <button id="btn-apply" class="btn btn-success" onclick="applyWifi()" disabled>
                                <i class="fas fa-save"></i> Terapkan WiFi Baru
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="../src/vendor/jquery/jquery.min.js"></script>
<script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../src/js/sb-admin-2.min.js"></script>

<script>
var DEVICE_ID = <?php echo json_encode($deviceId); ?>;
var selectedSSID = null;
var statusTimer = null;

// ─── Status Check ──────────────────────────────
function checkStatus() {
    fetch('device-wifi-scan.php?device_id=' + encodeURIComponent(DEVICE_ID))
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) {
            var isOnline = d.last_seen_at && (new Date() - new Date(d.last_seen_at)) < 120000;
            document.getElementById('dev-status').className = 'badge badge-' + (isOnline ? 'success' : 'danger');
            document.getElementById('dev-status').textContent = isOnline ? 'ONLINE' : 'OFFLINE';
            document.getElementById('dev-lastseen').textContent = d.last_seen_at || '-';
            if (d.current_wifi) {
                document.getElementById('dev-wifi').textContent = d.current_wifi;
            }
            if (d.mac_address) {
                document.getElementById('dev-mac').textContent = d.mac_address;
            }
        }
    })
    .catch(function() {});
}

checkStatus();
statusTimer = setInterval(checkStatus, 10000);

// ─── Scan WiFi ─────────────────────────────────
function startScan() {
    var btn = document.getElementById('btn-scan');
    var status = document.getElementById('scan-status');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Meminta scan...';
    status.style.display = 'block';
    status.className = 'alert alert-info';
    status.textContent = 'Meminta ESP melakukan scan WiFi... Menunggu 15-20 detik.';

    // Kirim scan command
    fetch('device-wifi-scan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id: DEVICE_ID, action: 'scan' })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.ok) {
            status.className = 'alert alert-danger';
            status.textContent = 'Gagal: ' + d.error;
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i> Scan WiFi Tersedia';
            return;
        }
        // Polling hasil scan
        pollScanResults(0);
    });
}

function pollScanResults(attempt) {
    var status = document.getElementById('scan-status');
    if (attempt > 30) {
        status.className = 'alert alert-warning';
        status.textContent = 'Timeout — ESP tidak merespons. Pastikan ESP online.';
        document.getElementById('btn-scan').disabled = false;
        document.getElementById('btn-scan').innerHTML = '<i class="fas fa-search"></i> Scan WiFi Tersedia';
        return;
    }

    setTimeout(function() {
        fetch('device-wifi-scan.php?device_id=' + encodeURIComponent(DEVICE_ID))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok && d.scan_results && d.scan_results.length > 0) {
                // Scan berhasil!
                status.className = 'alert alert-success';
                status.textContent = 'Ditemukan ' + d.scan_results.length + ' jaringan WiFi.';
                renderScanResults(d.scan_results);
                document.getElementById('btn-scan').disabled = false;
                document.getElementById('btn-scan').innerHTML = '<i class="fas fa-search"></i> Scan Ulang';
            } else if (d.scan_command_active) {
                status.textContent = 'ESP sedang scan... (' + (attempt + 1) + '/30)';
                pollScanResults(attempt + 1);
            } else {
                pollScanResults(attempt + 1);
            }
        })
        .catch(function() {
            pollScanResults(attempt + 1);
        });
    }, 2000);
}

// ─── Render Hasil Scan ─────────────────────────
function renderScanResults(results) {
    var container = document.getElementById('scan-results-container');
    var list = document.getElementById('scan-results');
    container.style.display = 'block';
    list.innerHTML = '';

    // Sort by signal strength
    results.sort(function(a, b) { return b.rssi - a.rssi; });

    results.forEach(function(net) {
        var signal = net.rssi;
        var signalIcon = signal > -50 ? 'fa-wifi text-success' : 
                         signal > -70 ? 'fa-wifi text-warning' : 'fa-wifi text-danger';
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        item.innerHTML = '<div><i class="fas ' + signalIcon + ' mr-2"></i>' +
                         '<strong>' + escHtml(net.ssid) + '</strong>' +
                         ' <small class="text-muted">' + (net.encr || 'Open') + '</small></div>' +
                         '<span class="badge badge-pill badge-' + (signal > -50 ? 'success' : signal > -70 ? 'warning' : 'danger') + '">' + signal + ' dBm</span>';
        item.onclick = function() {
            selectedSSID = net.ssid;
            document.getElementById('wifi-password').focus();
            document.getElementById('btn-apply').disabled = false;
            // Highlight selected
            list.querySelectorAll('.list-group-item').forEach(function(el) {
                el.classList.remove('active');
            });
            item.classList.add('active');
        };
        list.appendChild(item);
    });
}

// ─── Apply WiFi Baru ───────────────────────────
function applyWifi() {
    if (!selectedSSID) { return alert('Pilih WiFi terlebih dahulu'); }
    var password = document.getElementById('wifi-password').value;
    
    if (!confirm('Ganti WiFi ke "' + selectedSSID + '"? ESP akan reconnect.')) { return; }

    var btn = document.getElementById('btn-apply');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';

    fetch('/webapi/api/device-set-wifi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            device_id: DEVICE_ID,
            wifi_ssid: selectedSSID,
            wifi_password: password
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) {
            alert('WiFi baru dikirim ke ESP! Dalam 15-30 detik, ESP akan reconnect ke "' + selectedSSID + '".');
            document.getElementById('dev-wifi').textContent = selectedSSID + ' (pending...)';
        } else {
            alert('Gagal: ' + d.error);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Terapkan WiFi Baru';
    });
}

function togglePassword() {
    var el = document.getElementById('wifi-password');
    el.type = el.type === 'password' ? 'text' : 'password';
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add apps/device-wifi-config.php
git commit -m "feat: device-wifi-config.php - dashboard WiFi scan + change WiFi page"
```

---

## Task 9: Firmware — scan.ino Rewrite with WiFiManager + Device Polling

**Files:**
- Modify: `webapi/scan/scan.ino`

- [ ] **Step 1: Tambah WiFiManager + EEPROM ke scan.ino**

Tambah library di bagian atas file:

```cpp
#include <EEPROM.h>
#include <WiFiManager.h>

// ─── EEPROM Layout ─────────────────────────────
// Address 0-63:   WiFi SSID (null-terminated, max 63 chars)
// Address 64-127: WiFi Password (null-terminated, max 63 chars)
// Address 128-191: Device ID (null-terminated, max 63 chars)
// Address 192:    Magic byte (0xAA = valid config)
#define EEPROM_SIZE 256
#define EEPROM_MAGIC 0xAA
#define ADDR_WIFI_SSID    0
#define ADDR_WIFI_PASS    64
#define ADDR_DEVICE_ID    128
#define ADDR_MAGIC        192
```

- [ ] **Step 2: Tambah fungsi EEPROM read/write**

```cpp
// ─── EEPROM Helpers ─────────────────────────────
void eepromReadString(int addr, char* buf, int maxLen) {
  for (int i = 0; i < maxLen; i++) {
    buf[i] = EEPROM.read(addr + i);
    if (buf[i] == '\0') break;
  }
  buf[maxLen - 1] = '\0';
}

void eepromWriteString(int addr, const String &str, int maxLen) {
  for (int i = 0; i < maxLen - 1; i++) {
    EEPROM.write(addr + i, i < str.length() ? str.charAt(i) : '\0');
  }
  EEPROM.commit();
}

bool isEepromValid() {
  return EEPROM.read(ADDR_MAGIC) == EEPROM_MAGIC;
}

void saveWifiToEeprom(const String &ssid, const String &pass, const String &deviceId) {
  eepromWriteString(ADDR_WIFI_SSID, ssid, 64);
  eepromWriteString(ADDR_WIFI_PASS, pass, 64);
  eepromWriteString(ADDR_DEVICE_ID, deviceId, 64);
  EEPROM.write(ADDR_MAGIC, EEPROM_MAGIC);
  EEPROM.commit();
}

String eepromReadSSID() {
  char buf[64];
  eepromReadString(ADDR_WIFI_SSID, buf, 64);
  return String(buf);
}

String eepromReadPass() {
  char buf[64];
  eepromReadString(ADDR_WIFI_PASS, buf, 64);
  return String(buf);
}

String eepromReadDeviceId() {
  char buf[64];
  eepromReadString(ADDR_DEVICE_ID, buf, 64);
  return String(buf);
}
```

- [ ] **Step 3: Tambah variabel global + server URL config**

```cpp
// ─── Server ─────────────────────────────────────
const String server_base = "https://sbn-absensi.bakmibangkaasli17.com/webapi/api/";
const String device_config_url = server_base + "device-config.php";
const String device_register_url = server_base + "device-register.php";
const String device_scan_report_url = server_base + "device-scan-report.php";
String current_device_id = "";
String legacy_url = server_base + "create_legacy.php?uid=";
String register_url = server_base + "scan-register.php";
String mode_url = server_base + "register-mode.php?check=1";

// ─── Config Polling ─────────────────────────────
unsigned long lastConfigPoll = 0;
const unsigned long CONFIG_POLL_INTERVAL = 15000; // 15 detik
int lastConfigVersion = 0;
```

- [ ] **Step 4: Rewrite setup() — WiFiManager + AP Mode + Fallback**

```cpp
void setup() {
  Serial.begin(9600);
  EEPROM.begin(EEPROM_SIZE);
  lcd.init();
  lcd.backlight();
  lcd.begin(16, 2);
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("  RFID Attendance  ");
  lcd.setCursor(0, 1);
  lcd.print("   Initializing... ");
  
  pinMode(BUZZER, OUTPUT);
  SPI.begin();
  
  // ─── Step 1: Coba WiFi dari EEPROM ──────────────
  String savedSSID = eepromReadSSID();
  String savedPass = eepromReadPass();
  current_device_id = eepromReadDeviceId();
  
  bool connected = false;
  
  if (isEepromValid() && savedSSID.length() > 0) {
    Serial.println("WiFi dari EEPROM: " + savedSSID);
    lcd.setCursor(0, 1);
    lcd.print("Connect: " + savedSSID.substring(0, 8));
    
    WiFi.mode(WIFI_STA);
    WiFi.begin(savedSSID.c_str(), savedPass.c_str());
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 40) {
      delay(500);
      Serial.print(".");
      attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
      connected = true;
      Serial.println("\nWiFi connected: " + WiFi.localIP().toString());
    } else {
      Serial.println("\nWiFi EEPROM gagal connect");
    }
  }
  
  // ─── Step 2: Fallback WiFi + AP Mode ────────────
  if (!connected) {
    Serial.println("Starting WiFiManager AP Mode...");
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Setup WiFi Mode");
    lcd.setCursor(0, 1);
    lcd.print("Connect AP: ESP-RFID");
    
    WiFiManager wm;
    wm.setTitle("Konfigurasi WiFi ESP RFID");
    wm.setConfigPortalTimeout(300); // 5 menit timeout
    
    // Buat AP name dari MAC
    String apName = "ESP-RFID-" + WiFi.macAddress().substring(12);
    apName.replace(":", "");
    
    // Custom field untuk Device ID
    WiFiManagerParameter deviceParam("device_id", "Device ID (misal: ALAT-01)", current_device_id.c_str(), 50);
    wm.addParameter(&deviceParam);
    
    if (wm.autoConnect(apName.c_str())) {
      // Simpan ke EEPROM
      String newSSID = wm.getWiFiSSID();
      String newPass = wm.getWiFiPass();
      String newDeviceId = String(deviceParam.getValue());
      
      saveWifiToEeprom(newSSID, newPass, newDeviceId);
      current_device_id = newDeviceId;
      
      Serial.println("WiFi saved: " + newSSID);
      Serial.println("Device ID: " + newDeviceId);
      
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("WiFi Disimpan!");
      lcd.setCursor(0, 1);
      lcd.print("Rebooting...");
      delay(2000);
      ESP.restart();
    } else {
      Serial.println("AP Mode timeout");
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("Setup timeout");
      lcd.setCursor(0, 1);
      lcd.print("Using fallback...");
      
      // Fallback ke WiFi hardcoded
      WiFi.begin("TYO", "SCORPIO2510#");
      int att = 0;
      while (WiFi.status() != WL_CONNECTED && att < 20) {
        delay(500);
        att++;
      }
    }
  }
  
  Serial.println("WiFi Status: " + String(WiFi.status() == WL_CONNECTED ? "Connected" : "Failed"));
  Serial.println("IP: " + WiFi.localIP().toString());
  Serial.println("Device ID: " + current_device_id);
  Serial.println("MAC: " + WiFi.macAddress());
}
```

- [ ] **Step 5: Tambah fungsi pollDeviceConfig()**

```cpp
// ═════════════════════════════════════════════════════════════
// FUNGSI: Poll device config dari server
// ═════════════════════════════════════════════════════════════
void pollDeviceConfig() {
  if (WiFi.status() != WL_CONNECTED) return;
  if (current_device_id.length() == 0 && !isEepromValid()) return;
  
  String url = device_config_url + "?mac=" + WiFi.macAddress();
  
  String payload;
  int httpCode = 0;
  if (!doHttpsRequest(url, payload, httpCode) || httpCode != 200) return;
  
  DynamicJsonDocument doc(1024);
  if (deserializeJson(doc, payload)) return;
  
  // ─── Cek WiFi Pending ─────────────────────────
  if (doc["wifi_pending"].as<bool>()) {
    String newSSID = doc["wifi_ssid"].as<String>();
    String newPass = doc["wifi_password"].as<String>();
    int newVersion = doc["config_version"].as<int>();
    
    if (newVersion > lastConfigVersion) {
      Serial.println("WiFi change detected! SSID: " + newSSID);
      lcd.clear();
      lcd.setCursor(0, 0);
      lcd.print("WiFi Change!");
      lcd.setCursor(0, 1);
      lcd.print(newSSID.substring(0, 16));
      
      saveWifiToEeprom(newSSID, newPass, current_device_id);
      delay(1000);
      ESP.restart();
    }
  }
  
  // ─── Cek Scan Command ─────────────────────────
  if (doc["scan_wifi"].as<bool>()) {
    Serial.println("Scan WiFi command received...");
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Scanning WiFi...");
    
    int n = WiFi.scanNetworks();
    DynamicJsonDocument scanDoc(JSON_ARRAY_SIZE(n) + n * 128);
    JsonArray arr = scanDoc.to<JsonArray>();
    
    for (int i = 0; i < n; i++) {
      JsonObject net = arr.createNestedObject();
      net["ssid"] = WiFi.SSID(i);
      net["rssi"] = WiFi.RSSI(i);
      net["encr"] = (WiFi.encryptionType(i) == ENC_TYPE_NONE) ? "Open" : "WPA2";
    }
    
    // POST hasil scan ke server
    String reportPayload;
    serializeJson(scanDoc, reportPayload);
    
    DynamicJsonDocument macDoc(256);
    macDoc["mac_address"] = WiFi.macAddress();
    macDoc["results"] = arr;
    String finalPayload;
    serializeJson(macDoc, finalPayload);
    
    // Kirim via POST
    std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
    client->setInsecure();
    HTTPClient http;
    if (http.begin(*client, device_scan_report_url)) {
      http.addHeader("Content-Type", "application/json");
      http.POST(finalPayload);
      http.end();
    }
    
    WiFi.scanDelete();
    Serial.println("Scan results sent: " + String(n) + " networks");
    
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Scan Complete!");
    lcd.setCursor(0, 1);
    lcd.print(String(n) + " networks found");
  }
  
  // ─── Update config version ─────────────────────
  lastConfigVersion = doc["config_version"].as<int>();
  
  // ─── Register mode dari server ─────────────────
  // (opsional: jika ESP ingin tahu mode saat ini)
}
```

- [ ] **Step 6: Update loop() — tambah config polling**

Di dalam `void loop()`, sebelum atau sesudah check RFID, tambah:

```cpp
// Setiap 15 detik: poll device config
if (millis() - lastConfigPoll >= CONFIG_POLL_INTERVAL) {
  pollDeviceConfig();
  lastConfigPoll = millis();
}
```

- [ ] **Step 7: Setup Arduino IDE libraries**

Pastikan `platformio.ini` atau Arduino IDE sudah include:
- WiFiManager by tzapu (latest)
- ArduinoJson (latest)
- ESP8266WiFi (built-in)
- EEPROM (built-in)

- [ ] **Step 8: Commit**

```bash
git add webapi/scan/scan.ino
git commit -m "feat: scan.ino - WiFiManager + EEPROM + remote WiFi config

- WiFi tidak lagi hardcode, tersimpan di EEPROM
- First-time setup: AP mode dengan captive portal
- Poll server setiap 15 detik untuk config
- Scan WiFi dari server → ESP laporkan hasil
- WiFi change dari dashboard → ESP auto-reconnect
- Fallback ke WiFi lama jika EEPROM kosong"
```

---

## Task 10: Migration Deployment + Testing

**Files:**
- Run: `migrations/add_device_config.sql` di production VPS

- [ ] **Step 1: Deploy migration ke VPS**

Jalankan SQL di MySQL VPS:
```bash
mysql -u sbn17_rfid -p sbn17_rfid < migrations/add_device_config.sql
```

- [ ] **Step 2: Deploy API files ke VPS**

Download via curl atau git pull:
```bash
cd /www/wwwroot/sbn-absensi.bakmibangkaasli17.com
git pull origin main
```

- [ ] **Step 3: Test register-device.php via curl**

```bash
curl -s "http://localhost/webapi/api/device-register.php?mac=AC:67:B2:12:34:56&fv=1.3" | python3 -m json.tool
```
Expected:
```json
{
    "ok": true,
    "device_id": null,
    "assigned": false
}
```

- [ ] **Step 4: Test scan command via curl**

```bash
# Login dulu (ambil session cookie)
curl -s -c cookies.txt -d "username=admin&password=xxx" http://localhost/apps/login.php

# Kirim scan command
curl -s -b cookies.txt -X POST "http://localhost/webapi/api/device-wifi-scan.php" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"ALAT-01","action":"scan"}'
```

- [ ] **Step 5: Upload firmware scan.ino ke ESP via Arduino IDE**

1. Buka `webapi/scan/scan.ino` di Arduino IDE
2. Install library WiFiManager via Library Manager
3. Pilih board: "LOLIN(WEMOS) D1 R2 & mini" atau "NodeMCU 1.0"
4. Upload ke ESP
5. Buka Serial Monitor (9600 baud)
6. ESP akan restart → coba connect WiFi dari EEPROM
7. Jika gagal → mulai AP Mode "ESP-RFID-XXXX"

- [ ] **Step 6: Test end-to-end**

1. **First-time setup:**
   - ESP boot → AP Mode muncul
   - HP sambung ke WiFi "ESP-RFID-XXXX"
   - Buka 192.168.4.1
   - Pilih SSID WiFi, masukkan password, masukkan device_id
   - ESP reboot → connect ke WiFi

2. **Register ke server:**
   - ESP kirim MAC ke device-register.php
   - Admin buka Data Outlet → Edit → masukkan device_id yang sama

3. **Scan WiFi dari dashboard:**
   - Admin buka halaman WiFi Config
   - Klik "Scan WiFi"
   - Tunggu 15-20 detik
   - Daftar SSID muncul

4. **Ganti WiFi:**
   - Admin pilih SSID baru, masukkan password
   - Klik "Terapkan"
   - ESP reboot → connect ke WiFi baru ✅

---

## Implementation Order

| Order | Task | Dependency |
|-------|------|------------|
| 1 | Task 1: Database Migration | None |
| 2 | Task 2: device-register.php | Task 1 |
| 3 | Task 3: device-config.php | Task 1 |
| 4 | Task 4: device-scan-report.php | Task 1 |
| 5 | Task 5: device-wifi-scan.php | Task 1 |
| 6 | Task 6: device-set-wifi.php | Task 1 |
| 7 | Task 7: Outlet Forms | Task 1 |
| 8 | Task 8: WiFi Config Page | Task 5, 6 |
| 9 | Task 9: Firmware scan.ino | Task 2, 3, 4 |
| 10 | Task 10: Testing | All |

---

## Notes

- **Keamanan WiFi password**: Password disimpan plain-text di database (cukup untuk use case ini, karena hanya admin yang bisa akses dashboard).
- **EEPROM wear**: ESP8266 EEPROM sebenarnya menggunakan SPIFFS di belakang, tahan untuk operasi write berkala. Tidak masalah untuk config yang hanya ditulis saat ganti WiFi.
- **WiFi scan effect**: Saat `WiFi.scanNetworks()`, koneksi WiFi akan terganggu sekitar 3 detik. Setelah scan selesai, WiFi auto-reconnect.
- **Fallback WiFi**: Jika EEPROM kosong, ESP akan coba WiFi hardcoded ("TYO") sebagai fallback. Ini memudahkan development/testing.
- **MAC Address sebagai identifier**: Jika admin belum set device_id, ESP bisa di-identifikasi via MAC address.
