<?php
session_start();
require('fpdf/fpdf.php');
require_once "config.php";
require_once 'attendance_period_helper.php';

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Mendapatkan ID dari parameter GET
$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;

// Mendapatkan periode absen dari setting tanggal mulai/selesai
$period = attendance_period_from_month($link, $_GET['set_bulan'] ?? '');
$selectedMonth = $period['month'];
$selectedYear = $period['year'];
$startDate = $period['start'];
$endDate = $period['end'];

// Query untuk mendapatkan data karyawan
$karyawanRow = null;
$nip = '';
$uid = '';
if ($id > 0 && ($karyawanStmt = mysqli_prepare($link, "SELECT * FROM data_karyawan WHERE id = ? LIMIT 1"))) {
    mysqli_stmt_bind_param($karyawanStmt, "i", $id);
    mysqli_stmt_execute($karyawanStmt);
    $karyawanResult = mysqli_stmt_get_result($karyawanStmt);
    if ($karyawanResult) {
        $karyawanRow = mysqli_fetch_assoc($karyawanResult);
        $nip = $karyawanRow['nip'] ?? '';
        $uid = $karyawanRow['uid'] ?? '';
    }
    mysqli_stmt_close($karyawanStmt);
}

if (!$karyawanRow || ($nip === '' && $uid === '')) {
    exit('ID karyawan tidak valid.');
}

// Query untuk mendapatkan data absen
$absenSql = "SELECT 
                a.tanggal,
                COALESCE(MAX(CASE WHEN a.status = 'IN' THEN outlet_masuk.nama_outlet END), '-') as outlet_masuk_nama,
                COALESCE(MAX(CASE WHEN a.status = 'OUT' THEN outlet_keluar.nama_outlet END), '-') as outlet_keluar_nama,
                MAX(CASE WHEN a.status = 'IN' THEN a.waktu END) as jam_masuk,
                MAX(CASE WHEN a.status = 'OUT' THEN a.waktu END) as jam_keluar,
                MAX(a.keterangan) as keterangan
             FROM data_absen a
             LEFT JOIN data_outlet outlet_masuk ON a.status = 'IN' AND a.outlet_id = outlet_masuk.id
             LEFT JOIN data_outlet outlet_keluar ON a.status = 'OUT' AND a.outlet_id = outlet_keluar.id
             WHERE (a.nip = ? OR (a.nip IS NULL AND a.uid = ?)) AND a.tanggal BETWEEN ? AND ? 
             GROUP BY a.tanggal
             ORDER BY a.tanggal";
$absenResult = false;
if ($absenStmt = mysqli_prepare($link, $absenSql)) {
    mysqli_stmt_bind_param($absenStmt, "ssss", $nip, $uid, $startDate, $endDate);
    mysqli_stmt_execute($absenStmt);
    $absenResult = mysqli_stmt_get_result($absenStmt);
    mysqli_stmt_close($absenStmt);
}

if (!$absenResult) {
    exit('Gagal mengambil data absensi.');
}

class PDF extends FPDF
{
    function Header()
    {
        $this->SetFont('Arial','B',15);
        $this->Cell(0,10,'Rekap Absensi Bulanan',0,1,'C');
        $this->Ln(5);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Halaman '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

$pdf = new PDF('L');
$pdf->AliasNbPages();
$pdf->AddPage();

// Menambahkan logo perusahaan
$pdf->Image('../src/img/bakmi.jpg', 242, 15, 45);

// Informasi Karyawan
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,5,'Informasi Karyawan:',0,1);
$pdf->SetFont('Arial','',10);

$pdf->Cell(30,5,'Nama',0,0);
$pdf->Cell(5,5,':',0,0);
$pdf->Cell(100,5,$karyawanRow['nama'],0,1);

$pdf->Cell(30,5,'Divisi',0,0);
$pdf->Cell(5,5,':',0,0);
$pdf->Cell(100,5,$karyawanRow['division'],0,1);

$pdf->Cell(30,5,'Jabatan',0,0);
$pdf->Cell(5,5,':',0,0);
$pdf->Cell(100,5,$karyawanRow['jabatan'] ?? '',0,1);

$pdf->Cell(30,5,'No HP',0,0);
$pdf->Cell(5,5,':',0,0);
$pdf->Cell(100,5,$karyawanRow['no_hp'],0,1);

$pdf->Cell(30,5,'Email',0,0);
$pdf->Cell(5,5,':',0,0);
$pdf->Cell(100,5,$karyawanRow['mail'],0,1);

$pdf->Cell(30,5,'Periode',0,0);
$pdf->Cell(5,5,':',0,0);
$pdf->Cell(100,5,attendance_period_label($period),0,1);

$pdf->Ln(3);

// Tabel Absensi
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,6,'Data Absensi:',0,1);
$pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(144, 238, 144); // Warna hijau muda
$pdf->Cell(10,6,'No',1,0,'C',true);
$pdf->Cell(35,6,'Tanggal',1,0,'C',true);
$pdf->Cell(55,6,'Lokasi Masuk',1,0,'C',true);
$pdf->Cell(35,6,'Jam Masuk',1,0,'C',true);
$pdf->Cell(55,6,'Lokasi Keluar',1,0,'C',true);
$pdf->Cell(35,6,'Jam Keluar',1,0,'C',true);
$pdf->Cell(52,6,'Keterangan',1,1,'C',true);

