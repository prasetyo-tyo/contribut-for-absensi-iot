<?php 
session_start();

// Periksa apakah pengguna sudah login, jika tidak, arahkan ke halaman login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

// Prepare a delete statement
$sql = "SELECT * FROM data_karyawan";

if($stmt = mysqli_prepare($link, $sql)){
    // Attempt to execute the prepared statement
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        $rowcount = mysqli_num_rows($result);
    } else {
        $rowcount = "null";
    }
}
$today = date("Y-m-d");

$sql = "SELECT COALESCE(data_absen.nip, data_absen.uid) AS uid, data_absen.tanggal, MAX(data_karyawan.nama) AS nama, MAX(data_karyawan.division) AS division,
		min(case when status='IN' then  waktu end) jam_masuk,
		max(CASE WHEN status='OUT' then waktu end) jam_keluar
	FROM data_absen, data_karyawan 
	WHERE (data_absen.nip = data_karyawan.nip OR (data_absen.nip IS NULL AND data_absen.uid=data_karyawan.uid))  AND tanggal='".$today."'
	GROUP BY COALESCE(data_absen.nip, data_absen.uid), data_absen.tanggal";
	
if($stmt = mysqli_prepare($link, $sql)){
	//mysqli_stmt_bind_param($stmt, "i", $today );
	// Attempt to execute the prepared statement
	if(mysqli_stmt_execute($stmt)){
		$result = mysqli_stmt_get_result($stmt);
		$absensi =mysqli_num_rows($result);
	}else{
		$absensi = "null";
	}
}
	
// Hitung total karyawan
$sql = "SELECT COUNT(*) as total_karyawan FROM data_karyawan";
if($stmt = mysqli_prepare($link, $sql)){
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        $data = mysqli_fetch_assoc($result);
        $total_karyawan = $data['total_karyawan'];
    } else {
        $total_karyawan = 0;
    }
}

// Hitung jumlah hari dalam bulan ini
$jumlah_hari = (int) date('t');

// Hitung total kehadiran per bulan.
// Satu karyawan dalam satu tanggal dihitung satu kali, walaupun punya row IN dan OUT.
$sql = "SELECT COUNT(*) AS total_kehadiran
        FROM (
            SELECT uid, tanggal
            FROM data_absen
            WHERE MONTH(tanggal) = MONTH(CURRENT_DATE())
              AND YEAR(tanggal) = YEAR(CURRENT_DATE())
            GROUP BY uid, tanggal
        ) AS hadir_harian";
if($stmt = mysqli_prepare($link, $sql)){
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        $data = mysqli_fetch_assoc($result);
        $total_kehadiran = $data['total_kehadiran'];
    } else {
        $total_kehadiran = 0;
    }
}

// Hitung persentase kehadiran dan batasi maksimal 100%.
$target_kehadiran = $total_karyawan * $jumlah_hari;
$persentase_kehadiran = ($target_kehadiran > 0) ? ($total_kehadiran / $target_kehadiran) * 100 : 0;
$persentase_kehadiran = min($persentase_kehadiran, 100);

// Tambahkan kode berikut untuk mengambil data karyawan yang berulang tahun hari ini
$today = date("m-d");
$current_year = date("Y");
$birthday_employees = [];
$sql = "SELECT nama, tanggal_lahir, no_hp FROM data_karyawan WHERE DATE_FORMAT(tanggal_lahir, '%m-%d') = ?";
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "s", $today);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $birth_year = date("Y", strtotime($row['tanggal_lahir']));
            $age = $current_year - $birth_year;
            $birthday_employees[] = [
                'nama' => $row['nama'],
                'tanggal_lahir' => $row['tanggal_lahir'],
                'no_hp' => $row['no_hp'],
                'umur' => $age
            ];
        }
    }
}


$sql = "SELECT COUNT(DISTINCT uid) AS total_invalid FROM data_invalid";
if($stmt = mysqli_prepare($link, $sql)){
//mysqli_stmt_bind_param($stmt, "i", $today );
// Attempt to execute the prepared statement
	if(mysqli_stmt_execute($stmt)){
		$result = mysqli_stmt_get_result($stmt);
		$data = mysqli_fetch_assoc($result);
		$invalid = $data['total_invalid'] ?? 0;
	}else{
		$invalid = "null";
	}
}
	
