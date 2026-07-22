<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Koneksi ke database
include 'config.php';

// Periksa koneksi
if (mysqli_connect_errno()) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

// Cek apakah ada NIP/UID legacy dan tanggal yang diterima
if(isset($_GET['id']) && isset($_GET['tanggal'])){
    $nip = mysqli_real_escape_string($link, $_GET['id']);
    $tanggal = mysqli_real_escape_string($link, $_GET['tanggal']);

    // Ambil data absen untuk tanggal tertentu
    $sql = "SELECT * FROM data_absen WHERE (nip = ? OR (nip IS NULL AND uid = ?)) AND tanggal = ? ORDER BY FIELD(status, 'IN', 'OUT')";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "sss", $nip, $nip, $tanggal);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    // Periksa apakah data ditemukan
    if(mysqli_num_rows($result) > 0){
        $absen_data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $row_masuk = null;
        $row_keluar = null;
        foreach ($absen_data as $absen_row) {
            if ($absen_row['status'] === 'IN') {
                $row_masuk = $absen_row;
            } elseif ($absen_row['status'] === 'OUT') {
                $row_keluar = $absen_row;
            }
        }
        $row_masuk = $row_masuk ?: ['waktu' => '00:00:00', 'outlet_id' => null, 'keterangan' => 'HADIR'];
        $row_keluar = $row_keluar ?: ['waktu' => '00:00:00', 'outlet_id' => null, 'keterangan' => $row_masuk['keterangan']];
        $keterangan = $row_masuk['keterangan']; // Ambil keterangan dari data masuk
    } else {
        // Jika tidak ada data, tampilkan pesan error
        die("Data tidak ditemukan untuk NIP/UID: " . escape_html($nip) . " pada tanggal: " . escape_html($tanggal));
    }
} else {
    // Jika tidak ada UID atau tanggal, tampilkan pesan error
    die("NIP atau tanggal tidak diberikan");
}

// Ambil daftar karyawan
$query = "SELECT nip, uid, nama FROM data_karyawan";
$result = mysqli_query($link, $query);
$karyawan = [];
while ($karyawan_row = mysqli_fetch_assoc($result)) {
    $karyawan[] = $karyawan_row;
}

$outletResult = mysqli_query($link, "SELECT id, nama_outlet FROM data_outlet ORDER BY nama_outlet ASC");
$outlets = [];
while ($outletRow = mysqli_fetch_assoc($outletResult)) {
    $outlets[] = $outletRow;
}

