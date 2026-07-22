<?php
date_default_timezone_set('Asia/Jakarta');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once dirname(__DIR__) . '/apps/config.php';

$targetDate = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    fwrite(STDERR, "Format tanggal tidak valid. Gunakan YYYY-MM-DD.\n");
    exit(1);
}

mysqli_set_charset($link, 'utf8mb4');

$selectSql = "
    SELECT k.nip, k.uid
    FROM data_karyawan k
    WHERE k.status_karyawan = 'AKTIF'
      AND NOT EXISTS (
        SELECT 1
        FROM data_absen a
        WHERE a.nip = k.nip
          AND a.tanggal = ?
        LIMIT 1
    )
";

$insertSql = "
    INSERT INTO data_absen (tanggal, waktu, nip, uid, outlet_id, status, keterangan)
    SELECT ?, '00:00:00', ?, ?, NULL, 'IN', 'ALPA'
    WHERE NOT EXISTS (
        SELECT 1
        FROM data_absen
        WHERE nip = ?
          AND tanggal = ?
        LIMIT 1
    )
";

$selectStmt = mysqli_prepare($link, $selectSql);
if (!$selectStmt) {
    fwrite(STDERR, "Gagal menyiapkan query karyawan: " . mysqli_error($link) . "\n");
    exit(1);
}

mysqli_stmt_bind_param($selectStmt, 's', $targetDate);
mysqli_stmt_execute($selectStmt);
$result = mysqli_stmt_get_result($selectStmt);

$insertStmt = mysqli_prepare($link, $insertSql);
if (!$insertStmt) {
    fwrite(STDERR, "Gagal menyiapkan query insert ALPA: " . mysqli_error($link) . "\n");
    exit(1);
}

$checked = 0;
$inserted = 0;

mysqli_begin_transaction($link);

try {
    while ($employee = mysqli_fetch_assoc($result)) {
        $checked++;

        $nip = (string) ($employee['nip'] ?? '');
        $uid = (string) ($employee['uid'] ?? '');

        if ($nip === '' || $uid === '') {
            continue;
        }

        mysqli_stmt_bind_param($insertStmt, 'sssss', $targetDate, $nip, $uid, $nip, $targetDate);
        if (!mysqli_stmt_execute($insertStmt)) {
            throw new RuntimeException(mysqli_stmt_error($insertStmt));
        }

        if (mysqli_stmt_affected_rows($insertStmt) > 0) {
            $inserted++;
        }
    }

    mysqli_commit($link);
} catch (Throwable $e) {
    mysqli_rollback($link);
    fwrite(STDERR, "Gagal menandai ALPA: " . $e->getMessage() . "\n");
    exit(1);
}

mysqli_stmt_close($insertStmt);
mysqli_stmt_close($selectStmt);
mysqli_close($link);

echo sprintf(
    "[%s] mark_alpa selesai. tanggal=%s checked=%d inserted=%d\n",
    date('Y-m-d H:i:s'),
    $targetDate,
    $checked,
    $inserted
);
