<?php
session_start();

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

include 'config.php';
require_once 'karyawan_options.php';
require_once dirname(__DIR__) . '/shared/card_security.php';
require_once 'karyawan_picture_helper.php';

if (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
    $id = (int) $_GET['id'];
} elseif (isset($_POST['id']) && ctype_digit((string) $_POST['id'])) {
    $id = (int) $_POST['id'];
} else {
    echo "ID karyawan tidak ditentukan.";
    exit;
}

$sql = "SELECT * FROM data_karyawan WHERE id = ?";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $nip = $row['nip'] ?? '';
        $uid = $row['uid'] ?? '';
        $uid_fisik = $row['uid_fisik'] ?? '';
        $token_kartu = $row['token_kartu'] ?? '';
        $nama = $row['nama'] ?? '';
        $no_hp = $row['no_hp'] ?? '';
        $division = $row['division'] ?? '';
        $jabatan = $row['jabatan'] ?? '';
        $mail = $row['mail'] ?? '';
        $tanggal_lahir = $row['tanggal_lahir'] ?? '';
        $alamat = $row['alamat'] ?? '';
        $picture = $row['picture'] ?? '';
        $status_karyawan = $row['status_karyawan'] ?? 'AKTIF';
    } else {
        echo "Karyawan tidak ditemukan.";
        exit;
    }

    mysqli_stmt_close($stmt);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nip = strtoupper(trim($_POST['nip'] ?? ''));
    $uid_fisik = card_normalize_value($_POST['uid_fisik'] ?? '');
    $token_kartu = card_normalize_value($_POST['token_kartu'] ?? '');
    $nama = trim($_POST['nama'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');
    $no_hp = $no_hp === '' ? null : $no_hp;
    $division = trim($_POST['division'] ?? '');
    $jabatan = trim($_POST['jabatan'] ?? '');
    $status_karyawan = strtoupper(trim($_POST['status_karyawan'] ?? 'AKTIF'));
    if (!in_array($status_karyawan, ['AKTIF', 'NONAKTIF'], true)) {
        $status_karyawan = 'AKTIF';
    }
    $mail = trim($_POST['mail'] ?? '');
    $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '');
    $tanggal_lahir = $tanggal_lahir === '' ? null : $tanggal_lahir;
    $alamat = trim($_POST['alamat'] ?? '');
    $alamat = $alamat === '' ? null : $alamat;
    $picture = trim($_POST['picture'] ?? '');
    $picture = $picture === '' ? null : $picture;
    $old_picture = $row['picture'] ?? '';
    $picture_upload_error = "";

    $duplicate_error = "";
    if ($nip === "") {
        $duplicate_error = "NIP wajib diisi.";
    }

    if ($duplicate_error === "" && $nama === "") {
        $duplicate_error = "Nama wajib diisi.";
    }

    if ($duplicate_error === "" && $division === "") {
        $duplicate_error = "Divisi wajib dipilih.";
    }

    if ($duplicate_error === "" && $jabatan === "") {
        $duplicate_error = "Jabatan wajib dipilih.";
    }

    if ($duplicate_error === "" && (($uid_fisik === "") !== ($token_kartu === ""))) {
        $duplicate_error = "UID fisik dan token kartu harus diisi berpasangan.";
    }

    if ($uid_fisik !== '' && $token_kartu !== '') {
        $uid = card_build_internal_uid($uid_fisik, $token_kartu);
    }

    $check_sql = "SELECT id FROM data_karyawan WHERE nip = ? AND id <> ? LIMIT 1";
    if ($duplicate_error === "" && ($check_stmt = mysqli_prepare($link, $check_sql))) {
        mysqli_stmt_bind_param($check_stmt, "si", $nip, $id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $duplicate_error = "NIP sudah digunakan karyawan lain.";
        }
        mysqli_stmt_close($check_stmt);
    }

    if ($duplicate_error === "") {
        $check_sql = "SELECT id FROM data_karyawan WHERE uid = ? AND id <> ? LIMIT 1";
        if ($check_stmt = mysqli_prepare($link, $check_sql)) {
            mysqli_stmt_bind_param($check_stmt, "si", $uid, $id);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $duplicate_error = "Kartu ini sudah digunakan karyawan lain.";
            }
            mysqli_stmt_close($check_stmt);
        }
    }

    $uploadedPicture = null;
    if ($duplicate_error === "") {
        $uploadedPicture = karyawan_picture_upload('picture_file', $picture_upload_error);
        if ($picture_upload_error !== "") {
            $duplicate_error = $picture_upload_error;
        } elseif ($uploadedPicture !== null) {
            $picture = $uploadedPicture;
        }
    }

    if ($duplicate_error !== "") {
        echo "<script>alert('" . addslashes($duplicate_error) . "');</script>";
    } else {
        $sql = "UPDATE data_karyawan SET nip=?, uid=?, uid_fisik=?, token_kartu=?, nama=?, no_hp=?, division=?, jabatan=?, mail=?, tanggal_lahir=?, alamat=?, picture=?, status_karyawan=? WHERE id=?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssssssssssi", $nip, $uid, $uid_fisik, $token_kartu, $nama, $no_hp, $division, $jabatan, $mail, $tanggal_lahir, $alamat, $picture, $status_karyawan, $id);

            if (mysqli_stmt_execute($stmt)) {
                if ($uploadedPicture !== null && $old_picture !== '' && $old_picture !== $uploadedPicture) {
                    karyawan_picture_delete($old_picture);
                }
                echo "<script>alert('Data karyawan berhasil diperbarui.');</script>";
            } else {
                echo "<script>alert('ERROR: Tidak dapat memperbarui data. " . mysqli_error($link) . "');</script>";
            }

            mysqli_stmt_close($stmt);
        }
    }
}

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="">
  <meta name="author" content="">

  <title>PT. SUGIH BOGA NUSANTARA - Update Data Karyawan</title>

  <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
  <link rel="icon" href="../src/img/1.png" type="image/x-icon">
  <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">
  <link href="../src/vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>
