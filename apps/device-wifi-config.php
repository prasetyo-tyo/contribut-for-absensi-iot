<?php
/**
 * device-wifi-config.php
 * 
 * Halaman dashboard untuk mengelola WiFi ESP dari outlet.
 * Fitur:
 * - Lihat status device (online/offline, MAC, WiFi saat ini)
 * - Scan WiFi tersedia di sekitar ESP
 * - Pilih SSID + masukkan password
 * - Terapkan WiFi baru ke ESP
 */

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
                            | MAC: <?php echo htmlspecialchars($device['mac_address'] ?? 'Belum terdaftar'); ?>
                        </p>
                    </div>
                    <a href="data_outlet-update.php?id=<?php echo (int)($device['outlet_id'] ?? 0); ?>" class="btn btn-secondary">
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
                                <span id="dev-mac" class="text-monospace"><?php echo htmlspecialchars($device['mac_address'] ?? 'Belum terdaftar'); ?></span>
                            </div>
                            <div class="col-md-3">
                                <strong>WiFi Saat Ini:</strong><br>
                                <span id="dev-wifi" class="badge badge-info" style="font-size: 13px;">
                                    <?php echo htmlspecialchars($device['wifi_ssid'] ?? 'N/A'); ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <strong>Status:</strong><br>
                                <span id="dev-status" class="badge">Memuat...</span>
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
                            <h6>Pilih Jaringan WiFi:</h6>
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
                                <i class="fas fa-save"></i> Terapkan WiFi Baru ke ESP
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
<script src="../src/vendor/jquery-easing/jquery.easing.min.js"></script>
<script src="../src/js/sb-admin-2.min.js"></script>

<script>
var DEVICE_ID = <?php echo json_encode($deviceId); ?>;
var selectedSSID = null;
var statusTimer = null;

// ─── Status Check ──────────────────────────────────
function checkStatus() {
    fetch('device-wifi-scan.php?device_id=' + encodeURIComponent(DEVICE_ID))
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok) {
            var isOnline = d.last_seen_at && (new Date() - new Date(d.last_seen_at.replace(' ', 'T') + '+07:00')) < 120000;
            var statusEl = document.getElementById('dev-status');
            statusEl.className = 'badge badge-' + (isOnline ? 'success' : 'danger');
            statusEl.textContent = isOnline ? 'ONLINE' : 'OFFLINE';
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

// ─── Scan WiFi ─────────────────────────────────────
function startScan() {
    var btn = document.getElementById('btn-scan');
    var status = document.getElementById('scan-status');
    var resultsContainer = document.getElementById('scan-results-container');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Meminta scan...';
    status.style.display = 'block';
    status.className = 'alert alert-info';
    status.textContent = 'Mengirim perintah scan ke ESP... Harap tunggu 15-20 detik.';
    resultsContainer.style.display = 'none';

    // Kirim scan command ke server
    fetch('device-wifi-scan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ device_id: DEVICE_ID, action: 'scan' })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.ok) {
            status.className = 'alert alert-danger';
            status.textContent = 'Gagal mengirim perintah: ' + (d.error || 'Unknown error');
            enableScanButton();
            return;
        }
        // Polling hasil scan
        pollScanResults(0);
    })
    .catch(function() {
        status.className = 'alert alert-danger';
        status.textContent = 'Gagal koneksi ke server. Coba lagi.';
        enableScanButton();
    });
}

function pollScanResults(attempt) {
    var status = document.getElementById('scan-status');
    if (attempt > 30) { // 30 * 2 = 60 detik timeout
        status.className = 'alert alert-warning';
        status.textContent = 'Timeout — ESP tidak merespon. Pastikan ESP online dan terhubung ke server.';
        enableScanButton();
        return;
    }

    setTimeout(function() {
        fetch('device-wifi-scan.php?device_id=' + encodeURIComponent(DEVICE_ID))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok && d.scan_results && d.scan_results.length > 0) {
                // Scan berhasil!
                status.className = 'alert alert-success';
                status.textContent = 'Ditemukan ' + d.scan_results.length + ' jaringan WiFi di sekitar ESP.';
                renderScanResults(d.scan_results);
                document.getElementById('scan-results-container').style.display = 'block';
                enableScanButton();
            } else if (d.scan_command_active) {
                status.textContent = 'ESP sedang melakukan scan... (' + (attempt + 1) + '/30)';
                pollScanResults(attempt + 1);
            } else {
                // Belum ada hasil, coba lagi
                status.textContent = 'Menunggu hasil scan... (' + (attempt + 1) + '/30)';
                pollScanResults(attempt + 1);
            }
        })
        .catch(function() {
            pollScanResults(attempt + 1);
        });
    }, 2000);
}

function enableScanButton() {
    var btn = document.getElementById('btn-scan');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-radar"></i> Scan Ulang';
}

// ─── Render Hasil Scan ─────────────────────────────
function renderScanResults(results) {
    var list = document.getElementById('scan-results');
    list.innerHTML = '';

    // Sort by signal strength (strongest first)
    results.sort(function(a, b) { return b.rssi - a.rssi; });

    results.forEach(function(net) {
        var signal = net.rssi;
        var signalIcon, signalColor;
        if (signal > -50) {
            signalIcon = 'fa-wifi text-success';
            signalColor = 'success';
        } else if (signal > -70) {
            signalIcon = 'fa-wifi text-warning';
            signalColor = 'warning';
        } else {
            signalIcon = 'fa-wifi text-danger';
            signalColor = 'danger';
        }
        
        var ssid = net.ssid || '(Hidden SSID)';
        var encr = net.encr || 'Open';
        
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        item.innerHTML = '<div><i class="fas ' + signalIcon + ' mr-2"></i>' +
                         '<strong>' + escHtml(ssid) + '</strong>' +
                         ' <small class="text-muted">(' + encr + ')</small></div>' +
                         '<span class="badge badge-pill badge-' + signalColor + '">' + signal + ' dBm</span>';
        item.onclick = function() {
            selectedSSID = net.ssid || net.ssid;
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

// ─── Apply WiFi Baru ──────────────────────────────
function applyWifi() {
    if (!selectedSSID) {
        alert('Pilih jaringan WiFi terlebih dahulu.');
        return;
    }
    var password = document.getElementById('wifi-password').value;
    
    if (!confirm('Yakin ingin mengganti WiFi ke "' + selectedSSID + '"? ESP akan disconnect dan reconnect dalam 15-30 detik.')) {
        return;
    }

    var btn = document.getElementById('btn-apply');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim perintah...';

    fetch('../webapi/api/device-set-wifi.php', {
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
            alert('Perintah ganti WiFi berhasil dikirim! ESP akan reconnect ke "' + selectedSSID + '" dalam 15-30 detik.\n\nSetelah ESP restart dan connect, status akan berubah menjadi ONLINE.');
            document.getElementById('dev-wifi').textContent = selectedSSID + ' (pending...)';
        } else {
            alert('Gagal: ' + (d.error || 'Unknown error'));
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Terapkan WiFi Baru ke ESP';
    })
    .catch(function() {
        alert('Gagal koneksi ke server.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Terapkan WiFi Baru ke ESP';
    });
}

function togglePassword() {
    var el = document.getElementById('wifi-password');
    el.type = el.type === 'password' ? 'text' : 'password';
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
</script>
</body>
</html>