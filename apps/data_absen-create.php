<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Koneksi ke database
include 'config.php';

// Ambil daftar karyawan
$query = "SELECT nip, uid, nama FROM data_karyawan";
$result = mysqli_query($link, $query);
$karyawan = [];
while ($row = mysqli_fetch_assoc($result)) {
    $karyawan[] = $row;
}

$outletResult = mysqli_query($link, "SELECT id, nama_outlet FROM data_outlet ORDER BY nama_outlet ASC");
$outlets = [];
while ($row = mysqli_fetch_assoc($outletResult)) {
    $outlets[] = $row;
}

// Proses form jika ada pengiriman data
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nip = $_POST['uid'];
    $keterangan = $_POST['keterangan'];
    $tanggal = $_POST['tanggal'];
    $outlet_id = isset($_POST['outlet_id']) && $_POST['outlet_id'] !== '' ? (int) $_POST['outlet_id'] : null;
    
    // Periksa apakah tanggal adalah rentang atau tanggal tunggal
    if (strpos($tanggal, ' - ') !== false) {
        // Jika rentang tanggal
        list($tanggal_awal, $tanggal_akhir) = explode(' - ', $tanggal);
        $tanggal_awal = date('Y-m-d', strtotime($tanggal_awal));
        $tanggal_akhir = date('Y-m-d', strtotime($tanggal_akhir));
        
        // Loop melalui rentang tanggal
        $current_date = $tanggal_awal;
        while (strtotime($current_date) <= strtotime($tanggal_akhir)) {
            // Proses untuk setiap tanggal dalam rentang
            proses_absensi($link, $current_date, $nip, $keterangan, $outlet_id);
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }
    } else {
        // Jika tanggal tunggal
        $tanggal = date('Y-m-d', strtotime($tanggal));
        proses_absensi($link, $tanggal, $nip, $keterangan, $outlet_id);
    }
    
    // Tambahkan session untuk notifikasi
    $_SESSION['message'] = "Data absen telah ditambahkan.";
    header("location: " . $_SERVER['PHP_SELF']);
    exit;
}