$pdf->SetFont('Arial','',10);
$no = 1;
$hadir = 0;
$setengah_hari = 0;
$cuti = 0;
$izin = 0;
$sakit = 0;
$alpa = 0;
$wfh = 0;

while($row = mysqli_fetch_array($absenResult)) {
    $keterangan = strtoupper(trim((string) $row['keterangan']));
    $lokasiMasuk = $row['outlet_masuk_nama'] ?: '-';
    $jamMasuk = $row['jam_masuk'] ?: '-';
    $lokasiKeluar = $row['outlet_keluar_nama'] ?: '-';
    $jamKeluar = $row['jam_keluar'] ?: '-';

    if ($keterangan === 'ALPA') {
        $lokasiMasuk = '-';
        $jamMasuk = '-';
        $lokasiKeluar = '-';
        $jamKeluar = '-';
    }

    $pdf->Cell(10,6,$no,1,0,'C');
    $pdf->Cell(35,6,$row['tanggal'],1,0,'C');
    $pdf->Cell(55,6,$lokasiMasuk,1,0,'C');
    $pdf->Cell(35,6,$jamMasuk,1,0,'C');
    $pdf->Cell(55,6,$lokasiKeluar,1,0,'C');
    $pdf->Cell(35,6,$jamKeluar,1,0,'C');
    $pdf->Cell(52,6,$row['keterangan'],1,1,'C');
    $no++;

    if ($keterangan === '1/2 HARI') {
        $setengah_hari++;
    } elseif ($keterangan === 'CUTI') {
        $cuti++;
    } elseif ($keterangan === 'IZIN') {
        $izin++;
    } elseif ($keterangan === 'SAKIT') {
        $sakit++;
    } elseif ($keterangan === 'ALPA') {
        $alpa++;
    } elseif ($keterangan === 'WFH') {
        $wfh++;
    } elseif ($jamMasuk !== '-' && $jamMasuk !== '00:00:00') {
        $hadir++;
    }
}

$pdf->Ln(10);

// Menampilkan ringkasan kehadiran
$pdf->SetFont('Arial','B',12);
$pdf->Cell(0,6,'Ringkasan Kehadiran:',0,1);
$pdf->SetFont('Arial','',10);

if ($hadir > 0) {
    $pdf->Cell(0,6,"Hadir: $hadir hari",0,1);
}
if ($setengah_hari > 0) {
    $pdf->Cell(0,6,"1/2 Hari: $setengah_hari hari",0,1);
}
if ($cuti > 0) {
    $pdf->Cell(0,6,"Cuti: $cuti hari",0,1);
}
if ($izin > 0) {
    $pdf->Cell(0,6,"Izin: $izin hari",0,1);
}
if ($sakit > 0) {
    $pdf->Cell(0,6,"Sakit: $sakit hari",0,1);
}
if ($alpa > 0) {
    $pdf->Cell(0,6,"Alpa: $alpa hari",0,1);
}
if ($wfh > 0) {
    $pdf->Cell(0,6,"WFH: $wfh hari",0,1);
}

$pdf->Output();

mysqli_close($link);
?>