// Close statement
mysqli_stmt_close($stmt);

// Close connection
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

  <title>PT. SUGIH BOGA NUSANTARA - Dashboard</title>

  <!-- Custom fonts for this template-->
  <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
  <link rel="icon" href="../src/img/1.png" type="image/x-icon">
  <!-- Custom styles for this template-->
  <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">

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

          <!-- Content Row -->
          <div class="row">

            <!-- Total Karyawan Card -->
            <div class="col-xl-3 col-md-6 mb-4">
              <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                  <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                      <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Karyawan</div>
                      <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $rowcount; ?></div>
                    </div>
                    <div class="col-auto">
                      <i class="fas fa-users fa-2x text-gray-300"></i>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Absensi Hari Ini Card -->
            <div class="col-xl-3 col-md-6 mb-4">
              <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                  <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                      <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Absensi Hari ini</div>
                      <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $absensi; ?></div>
                    </div>
                    <div class="col-auto">
                      <i class="fas fa-user-clock fa-2x text-gray-300"></i>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Persentase Kehadiran Card -->
            <div class="col-xl-3 col-md-6 mb-4">
              <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                  <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                      <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Persentase Kehadiran Bulanan</div>
                      <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($persentase_kehadiran, 2) . '%'; ?></div>
                    </div>
                    <div class="col-auto">
                      <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Kartu Invalid Card -->
            <div class="col-xl-3 col-md-6 mb-4">
              <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                  <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                      <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Kartu Invalid</div>
                      <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $invalid; ?></div>
                    </div>
                    <div class="col-auto">
                      <i class="fas fa-id-card fa-2x text-gray-300"></i>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Birthday Cards -->
          <div class="row">
            <div class="col-xl-12 col-lg-12">
              <div class="card shadow mb-4">
                <div class="card-header py-3">
                  <h6 class="m-0 font-weight-bold text-primary">Ucapan Selamat Ulang Tahun</h6>
                </div>
                <div class="card-body">
                  <?php if (empty($birthday_employees)): ?>
                    <p class="card-text">Tidak ada yang berulang tahun hari ini.</p>
                  <?php else: ?>
                    <div class="row">
                      <?php foreach ($birthday_employees as $employee): ?>
                        <div class="col-md-6 mb-4">
                          <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                              <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                  <div class="h5 mb-0 font-weight-bold text-gray-800">Selamat Ulang Tahun, <?php echo htmlspecialchars($employee['nama']); ?>!</div>
                                  <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Tanggal Lahir: <?php echo htmlspecialchars(date('d-m-Y', strtotime($employee['tanggal_lahir']))); ?>
                                  </div>
                                  <div class="mb-0 text-gray-800">
                                    Umur: <?php echo htmlspecialchars($employee['umur']); ?> tahun
                                  </div>
                                  <div class="mb-0 text-gray-800">
                                    No. HP: <?php echo htmlspecialchars($employee['no_hp']); ?>
                                  </div>
                                </div>
                                <div class="col-auto">
                                  <i class="fas fa-birthday-cake fa-2x text-gray-300"></i>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Content Row -->
          <!-- <div class="row">

             Area Chart 
            <div class="col-xl-8 col-lg-7">
              <div class="card shadow mb-4">
                
              </div>
            </div>
          </div>

          Content Row 
          <div class="row">

             Content Column -->
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
                <a class="btn btn-primary" href="logout.php">Logout</a>
              </div>
            </div>
          </div>
        </div>

        <!-- Bootstrap core JavaScript-->
        <script src="../src/vendor/jquery/jquery.min.js"></script>
        <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

        <!-- Core plugin JavaScript-->
        <script src="../src/vendor/jquery-easing/jquery.easing.min.js"></script>

        <!-- Custom scripts for all pages-->
        <script src="../src/js/sb-admin-2.min.js"></script>

        <!-- Page level plugins -->
        <script src="../src/vendor/chart.js/Chart.min.js"></script>

        <!-- Page level custom scripts -->
        <script src="../src/js/demo/chart-area-demo.js"></script>
        <script src="../src/js/demo/chart-pie-demo.js"></script>

      </body>

      </html>
