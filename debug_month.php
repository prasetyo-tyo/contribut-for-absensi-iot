<?php
require 'apps/config.php';
$id = 2;
$karyawan = mysqli_fetch_assoc(mysqli_query($link, "SELECT id, uid, nama FROM data_karyawan WHERE id = {$id}"));
var_export($karyawan);
echo PHP_EOL;
$uid = mysqli_real_escape_string($link, $karyawan['uid']);
$res = mysqli_query($link, "SELECT tanggal, waktu, status, keterangan, created_at FROM data_absen WHERE uid = '{$uid}' ORDER BY tanggal, waktu");
while ($row = mysqli_fetch_assoc($res)) {
  echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
