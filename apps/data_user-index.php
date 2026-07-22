<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "config.php";

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$result = mysqli_query($link, "SELECT id, username, created_at, updated_at FROM users ORDER BY username ASC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PT. SUGIH BOGA NUSANTARA - Data User</title>
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
                <h1 class="h3 mb-2 text-gray-800">Data User</h1>
                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo escape_html($_SESSION['success']); unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo escape_html($_SESSION['error']); unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Akun Login Sistem</h6>
                    </div>
                    <div class="card-body">
                        <a href="data_user-create.php" class="btn btn-success mb-3">Tambah User</a>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Dibuat</th>
                                    <th>Diubah</th>
                                    <th>Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($result && mysqli_num_rows($result) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td><?php echo escape_html($row['username']); ?></td>
                                            <td><?php echo escape_html($row['created_at']); ?></td>
                                            <td><?php echo escape_html($row['updated_at']); ?></td>
                                            <td style="white-space: nowrap;">
                                                <a href="data_user-update.php?id=<?php echo (int) $row['id']; ?>" title="Edit">
                                                    <span class="fa fa-edit"></span>
                                                </a>
                                                <?php if ((int) $row['id'] !== (int) $_SESSION['id']): ?>
                                                    &nbsp;
                                                    <form action="data_user-delete.php" method="post" style="display:inline;" onsubmit="return confirm('Hapus user ini?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo escape_html($csrfToken); ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <button type="submit" title="Hapus" style="border:none;background:none;padding:0;color:#e74a3b;cursor:pointer;">
                                                            <span class="fa fa-trash"></span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    &nbsp;<span class="text-muted">Akun aktif</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center">Belum ada user.</td></tr>
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
<?php mysqli_close($link); ?>
