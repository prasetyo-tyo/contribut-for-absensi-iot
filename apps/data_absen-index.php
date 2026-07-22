<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Jika belum login, arahkan ke halaman login
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Pagination
if (isset($_GET['pageno']) && ctype_digit((string) $_GET['pageno']) && (int) $_GET['pageno'] > 0) {
    $pageno = (int) $_GET['pageno'];
} else {
    $pageno = 1;
}
$no_of_records_per_page = 10;
$offset = ($pageno-1) * $no_of_records_per_page;
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$selected_date = isset($_GET['set_tanggal']) ? trim((string) $_GET['set_tanggal']) : '';
$whereClauses = [];
$bindTypes = '';
$bindValues = [];

if ($selected_date !== '') {
    $whereClauses[] = "data_absen.tanggal = ?";
    $bindTypes .= 's';
    $bindValues[] = $selected_date;
}

if ($search !== '') {
    $whereClauses[] = "CONCAT(data_absen.tanggal, data_karyawan.nama, COALESCE(outlet_masuk.nama_outlet, ''), COALESCE(outlet_keluar.nama_outlet, '')) LIKE ?";
    $bindTypes .= 's';
    $bindValues[] = '%' . $search . '%';
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = ' WHERE ' . implode(' AND ', $whereClauses);
}

$total_pages_sql = "SELECT COUNT(*) AS count FROM (
                        SELECT COALESCE(data_absen.nip, data_absen.uid) AS uid, data_absen.tanggal
                        FROM data_absen
                        JOIN data_karyawan ON (data_absen.nip = data_karyawan.nip OR (data_absen.nip IS NULL AND data_absen.uid = data_karyawan.uid))
                        LEFT JOIN data_outlet outlet_masuk ON data_absen.status = 'IN' AND data_absen.outlet_id = outlet_masuk.id
                        LEFT JOIN data_outlet outlet_keluar ON data_absen.status = 'OUT' AND data_absen.outlet_id = outlet_keluar.id
                        $whereSql
                        GROUP BY COALESCE(data_absen.nip, data_absen.uid), data_absen.tanggal
                    ) AS grouped_absen";
$total_rows = 0;
if ($countStmt = mysqli_prepare($link, $total_pages_sql)) {
    if ($bindValues) {
        mysqli_stmt_bind_param($countStmt, $bindTypes, ...$bindValues);
    }
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    if ($countResult && ($countRow = mysqli_fetch_assoc($countResult))) {
        $total_rows = (int) $countRow['count'];
    }
    mysqli_stmt_close($countStmt);
}
$total_pages = ceil($total_rows / $no_of_records_per_page);

// Column sorting on column name
$orderBy = array('tanggal', 'uid', 'nama'); 
$orderSqlMap = array(
    'tanggal' => 'data_absen.tanggal',
    'uid' => 'COALESCE(data_absen.nip, data_absen.uid)',
    'nama' => 'MAX(data_karyawan.nama)',
);
$order = 'tanggal';
if (isset($_GET['order']) && in_array($_GET['order'], $orderBy)) {
    $order = $_GET['order'];
}
$orderSql = $orderSqlMap[$order];

// Column sort order
$sortBy = array('asc', 'desc'); 
$sort = 'desc';
if (isset($_GET['sort']) && in_array($_GET['sort'], $sortBy)) {
    if($_GET['sort'] == 'asc') {
        $sort = 'desc';
    } else {
        $sort = 'asc';
    }
}