// Proses form jika ada pengiriman data
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nip = $_POST['uid'];
    $keterangan = $_POST['keterangan'];
    $tanggal = $_POST['tanggal'];
    $outlet_masuk_id = isset($_POST['outlet_masuk_id']) && $_POST['outlet_masuk_id'] !== '' ? (int) $_POST['outlet_masuk_id'] : null;
    $outlet_keluar_id = isset($_POST['outlet_keluar_id']) && $_POST['outlet_keluar_id'] !== '' ? (int) $_POST['outlet_keluar_id'] : null;
    
    if (!in_array($keterangan, ['HADIR', '1/2 HARI'], true)) {
        $waktu_masuk = '00:00:00';
        $waktu_keluar = '00:00:00';
    } else {
        $waktu_masuk = $_POST['waktu_masuk'];
        $waktu_keluar = $_POST['waktu_keluar'];
    }

    // Update data absen masuk di database
    $update_query_masuk = "UPDATE data_absen SET waktu = ?, keterangan = ?, outlet_id = ? WHERE (nip = ? OR (nip IS NULL AND uid = ?)) AND tanggal = ? AND status = 'IN'";
    $stmt_masuk = mysqli_prepare($link, $update_query_masuk);
    mysqli_stmt_bind_param($stmt_masuk, "ssisss", $waktu_masuk, $keterangan, $outlet_masuk_id, $nip, $nip, $tanggal);
    mysqli_stmt_execute($stmt_masuk);

    // Update data absen keluar di database
    $update_query_keluar = "UPDATE data_absen SET waktu = ?, keterangan = ?, outlet_id = ? WHERE (nip = ? OR (nip IS NULL AND uid = ?)) AND tanggal = ? AND status = 'OUT'";
    $stmt_keluar = mysqli_prepare($link, $update_query_keluar);
    mysqli_stmt_bind_param($stmt_keluar, "ssisss", $waktu_keluar, $keterangan, $outlet_keluar_id, $nip, $nip, $tanggal);
    mysqli_stmt_execute($stmt_keluar);

    // Tambahkan session untuk notifikasi
    $_SESSION['message'] = "Data absen telah diperbarui.";
    header("location: data_absen-index.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PT. SUGIH BOGA NUSANTARA  - Edit Data Absensi</title>
    <link rel="icon" href="../src/img/1.png" type="image/x-icon">
    <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito" rel="stylesheet">
    <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="../src/vendor/jquery-ui/jquery-ui.css" rel="stylesheet">
</head>
<body id="page-top">
    <div id="wrapper">
        <?php include 'partial_sidebar.php';?>
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <?php include 'partial_topbar.php';?>
                <div class="container-fluid">
                    <h1 class="h3 mb-2 text-gray-800">Data Absensi</h1>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Edit Data</h6>
                        </div>
                        <div class="card-body">
                            <div class="col-md-12"> 
                                <form method="post">
                                    <input type="hidden" name="uid" value="<?= escape_html($nip) ?>">
                                    <input type="hidden" name="tanggal" value="<?= escape_html($tanggal) ?>">
                                    <div class="form-group">
                                        <label for="nama">Nama</label>
                                        <select name="nama" id="nama" class="form-control" disabled>
                                            <?php foreach ($karyawan as $k): ?>
                                                <option value="<?= escape_html($k['nip']) ?>" <?= ($k['nip'] == $nip) ? 'selected' : '' ?>><?= escape_html($k['nama']) ?> - <?= escape_html($k['nip']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="keterangan">Keterangan</label>
                                        <select class="form-control" name="keterangan" id="keterangan" required>
                                            <option value="HADIR" <?= ($keterangan == 'HADIR') ? 'selected' : '' ?>>HADIR</option>
                                            <option value="1/2 HARI" <?= ($keterangan == '1/2 HARI') ? 'selected' : '' ?>>1/2 HARI</option>
                                            <option value="IZIN" <?= ($keterangan == 'IZIN') ? 'selected' : '' ?>>IZIN</option>
                                            <option value="SAKIT" <?= ($keterangan == 'SAKIT') ? 'selected' : '' ?>>SAKIT</option>
                                            <option value="CUTI" <?= ($keterangan == 'CUTI') ? 'selected' : '' ?>>CUTI</option>
                                            <option value="ALPA" <?= ($keterangan == 'ALPA') ? 'selected' : '' ?>>ALPA</option>
                                            <option value="WFH" <?= ($keterangan == 'WFH') ? 'selected' : '' ?>>WFH</option>
                                        </select>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="outlet_masuk_id">Lokasi Masuk</label>
                                            <select class="form-control" name="outlet_masuk_id" id="outlet_masuk_id" required>
                                                <option value="">--Pilih Lokasi Masuk--</option>
                                                <?php foreach ($outlets as $outlet): ?>
                                                    <option value="<?= (int) $outlet['id'] ?>" <?= ((int) $outlet['id'] === (int) ($row_masuk['outlet_id'] ?? 0)) ? 'selected' : '' ?>>
                                                        <?= escape_html($outlet['nama_outlet']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="outlet_keluar_id">Lokasi Keluar</label>
                                            <select class="form-control" name="outlet_keluar_id" id="outlet_keluar_id" required>
                                                <option value="">--Pilih Lokasi Keluar--</option>
                                                <?php foreach ($outlets as $outlet): ?>
                                                    <option value="<?= (int) $outlet['id'] ?>" <?= ((int) $outlet['id'] === (int) ($row_keluar['outlet_id'] ?? 0)) ? 'selected' : '' ?>>
                                                        <?= escape_html($outlet['nama_outlet']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="tanggal">Tanggal</label>
                                        <input type="date" name="tanggal" id="tanggal" class="form-control" value="<?= escape_html($tanggal) ?>" readonly>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="waktu_masuk">Jam Masuk</label>
                                            <input type="time" name="waktu_masuk" id="waktu_masuk" class="form-control" value="<?= escape_html(substr($row_masuk['waktu'], 0, 5)) ?>" required>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="waktu_keluar">Jam Keluar</label>
                                            <input type="time" name="waktu_keluar" id="waktu_keluar" class="form-control" value="<?= escape_html(substr($row_keluar['waktu'], 0, 5)) ?>" required>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row justify-content-end">
                                        <input type="submit" class="btn btn-success" value="Simpan Perubahan"> &nbsp;
                                        <a href="data_absen-index.php" class="btn btn-primary">Batal</a>
                                    </div>
                                </form>
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
<script src="../src/vendor/jquery-ui/jquery-ui.js"></script>

<script>
    // Script untuk menonaktifkan pilihan waktu jika keterangan bukan 'Hadir'
    document.getElementById('keterangan').addEventListener('change', function() {
        const waktuMasuk = document.getElementById('waktu_masuk');
        const waktuKeluar = document.getElementById('waktu_keluar');
        if (this.value !== 'HADIR' && this.value !== '1/2 HARI') {
            waktuMasuk.value = '00:00';
            waktuMasuk.disabled = true;
            waktuKeluar.value = '00:00';
            waktuKeluar.disabled = true;
        } else {
            waktuMasuk.disabled = false;
            waktuKeluar.disabled = false;
        }
    });

    // Panggil event change saat halaman dimuat untuk mengatur status awal
    document.getElementById('keterangan').dispatchEvent(new Event('change'));
</script>

</body>
</html>

<?php
mysqli_close($link);
?>
