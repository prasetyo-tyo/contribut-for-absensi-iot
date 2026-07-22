<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "config.php";

$nama_outlet = '';
$kode_alat = '';
$keterangan = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nama_outlet = trim($_POST['nama_outlet'] ?? '');
    $kode_alat = trim($_POST['kode_alat'] ?? '');
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($nama_outlet === '') {
        $error = 'Nama outlet wajib diisi.';
    } else {
        $sql = "INSERT INTO data_outlet (nama_outlet, kode_alat, keterangan) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, "sss", $nama_outlet, $kode_alat, $keterangan);
        if (mysqli_stmt_execute($stmt)) {
            header("location: data_outlet-index.php");
            exit;
        }

        $error = "Gagal menyimpan outlet: " . mysqli_error($link);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Tambah Outlet</title>
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
                <h1 class="h3 mb-2 text-gray-800">Tambah Outlet</h1>
                <div class="card shadow mb-4">
                    <div class="card-body">
                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="form-group">
                                <label>Nama Outlet</label>
                                <input type="text" name="nama_outlet" class="form-control" required value="<?php echo htmlspecialchars($nama_outlet); ?>">
                            </div>
                            <div class="form-group">
                                <label>Kode Alat</label>
                                <input type="text" name="kode_alat" class="form-control" value="<?php echo htmlspecialchars($kode_alat); ?>" placeholder="Misal: ALAT-01">
                            </div>
                            <div class="form-group">
                                <label>Keterangan</label>
                                <textarea name="keterangan" class="form-control" rows="3"><?php echo htmlspecialchars($keterangan); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-success">Simpan</button>
                            <a href="data_outlet-index.php" class="btn btn-primary">Batal</a>
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