function proses_absensi($link, $tanggal, $nip, $keterangan, $outlet_id) {
    // Jika keterangan bukan 'Hadir', set waktu masuk menjadi 00:00:00
    if (!in_array($keterangan, ['HADIR', '1/2 HARI'], true)) {
        $waktu_masuk = '00:00:00';
        $waktu_keluar = '00:00:00';
    } else {
        $waktu_masuk = $_POST['waktu_masuk'];
        $waktu_keluar = $_POST['waktu_keluar'];
    }

    $uid = '';
    $karyawan_query = "SELECT uid FROM data_karyawan WHERE nip = ? LIMIT 1";
    $karyawan_stmt = mysqli_prepare($link, $karyawan_query);
    mysqli_stmt_bind_param($karyawan_stmt, "s", $nip);
    mysqli_stmt_execute($karyawan_stmt);
    $karyawan_result = mysqli_stmt_get_result($karyawan_stmt);
    if ($karyawan_row = mysqli_fetch_assoc($karyawan_result)) {
        $uid = $karyawan_row['uid'];
    }
    mysqli_stmt_close($karyawan_stmt);

    // Simpan data absen ke database
    $insert_query = "INSERT INTO data_absen (tanggal, waktu, nip, uid, outlet_id, status, keterangan) VALUES (?, ?, ?, ?, ?, 'IN', ?)";
    $stmt_masuk = mysqli_prepare($link, $insert_query);
    mysqli_stmt_bind_param($stmt_masuk, "ssssis", $tanggal, $waktu_masuk, $nip, $uid, $outlet_id, $keterangan);
    mysqli_stmt_execute($stmt_masuk);

    // Simpan waktu keluar ke database
    $insert_query_keluar = "INSERT INTO data_absen (tanggal, waktu, nip, uid, outlet_id, status, keterangan) VALUES (?, ?, ?, ?, ?, 'OUT', ?)";
    $stmt_keluar = mysqli_prepare($link, $insert_query_keluar);
    mysqli_stmt_bind_param($stmt_keluar, "ssssis", $tanggal, $waktu_keluar, $nip, $uid, $outlet_id, $keterangan);
    mysqli_stmt_execute($stmt_keluar);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>PT. SUGIH BOGA NUSANTARA  - Tambah Data Absensi</title>
    <link rel="icon" href="../src/img/1.png" type="image/x-icon">
    <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito" rel="stylesheet">
    <link href="../src/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="../src/vendor/jquery-ui/jquery-ui.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
    <style>
        /* Ubah CSS berikut */
        .daterangepicker {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            margin-top: 0 !important;
            max-width: 300px;
        }
        .daterangepicker .calendar-table {
            max-width: 100%;
        }
        .daterangepicker:before, .daterangepicker:after {
            display: none;
        }
    </style>
</head>
<body id="page-top">
    <div id="wrapper">
        <?php include 'partial_sidebar.php';?>
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <?php include 'partial_topbar.php';?>
                <div class="container-fluid">
                    <h1 class="h3 mb-2 text-gray-800">Data Absensi</h1>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Tambah Data</h6>
                        </div>
                        <div class="card-body">
                            <div class="col-md-12"> 
                                <form method="post">
                                    <div class="form-group">
                                        <label for="uid">Nama</label>
                                        <select name="uid" id="uid" class="form-control" required>
                                            <option value="">--Pilih Karyawan--</option>
                                            <?php foreach ($karyawan as $k): ?>
                                                <option value="<?= htmlspecialchars($k['nip']) ?>"><?= htmlspecialchars($k['nama']) ?> - <?= htmlspecialchars($k['nip']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="keterangan">Keterangan</label>
                                        <select class="form-control" name="keterangan" id="keterangan" required>
                                            <option value="HADIR">HADIR</option>
                                            <option value="1/2 HARI">1/2 HARI</option>
                                            <option value="IZIN">IZIN</option>
                                            <option value="SAKIT">SAKIT</option>
                                            <option value="CUTI">CUTI</option>
                                            <option value="ALPA">ALPA</option>
                                            <option value="WFH">WFH</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="outlet_id">Outlet</label>
                                        <select name="outlet_id" id="outlet_id" class="form-control" required>
                                            <option value="">--Pilih Outlet--</option>
                                            <?php foreach ($outlets as $outlet): ?>
                                                <option value="<?= (int) $outlet['id'] ?>"><?= htmlspecialchars($outlet['nama_outlet']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="tanggal">Tanggal</label>
                                        <input type="text" name="tanggal" id="tanggal" class="form-control" required>
                                    </div>
                                    <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="waktu_masuk">Jam Masuk</label>
                                        <input type="time" name="waktu_masuk" id="waktu_masuk" class="form-control" required>
                                        <span class="help-block"></span>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="waktu_keluar">Jam Keluar</label>
                                        <input type="time" name="waktu_keluar" id="waktu_keluar" class="form-control"required>
                                        <span class="help-block"></span>
                                    </div>
                                    </div>
                                    <hr>
                                    <div class="row justify-content-end">
                                        <input type="submit" class="btn btn-success" value="Tambah Absen"> &nbsp;
                                        <a href="data_absen-index.php" class="btn btn-primary">Batal</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
  <script src="../src/vendor/jquery/jquery.min.js"></script>
  <script src="../src/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="../src/vendor/jquery-easing/jquery.easing.min.js"></script>
  <script src="../src/vendor/jquery-ui/jquery-ui.js"></script>
  <script src="../src/vendor/datatables/jquery.dataTables.min.js"></script>
  <script src="../src/vendor/datatables/dataTables.bootstrap4.min.js"></script>

  <!-- Custom scripts for all pages-->
  <script src="../src/js/sb-admin-2.min.js"></script>

  <script type="text/javascript" src="https://cdn.jsdelivr.net/jquery/latest/jquery.min.js"></script>
  <script type="text/javascript" src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
  <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

  <script>
    $(function() {
        $('input[name="tanggal"]').daterangepicker({
            opens: 'center',
            autoUpdateInput: false,
            singleDatePicker: true,
            showDropdowns: true,
            locale: {
                format: 'YYYY-MM-DD',
                separator: ' - ',
                applyLabel: 'Pilih',
                cancelLabel: 'Batal'
            }
        });

        $('input[name="tanggal"]').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('YYYY-MM-DD'));
        });

        $('input[name="tanggal"]').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
        });

        // Tambahkan tombol untuk mengaktifkan/menonaktifkan pemilihan rentang
        var toggleButton = $('<button type="button" class="btn btn-sm btn-secondary mt-2">Aktifkan Rentang Tanggal</button>');
        $('input[name="tanggal"]').after(toggleButton);

        toggleButton.on('click', function() {
            var picker = $('input[name="tanggal"]').data('daterangepicker');
            picker.singleDatePicker = !picker.singleDatePicker;
            
            if (picker.singleDatePicker) {
                $(this).text('Aktifkan Rentang Tanggal');
                $('input[name="tanggal"]').val('');
            } else {
                $(this).text('Nonaktifkan Rentang Tanggal');
                $('input[name="tanggal"]').val('');
            }

            // Perbarui event handler
            $('input[name="tanggal"]').off('apply.daterangepicker').on('apply.daterangepicker', function(ev, picker) {
                if (picker.singleDatePicker) {
                    $(this).val(picker.startDate.format('YYYY-MM-DD'));
                } else {
                    $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
                }
            });
        });

        // Fungsi untuk mengatur status input waktu
        function setTimeInputStatus() {
            var keterangan = $('#keterangan').val();
            var $waktuMasuk = $('#waktu_masuk');
            var $waktuKeluar = $('#waktu_keluar');

            if (keterangan === 'HADIR' || keterangan === '1/2 HARI') {
                $waktuMasuk.prop('disabled', false).prop('required', true);
                $waktuKeluar.prop('disabled', false).prop('required', true);
            } else {
                $waktuMasuk.prop('disabled', true).prop('required', false).val('');
                $waktuKeluar.prop('disabled', true).prop('required', false).val('');
            }
        }

        // Panggil fungsi saat halaman dimuat
        setTimeInputStatus();

        // Panggil fungsi saat keterangan berubah
        $('#keterangan').on('change', setTimeInputStatus);
    });
  </script>

<?php if (isset($_SESSION['message'])): ?>
    <script>
        alert("<?= $_SESSION['message'] ?>"); // Tampilkan pop-up
    </script>
    <?php unset($_SESSION['message']); // Hapus pesan setelah ditampilkan ?>
<?php endif; ?>

</body>
</html>
