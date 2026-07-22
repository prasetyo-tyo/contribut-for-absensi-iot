<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "config.php";
require_once "app_settings.php";
require_once "attendance_period_helper.php";

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pin_err = '';
$confirm_pin_err = '';
$hasConfiguredPin = get_absen_view_pin_hash($link) !== '';
$attendance_err = '';
$halfday_enabled = get_app_setting($link, 'halfday_enabled', '0') === '1';
$flexible_outlet_ids = get_app_setting($link, 'flexible_outlet_ids', '1');
$flexible_full_day_min_minutes = get_app_setting($link, 'flexible_full_day_min_minutes', '420');
$outlet_full_day_min_minutes = get_app_setting($link, 'outlet_full_day_min_minutes', '480');
$attendancePeriodSettings = attendance_period_settings($link);
$attendance_period_start_day = (string) $attendancePeriodSettings['start_day'];
$attendance_period_end_day = (string) $attendancePeriodSettings['end_day'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrfToken, (string) $_POST['csrf_token'])
    ) {
        $pin_err = 'Token keamanan tidak valid.';
        $attendance_err = 'Token keamanan tidak valid.';
    }

    $action = (string) ($_POST['action_type'] ?? 'pin');

    if ($pin_err === '' && $action === 'pin') {
        $pin = trim((string) ($_POST['pin'] ?? ''));
        $confirmPin = trim((string) ($_POST['confirm_pin'] ?? ''));

        if (strlen($pin) < 6) {
            $pin_err = 'PIN/password minimal 6 karakter.';
        }

        if ($pin !== $confirmPin) {
            $confirm_pin_err = 'Konfirmasi PIN/password tidak sama.';
        }

        if ($pin_err === '' && $confirm_pin_err === '') {
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            if ($pinHash !== false && set_app_setting($link, 'absen_view_pin_hash', $pinHash)) {
                unset($_SESSION['absen_view_granted']);
                $_SESSION['success'] = 'PIN halaman absen berhasil diperbarui.';
                header('location: pengaturan_keamanan.php');
                exit;
            }

            $pin_err = 'Gagal menyimpan PIN/password.';
        }
    } elseif ($attendance_err === '' && $action === 'attendance') {
        $halfday_enabled = isset($_POST['halfday_enabled']);
        $flexible_outlet_ids = trim((string) ($_POST['flexible_outlet_ids'] ?? '1'));
        $flexible_full_day_min_minutes = trim((string) ($_POST['flexible_full_day_min_minutes'] ?? '420'));
        $outlet_full_day_min_minutes = trim((string) ($_POST['outlet_full_day_min_minutes'] ?? '480'));
        $attendance_period_start_day = trim((string) ($_POST['attendance_period_start_day'] ?? '25'));
        $attendance_period_end_day = trim((string) ($_POST['attendance_period_end_day'] ?? '24'));

        $flexibleOutletIds = array_filter(array_map('trim', explode(',', $flexible_outlet_ids)), 'strlen');
        $invalidOutletIds = array_filter($flexibleOutletIds, function ($id) {
            return !ctype_digit($id);
        });

        if (!empty($invalidOutletIds)) {
            $attendance_err = 'ID outlet fleksibel harus angka, pisahkan dengan koma.';
        } elseif (!ctype_digit($flexible_full_day_min_minutes) || !ctype_digit($outlet_full_day_min_minutes) || !ctype_digit($attendance_period_start_day) || !ctype_digit($attendance_period_end_day)) {
            $attendance_err = 'Durasi dan tanggal periode harus angka.';
        } elseif ((int) $flexible_full_day_min_minutes <= 0 || (int) $outlet_full_day_min_minutes <= 0) {
            $attendance_err = 'Durasi harus lebih dari 0.';
        } elseif ((int) $attendance_period_start_day < 1 || (int) $attendance_period_start_day > 31 || (int) $attendance_period_end_day < 1 || (int) $attendance_period_end_day > 31) {
            $attendance_err = 'Tanggal periode absensi harus antara 1 sampai 31.';
        }

        if ($attendance_err === '') {
            $ok = set_app_setting($link, 'halfday_enabled', $halfday_enabled ? '1' : '0');
            $ok = set_app_setting($link, 'flexible_outlet_ids', implode(',', $flexibleOutletIds)) && $ok;
            $ok = set_app_setting($link, 'flexible_full_day_min_minutes', $flexible_full_day_min_minutes) && $ok;
            $ok = set_app_setting($link, 'outlet_full_day_min_minutes', $outlet_full_day_min_minutes) && $ok;
            $ok = set_app_setting($link, 'attendance_period_start_day', (string) (int) $attendance_period_start_day) && $ok;
            $ok = set_app_setting($link, 'attendance_period_end_day', (string) (int) $attendance_period_end_day) && $ok;

            if ($ok) {
                $_SESSION['success'] = 'Pengaturan absensi berhasil diperbarui.';
                header('location: pengaturan_keamanan.php');
                exit;
            }

            $attendance_err = 'Gagal menyimpan pengaturan absensi.';
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
    <title>PT. SUGIH BOGA NUSANTARA - Pengaturan Keamanan</title>
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
                <h1 class="h3 mb-2 text-gray-800">Pengaturan Keamanan</h1>
                <?php if (!empty($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo escape_html($_SESSION['success']); unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo escape_html($_SESSION['error']); unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">PIN Halaman Absen Publik</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">PIN/password ini dipakai untuk membuka <code>absen.php</code>. Nilai asli tidak ditampilkan karena disimpan dalam bentuk hash.</p>
                        <?php if (!$hasConfiguredPin): ?>
                            <div class="alert alert-warning">PIN publik belum diset. Halaman <code>absen.php</code> tetap terkunci sampai Anda menyimpan PIN baru di sini.</div>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo escape_html($csrfToken); ?>">
                            <div class="form-group">
                                <label for="pin">PIN / Password Baru</label>
                                <div class="input-group">
                                    <input type="password" id="pin" name="pin" class="form-control <?php echo $pin_err !== '' ? 'is-invalid' : ''; ?>" required>
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="pin" aria-label="Tampilkan atau sembunyikan PIN">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <span class="invalid-feedback"><?php echo escape_html($pin_err); ?></span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_pin">Konfirmasi PIN / Password Baru</label>
                                <div class="input-group">
                                    <input type="password" id="confirm_pin" name="confirm_pin" class="form-control <?php echo $confirm_pin_err !== '' ? 'is-invalid' : ''; ?>" required>
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_pin" aria-label="Tampilkan atau sembunyikan konfirmasi PIN">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <span class="invalid-feedback"><?php echo escape_html($confirm_pin_err); ?></span>
                                </div>
                            </div>
                            <div class="row justify-content-end">
                                <input type="submit" class="btn btn-success" value="Simpan">
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Aturan Absensi</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Aturan ini dipakai otomatis saat scan pulang berdasarkan durasi kerja. Jika aktif dan durasi belum mencapai full day, keterangan absen menjadi <b>1/2 HARI</b>.</p>
                        <?php if ($attendance_err !== ''): ?>
                            <div class="alert alert-danger"><?php echo escape_html($attendance_err); ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo escape_html($csrfToken); ?>">
                            <input type="hidden" name="action_type" value="attendance">
                            <div class="form-group form-check">
                                <input type="checkbox" class="form-check-input" id="halfday_enabled" name="halfday_enabled" <?php echo $halfday_enabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="halfday_enabled">Aktifkan aturan 1/2 hari otomatis</label>
                            </div>
                            <div class="form-group">
                                <label for="flexible_outlet_ids">ID outlet fleksibel/kantor/produksi</label>
                                <input type="text" class="form-control" id="flexible_outlet_ids" name="flexible_outlet_ids" value="<?php echo escape_html($flexible_outlet_ids); ?>" placeholder="Contoh: 1,3,5">
                                <small class="form-text text-muted">Outlet ID di sini memakai aturan durasi fleksibel. Pisahkan beberapa ID dengan koma.</small>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="flexible_full_day_min_minutes">Fleksibel full day (menit)</label>
                                    <input type="number" min="1" class="form-control" id="flexible_full_day_min_minutes" name="flexible_full_day_min_minutes" value="<?php echo escape_html($flexible_full_day_min_minutes); ?>">
                                    <small class="form-text text-muted">Contoh 420 menit = 7 jam.</small>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="outlet_full_day_min_minutes">Outlet full day (menit)</label>
                                    <input type="number" min="1" class="form-control" id="outlet_full_day_min_minutes" name="outlet_full_day_min_minutes" value="<?php echo escape_html($outlet_full_day_min_minutes); ?>">
                                    <small class="form-text text-muted">Contoh 480 menit = 8 jam.</small>
                                </div>
                            </div>
                            <hr>
                            <h6 class="font-weight-bold text-primary">Periode Cetak Rekap</h6>
                            <p class="text-muted mb-3">Dipakai untuk cetak/export rekap bulanan. Contoh mulai 25 dan selesai 24: bulan 07-2026 mengambil data 25/06/2026 sampai 24/07/2026.</p>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="attendance_period_start_day">Tanggal mulai periode</label>
                                    <input type="number" min="1" max="31" class="form-control" id="attendance_period_start_day" name="attendance_period_start_day" value="<?php echo escape_html($attendance_period_start_day); ?>">
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="attendance_period_end_day">Tanggal selesai periode</label>
                                    <input type="number" min="1" max="31" class="form-control" id="attendance_period_end_day" name="attendance_period_end_day" value="<?php echo escape_html($attendance_period_end_day); ?>">
                                </div>
                            </div>
                            <div class="row justify-content-end">
                                <input type="submit" class="btn btn-success" value="Simpan Aturan Absensi">
                            </div>
                        </form>
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
<script>
document.querySelectorAll('.toggle-password').forEach(function (button) {
    button.addEventListener('click', function () {
        var targetId = button.getAttribute('data-target');
        var input = document.getElementById(targetId);
        var icon = button.querySelector('i');

        if (!input || !icon) {
            return;
        }

        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
});
</script>
</body>
</html>
<?php mysqli_close($link); ?>
