<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Mendapatkan bulan dan tahun dari parameter GET
if (isset($_GET['set_bulan']) && !empty($_GET['set_bulan'])) {
    $parts = explode('-', (string) $_GET['set_bulan']);
    $month = $parts[0] ?? '';
    $year = $parts[1] ?? '';
    
    // Validasi bulan dan tahun
    if (is_numeric($month) && is_numeric($year) && $month >= 1 && $month <= 12) {
        $selectedMonth = sprintf('%02d', $month);
        $selectedYear = $year;
    } else {
        $selectedMonth = date('m');
        $selectedYear = date('Y');
    }
} else {
    $selectedMonth = date('m');
    $selectedYear = date('Y');
}

// Gunakan rentang bulan kalender biasa
$startDate = $selectedYear . '-' . $selectedMonth . '-01';
$endDate = date('Y-m-t', strtotime($startDate));

// Mendapatkan ID dari parameter id
$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;

// Pagination untuk data absen
if (isset($_GET['pageno']) && ctype_digit((string) $_GET['pageno']) && (int) $_GET['pageno'] > 0) {
    $pageno = (int) $_GET['pageno'];
} else {
    $pageno = 1;
}
$no_of_records_per_page = 10;
$offset = ($pageno - 1) * $no_of_records_per_page;

$karyawanRow = null;
$absenResult = false;
$total_rows = 0;

// Query untuk mendapatkan data karyawan berdasarkan ID
$karyawanSql = "SELECT * FROM data_karyawan WHERE id = ? LIMIT 1";
if ($id > 0 && ($karyawanStmt = mysqli_prepare($link, $karyawanSql))) {
    mysqli_stmt_bind_param($karyawanStmt, "i", $id);
    mysqli_stmt_execute($karyawanStmt);
    $karyawanResult = mysqli_stmt_get_result($karyawanStmt);
    if ($karyawanResult) {
        $karyawanRow = mysqli_fetch_assoc($karyawanResult);
    }
    mysqli_stmt_close($karyawanStmt);
}

// Query untuk mendapatkan data absen berdasarkan ID dari karyawan
if ($id > 0 && $karyawanRow) {
    $absenSql = "SELECT 
                    a.tanggal,
                    COALESCE(MAX(CASE WHEN a.status = 'IN' THEN outlet_masuk.nama_outlet END), '-') as outlet_masuk_nama,
                    COALESCE(MAX(CASE WHEN a.status = 'OUT' THEN outlet_keluar.nama_outlet END), '-') as outlet_keluar_nama,
                    MAX(CASE WHEN a.status = 'IN' THEN a.waktu END) as jam_masuk,
                    MAX(CASE WHEN a.status = 'OUT' THEN a.waktu END) as jam_keluar,
                    MAX(a.keterangan) as keterangan
                 FROM data_absen a
                 INNER JOIN data_karyawan k ON (a.nip = k.nip OR (a.nip IS NULL AND a.uid = k.uid)) 
                 LEFT JOIN data_outlet outlet_masuk ON a.status = 'IN' AND a.outlet_id = outlet_masuk.id
                 LEFT JOIN data_outlet outlet_keluar ON a.status = 'OUT' AND a.outlet_id = outlet_keluar.id
                 WHERE k.id = ?
                 AND a.tanggal BETWEEN ? AND ?
                 GROUP BY a.tanggal
                 ORDER BY a.tanggal 
                 LIMIT ?, ?";

    if ($absenStmt = mysqli_prepare($link, $absenSql)) {
        mysqli_stmt_bind_param($absenStmt, "issii", $id, $startDate, $endDate, $offset, $no_of_records_per_page);
        mysqli_stmt_execute($absenStmt);
        $absenResult = mysqli_stmt_get_result($absenStmt);
        mysqli_stmt_close($absenStmt);
    }

    if (!$absenResult) {
        echo "Error: " . escape_html(mysqli_error($link));
    }
} else {
    echo "ID tidak ditemukan.";
}

// Perbaiki query untuk menghitung total halaman
$total_pages_sql = "SELECT COUNT(DISTINCT a.tanggal) 
                    FROM data_absen a
                    INNER JOIN data_karyawan k ON (a.nip = k.nip OR (a.nip IS NULL AND a.uid = k.uid)) 
                    WHERE k.id = ? AND a.tanggal BETWEEN ? AND ?";
