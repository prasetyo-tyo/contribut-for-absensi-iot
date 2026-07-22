<?php

require_once __DIR__ . '/fpdf/fpdf.php';
require_once __DIR__ . '/attendance_period_helper.php';

function rekap_parse_month($value, $link = null)
{
    return attendance_period_from_month($link, $value);
}

function rekap_safe_filename($value)
{
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string) $value));
    $value = trim($value, '_');

    return $value !== '' ? $value : 'data';
}

function rekap_xml($value)
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function rekap_latin($value)
{
    $value = (string) $value;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $value;
}

function rekap_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';

    return $host !== '' ? $scheme . '://' . $host . '/' : '';
}

function rekap_photo_url($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $relativePath = ltrim(str_replace('\\', '/', $path), '/');

    return rekap_base_url() . 'apps/view_upload.php?file=' . rawurlencode($relativePath);
}

function rekap_format_total_jam($jamMasuk, $jamKeluar)
{
    if (empty($jamMasuk) || empty($jamKeluar)) {
        return '-';
    }

    $start = strtotime((string) $jamMasuk);
    $end = strtotime((string) $jamKeluar);
    if ($start === false || $end === false) {
        return '-';
    }
    if ($end < $start) {
        $end += 86400;
    }

    $minutes = intdiv($end - $start, 60);
    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    return $hours . 'Jam ' . $remainingMinutes . 'Menit';
}

function rekap_normalize_attendance_row(array $row)
{
    $keterangan = strtoupper(trim((string) ($row['keterangan'] ?? '')));

    if ($keterangan === 'ALPA') {
        $row['outlet_masuk'] = '-';
        $row['jam_masuk'] = '-';
        $row['outlet_keluar'] = '-';
        $row['jam_keluar'] = '-';
        $row['foto_masuk'] = '';
        $row['foto_keluar'] = '';
        $row['total_jam'] = '-';
    } else {
        $row['total_jam'] = rekap_format_total_jam($row['jam_masuk'] ?? '', $row['jam_keluar'] ?? '');
    }

    return $row;
}

