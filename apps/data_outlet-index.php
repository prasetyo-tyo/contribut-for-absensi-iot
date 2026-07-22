<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once "config.php";

$result = mysqli_query($link, "SELECT id, nama_outlet, kode_alat, keterangan, created_at FROM data_outlet ORDER BY id ASC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PT. SUGIH BOGA NUSANTARA - Data Outlet</title>
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
                <h1 class="h3 mb-2 text-gray-800">Data Outlet</h1>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Master Outlet dan Alat</h6>
                    </div>
                    <div class="card-body">
                        <a href="data_outlet-create.php" class="btn btn-success mb-3">Tambah Outlet</a>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nama Outlet</th>
                                    <th>Kode Alat</th>
                                    <th>Keterangan</th>
                                    <th>Dibuat</th>
                                    <th>Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($result && mysqli_num_rows($result) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td><?php echo (int) $row['id']; ?></td>
                                            <td><?php echo htmlspecialchars($row['nama_outlet']); ?></td>
                                            <td><?php echo htmlspecialchars($row['kode_alat'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($row['keterangan'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                            <td>
                                                <a href="data_outlet-update.php?id=<?php echo (int) $row['id']; ?>" title="Edit"><span class="fa fa-edit"></span></a>
                                                &nbsp;
                                                <form action="data_outlet-delete.php" method="post" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus outlet ini?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                    <button type="submit" title="Hapus" style="border:none;background:none;padding:0;color:#e74a3b;cursor:pointer;"><span class="fa fa-trash"></span></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center">Belum ada data outlet.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<a class="scroll-to-top rounded" href="#page-top">
    <i class="fas fa-angle-up"></i>
</a>

<div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutModalLabel">Ready to Leave?</h5>
                <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">Pilih "Logout" jika ingin mengakhiri sesi ini.</div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                <a class="btn btn-primary" href="logout.php">Logout</a>
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