<body id="page-top">
  <div id="wrapper">
    <?php include 'partial_sidebar.php';?>

    <div id="content-wrapper" class="d-flex flex-column">
      <div id="content">
        <?php include 'partial_topbar.php';?>

        <div class="container-fluid">
          <h1 class="h3 mb-2 text-gray-800">Data Karyawan</h1>
          <div class="card shadow mb-4">
            <div class="card-header py-3">
              <h6 class="m-0 font-weight-bold text-primary">Edit Data</h6>
            </div>
            <div class="card-body" >
              <div class="col-md-12">
                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>NIP / Nomor Induk Pegawai:</label>
                        <input type="text" name="nip" class="form-control" value="<?php echo escape_html($nip); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Nama:</label>
                        <input type="text" name="nama" class="form-control" value="<?php echo escape_html($nama); ?>" required>
                    </div>
                    <div class="form-row">
                      <div class="form-group col-md-6">
                        <label>UID:</label>
                        <input type="text" class="form-control" value="<?php echo escape_html($uid); ?>" readonly>
                        <small class="form-text text-muted">UID internal otomatis berubah jika UID fisik + token kartu diganti.</small>
                      </div>
                      <div class="form-group col-md-6">
                        <label>Email:</label>
                        <input type="email" name="mail" class="form-control" value="<?php echo escape_html($mail); ?>">
                      </div>
                    </div>
                    <div class="form-row">
                      <div class="form-group col-md-6">
                        <label>UID Fisik Kartu:</label>
                        <input type="text" name="uid_fisik" class="form-control" value="<?php echo escape_html($uid_fisik); ?>">
                      </div>
                      <div class="form-group col-md-6">
                        <label>Token Kartu:</label>
                        <input type="text" name="token_kartu" class="form-control" value="<?php echo escape_html($token_kartu); ?>">
                      </div>
                    </div>
                    <div class="form-row">
                      <div class="form-group col-md-6">
                        <label>Divisi:</label>
                        <select class="form-control" name="division" required>
                            <option value="">Pilih Divisi</option>
                            <?php render_options(get_division_options(), $division); ?>
                        </select>
                      </div>
                      <div class="form-group col-md-6">
                        <label>Jabatan:</label>
                        <select class="form-control" name="jabatan" required>
                            <option value="">Pilih Jabatan</option>
                            <?php render_options(get_jabatan_options(), $jabatan); ?>
                        </select>
                      </div>
                    </div>
                    <div class="form-group">
                        <label>Status Karyawan:</label>
                        <select class="form-control" name="status_karyawan">
                            <option value="AKTIF" <?php echo $status_karyawan === "AKTIF" ? "selected" : ""; ?>>Aktif</option>
                            <option value="NONAKTIF" <?php echo $status_karyawan === "NONAKTIF" ? "selected" : ""; ?>>Nonaktif</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Alamat:</label>
                        <textarea name="alamat" class="form-control"><?php echo escape_html($alamat); ?></textarea>
                    </div>
                    <div class="form-row">
                      <div class="form-group col-md-6">
                        <label>Tanggal Lahir:</label>
                        <input type="date" name="tanggal_lahir" class="form-control" value="<?php echo escape_html($tanggal_lahir); ?>">
                      </div>
                      <div class="form-group col-md-6">
                        <label>No HP:</label>
                        <input type="text" name="no_hp" class="form-control" value="<?php echo escape_html($no_hp); ?>">
                      </div>
                    </div>
                    <div class="form-group">
                        <label>Foto Karyawan:</label><br>
                        <?php $fotoKaryawan = karyawan_picture_url($picture); ?>
                        <?php if ($fotoKaryawan !== ""): ?>
                          <img src="<?php echo escape_html($fotoKaryawan); ?>" alt="Foto Karyawan" class="img-thumbnail mb-2" style="width: 160px; height: 240px; object-fit: cover;"><br>
                        <?php endif; ?>
                        <input type="file" name="picture_file" class="form-control-file" accept="image/*">
                        <small class="form-text text-muted">Kosongkan jika tidak ingin mengganti foto. Di HP biasanya muncul pilihan Kamera/Galeri/File. Foto otomatis dikompres sebelum upload.</small>
                    </div>
                    <hr>
                    <div class="row justify-content-end">
                    <input type="hidden" name="picture" value="<?php echo escape_html($picture); ?>">
                    <input type="hidden" name="id" value="<?php echo escape_html($id); ?>">
                    <input type="submit" class="btn btn-success" value="Perbarui Data">
                    <a href="data_karyawan-index.php" class="btn btn-primary">Batal</a>
                    </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
  <script src="../src/vendor/jquery-easing/jquery.easing.min.js"></script>
  <script src="../src/js/sb-admin-2.min.js"></script>
  <script src="karyawan_picture_compress.js"></script>
  <script src="../src/vendor/jquery/jquery.min.js"></script>
  <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>