function rekap_get_employees($link, $division, $jabatan)
{
    $where = [];
    $types = '';
    $params = [];

    $where[] = "status_karyawan = 'AKTIF'";

    if ($division !== '') {
        $where[] = 'division = ?';
        $types .= 's';
        $params[] = $division;
    }
    if ($jabatan !== '') {
        $where[] = 'jabatan = ?';
        $types .= 's';
        $params[] = $jabatan;
    }

    $sql = 'SELECT id, nip, uid, nama, division, COALESCE(jabatan, \'\') AS jabatan, no_hp, mail
            FROM data_karyawan';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY division, jabatan, nama';

    $stmt = mysqli_prepare($link, $sql);
    if (!$stmt) {
        throw new RuntimeException('Gagal menyiapkan data karyawan.');
    }
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $employees = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $employees[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $employees;
}

function rekap_get_attendance($link, $nip, $uid, $startDate, $endDate)
{
    $sql = "SELECT
                a.tanggal,
                COALESCE(MAX(CASE WHEN a.status = 'IN' THEN outlet_masuk.nama_outlet END), '-') AS outlet_masuk,
                MAX(CASE WHEN a.status = 'IN' THEN a.waktu END) AS jam_masuk,
                COALESCE(MAX(CASE WHEN a.status = 'OUT' THEN outlet_keluar.nama_outlet END), '-') AS outlet_keluar,
                MAX(CASE WHEN a.status = 'OUT' THEN a.waktu END) AS jam_keluar,
                COALESCE(MAX(a.keterangan), '-') AS keterangan,
                MAX(CASE WHEN a.status = 'IN' THEN foto.foto_path END) AS foto_masuk,
                MAX(CASE WHEN a.status = 'OUT' THEN foto.foto_path END) AS foto_keluar
            FROM data_absen a
            LEFT JOIN data_outlet outlet_masuk
                ON a.status = 'IN' AND a.outlet_id = outlet_masuk.id
            LEFT JOIN data_outlet outlet_keluar
                ON a.status = 'OUT' AND a.outlet_id = outlet_keluar.id
            LEFT JOIN data_absen_foto foto
                ON a.id = foto.absen_id
            WHERE (a.nip = ? OR (a.nip IS NULL AND a.uid = ?)) AND a.tanggal BETWEEN ? AND ?
            GROUP BY a.tanggal
            ORDER BY a.tanggal";

    $stmt = mysqli_prepare($link, $sql);
    if (!$stmt) {
        throw new RuntimeException('Gagal menyiapkan data absensi.');
    }
    mysqli_stmt_bind_param($stmt, 'ssss', $nip, $uid, $startDate, $endDate);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row = rekap_normalize_attendance_row($row);
        $row['link_gambar_masuk'] = rekap_photo_url($row['foto_masuk'] ?? '');
        $row['link_gambar_keluar'] = rekap_photo_url($row['foto_keluar'] ?? '');
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

function rekap_build_dataset($link, $month, $division, $jabatan)
{
    $employees = rekap_get_employees($link, $division, $jabatan);
    foreach ($employees as &$employee) {
        $employee['absensi'] = rekap_get_attendance(
            $link,
            $employee['nip'],
            $employee['uid'],
            $month['start'],
            $month['end']
        );
    }
    unset($employee);

    return $employees;
}

class RekapBulananPDF extends FPDF
{
    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 5, 'Halaman ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

function rekap_count_summary(array $rows)
{
    $summary = [
        'hadir' => 0,
        'setengah_hari' => 0,
        'cuti' => 0,
        'izin' => 0,
        'sakit' => 0,
        'alpa' => 0,
        'wfh' => 0,
    ];

    foreach ($rows as $row) {
        $keterangan = trim((string) ($row['keterangan'] ?? ''));
        if ($keterangan === '1/2 HARI') {
            $summary['setengah_hari']++;
        } elseif ($keterangan === 'CUTI') {
            $summary['cuti']++;
        } elseif ($keterangan === 'IZIN') {
            $summary['izin']++;
        } elseif ($keterangan === 'SAKIT') {
            $summary['sakit']++;
        } elseif ($keterangan === 'ALPA') {
            $summary['alpa']++;
        } elseif ($keterangan === 'WFH') {
            $summary['wfh']++;
        } elseif (!empty($row['jam_masuk']) && $row['jam_masuk'] !== '-' && $row['jam_masuk'] !== '00:00:00') {
            $summary['hadir']++;
        }
    }

    return $summary;
}

function rekap_add_employee_pdf($pdf, $employee, $rows, $monthLabel)
{
    $pdf->AddPage('L');
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(0, 10, rekap_latin('Rekap Absensi Bulanan'), 0, 1, 'C');
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 6, rekap_latin('Informasi Karyawan:'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(30, 5, 'Nama', 0, 0);
    $pdf->Cell(5, 5, ':', 0, 0);
    $pdf->Cell(100, 5, rekap_latin($employee['nama']), 0, 1);
    $pdf->Cell(30, 5, 'Divisi', 0, 0);
    $pdf->Cell(5, 5, ':', 0, 0);
    $pdf->Cell(100, 5, rekap_latin($employee['division']), 0, 1);
    $pdf->Cell(30, 5, 'Jabatan', 0, 0);
    $pdf->Cell(5, 5, ':', 0, 0);
    $pdf->Cell(100, 5, rekap_latin($employee['jabatan']), 0, 1);
    $pdf->Cell(30, 5, 'No HP', 0, 0);
    $pdf->Cell(5, 5, ':', 0, 0);
    $pdf->Cell(100, 5, rekap_latin($employee['no_hp'] ?? ''), 0, 1);
    $pdf->Cell(30, 5, 'Email', 0, 0);
    $pdf->Cell(5, 5, ':', 0, 0);
    $pdf->Cell(100, 5, rekap_latin($employee['mail'] ?? ''), 0, 1);
    $pdf->Ln(3);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 6, rekap_latin('Data Absensi: ' . $monthLabel), 0, 1);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(144, 238, 144);
    $widths = [10, 28, 42, 28, 42, 28, 39, 34];
    $headers = ['No', 'Tanggal', 'Lokasi Masuk', 'Jam Masuk', 'Lokasi Keluar', 'Jam Keluar', 'Keterangan', 'Total Jam'];
    foreach ($headers as $index => $header) {
        $pdf->Cell($widths[$index], 6, rekap_latin($header), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 8);
    if (!$rows) {
        $pdf->Cell(array_sum($widths), 7, rekap_latin('Tidak ada data absensi.'), 1, 1, 'C');
    } else {
        foreach ($rows as $index => $row) {
            $pdf->Cell($widths[0], 6, $index + 1, 1, 0, 'C');
            $pdf->Cell($widths[1], 6, date('d/m/Y', strtotime($row['tanggal'])), 1, 0, 'C');
            $pdf->Cell($widths[2], 6, rekap_latin($row['outlet_masuk']), 1, 0, 'C');
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->Cell($widths[3], 6, (string) $row['jam_masuk'], 1, 0, 'C');
            if (!empty($row['link_gambar_masuk'])) {
                $pdf->Link($x, $y, $widths[3], 6, $row['link_gambar_masuk']);
            }
            $pdf->Cell($widths[4], 6, rekap_latin($row['outlet_keluar']), 1, 0, 'C');
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->Cell($widths[5], 6, (string) $row['jam_keluar'], 1, 0, 'C');
            if (!empty($row['link_gambar_keluar'])) {
                $pdf->Link($x, $y, $widths[5], 6, $row['link_gambar_keluar']);
            }
            $pdf->Cell($widths[6], 6, rekap_latin($row['keterangan']), 1, 0, 'C');
            $pdf->Cell($widths[7], 6, rekap_latin($row['total_jam'] ?? '-'), 1, 1, 'C');
        }
    }

    $summary = rekap_count_summary($rows);
    $pdf->Ln(8);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 6, rekap_latin('Ringkasan Kehadiran:'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    if ($summary['hadir'] > 0) {
        $pdf->Cell(0, 6, 'Hadir: ' . $summary['hadir'] . ' hari', 0, 1);
    }
    if ($summary['setengah_hari'] > 0) {
        $pdf->Cell(0, 6, '1/2 Hari: ' . $summary['setengah_hari'] . ' hari', 0, 1);
    }
    if ($summary['cuti'] > 0) {
        $pdf->Cell(0, 6, 'Cuti: ' . $summary['cuti'] . ' hari', 0, 1);
    }
    if ($summary['izin'] > 0) {
        $pdf->Cell(0, 6, 'Izin: ' . $summary['izin'] . ' hari', 0, 1);
    }
    if ($summary['sakit'] > 0) {
        $pdf->Cell(0, 6, 'Sakit: ' . $summary['sakit'] . ' hari', 0, 1);
    }
    if ($summary['alpa'] > 0) {
        $pdf->Cell(0, 6, 'Alpa: ' . $summary['alpa'] . ' hari', 0, 1);
    }
    if ($summary['wfh'] > 0) {
        $pdf->Cell(0, 6, 'WFH: ' . $summary['wfh'] . ' hari', 0, 1);
    }
}

function rekap_build_pdf($employees, $monthLabel)
{
    $pdf = new RekapBulananPDF('L', 'mm', 'A4');
    $pdf->SetCompression(true);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AliasNbPages();

    foreach ($employees as $employee) {
        rekap_add_employee_pdf($pdf, $employee, $employee['absensi'], $monthLabel);
    }

    if (!$employees) {
        $pdf->AddPage('L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 10, 'Tidak ada data karyawan sesuai filter.', 0, 1, 'C');
    }

    return $pdf->Output('S');
}

function rekap_excel_column($number)
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }

    return $name;
}

function rekap_build_sheet_xml($employees, $monthLabel, array &$relationships = [])
{
    if (count($employees) === 1) {
        $employee = $employees[0];
        $rows = [];
        $rows[] = ['Rekap Absensi Bulanan', '', '', '', '', '', '', ''];
        $rows[] = [''];
        $rows[] = ['Informasi Karyawan:', '', '', '', '', '', '', ''];
        $rows[] = ['Nama', $employee['nama'], '', 'Divisi', $employee['division'], '', '', ''];
        $rows[] = ['Jabatan', $employee['jabatan'], '', 'No HP', $employee['no_hp'] ?? '', '', ''];
        $rows[] = ['Email', $employee['mail'] ?? '', '', '', '', '', '', ''];
        $rows[] = [''];
        $rows[] = ['Data Absensi: ' . $monthLabel, '', '', '', '', '', '', ''];
        $rows[] = ['No', 'Tanggal', 'Lokasi Masuk', 'Jam Masuk', 'Lokasi Keluar', 'Jam Keluar', 'Keterangan', 'Total Jam'];

        if (!$employee['absensi']) {
            $rows[] = ['', '', '', '', '', '', 'Tidak ada data absensi.', ''];
        } else {
            foreach ($employee['absensi'] as $index => $attendance) {
                $rows[] = [
                    $index + 1,
                    $attendance['tanggal'],
                    $attendance['outlet_masuk'],
                    !empty($attendance['link_gambar_masuk']) ? ['text' => $attendance['jam_masuk'], 'hyperlink' => $attendance['link_gambar_masuk']] : $attendance['jam_masuk'],
                    $attendance['outlet_keluar'],
                    !empty($attendance['link_gambar_keluar']) ? ['text' => $attendance['jam_keluar'], 'hyperlink' => $attendance['link_gambar_keluar']] : $attendance['jam_keluar'],
                    $attendance['keterangan'],
                    $attendance['total_jam'] ?? '-',
                ];
            }
        }

        $summary = rekap_count_summary($employee['absensi']);
        $rows[] = [''];
        $rows[] = ['Ringkasan Kehadiran:', '', '', '', '', '', '', ''];
        if ($summary['hadir'] > 0) {
            $rows[] = ['Hadir', $summary['hadir'] . ' hari', '', '', '', '', '', ''];
        }
        if ($summary['setengah_hari'] > 0) {
            $rows[] = ['1/2 Hari', $summary['setengah_hari'] . ' hari', '', '', '', '', '', ''];
        }
        if ($summary['cuti'] > 0) {
            $rows[] = ['Cuti', $summary['cuti'] . ' hari', '', '', '', '', '', ''];
        }
        if ($summary['izin'] > 0) {
            $rows[] = ['Izin', $summary['izin'] . ' hari', '', '', '', '', '', ''];
        }
        if ($summary['sakit'] > 0) {
            $rows[] = ['Sakit', $summary['sakit'] . ' hari', '', '', '', '', '', ''];
        }
        if ($summary['alpa'] > 0) {
            $rows[] = ['Alpa', $summary['alpa'] . ' hari', '', '', '', '', '', ''];
        }
        if ($summary['wfh'] > 0) {
            $rows[] = ['WFH', $summary['wfh'] . ' hari', '', '', '', '', '', ''];
        }
        $mergeRange = 'A1:H1';
        $headerRow = 9;
        $lastColumn = 'H';
        $widths = [8, 15, 28, 14, 28, 14, 20, 16];
    } else {
        $rows = [];
        $rows[] = ['Rekap Absensi Bulanan ' . $monthLabel, '', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['No', 'Nama', 'Divisi', 'Jabatan', 'Tanggal', 'Lokasi Masuk', 'Jam Masuk', 'Lokasi Keluar', 'Jam Keluar', 'Keterangan', 'Total Jam'];

        $number = 1;
        foreach ($employees as $employee) {
            if (!$employee['absensi']) {
                $rows[] = [$number++, $employee['nama'], $employee['division'], $employee['jabatan'], '', '', '', '', '', 'Tidak ada data', ''];
                continue;
            }
            foreach ($employee['absensi'] as $attendance) {
                $rows[] = [
                    $number++,
                    $employee['nama'],
                    $employee['division'],
                    $employee['jabatan'],
                    $attendance['tanggal'],
                    $attendance['outlet_masuk'],
                    !empty($attendance['link_gambar_masuk']) ? ['text' => $attendance['jam_masuk'], 'hyperlink' => $attendance['link_gambar_masuk']] : $attendance['jam_masuk'],
                    $attendance['outlet_keluar'],
                    !empty($attendance['link_gambar_keluar']) ? ['text' => $attendance['jam_keluar'], 'hyperlink' => $attendance['link_gambar_keluar']] : $attendance['jam_keluar'],
                    $attendance['keterangan'],
                    $attendance['total_jam'] ?? '-',
                ];
            }
        }
        $mergeRange = 'A1:K1';
        $headerRow = 2;
        $lastColumn = 'K';
        $widths = [7, 24, 22, 22, 13, 22, 12, 22, 12, 18, 16];
    }

    $relationships = [];
    $hyperlinks = [];
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $xml .= '<cols>';
    foreach ($widths as $index => $width) {
        $column = $index + 1;
        $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols><sheetData>';

    foreach ($rows as $rowIndex => $values) {
        $excelRow = $rowIndex + 1;
        $style = $excelRow === 1 ? 2 : ($excelRow === $headerRow ? 1 : 0);
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($values as $columnIndex => $value) {
            $cell = rekap_excel_column($columnIndex + 1) . $excelRow;
            if (is_array($value) && isset($value['hyperlink'])) {
                $relationshipId = 'rId' . (count($relationships) + 1);
                $relationships[] = ['id' => $relationshipId, 'target' => $value['hyperlink']];
                $hyperlinks[] = ['ref' => $cell, 'id' => $relationshipId];
                $text = $value['text'] ?? 'Link';
                $xml .= '<c r="' . $cell . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . rekap_xml($text) . '</t></is></c>';
            } elseif (is_int($value)) {
                $xml .= '<c r="' . $cell . '" s="' . $style . '" t="n"><v>' . $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $cell . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' .
                    rekap_xml($value) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }

    $lastRow = max($headerRow, count($rows));
    $xml .= '</sheetData>';
    $xml .= '<autoFilter ref="A' . $headerRow . ':' . $lastColumn . $lastRow . '"/>';
    $xml .= '<mergeCells count="1"><mergeCell ref="' . $mergeRange . '"/></mergeCells>';
    if ($hyperlinks) {
        $xml .= '<hyperlinks>';
        foreach ($hyperlinks as $hyperlink) {
            $xml .= '<hyperlink ref="' . rekap_xml($hyperlink['ref']) . '" r:id="' . rekap_xml($hyperlink['id']) . '"/>';
        }
        $xml .= '</hyperlinks>';
    }
    $xml .= '</worksheet>';

    return $xml;
}

function rekap_build_xlsx($employees, $monthLabel)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi PHP ZipArchive belum aktif.');
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'rekap_xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file XLSX.');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Rekap Bulanan" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="14"/><name val="Arial"/></font></fonts>
<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F6F43"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border/><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/></cellXfs>
</styleSheet>');
    $sheetRelationships = [];
    $zip->addFromString('xl/worksheets/sheet1.xml', rekap_build_sheet_xml($employees, $monthLabel, $sheetRelationships));
    if ($sheetRelationships) {
        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $relsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheetRelationships as $relationship) {
            $relsXml .= '<Relationship Id="' . rekap_xml($relationship['id']) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . rekap_xml($relationship['target']) . '" TargetMode="External"/>';
        }
        $relsXml .= '</Relationships>';
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $relsXml);
    }
    $zip->close();

    $contents = file_get_contents($tempFile);
    @unlink($tempFile);
    if ($contents === false) {
        throw new RuntimeException('Gagal membaca file XLSX.');
    }

    return $contents;
}
