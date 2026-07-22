<?php
session_start();
require_once dirname(__DIR__) . '/shared/card_security.php';

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Jika belum login, arahkan ke halaman login
    header("location: login.php");
    exit;
}

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Sisa kode data_absen-index.php Anda ...
?>
<?php
date_default_timezone_set('Asia/Jakarta');
// Include config file
require_once "config.php";
require_once "karyawan_options.php";
require_once "karyawan_picture_helper.php";

// Define variables and initialize with empty values
$created = "";
$nip = "";
$uid = "";
$uid_fisik = "";
$token_kartu = "";
$nama = "";
$no_hp = "";
$division = "";
$jabatan = "";
$mail = "";
$tanggal_lahir = null;
$alamat = "";
$picture = "";

$created_err = "";
$nip_err = "";
$uid_err = "";
$uid_fisik_err = "";
$token_kartu_err = "";
$nama_err = "";
$no_hp_err = "";
$division_err = "";
$jabatan_err = "";
$mail_err = "";
$tanggal_lahir_err = "";
$alamat_err = "";
$picture_err = "";


// Processing form data when form is submitted
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $invalid_id = (int) $_GET['id'];
    $invalid_sql = "SELECT id, uid, token_kartu FROM data_invalid WHERE id = ?";
    if ($invalid_stmt = mysqli_prepare($link, $invalid_sql)) {
        mysqli_stmt_bind_param($invalid_stmt, "i", $invalid_id);
        mysqli_stmt_execute($invalid_stmt);
        $invalid_result = mysqli_stmt_get_result($invalid_stmt);
        if ($invalid_row = mysqli_fetch_assoc($invalid_result)) {
            $uid_fisik = $invalid_row['uid'];
            $token_kartu = $invalid_row['token_kartu'];
        }
        mysqli_stmt_close($invalid_stmt);
    }
}

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $nip = strtoupper(trim($_POST["nip"] ?? ""));
	$created = date("Y-m-d H:i:s");
	$uid_fisik = card_normalize_value($_POST["uid_fisik"] ?? "");
	$token_kartu = card_normalize_value($_POST["token_kartu"] ?? "");
	$nama = trim($_POST["nama"]);
	$division = trim($_POST["division"]);
    $jabatan = trim($_POST["jabatan"] ?? "");
	$mail = trim($_POST["mail"]);
	$alamat = trim($_POST["alamat"]);
    $no_hp = trim($_POST["no_hp"] ?? "");
    $tanggal_lahir = trim($_POST["tanggal_lahir"] ?? "") ?: null;
	$picture = "";

    if (empty($nip)) {
        $nip_err = "NIP wajib diisi.";
    } else {
        $check_nip_sql = "SELECT id FROM data_karyawan WHERE nip = ? LIMIT 1";
        if ($check_nip_stmt = mysqli_prepare($link, $check_nip_sql)) {
            mysqli_stmt_bind_param($check_nip_stmt, "s", $nip);
            mysqli_stmt_execute($check_nip_stmt);
            mysqli_stmt_store_result($check_nip_stmt);
            if (mysqli_stmt_num_rows($check_nip_stmt) > 0) {
                $nip_err = "NIP ini sudah digunakan.";
            }
            mysqli_stmt_close($check_nip_stmt);
        }
    }

    if (empty($uid_fisik)) {
        $uid_fisik_err = "UID fisik wajib ada.";
    }

    if (empty($token_kartu)) {
        $token_kartu_err = "Token kartu wajib ada.";
    }

    if (empty($nama)) {
        $nama_err = "Nama wajib diisi.";
    }

    if (empty($division)) {
        $division_err = "Divisi wajib dipilih.";
    }

    if (empty($jabatan)) {
        $jabatan_err = "Jabatan wajib dipilih.";
    }

    if (empty($mail)) {
        $mail_err = "Email wajib diisi.";
    } elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $mail_err = "Format email tidak valid.";
    }

    if (empty($nip_err) && empty($uid_fisik_err) && empty($token_kartu_err) && empty($nama_err) && empty($division_err) && empty($jabatan_err) && empty($mail_err)) {
        $uid = card_build_internal_uid($uid_fisik, $token_kartu);
        $check_sql = "SELECT id FROM data_karyawan WHERE uid = ?";
        if ($check_stmt = mysqli_prepare($link, $check_sql)) {
            mysqli_stmt_bind_param($check_stmt, "s", $uid);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);

            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $uid_err = "Kombinasi UID fisik dan token kartu ini sudah terdaftar.";
            }

            mysqli_stmt_close($check_stmt);
        }
    }

    if (empty($uid_err) && empty($nip_err) && empty($uid_fisik_err) && empty($token_kartu_err) && empty($nama_err) && empty($division_err) && empty($jabatan_err) && empty($mail_err)) {
        $uploadedPicture = karyawan_picture_upload('picture_file', $picture_err);
        if ($picture_err === "" && $uploadedPicture !== null) {
            $picture = $uploadedPicture;
        }
    }

    if (empty($uid_err) && empty($nip_err) && empty($uid_fisik_err) && empty($token_kartu_err) && empty($nama_err) && empty($division_err) && empty($jabatan_err) && empty($mail_err) && empty($picture_err)) {
        mysqli_begin_transaction($link);

        try {
            $insert_sql = "INSERT INTO data_karyawan (created, nip, uid, uid_fisik, token_kartu, nama, no_hp, division, jabatan, mail, tanggal_lahir, alamat, picture) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($link, $insert_sql);

            if (!$insert_stmt) {
                throw new Exception(mysqli_error($link));
            }

            mysqli_stmt_bind_param($insert_stmt, "sssssssssssss", $created, $nip, $uid, $uid_fisik, $token_kartu, $nama, $no_hp, $division, $jabatan, $mail, $tanggal_lahir, $alamat, $picture);

            if (!mysqli_stmt_execute($insert_stmt)) {
                throw new Exception(mysqli_stmt_error($insert_stmt));
            }

            mysqli_stmt_close($insert_stmt);

            $delete_sql = "DELETE FROM data_invalid WHERE uid = ? AND token_kartu = ?";
            $delete_stmt = mysqli_prepare($link, $delete_sql);

            if (!$delete_stmt) {
                throw new Exception(mysqli_error($link));
            }

            mysqli_stmt_bind_param($delete_stmt, "ss", $uid_fisik, $token_kartu);

            if (!mysqli_stmt_execute($delete_stmt)) {
                throw new Exception(mysqli_stmt_error($delete_stmt));
            }

            mysqli_stmt_close($delete_stmt);

            mysqli_commit($link);

            echo '<script language="javascript" type="text/javascript"> 
						alert("Kartu '.$uid_fisik.' berhasil ditautkan atas nama '.$nama.'");
						window.location.replace("data_karyawan-index.php");
			  </script>';
            exit();
        } catch (Exception $e) {
            mysqli_rollback($link);
            echo '<div class="alert alert-danger">Gagal mendaftarkan kartu invalid: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

}
?>
<!DOCTYPE html>
<html lang="en">

<head>

  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="">
  <meta name="author" content="">

  <title>PT. SUGIH BOGA NUSANTARA - Dashboard</title>

  <!-- Custom fonts for this template-->
  <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

  <!-- Custom styles for this template-->
  <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">

  <!-- Custom styles for this page -->
  <link href="../src/vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
  
 
</head>

<body id="page-top">

  <!-- Page Wrapper -->
  <div id="wrapper">

    <!-- Sidebar -->
	<?php include 'partial_sidebar.php';?>
	<!-- End of Sidebar -->

    <!-- Content Wrapper -->
    <div id="content-wrapper" class="d-flex flex-column">

      <!-- Main Content -->
      <div id="content">

        <!-- Topbar -->
		<?php include 'partial_topbar.php';?>
		<!-- End of Topbar -->

        <!-- Begin Page Content -->
        <div class="container-fluid">

          <!-- Page Heading -->
          <h1 class="h3 mb-2 text-gray-800">Data Kartu Invalid</h1>
		  <div class="card shadow mb-4">
			<div class="card-header py-3">
              <h6 class="m-0 font-weight-bold text-primary">Daftarkan Kartu Invalid</h6>
            </div>
            <div class="card-body" >
				<div class="col-md-12">
				  <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
					  <div class="form-group">
						<label for="nip">NIP / Nomor Induk Pegawai</label>
						<input type="text" name="nip" class="form-control" value="<?php echo escape_html($nip); ?>" placeholder="Input NIP karyawan" required>
                        <span class="help-block"><?php echo escape_html($nip_err); ?></span>
					  </div>
					  <div class="form-group">
						<label for="nama">Nama</label>
						<input type="text" name="nama" class="form-control" value="<?php echo escape_html($nama); ?>" placeholder="Input nama karyawan" required>
                        <span class="help-block"><?php echo escape_html($nama_err); ?></span>
					  </div>
					  <div class="form-row">
						<div class="form-group col-md-6">
						  <label for="uid_fisik">UID Fisik</label>
                          <input type="text" name="uid_fisik" class="form-control <?php echo (!empty($uid_fisik_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($uid_fisik); ?>" readonly>
						  <span class="invalid-feedback"><?php echo escape_html($uid_fisik_err); ?></span>
						</div>
						<div class="form-group col-md-6">
						  <label for="token_kartu">Token Kartu</label>
						  <input type="text" name="token_kartu" class="form-control <?php echo (!empty($token_kartu_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($token_kartu); ?>" readonly>
						  <span class="invalid-feedback"><?php echo escape_html($token_kartu_err); ?></span>
						</div>
                      </div>
                      <div class="form-row">
                        <div class="form-group col-md-6">
						  <label for="mail">Email</label>
						  <input type="text" name="mail" class="form-control" value="<?php echo escape_html($mail); ?>" placeholder="Input email karyawan" required>
						  <span class="help-block"><?php echo escape_html($mail_err); ?></span>
						</div>
                        <div class="form-group col-md-6">
                          <label for="no_hp">No HP</label>
                          <input type="text" name="no_hp" class="form-control" value="<?php echo htmlspecialchars($no_hp); ?>" placeholder="Input nomor HP karyawan">
                        </div>
                      </div>
                      <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" name="tanggal_lahir" class="form-control" value="<?php echo htmlspecialchars($tanggal_lahir ?? ''); ?>">
                      </div>
					  <div class="form-row">
						<div class="form-group col-md-6">
						  <label for="division">Divisi</label>
						  <select class="form-control" name="division">
                            <option value="">Pilih Divisi</option>
                            <?php render_options(get_division_options(), $division); ?>
						  </select>
						  <span class="help-block"><?php echo escape_html($division_err); ?></span>
						</div>
						<div class="form-group col-md-6">
						  <label for="jabatan">Jabatan</label>
						  <select class="form-control" name="jabatan">
                            <option value="">Pilih Jabatan</option>
                            <?php render_options(get_jabatan_options(), $jabatan); ?>
						  </select>
						  <span class="help-block"><?php echo escape_html($jabatan_err); ?></span>
						</div>
					  </div>
					  <div class="form-group">
						<label for="alamat">Alamat</label>
						<textarea name="alamat" class="form-control" placeholder="Input alamat rumah karyawan"><?php echo escape_html($alamat); ?></textarea>
						<span class="help-block"><?php echo escape_html($alamat_err); ?></span>
					  </div>
                                          <div class="form-group">
                                                <label for="picture_file">Foto Karyawan</label>
                                                <input type="file" name="picture_file" class="form-control-file" accept="image/*">
                                                <small class="form-text text-muted">Di HP biasanya muncul pilihan Kamera/Galeri/File. Foto otomatis dikompres sebelum upload.</small>
                                                <span class="help-block text-danger"><?php echo escape_html($picture_err); ?></span>
                                          </div>
					  <hr>
					<div class="row justify-content-end">
						<input type="submit" class="btn btn-success" value="Tambah"> &nbsp
                        <a href="data_invalid-index.php" class="btn btn-primary">Batal</a>
					</div>  
				</form>
				  
				</div>
			</div>
			
		  </div>

        </div>
        <!-- /.container-fluid -->

      </div>
      <!-- End of Main Content -->

      <!-- Footer -->
      <footer class="sticky-footer bg-white">
        <div class="container my-auto">
          <div class="copyright text-center my-auto">
            <span>Copyright &copy; Team 7</span>
          </div>
        </div>
      </footer>
      <!-- End of Footer -->

    </div>
    <!-- End of Content Wrapper -->

  </div>
  <!-- End of Page Wrapper -->

  <!-- Scroll to Top Button-->
  <a class="scroll-to-top rounded" href="#page-top">
    <i class="fas fa-angle-up"></i>
  </a>

  <!-- Logout Modal-->
  <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
          <button class="close" type="button" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">×</span>
          </button>
        </div>
        <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
          <a class="btn btn-primary" href="login.php">Logout</a>
        </div>
      </div>
    </div>
  </div>


  <!-- Bootstrap core JavaScript-->
  <script src="../src/vendor/jquery/jquery.min.js"></script>
  <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

  <!-- Custom scripts for all pages-->
  <script src="../src/js/sb-admin-2.min.js"></script>
  <script src="karyawan_picture_compress.js"></script>


</body>

</html>
