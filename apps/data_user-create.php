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

$username = '';
$username_err = '';
$password_err = '';
$confirm_password_err = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, (string) $_POST['csrf_token'])
    ) {
        $username_err = "Token keamanan tidak valid.";
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');

    if ($username_err === '' && $username === '') {
        $username_err = "Username wajib diisi.";
    } elseif ($username_err === '') {
        $checkSql = "SELECT id FROM users WHERE username = ? LIMIT 1";
        if ($checkStmt = mysqli_prepare($link, $checkSql)) {
            mysqli_stmt_bind_param($checkStmt, "s", $username);
            mysqli_stmt_execute($checkStmt);
            mysqli_stmt_store_result($checkStmt);
            if (mysqli_stmt_num_rows($checkStmt) > 0) {
                $username_err = "Username sudah digunakan.";
            }
            mysqli_stmt_close($checkStmt);
        }
    }

    if (strlen($password) < 6) {
        $password_err = "Password minimal 6 karakter.";
    }

    if ($confirm_password !== $password) {
        $confirm_password_err = "Konfirmasi password tidak sama.";
    }

    if ($username_err === '' && $password_err === '' && $confirm_password_err === '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $insertSql = "INSERT INTO users (username, password) VALUES (?, ?)";
        if ($stmt = mysqli_prepare($link, $insertSql)) {
            mysqli_stmt_bind_param($stmt, "ss", $username, $passwordHash);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "User berhasil ditambahkan.";
                header("location: data_user-index.php");
                exit;
            }
            $username_err = "Gagal menyimpan user.";
            mysqli_stmt_close($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PT. SUGIH BOGA NUSANTARA - Tambah User</title>
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
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Tambah User</h6>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo escape_html($csrfToken); ?>">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" name="username" class="form-control <?php echo $username_err !== '' ? 'is-invalid' : ''; ?>" value="<?php echo escape_html($username); ?>" required>
                                <span class="invalid-feedback"><?php echo escape_html($username_err); ?></span>
                            </div>
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" class="form-control <?php echo $password_err !== '' ? 'is-invalid' : ''; ?>" required>
                                <span class="invalid-feedback"><?php echo escape_html($password_err); ?></span>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Konfirmasi Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control <?php echo $confirm_password_err !== '' ? 'is-invalid' : ''; ?>" required>
                                <span class="invalid-feedback"><?php echo escape_html($confirm_password_err); ?></span>
                            </div>
                            <div class="row justify-content-end">
                                <input type="submit" class="btn btn-success" value="Simpan">
                                <a href="data_user-index.php" class="btn btn-primary ml-2">Batal</a>
                            </div>
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
<?php mysqli_close($link); ?>
