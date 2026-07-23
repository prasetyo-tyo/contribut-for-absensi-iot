<?php
session_start();
require_once dirname(__DIR__) . '/shared/card_security.php';

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Jika belum login, arahkan ke halaman login
    header("location: login.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');
// Include config file
require_once "config.php";
require_once "karyawan_options.php";
require_once "karyawan_picture_helper.php";

// Define variables and initialize with empty values
$created = $nip = $uid = $nama = $no_hp = $division = $jabatan = $mail = $tanggal_lahir = $alamat = $picture = "";
$status_karyawan = "AKTIF";
$uid_fisik = $token_kartu = "";
$created_err = $nip_err = $uid_err = $nama_err = $no_hp_err = $division_err = $jabatan_err = $mail_err = $tanggal_lahir_err = $alamat_err = $picture_err = $uid_fisik_err = $token_kartu_err = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validasi input
    $nip = trim($_POST["nip"] ?? "");
    if (empty($nip)) {
        $nip_err = "Mohon masukkan NIP.";
    } else {
        $nip = strtoupper($nip);
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

    $uid_fisik = card_normalize_value($_POST["uid_fisik"] ?? "");
    $token_kartu = card_normalize_value($_POST["token_kartu"] ?? "");
    $input_uid = trim($_POST["uid"]);

    if (!empty($uid_fisik) || !empty($token_kartu)) {
        if (empty($uid_fisik)) {
            $uid_fisik_err = "Mohon masukkan UID fisik kartu.";
        }

        if (empty($token_kartu)) {
            $token_kartu_err = "Mohon masukkan token kartu.";
        }

        if (empty($uid_fisik_err) && empty($token_kartu_err)) {
            $uid = card_build_internal_uid($uid_fisik, $token_kartu);
        }
    } elseif(empty($input_uid)){
        $uid_err = "Mohon masukkan UID legacy atau isi UID fisik + token kartu.";
    } else{
        $uid = $input_uid;
    }
    
    $input_nama = trim($_POST["nama"]);
    if(empty($input_nama)){
        $nama_err = "Mohon masukkan nama.";
    } else{
        $nama = $input_nama;
    }
    
    $input_no_hp = trim($_POST["no_hp"]);
    if(empty($input_no_hp)){
        $no_hp_err = "Mohon masukkan nomor HP.";
    } else{
        $no_hp = $input_no_hp;
    }
    
    $input_division = trim($_POST["division"]);
    if(empty($input_division)){
        $division_err = "Mohon pilih divisi.";
    } else{
        $division = $input_division;
    }
    
    $input_jabatan = trim($_POST["jabatan"] ?? "");
    $status_karyawan = strtoupper(trim($_POST["status_karyawan"] ?? "AKTIF"));
    if (!in_array($status_karyawan, ["AKTIF", "NONAKTIF"], true)) {
        $status_karyawan = "AKTIF";
    }
    if(empty($input_jabatan)){
        $jabatan_err = "Mohon pilih jabatan.";
    } else{
        $jabatan = $input_jabatan;
    }
    
    $input_mail = trim($_POST["mail"]);
    if(empty($input_mail)){
        $mail_err = "Mohon masukkan email.";
    } elseif(!filter_var($input_mail, FILTER_VALIDATE_EMAIL)){
        $mail_err = "Format email tidak valid.";
    } else{
        $mail = $input_mail;
    }
    
    $input_tanggal_lahir = trim($_POST["tanggal_lahir"]);
    if(empty($input_tanggal_lahir)){
        $tanggal_lahir_err = "Mohon masukkan tanggal lahir.";
    } else{
        $tanggal_lahir = $input_tanggal_lahir;
    }
    
    $input_alamat = trim($_POST["alamat"]);
    if(empty($input_alamat)){
        $alamat_err = "Mohon masukkan alamat.";
    } else{
        $alamat = $input_alamat;
    }
    
    // Check input errors before inserting in database
    if(empty($nip_err) && empty($uid_err) && empty($uid_fisik_err) && empty($token_kartu_err) && empty($nama_err) && empty($no_hp_err) && empty($division_err) && empty($jabatan_err) && empty($mail_err) && empty($tanggal_lahir_err) && empty($alamat_err)){
        $created = date("Y-m-d H:i:s");
        $uploadedPicture = karyawan_picture_upload('picture_file', $picture_err);
        if ($picture_err === "" && $uploadedPicture !== null) {
            $picture = $uploadedPicture;
        }
    }

    if(empty($nip_err) && empty($uid_err) && empty($uid_fisik_err) && empty($token_kartu_err) && empty($nama_err) && empty($no_hp_err) && empty($division_err) && empty($jabatan_err) && empty($mail_err) && empty($tanggal_lahir_err) && empty($alamat_err) && empty($picture_err)){
        // Menggunakan mysqli yang sudah diinisialisasi di config.php
        $sql = "INSERT INTO data_karyawan (created, nip, uid, uid_fisik, token_kartu, nama, no_hp, division, jabatan, mail, tanggal_lahir, alamat, picture, status_karyawan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "ssssssssssssss", $created, $nip, $uid, $uid_fisik, $token_kartu, $nama, $no_hp, $division, $jabatan, $mail, $tanggal_lahir, $alamat, $picture, $status_karyawan);
            
            if(mysqli_stmt_execute($stmt)){
                echo '<script language="javascript" type="text/javascript"> 
                          alert("Data berhasil ditambahkan");
                          window.location.replace("data_karyawan-index.php");
                </script>';
            } else{
                echo "Terjadi kesalahan: " . mysqli_error($link);
            }
            
            mysqli_stmt_close($stmt);
        } else {
            echo "Terjadi kesalahan dalam persiapan statement: " . mysqli_error($link);
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

  <title>PT. SUGIH BOGA NUSANTARA - Tambah DataKaryawan</title>

  <!-- Custom fonts for this template-->
  <link href="../src/vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
  <link rel="icon" href="../src/img/1.png" type="image/x-icon">
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
          <h1 class="h3 mb-2 text-gray-800">Data Karyawan</h1>
          <div class="card shadow mb-4">
            <div class="card-header py-3">
              <h6 class="m-0 font-weight-bold text-primary">Tambah Data</h6>
            </div>
            <div class="card-body" >
              <div class="col-md-12">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                  <div class="form-group">
                    <label for="nip">NIP / Nomor Induk Pegawai</label>
                    <input type="text" name="nip" class="form-control <?php echo (!empty($nip_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($nip); ?>" placeholder="Contoh: EMP00001">
                    <span class="invalid-feedback"><?php echo $nip_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="uid">UID Legacy/Internal</label>
                    <input type="text" name="uid" class="form-control <?php echo (!empty($uid_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $uid; ?>" placeholder="Input UID kartu absensi">
                    <span class="invalid-feedback"><?php echo $uid_err; ?></span>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label for="uid_fisik">UID Fisik Kartu</label>
                      <input type="text" name="uid_fisik" class="form-control <?php echo (!empty($uid_fisik_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($uid_fisik); ?>" placeholder="Contoh: A1B2C3D4">
                      <span class="invalid-feedback"><?php echo $uid_fisik_err; ?></span>
                    </div>
                    <div class="form-group col-md-6">
                      <label for="token_kartu">Token Kartu (Block 2)</label>
                      <input type="text" name="token_kartu" class="form-control <?php echo (!empty($token_kartu_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($token_kartu); ?>" placeholder="Token kartu acak">
                      <span class="invalid-feedback"><?php echo $token_kartu_err; ?></span>
                    </div>
                  </div>
                  <div class="form-group">
                    <label for="nama">Nama</label>
                    <input type="text" name="nama" class="form-control <?php echo (!empty($nama_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $nama; ?>" placeholder="Input nama karyawan">
                    <span class="invalid-feedback"><?php echo $nama_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="no_hp">No HP</label>
                    <input type="text" name="no_hp" class="form-control <?php echo (!empty($no_hp_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $no_hp; ?>" placeholder="Input nomor HP karyawan">
                    <span class="invalid-feedback"><?php echo $no_hp_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="mail">Email</label>
                    <input type="email" name="mail" class="form-control <?php echo (!empty($mail_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $mail; ?>" placeholder="Input email karyawan">
                    <span class="invalid-feedback"><?php echo $mail_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="tanggal_lahir">Tanggal Lahir</label>
                    <input type="date" name="tanggal_lahir" class="form-control <?php echo (!empty($tanggal_lahir_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $tanggal_lahir; ?>">
                    <span class="invalid-feedback"><?php echo $tanggal_lahir_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="division">Divisi</label>
                    <select class="form-control <?php echo (!empty($division_err)) ? 'is-invalid' : ''; ?>" name="division">
                      <option value="">Pilih Divisi</option>
                      <?php render_options(get_division_options(), $division); ?>
                    </select>
                    <span class="invalid-feedback"><?php echo $division_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="jabatan">Jabatan</label>
                    <select class="form-control <?php echo (!empty($jabatan_err)) ? 'is-invalid' : ''; ?>" name="jabatan">
                      <option value="">Pilih Jabatan</option>
                      <?php render_options(get_jabatan_options(), $jabatan); ?>
                    </select>
                    <span class="invalid-feedback"><?php echo $jabatan_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="status_karyawan">Status Karyawan</label>
                    <select class="form-control" name="status_karyawan">
                      <option value="AKTIF" <?php echo $status_karyawan === "AKTIF" ? "selected" : ""; ?>>Aktif</option>
                      <option value="NONAKTIF" <?php echo $status_karyawan === "NONAKTIF" ? "selected" : ""; ?>>Nonaktif</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label for="alamat">Alamat</label>
                    <textarea name="alamat" class="form-control <?php echo (!empty($alamat_err)) ? 'is-invalid' : ''; ?>" placeholder="Input alamat rumah karyawan"><?php echo $alamat; ?></textarea>
                    <span class="invalid-feedback"><?php echo $alamat_err; ?></span>
                  </div>
                  <div class="form-group">
                    <label for="picture_file">Foto Karyawan</label>
                    <input type="file" name="picture_file" class="form-control-file <?php echo (!empty($picture_err)) ? 'is-invalid' : ''; ?>" accept="image/*">
                    <small class="form-text text-muted">Di HP biasanya muncul pilihan Kamera/Galeri/File. Foto otomatis dikompres sebelum upload.</small>
                    <?php if (!empty($picture_err)): ?>
                      <div class="invalid-feedback d-block"><?php echo htmlspecialchars($picture_err); ?></div>
                    <?php endif; ?>
                  </div>
                  <hr>
                  <div class="row justify-content-end">
                    <input type="submit" class="btn btn-success" value="Tambah">
                    <a href="data_karyawan-index.php" class="btn btn-primary ml-2">Batal</a>
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
          <h5 class="modal-title" id="exampleModalLabel">Siap untuk Keluar?</h5>
          <button class="close" type="button" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">�</span>
          </button>
        </div>
        <div class="modal-body">Pilih "Logout" di bawah jika Anda siap untuk mengakhiri sesi Anda saat ini.</div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-dismiss="modal">Batal</button>
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
  <script src="karyawan_picture_compress.js"></script>

  <!-- ═══ RFID Scan Auto-Polling — DUAL MODE ════════════════════════════ -->
  <!-- Saat halaman dibuka: set ESP ke mode REGISTER                     -->
  <!-- Saat halaman ditutup/navigasi: set ESP ke mode ABSEN              -->
  <!-- ESP akan cek mode sebelum kirim data kartu:                       -->
  <!--   - REGISTER → kirim ke scan-register.php → auto-fill form        -->
  <!--   - ABSEN   → kirim ke create_legacy.php → absensi normal         -->
  <script>
  (function() {
    // ─── Konfigurasi ─────────────────────────────────────────────
    var MODE_URL   = '/webapi/api/register-mode.php';
    var POLL_URL   = '/webapi/api/scan-poll.php';
    var POLL_INTERVAL = 2000;
    var pollingActive = true;

    // ─── Referensi DOM ───────────────────────────────────────────
    var uidFisikInput = document.querySelector('input[name="uid_fisik"]');
    var tokenInput    = document.querySelector('input[name="token_kartu"]');

    // ─── Set Mode Register saat halaman dibuka ───────────────────
    function setRegisterMode() {
      fetch(MODE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mode: 'register' })
      })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        console.log('[RFID] Mode register aktif:', d);
      })
      .catch(function(e) {
        console.warn('[RFID] Gagal set mode register:', e);
      });
    }

    // ─── Set Mode Absen saat halaman ditutup ────────────────────
    // Dipanggil saat: navigasi, klik link, submit form, reload
    function setAbsenMode() {
      // Pakai sendBeacon agar tetap terkirim walau halaman tutup
      var blob = new Blob([JSON.stringify({ mode: 'absen' })], { type: 'application/json' });
      navigator.sendBeacon(MODE_URL, blob);
      console.log('[RFID] Mode balik ke absen');
    }

    // Hook ke event: unload, beforeunload, klik link, submit form
    window.addEventListener('beforeunload', setAbsenMode);

    // Hook ke semua link internal — sebelum navigasi, reset mode
    document.addEventListener('click', function(e) {
      var link = e.target.closest('a');
      if (link && link.href && link.href.indexOf(window.location.host) !== -1) {
        // DOM sudah akan di-unload, tapi kita kirim beacon
        setAbsenMode();
      }
    });

    // ─── Buat Badge Status RFID ──────────────────────────────────
    var badgeContainer = document.createElement('div');
    badgeContainer.id = 'rfid-scan-status';
    badgeContainer.className = 'alert alert-success d-flex align-items-center mb-3';
    badgeContainer.style.cssText = 'padding: 8px 12px; font-size: 14px; border-radius: 5px;';
    badgeContainer.innerHTML = '<i class="fas fa-check-circle mr-2"></i> <span id="rfid-status-text">Mode REGISTER aktif — Tap kartu untuk isi UID otomatis</span>';

    // Ambil status dari MODE_URL dulu
    fetch(MODE_URL + '?check=1')
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d.ok && d.mode === 'register') {
          updateBadge(
            'Mode REGISTER aktif — Silakan tap kartu ke ESP',
            'success',
            'fa-check-circle'
          );
        }
      })
      .catch(function() { /* ignore */ });

    function updateBadge(text, type, icon) {
      icon = icon || 'fa-wifi';
      var statusEl = document.getElementById('rfid-status-text');
      if (statusEl) {
        statusEl.textContent = text;
      }
      if (badgeContainer) {
        badgeContainer.className = 'alert alert-' + (type || 'info') + ' d-flex align-items-center mb-3';
        badgeContainer.style.cssText = 'padding: 8px 12px; font-size: 14px; border-radius: 5px;';
        var iconEl = badgeContainer.querySelector('i');
        if (iconEl) {
          iconEl.className = 'fas ' + icon + ' mr-2';
        }
      }
    }

    // Insert badge di atas field UID + Token
    var uidField = uidFisikInput ? uidFisikInput.closest('.form-row') : null;
    if (uidField && uidField.parentNode) {
      uidField.parentNode.insertBefore(badgeContainer, uidField);
    }

    // ─── Polling scan terbaru ────────────────────────────────────
    function doPoll() {
      if (!pollingActive) return;

      fetch(POLL_URL, {
        method: 'GET',
        headers: { 'Accept': 'application/json' }
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.ok && data.scan) {
          var scan = data.scan;

          // Isi UID Fisik
          if (uidFisikInput && scan.uid_fisik) {
            uidFisikInput.value = scan.uid_fisik;
            uidFisikInput.dispatchEvent(new Event('input', { bubbles: true }));
          }

          // Isi Token Kartu
          if (tokenInput && scan.token_kartu) {
            tokenInput.value = scan.token_kartu;
            tokenInput.dispatchEvent(new Event('input', { bubbles: true }));
          }

          // Update status
          var tokenInfo = (scan.token_kartu ? ' | Token: ' + scan.token_kartu.substring(0, 16) + '...' : ' | Token: kosong');
          updateBadge('Kartu terdeteksi! UID: ' + scan.uid_fisik + tokenInfo, 'success', 'fa-check-circle');

          // Highlight field hijau
          [uidFisikInput, tokenInput].forEach(function(el) {
            if (el && el.value) {
              el.style.borderColor = '#28a745';
              el.style.boxShadow = '0 0 8px rgba(40,167,69,0.5)';
              setTimeout(function() {
                el.style.borderColor = '';
                el.style.boxShadow = '';
              }, 3000);
            }
          });
        }
      })
      .catch(function(err) {
        console.log('RFID poll error:', err);
      });
    }

    // ─── Mulai semua ─────────────────────────────────────────────
    // Set mode register dulu
    setRegisterMode();

    // Mulai polling scan
    setInterval(doPoll, POLL_INTERVAL);

    // Keyboard shortcut Ctrl+Shift+R → toggle polling
    document.addEventListener('keydown', function(e) {
      if (e.ctrlKey && e.shiftKey && e.key === 'R') {
        pollingActive = !pollingActive;
        updateBadge(
          pollingActive ? 'Menunggu scan kartu RFID...' : 'Polling OFF (Ctrl+Shift+R)',
          pollingActive ? 'info' : 'warning',
          pollingActive ? 'fa-wifi' : 'fa-ban'
        );
      }
    });
  })();
  </script>

</body>
</html>
