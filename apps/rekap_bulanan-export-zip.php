<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once 'config.php';
require_once 'rekap_bulanan-export-helper.php';

$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
$division = trim((string) ($_GET['division'] ?? ''));
$jabatan = trim((string) ($_GET['jabatan'] ?? ''));
$month = rekap_parse_month($_GET['set_bulan'] ?? '', $link);

try {
    if (!in_array($format, ['pdf', 'xlsx'], true)) {
        throw new InvalidArgumentException('Format ZIP tidak valid.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi PHP ZipArchive belum aktif.');
    }

    $employees = rekap_build_dataset($link, $month, $division, $jabatan);
    $tempFile = tempnam(sys_get_temp_dir(), 'rekap_zip_');
    $zip = new ZipArchive();
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat ZIP.');
    }

    foreach ($employees as $employee) {
        $singleEmployee = [$employee];
        $fileBase = sprintf(
            '%s_%s_%s',
            str_pad((string) $employee['id'], 4, '0', STR_PAD_LEFT),
            rekap_safe_filename($employee['nama']),
            $month['value']
        );
        if ($format === 'pdf') {
            $zip->addFromString($fileBase . '.pdf', rekap_build_pdf($singleEmployee, $month['value']));
        } else {
            $zip->addFromString($fileBase . '.xlsx', rekap_build_xlsx($singleEmployee, $month['value']));
        }
    }

    if (!$employees) {
        $zip->addFromString('TIDAK_ADA_DATA.txt', 'Tidak ada data karyawan sesuai filter.');
    }
    $zip->close();

    $content = file_get_contents($tempFile);
    @unlink($tempFile);
    if ($content === false) {
        throw new RuntimeException('Gagal membaca ZIP.');
    }

    $baseName = 'rekap-absensi-' . $month['value'] . '-' . $format;
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $baseName . '.zip"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    echo $content;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Export ZIP gagal: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
} finally {
    mysqli_close($link);
}

