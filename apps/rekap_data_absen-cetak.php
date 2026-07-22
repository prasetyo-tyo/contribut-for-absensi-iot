<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Jika belum login, arahkan ke halaman login
    header("location: login.php");
    exit;
}

require('fpdf/fpdf.php'); // Pastikan Anda sudah mengunduh dan menyertakan FPDF
require_once "config.php"; // Koneksi ke database

// Ambil tanggal dari parameter
$tanggal = isset($_GET['tgl']) ? $_GET['tgl'] : date('Y-m-d');
$dateTime = DateTime::createFromFormat('d-m-Y', $tanggal);
if ($dateTime) {
    $tanggal = $dateTime->format('Y-m-d'); // Ubah ke format Y-m-d
}

// Query untuk mengambil data absensi berdasarkan tanggal
$sql = "SELECT COALESCE(data_absen.nip, data_absen.uid) AS uid, data_absen.tanggal,
               MAX(data_karyawan.nama) AS nama,
               COALESCE(MAX(CASE WHEN data_absen.status='IN' THEN outlet_masuk.nama_outlet END), '-') AS outlet_masuk_nama,
               COALESCE(MAX(CASE WHEN data_absen.status='OUT' THEN outlet_keluar.nama_outlet END), '-') AS outlet_keluar_nama,
               MIN(CASE WHEN data_absen.status='IN' THEN data_absen.waktu END) jam_masuk,
               MAX(CASE WHEN data_absen.status='OUT' THEN data_absen.waktu END) jam_keluar,
               MAX(data_absen.keterangan) AS keterangan
        FROM data_absen
        JOIN data_karyawan ON (data_absen.nip = data_karyawan.nip OR (data_absen.nip IS NULL AND data_absen.uid = data_karyawan.uid)) 
        LEFT JOIN data_outlet outlet_masuk ON data_absen.status = 'IN' AND data_absen.outlet_id = outlet_masuk.id
        LEFT JOIN data_outlet outlet_keluar ON data_absen.status = 'OUT' AND data_absen.outlet_id = outlet_keluar.id
        WHERE data_absen.tanggal = ?
        GROUP BY COALESCE(data_absen.nip, data_absen.uid), data_absen.tanggal";
$result = false;
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "s", $tanggal);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
}
if (!$result) {
    die('Query Error: ' . mysqli_error($link));
}

// Inisialisasi objek PDF
$pdf = new FPDF('L');
$pdf->AddPage(); // Tambahkan halaman baru

// Menambahkan logo di bagian paling atas
$pdf->Image('../src/img/bakmi.jpg', 123, -2, 50); // Ganti dengan path yang benar

// Menambahkan kotak dan teks "Rekap Absensi" di bawah logo
$pdf->SetXY(10, 45); // Set posisi untuk teks
$pdf->SetFont('Arial', 'B', 14); // Font tebal
$pdf->SetFillColor(144, 238, 144); // Warna hijau muda
$pdf->Cell(277, 8, 'REKAP ABSENSI', 1, 1, 'C', true); // Mengubah tinggi sel menjadi 8

// Tambahkan pemeriksaan untuk memastikan query berhasil dan ada data
if (mysqli_num_rows($result) == 0) {
    $pdf->Cell(0, 10, 'Tidak ada data untuk tanggal: ' . $tanggal, 0, 1, 'C');
} else {
    // Menambahkan header
    $pdf->SetFillColor(144, 238, 144); // Warna hijau muda
    $pdf->SetFont('Arial', 'B', 9); // Font tebal
    $pdf->Cell(10, 7, 'No', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell(55, 7, 'Nama', 1, 0, 'C', true);
    $pdf->Cell(45, 7, 'Lokasi Masuk', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Jam Masuk', 1, 0, 'C', true);
    $pdf->Cell(45, 7, 'Lokasi Keluar', 1, 0, 'C', true);
    $pdf->Cell(25, 7, 'Jam Keluar', 1, 0, 'C', true);
    $pdf->Cell(42, 7, 'Keterangan', 1, 1, 'C', true); // Mengubah tinggi sel menjadi 7

    // Data tabel
    $pdf->SetFont('Arial', '', 8); // Mengubah ukuran font menjadi 8 dan menghapus 'B' untuk font normal
    $no = 1;
    while ($row = mysqli_fetch_array($result)) {
        $pdf->Cell(10, 6, $no++, 1, 0);
        
        // Format tanggal menjadi dd-mm-yyyy
        $formattedDate = date('d-m-Y', strtotime($row['tanggal']));
        $pdf->Cell(30, 6, $formattedDate, 1, 0);
        
        $pdf->Cell(55, 6, $row['nama'], 1, 0);
        $pdf->Cell(45, 6, $row['outlet_masuk_nama'], 1, 0);
        $pdf->Cell(25, 6, $row['jam_masuk'], 1, 0);
        $pdf->Cell(45, 6, $row['outlet_keluar_nama'], 1, 0);
        $pdf->Cell(25, 6, $row['jam_keluar'], 1, 0);
        $pdf->Cell(42, 6, $row['keterangan'], 1, 1);
    }
}

// Output PDF
$pdf->Output('I', 'Data_Absensi_' . $tanggal . '.pdf'); // Menampilkan file PDF di browser
mysqli_close($link);
?>
