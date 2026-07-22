<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Koneksi ke database
include 'config.php';

// Periksa koneksi
if (mysqli_connect_errno()) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Metode request tidak valid.";
    header("location: data_absen-index.php");
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
) {
    $_SESSION['error'] = "Token keamanan tidak valid.";
    header("location: data_absen-index.php");
    exit;
}

// Periksa apakah ada ID dan tanggal yang diterima
if(isset($_POST['id']) && isset($_POST['tanggal'])){
    $nip = mysqli_real_escape_string($link, $_POST['id']);
    $tanggal = mysqli_real_escape_string($link, $_POST['tanggal']);
    
    // Periksa apakah tanggal absen sama dengan hari ini
    if ($tanggal >= date('Y-m-d', strtotime('-7 days'))) {
        // Lanjutkan dengan proses delete
    } else {
        $_SESSION['error'] = "Maaf, Anda hanya dapat menghapus absen untuk 7 hari terakhir.";
        header("location: data_absen-index.php");
        exit;
    }
    
    // Hapus semua event absen pada tanggal tersebut, termasuk IN/OUT beda outlet.
    $delete_query = "DELETE FROM data_absen WHERE (nip = ? OR (nip IS NULL AND uid = ?)) AND tanggal = ?";
    $stmt = mysqli_prepare($link, $delete_query);
    mysqli_stmt_bind_param($stmt, "sss", $nip, $nip, $tanggal);
    
    if(mysqli_stmt_execute($stmt)){
        $_SESSION['success'] = "Data absen berhasil dihapus.";
    } else {
        $_SESSION['error'] = "Terjadi kesalahan saat menghapus data absen.";
    }
    
    mysqli_stmt_close($stmt);
} else {
    $_SESSION['error'] = "ID atau tanggal tidak diberikan.";
}

// Tutup koneksi database
mysqli_close($link);

// Kembali ke halaman indeks
header("location: data_absen-index.php");
exit;
?>
