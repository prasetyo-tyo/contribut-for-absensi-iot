<?php
// auto_search-personeldata.php

// Include config file untuk koneksi database
require_once "config.php";

// Ambil parameter pencarian dari query string
$searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';

// Inisialisasi array untuk menyimpan hasil
$result = [];

// Pastikan ada input pencarian
if (!empty($searchTerm)) {
    // Persiapkan query untuk mencari nama karyawan
    $sql = "SELECT id, nama, uid FROM karyawan WHERE nama LIKE ? LIMIT 10";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        // Bind parameter
        $param = "%" . $searchTerm . "%";
        mysqli_stmt_bind_param($stmt, "s", $param);
        
        // Eksekusi statement
        if (mysqli_stmt_execute($stmt)) {
            // Ambil hasil
            $resultSet = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($resultSet)) {
                $result[] = [
                    'id' => $row['id'],
                    'value' => $row['nama'],
                    'uid' => $row['uid']
                ];
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Kembalikan hasil dalam format JSON
header('Content-Type: application/json');
echo json_encode($result);

// Tutup koneksi database
mysqli_close($link);
?>