if ($id > 0 && $karyawanRow && ($totalStmt = mysqli_prepare($link, $total_pages_sql))) {
    mysqli_stmt_bind_param($totalStmt, "iss", $id, $startDate, $endDate);
    mysqli_stmt_execute($totalStmt);
    $result = mysqli_stmt_get_result($totalStmt);
    if ($result && ($row = mysqli_fetch_row($result))) {
        $total_rows = (int) $row[0];
    }
    mysqli_stmt_close($totalStmt);
}
$total_pages = max(1, ceil($total_rows / $no_of_records_per_page));

// Pastikan $pageno tidak melebihi $total_pages
$pageno = min($pageno, $total_pages);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">
    <title>Sistem Absensi</title>
    <link rel="icon" href="../src/img/1.png" type="image/x-icon">
    <!-- Custom fonts for this template-->
    <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <!-- Custom styles for this template-->
    <!-- Custom styles for this page -->
    <link href="../src/vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.2.0/css/datepicker.min.css" rel="stylesheet">
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
                    <h1 class="h3 mb-2 text-gray-800">Rekap Absensi</h1>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Rekap Absensi Bulanan</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 col-sm-12 mb-4">
                                    <div class="row">
                                        <img src="../src/img/user.png" class="rounded mx-auto d-block" alt="Photo karyawan" style="width:50%; float:left;">
                                    </div>
                                    <div class="row" style="padding:20px 0px 0 0px;">
                                        <div class="container">
                                            <table class="table table-borderless">
                                                <tbody>
                                                    <tr>
                                                        <td style="width: 30%; padding:5px">Nama</td>
                                                        <td style="width: 5%; padding:5px">:</td>
                                                        <td style="width: 65%; padding:5px"><b><?php echo escape_html($karyawanRow['nama'] ?? ''); ?></b></td>
                                                    </tr>
                                                    <tr>
                                                    <tr>
                                                        <td style="width: 30%; padding:5px">Divisi</td>
                                                        <td style="width: 5%; padding:5px">:</td>
                                                        <td style="width: 65%; padding:5px"><b><?php echo escape_html($karyawanRow["division"] ?? ""); ?></b></td>
                                                    </tr>
                                                    <tr>
                                                        <td style="width: 30%; padding:5px">Jabatan</td>
                                                        <td style="width: 5%; padding:5px">:</td>
                                                        <td style="width: 65%; padding:5px"><b><?php echo escape_html($karyawanRow["jabatan"] ?? ""); ?></b></td>
                                                    </tr>
                                                        <td style="width: 30%; padding:5px">No HP</td>
                                                        <td style="width: 5%; padding:5px">:</td>
                                                        <td style="width: 65%; padding:5px"><b><?php echo escape_html($karyawanRow['no_hp'] ?? ''); ?></b></td>
                                                    </tr>
                                                    <tr>
                                                        <td style="width: 30%; padding:5px">Email</td>
                                                        <td style="width: 5%; padding:5px">:</td>
                                                        <td style="width: 65%; padding:5px"><b><?php echo escape_html($karyawanRow['mail'] ?? ''); ?></b></td>
                                                    </tr>
                                                    <tr>
                                                        <td style="width: 30%; padding:5px">Alamat</td>
                                                        <td style="width: 5%; padding:5px">:</td>
                                                        <td style="width: 65%; padding:5px"><b><?php echo escape_html($karyawanRow['alamat'] ?? ''); ?></b></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <hr>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-8 col-sm-12">
                                    <div class="row">
                                        <div class="col-md-4 col-sm-10">
                                            <div class="d-flex mb-3">
                                                <form action="rekap_bulanan-view.php" method="get" id="monthForm">
                                                    <div class="input-group">
                                                        <input type="hidden" name="id" value="<?php echo escape_html($id); ?>" />
                                                        <input type="text" 
                                                               class="form-control" 
                                                               name="set_bulan" 
                                                               id="datepicker" 
                                                               value="<?php echo escape_html($selectedMonth . '-' . $selectedYear); ?>" 
                                                               readonly />
                                                        <div class="input-group-append">
                                                            <button type="submit" class="btn btn-outline-secondary">Set Bulan</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                        <!--<div class="col-md-4 col-sm-10">
                                             <div class="d-flex mb-3 justify-content-end">
                                                <form action="rekap_bulanan-view.php" method="get">
                                                    <input type="hidden" class="form-control" name="id" value="<#php echo $id; ?>" />
                                                    <input type="text" class="form-control" placeholder="Pencarian" name="search">
                                                </form>
                                            </div> 
                                        </div> -->
                                        <div class="col-md-4 col-sm-10">
                                            <div class="d-flex mb-3">
                                                <a href="rekap_bulanan-cetak.php?id=<?php echo urlencode((string) $id); ?>&set_bulan=<?php echo urlencode($selectedMonth . '-' . $selectedYear); ?>" class="btn btn-secondary pull-right" target="_blank">Cetak Data</a> &nbsp;
                                                <a href="rekap_absen_bulanan-index.php" class="btn btn-info pull-right">Kembali</a>
                                            </div>
                                        </div>
                                    </div>
                                    <br>
                                    <div class="table-responsive">
                                        <?php if ($total_rows > 0): ?>
                                            <table class='table table-bordered table-striped'>
                                                <thead>
                                                    <tr>
                                                        <th>Tanggal</th>
                                                        <th>Lokasi Masuk</th>
                                                        <th>Jam Masuk</th>
                                                        <th>Lokasi Keluar</th>
                                                        <th>Jam Keluar</th>
                                                        <th>Keterangan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    while($row = mysqli_fetch_array($absenResult)) {
                                                        echo "<tr>";
                                                        echo "<td>" . escape_html($row['tanggal']) . "</td>";
                                                        echo "<td>" . escape_html($row['outlet_masuk_nama']) . "</td>";
                                                        echo "<td>" . escape_html($row['jam_masuk']) . "</td>";
                                                        echo "<td>" . escape_html($row['outlet_keluar_nama']) . "</td>";
                                                        echo "<td>" . escape_html($row['jam_keluar']) . "</td>";
                                                        echo "<td>" . escape_html($row['keterangan']) . "</td>";
                                                        echo "</tr>";
                                                    }
                                                    ?>
                                                </tbody>
                                            </table>
                                            <nav aria-label="Page navigation">
                                                <ul class="pagination">
                                                    <li class="page-item">
                                                        <a class="page-link" href="?id=<?php echo urlencode((string) $id); ?>&set_bulan=<?php echo urlencode(date('m-Y', strtotime("$selectedYear-$selectedMonth-01"))); ?>&pageno=1">First</a>
                                                    </li>
                                                    <li class="page-item">
                                                        <a class="page-link" href="?id=<?php echo urlencode((string) $id); ?>&set_bulan=<?php echo urlencode(date('m-Y', strtotime("$selectedYear-$selectedMonth-01"))); ?>&pageno=<?php echo max(1, $pageno - 1); ?>">Prev</a>
                                                    </li>
                                                    <li class="page-item">
                                                        <a class="page-link" href="?id=<?php echo urlencode((string) $id); ?>&set_bulan=<?php echo urlencode(date('m-Y', strtotime("$selectedYear-$selectedMonth-01"))); ?>&pageno=<?php echo min($total_pages, $pageno + 1); ?>">Next</a>
                                                    </li>
                                                    <li class="page-item">
                                                        <a class="page-link" href="?id=<?php echo urlencode((string) $id); ?>&set_bulan=<?php echo urlencode(date('m-Y', strtotime("$selectedYear-$selectedMonth-01"))); ?>&pageno=<?php echo $total_pages; ?>">Last</a>
                                                    </li>
                                                </ul>
                                            </nav>
                                            <p>Halaman <?php echo $pageno; ?> dari <?php echo $total_pages; ?></p>
                                        <?php else: ?>
                                            <p class="text-center">Tidak ada catatan yang ditemukan.</p>
                                        <?php endif; ?>
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
  <script src="../src/vendor/datatables/jquery.dataTables.min.js"></script>
  <script src="../src/vendor/datatables/dataTables.bootstrap4.min.js"></script>
  <script src="../src/vendor/chart.js/Chart.min.js"></script>

  <!-- Page level custom scripts -->
  <script src="../src/js/demo/chart-area-demo.js"></script>
  <script src="../src/js/demo/chart-pie-demo.js"></script>

  <!-- Datepicker script -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.2.0/js/bootstrap-datepicker.min.js"></script>
  <script>
    $(document).ready(function(){
        $("#datepicker").datepicker({
            format: "mm-yyyy",
            startView: "months", 
            minViewMode: "months",
            autoclose: true
        });

        $("#datepicker").on('changeDate', function(e) {
            // Don't submit form automatically
        });
    });
  </script>
</body>
</html>

<?php
// Close connection
mysqli_close($link);
?>