// Query utama
$sql = "SELECT COALESCE(data_absen.nip, data_absen.uid) AS uid, data_absen.tanggal, MAX(data_karyawan.nama) AS nama, MAX(data_karyawan.division) AS division,
           COALESCE(MAX(CASE WHEN data_absen.status='IN' THEN outlet_masuk.nama_outlet END), '-') AS outlet_masuk_nama,
           COALESCE(MAX(CASE WHEN data_absen.status='OUT' THEN outlet_keluar.nama_outlet END), '-') AS outlet_keluar_nama,
           MAX(CASE WHEN data_absen.status='IN' THEN data_absen.outlet_id END) AS outlet_masuk_id,
           MAX(CASE WHEN data_absen.status='OUT' THEN data_absen.outlet_id END) AS outlet_keluar_id,
           MAX(data_absen.keterangan) AS keterangan,
           MIN(CASE WHEN data_absen.status='IN' THEN data_absen.waktu END) jam_masuk,
           MAX(CASE WHEN data_absen.status='OUT' THEN data_absen.waktu END) jam_keluar,
           MAX(CASE WHEN data_absen.status='IN' THEN data_absen_foto.foto_path END) foto_masuk,
           MAX(CASE WHEN data_absen.status='OUT' THEN data_absen_foto.foto_path END) foto_keluar
      FROM data_absen
      JOIN data_karyawan ON (data_absen.nip = data_karyawan.nip OR (data_absen.nip IS NULL AND data_absen.uid = data_karyawan.uid))
      LEFT JOIN data_outlet outlet_masuk ON data_absen.status = 'IN' AND data_absen.outlet_id = outlet_masuk.id
      LEFT JOIN data_outlet outlet_keluar ON data_absen.status = 'OUT' AND data_absen.outlet_id = outlet_keluar.id
      LEFT JOIN data_absen_foto ON data_absen.id = data_absen_foto.absen_id
      $whereSql
      GROUP BY COALESCE(data_absen.nip, data_absen.uid), data_absen.tanggal
      ORDER BY data_absen.tanggal DESC, $orderSql $sort 
      LIMIT ?, ?";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>PT. SUGIH BOGA NUSANTARA - Data Absen</title>

    <!-- Custom fonts for this template-->
    <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link rel="icon" href="../src/img/1.png" type="image/x-icon">
    <!-- Custom styles for this template-->
    <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">

    <!-- Custom styles for this page -->
    <link href="../src/vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
  
  
    <!-- Bootstrap core JavaScript-->
    <script src="../src/vendor/jquery/jquery.min.js"></script>
    <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script type="text/javascript">
        $(document).ready(function(){
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>
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
                    <h1 class="h3 mb-2 text-gray-800">Data Absensi</h1>
                    
                    <!-- DataTales Example -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Data Absensi Harian</h6>
                        </div>
                        <div class="card-body">
                            <div class="col-md-12">
                                <div class="row">
                                    <div class="col-12 col-md-4 mb-3">
                                        <a href="data_absen-create.php" class="btn btn-success pull-right mb-3">Tambah Data Absensi</a>
                                        <a href="data_absen-index.php" class="btn btn-info pull-right mb-3">Reset View Tabel</a>
                                    </div>
                                    <div class="col-12 col-md-4 mb-3">
                                        <form action="data_absen-index.php" method="get">
                                            <div class="input-group">
                                                <input type="date" class="form-control" placeholder="Pilih tanggal" aria-label="Set Tanggal" 
                                                aria-describedby="basic-addon2" name="set_tanggal" value="<?php echo $selected_date !== '' ? htmlspecialchars($selected_date) : date('Y-m-d'); ?>">
                                                <div class="input-group-append">
                                                    <input type="submit" class="btn btn-outline-secondary" value="Set Tanggal">
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="col-12 col-md-4 mb-3">
                                        <form action="data_absen-index.php" method="get">
                                            <div class="col">
                                                <input type="text" class="form-control" placeholder="Pencarian data absensi" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        </form>
                                    </div>    
                                </div>
                                <br>

                                <div class="table-responsive">
                                    <div style="overflow-x: auto; width: 100%;">
                                        <?php
                                        if($stmt = mysqli_prepare($link, $sql)){
                                            $queryBindTypes = $bindTypes . 'ii';
                                            $queryBindValues = $bindValues;
                                            $queryBindValues[] = $offset;
                                            $queryBindValues[] = $no_of_records_per_page;
                                            mysqli_stmt_bind_param($stmt, $queryBindTypes, ...$queryBindValues);
                                            mysqli_stmt_execute($stmt);
                                            $result = mysqli_stmt_get_result($stmt);
                                            if(mysqli_num_rows($result) > 0){
                                                echo "<table class='table table-bordered table-striped'>";
                                                    echo "<thead>";
                                                        echo "<tr>";
                                                            echo "<th><a href='?search=" . urlencode($search) . "&set_tanggal=" . urlencode($selected_date) . "&order=tanggal&sort=$sort'>Tanggal</a></th>";
                                                            echo "<th><a href='?search=" . urlencode($search) . "&set_tanggal=" . urlencode($selected_date) . "&order=nama&sort=$sort'>Nama</a></th>";
                                                            echo "<th>Lokasi Masuk</th>";
                                                            echo "<th>Jam Masuk</th>";
                                                            echo "<th>Lokasi Keluar</th>";
                                                            echo "<th>Jam Keluar</th>";
                                                            echo "<th>Foto Masuk</th>";
                                                            echo "<th>Foto Keluar</th>";
                                                            echo "<th>Keterangan</th>";  // Tambahkan kolom Keterangan
                                                            echo "<th>Action</th>";
                                                        echo "</tr>";
                                                    echo "</thead>";
                                                    echo "<tbody>";
                                                    while($row = mysqli_fetch_array($result)){
                                                        $tanggalTampil = date('d/m/y', strtotime($row['tanggal']));
                                                        $outletBerbeda = $row['outlet_masuk_id'] !== null
                                                            && $row['outlet_keluar_id'] !== null
                                                            && (string) $row['outlet_masuk_id'] !== (string) $row['outlet_keluar_id'];
                                                        echo "<tr>";
                                                        echo "<td>" . escape_html($tanggalTampil) . "</td>";
                                                        echo "<td>" . escape_html($row['nama']) . "</td>";
                                                        echo "<td>" . escape_html($row['outlet_masuk_nama']) . "</td>";
                                                        echo "<td>" . escape_html($row['jam_masuk']) . "</td>";
                                                        if ($outletBerbeda) {
                                                            echo "<td class='text-danger font-weight-bold'>" . escape_html($row['outlet_keluar_nama']) . "</td>";
                                                        } else {
                                                            echo "<td>" . escape_html($row['outlet_keluar_nama']) . "</td>";
                                                        }
                                                        echo "<td>" . escape_html($row['jam_keluar']) . "</td>";
                                                        echo "<td>";
                                                        if (!empty($row['foto_masuk'])) {
                                                            $fotoMasuk = 'view_upload.php?file=' . rawurlencode(ltrim($row['foto_masuk'], '/'));
                                                            echo "<a href='" . htmlspecialchars($fotoMasuk, ENT_QUOTES, 'UTF-8') . "' target='_blank'>";
                                                            echo "<img src='" . htmlspecialchars($fotoMasuk, ENT_QUOTES, 'UTF-8') . "' alt='Foto Masuk' style='width:70px;height:70px;object-fit:cover;border-radius:6px;'>";
                                                            echo "</a>";
                                                        } else {
                                                            echo "-";
                                                        }
                                                        echo "</td>";
                                                        echo "<td>";
                                                        if (!empty($row['foto_keluar'])) {
                                                            $fotoKeluar = 'view_upload.php?file=' . rawurlencode(ltrim($row['foto_keluar'], '/'));
                                                            echo "<a href='" . htmlspecialchars($fotoKeluar, ENT_QUOTES, 'UTF-8') . "' target='_blank'>";
                                                            echo "<img src='" . htmlspecialchars($fotoKeluar, ENT_QUOTES, 'UTF-8') . "' alt='Foto Keluar' style='width:70px;height:70px;object-fit:cover;border-radius:6px;'>";
                                                            echo "</a>";
                                                        } else {
                                                            echo "-";
                                                        }
                                                        echo "</td>";
                                                        echo "<td>" . escape_html($row['keterangan']) . "</td>";  // Menampilkan keterangan
                                                            echo "<td>";
                                                                $tanggal_absen = $row['tanggal'];
                                                                $tanggal_sekarang = date('Y-m-d');

                                                                // Ubah kondisi ini sesuai kebutuhan Anda
                                                                if ($tanggal_absen >= date('Y-m-d', strtotime('-30 days'))) {
                                                                    echo "<a href='data_absen-edit.php?id=" . urlencode($row['uid']) . "&tanggal=" . urlencode($row['tanggal']) . "' title='Edit' data-toggle='tooltip'><span class='fa fa-edit'></span></a> &nbsp";
                                                                    echo "<form action='data_absen-delete.php' method='post' style='display:inline;' onsubmit='return confirm(\"Apakah Anda yakin ingin menghapus data ini?\");'>";
                                                                    echo "<input type='hidden' name='csrf_token' value='" . escape_html($csrfToken) . "'>";
                                                                    echo "<input type='hidden' name='id' value='" . escape_html($row['uid']) . "'>";
                                                                    echo "<input type='hidden' name='tanggal' value='" . escape_html($tanggal_absen) . "'>";
                                                                    echo "<button type='submit' title='Hapus' data-toggle='tooltip' style='border:none;background:none;padding:0;color:#e74a3b;cursor:pointer;'><span class='fa fa-trash'></span></button>";
                                                                    echo "</form>";
                                                                } else {
                                                                    echo "<span class='text-muted'>Tidak dapat diedit/dihapus</span>";
                                                                }
                                                            echo "</td>";
                                                        echo "</tr>";
                                                    }
                                                    echo "</tbody>";
                                                echo "</table>";
                                            } else {
                                                echo "<p class='lead'><em>Tidak ada catatan yang ditemukan.</em></p>";
                                            }
                                            mysqli_stmt_close($stmt);
                                        } else {
                                            echo "ERROR: Tidak dapat menyiapkan query. " . mysqli_error($link);
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <!-- Navigasi halaman di luar div yang dapat di-scroll -->
                                <div class="mt-3" style="width: 100%; overflow-x: visible;">
                                    <nav aria-label="Page navigation example">
                                        <ul class="pagination justify-content-center flex-wrap">
                                            <li class="page-item"><a class="page-link" href="?pageno=1">First</a></li>
                                            <li class="page-item <?php if($pageno <= 1){ echo 'disabled'; } ?>">
                                                <a class="page-link" href="<?php if($pageno <= 1){ echo '#'; } else { echo "?pageno=".($pageno - 1); } ?>">Prev</a>
                                            </li>
                                            <li class="page-item <?php if($pageno >= $total_pages){ echo 'disabled'; } ?>">
                                                <a class="page-link" href="<?php if($pageno >= $total_pages){ echo '#'; } else { echo "?pageno=".($pageno + 1); } ?>">Next</a>
                                            </li>
                                            <li class="page-item"><a class="page-link" href="?pageno=<?php echo $total_pages; ?>">Last</a></li>
                                        </ul>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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


  <!-- Page level plugins -->
  <script src="../src/vendor/datatables/jquery.dataTables.min.js"></script>
  <script src="../src/vendor/datatables/dataTables.bootstrap4.min.js"></script>

  <!-- Custom scripts for all pages-->
  <script src="../src/js/sb-admin-2.min.js"></script>



</body>

</html>


<?php
mysqli_close($link);
?>

