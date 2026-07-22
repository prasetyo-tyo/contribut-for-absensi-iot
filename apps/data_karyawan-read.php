<?php
session_start();

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

function display_value($value)
{
    $value = trim((string) $value);

    return $value !== '' ? $value : '-';
}

function format_display_date($value)
{
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

require_once "karyawan_picture_helper.php";

// Sisa kode data_absen-index.php Anda ...
?>
<?php
// Check existence of id parameter before processing further
if(isset($_GET["id"]) && !empty(trim($_GET["id"]))){
    // Include config file
    require_once "config.php";

    // Prepare a select statement
    $sql = "SELECT data_karyawan.*,
                   (SELECT o.nama_outlet
                    FROM data_absen a
                    LEFT JOIN data_outlet o ON a.outlet_id = o.id
                    WHERE a.status = 'IN'
                      AND (a.nip = data_karyawan.nip OR (a.nip IS NULL AND a.uid = data_karyawan.uid))
                    ORDER BY a.tanggal DESC, a.waktu DESC, a.id DESC
                    LIMIT 1) AS lokasi_masuk_terakhir
            FROM data_karyawan
            WHERE id = ?";

    if($stmt = mysqli_prepare($link, $sql)){
        // Bind variables to the prepared statement as parameters
        mysqli_stmt_bind_param($stmt, "i", $param_id);

        // Set parameters
        $param_id = trim($_GET["id"]);

        // Attempt to execute the prepared statement
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);

            if(mysqli_num_rows($result) == 1){
                /* Fetch result row as an associative array. Since the result set
                contains only one row, we don't need to use while loop */
                $row = mysqli_fetch_array($result, MYSQLI_ASSOC);

                /* Retrieve individual field value
                {INDIVIDUAL_FIELDS}
                $name = $row["name"];
                $address = $row["address"];
                $salary = $row["salary"];
                 */
            } else{
                // URL doesn't contain valid id parameter. Redirect to error page
                header("location: error.php");
                exit();
            }

        } else{
            echo "Oops! Something went wrong. Please try again later.";
        }
    }

    // Close statement
    mysqli_stmt_close($stmt);

    // Close connection
    mysqli_close($link);
} else{
    // URL doesn't contain id parameter. Redirect to error page
    header("location: error.php");
    exit();
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

  <title>PT. SUGIH BOGA NUSANTARA - Data Karyawan</title>

  <!-- Custom fonts for this template-->
  <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
  <link rel="icon" href="../src/img/1.png" type="image/x-icon">
  <!-- Custom styles for this template-->
  <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">

  <!-- Custom styles for this page -->
  <link href="../src/vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

  <style>
    .employee-profile-wrapper {
      max-width: 1180px;
      margin: 0 auto 2rem;
    }
    .employee-hero-card,
    .employee-info-card {
      border: 0;
      border-radius: 14px;
      box-shadow: 0 10px 30px rgba(31, 45, 61, 0.07);
    }
    .employee-hero-card {
      overflow: hidden;
    }
    .employee-avatar-wrap {
      width: 118px;
      height: 177px;
      border-radius: 12px;
      overflow: hidden;
      background: #f4f6f8;
      border: 1px solid #e7ecf2;
      flex: 0 0 auto;
    }
    .employee-avatar-wrap img,
    .employee-avatar-empty {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .employee-avatar-empty {
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      color: #7b8794;
      font-size: 12px;
      padding: 10px;
    }
    .employee-name {
      color: #1f2d3d;
      font-size: 1.35rem;
      font-weight: 800;
      margin-bottom: 0.25rem;
    }
    .employee-subtitle {
      color: #6b778c;
      font-size: 0.92rem;
      margin-bottom: 0.5rem;
    }
    .employee-pill {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 0.32rem 0.7rem;
      background: #eef6ff;
      color: #1769aa;
      font-weight: 700;
      font-size: 0.78rem;
    }
    .employee-hero-divider {
      border-left: 1px solid #edf0f4;
    }
    .detail-item {
      margin-bottom: 1rem;
    }
    .detail-label {
      display: block;
      color: #8792a2;
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      margin-bottom: 0.18rem;
      text-transform: uppercase;
    }
    .detail-value {
      color: #243447;
      font-size: 0.95rem;
      font-weight: 800;
      overflow-wrap: anywhere;
    }
    .uid-toggle-btn {
      border: 0;
      background: #f6f8fb;
      color: #5f6b7a;
      border-radius: 999px;
      width: 32px;
      height: 32px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-left: 8px;
      cursor: pointer;
    }
    .uid-toggle-btn:hover {
      background: #eef6ff;
      color: #1769aa;
    }
    .employee-info-card .card-header {
      background: #fff;
      border-bottom: 1px solid #edf0f4;
      padding: 1rem 1.25rem;
    }
    .employee-info-card .card-header h6 {
      color: #3f4b5f;
      font-size: 0.92rem;
      font-weight: 800;
      margin: 0;
    }
    @media (max-width: 767.98px) {
      .employee-hero-divider {
        border-left: 0;
        border-top: 1px solid #edf0f4;
        margin-top: 1rem;
        padding-top: 1rem;
      }
      .employee-avatar-wrap {
        width: 96px;
        height: 144px;
      }
    }
  </style>
 
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
          <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <div>
              <h1 class="h3 mb-1 text-gray-800">Detail Karyawan</h1>
              <p class="mb-0 text-muted">Informasi profil dan data kerja karyawan.</p>
            </div>
            <div class="mt-3 mt-sm-0">
              <a href="data_karyawan-update.php?id=<?php echo urlencode((string) $row['id']); ?>" class="btn btn-success btn-sm shadow-sm mr-2">
                <i class="fas fa-edit fa-sm mr-1"></i> Edit Data
              </a>
              <a href="data_karyawan-index.php" class="btn btn-outline-primary btn-sm shadow-sm">
                <i class="fas fa-arrow-left fa-sm mr-1"></i> Kembali
              </a>
            </div>
          </div>

          <div class="employee-profile-wrapper">
            <div class="card employee-hero-card mb-4">
              <div class="card-body p-4">
                <div class="row align-items-center">
                  <div class="col-lg-5 d-flex align-items-center mb-4 mb-lg-0">
                    <?php $fotoKaryawan = karyawan_picture_url($row["picture"] ?? ""); ?>
                    <div class="employee-avatar-wrap mr-4">
                      <?php if ($fotoKaryawan !== ""): ?>
                        <img src="<?php echo escape_html($fotoKaryawan); ?>" alt="Foto <?php echo escape_html($row['nama'] ?? 'Karyawan'); ?>">
                      <?php else: ?>
                        <div class="employee-avatar-empty">Belum ada foto</div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <div class="employee-name"><?php echo escape_html(display_value($row["nama"] ?? "")); ?></div>
                      <div class="employee-subtitle">
                        <span class="text-primary font-weight-bold"><?php echo escape_html(display_value($row["jabatan"] ?? "")); ?></span>
                        <span class="text-muted"> / <?php echo escape_html(display_value($row["division"] ?? "")); ?></span>
                      </div>
                      <span class="employee-pill">NIP: <?php echo escape_html(display_value($row["nip"] ?? "")); ?></span>
                      <span class="employee-pill <?php echo (($row["status_karyawan"] ?? "AKTIF") === "AKTIF") ? "text-success" : "text-danger"; ?>">Status: <?php echo escape_html((($row["status_karyawan"] ?? "AKTIF") === "AKTIF") ? "Aktif" : "Nonaktif"); ?></span>
                    </div>
                  </div>
                  <div class="col-lg-7 employee-hero-divider pl-lg-4">
                    <div class="row">
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">Lokasi Masuk Terakhir</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["lokasi_masuk_terakhir"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">No HP</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["no_hp"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item mb-md-0">
                        <span class="detail-label">Email</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["mail"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item mb-0">
                        <span class="detail-label">Tanggal Lahir</span>
                        <span class="detail-value"><?php echo escape_html(format_display_date($row["tanggal_lahir"] ?? "")); ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-xl-6 mb-4">
                <div class="card employee-info-card h-100">
                  <div class="card-header">
                    <h6>Informasi Pekerjaan</h6>
                  </div>
                  <div class="card-body">
                    <div class="row">
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">NIP</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["nip"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">Nama</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["nama"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">Divisi</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["division"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">Jabatan</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["jabatan"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">Status Karyawan</span>
                        <span class="detail-value"><?php echo escape_html((($row["status_karyawan"] ?? "AKTIF") === "AKTIF") ? "Aktif" : "Nonaktif"); ?></span>
                      </div>
                      <div class="col-12 detail-item mb-0">
                        <span class="detail-label">Lokasi Masuk Terakhir</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["lokasi_masuk_terakhir"] ?? "")); ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-xl-6 mb-4">
                <div class="card employee-info-card h-100">
                  <div class="card-header">
                    <h6>Informasi Kontak</h6>
                  </div>
                  <div class="card-body">
                    <div class="row">
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">No HP</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["no_hp"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item">
                        <span class="detail-label">Email</span>
                        <span class="detail-value"><?php echo escape_html(display_value($row["mail"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item mb-md-0">
                        <span class="detail-label">Tanggal Lahir</span>
                        <span class="detail-value"><?php echo escape_html(format_display_date($row["tanggal_lahir"] ?? "")); ?></span>
                      </div>
                      <div class="col-md-6 detail-item mb-0">
                        <span class="detail-label">UID Internal</span>
                        <span class="detail-value" id="uidInternalValue" data-real-value="<?php echo escape_html(display_value($row["uid"] ?? "")); ?>">********</span>
                        <button type="button" class="uid-toggle-btn" id="toggleUidInternal" aria-label="Tampilkan UID Internal">
                          <i class="fas fa-eye"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 mb-4">
                <div class="card employee-info-card">
                  <div class="card-header">
                    <h6>Alamat</h6>
                  </div>
                  <div class="card-body">
                    <div class="detail-item mb-0">
                      <span class="detail-label">Alamat Rumah</span>
                      <span class="detail-value"><?php echo nl2br(escape_html(display_value($row["alamat"] ?? ""))); ?></span>
                    </div>
                  </div>
                </div>
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
          <a class="btn btn-primary" href="login..php">Logout</a>
        </div>
      </div>
    </div>
  </div>


  <!-- Bootstrap core JavaScript-->
  <script src="../src/vendor/jquery/jquery.min.js"></script>
  <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

  <!-- Custom scripts for all pages-->
  <script src="../src/js/sb-admin-2.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var toggleButton = document.getElementById('toggleUidInternal');
      var uidValue = document.getElementById('uidInternalValue');
      if (!toggleButton || !uidValue) return;

      toggleButton.addEventListener('click', function () {
        var isVisible = toggleButton.getAttribute('data-visible') === '1';
        var icon = toggleButton.querySelector('i');
        if (isVisible) {
          uidValue.textContent = '********';
          toggleButton.setAttribute('data-visible', '0');
          toggleButton.setAttribute('aria-label', 'Tampilkan UID Internal');
          if (icon) icon.className = 'fas fa-eye';
        } else {
          uidValue.textContent = uidValue.getAttribute('data-real-value') || '-';
          toggleButton.setAttribute('data-visible', '1');
          toggleButton.setAttribute('aria-label', 'Sembunyikan UID Internal');
          if (icon) icon.className = 'fas fa-eye-slash';
        }
      });
    });
  </script>


</body>

</html>
