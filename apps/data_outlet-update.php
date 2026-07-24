<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "config.php";

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    die('ID outlet tidak valid.');
}

$stmt = mysqli_prepare($link, "SELECT id, nama_outlet, kode_alat, keterangan, device_id FROM data_outlet WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$outlet = mysqli_fetch_assoc($result);

if (!$outlet) {
    die('Outlet tidak ditemukan.');
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nama_outlet = trim($_POST['nama_outlet'] ?? '');
    $kode_alat = trim($_POST['kode_alat'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $device_id = trim($_POST['device_id'] ?? '');

    if ($nama_outlet === '') {
        $error = 'Nama outlet wajib diisi.';
    } else {
        $update = mysqli_prepare($link, "UPDATE data_outlet SET nama_outlet = ?, kode_alat = ?, keterangan = ?, device_id = ? WHERE id = ?");
        mysqli_stmt_bind_param($update, "ssssi", $nama_outlet, $kode_alat, $keterangan, $device_id, $id);
        if (mysqli_stmt_execute($update)) {
            header("location: data_outlet-index.php");
            exit;
        }
        $error = "Gagal memperbarui outlet: " . mysqli_error($link);
    }

    $outlet['nama_outlet'] = $nama_outlet;
    $outlet['kode_alat'] = $kode_alat;
    $outlet['keterangan'] = $keterangan;
    $outlet['device_id'] = $device_id;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Edit Outlet</title>
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
                <h1 class="h3 mb-2 text-gray-800">Edit Outlet</h1>
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="form-group">
                                <label>Nama Outlet</label>
                                <input type="text" name="nama_outlet" class="form-control" required value="<?php echo htmlspecialchars($outlet['nama_outlet']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Kode Alat</label>
                                <input type="text" name="kode_alat" class="form-control" value="<?php echo htmlspecialchars($outlet['kode_alat'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Keterangan</label>
                                <textarea name="keterangan" class="form-control" rows="3"><?php echo htmlspecialchars($outlet['keterangan'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Device ID</label>
                                <input type="text" name="device_id" class="form-control" value="<?php echo htmlspecialchars($outlet['device_id'] ?? ''); ?>" placeholder="Misal: ALAT-01">
                                <small class="form-text text-muted">ID unik ESP8266. Lihat di serial monitor atau bodi alat.</small>
                            </div>
                            <button type="submit" class="btn btn-success">Simpan</button>
                            <a href="data_outlet-index.php" class="btn btn-primary">Batal</a>
<?php
// Tampilkan WiFi status jika device sudah terdaftar
$deviceIdVal = $outlet['device_id'] ?? '';
if (!empty($deviceIdVal)) {
    $devSql = "SELECT mac_address, wifi_ssid, wifi_signal, last_seen_at, current_mode
               FROM device_config WHERE device_id = ? LIMIT 1";
    $devStmt = mysqli_prepare($link, $devSql);
    mysqli_stmt_bind_param($devStmt, "s", $deviceIdVal);
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
            <tr><td>Mode</td><td><span class="badge badge-<?php echo ($dev['current_mode'] ?? 'absen') === 'register' ? 'warning' : 'primary'; ?>"><?php echo strtoupper($dev['current_mode'] ?? 'absen'); ?></span></td></tr>
            <tr><td>Status</td><td><span class="badge badge-<?php echo $isOnline ? 'success' : 'danger'; ?>"><?php echo $isOnline ? 'ONLINE' : 'OFFLINE'; ?></span></td></tr>
            <tr><td>Last Seen</td><td><?php echo $dev['last_seen_at'] ?? '-'; ?></td></tr>
        </table>
        <a href="device-wifi-config.php?device_id=<?php echo urlencode($deviceIdVal); ?>" class="btn btn-info btn-sm mt-2">
            <i class="fas fa-wifi"></i> Kelola WiFi
        </a>
    </div>
</div>
<?php
    }
}
?>
                        </form>
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
</body>
</html>
