<?php
/**
 * Copy file ini ke config.php lalu edit sesuai environment:
 * cp config.example.php config.php
 */
define('DB_SERVER', '127.0.0.1');
define('DB_USERNAME', 'your_db_username');
define('DB_PASSWORD', 'your_db_password');
define('DB_NAME', 'your_db_name');
define('DB_PORT', '3306');

/* Mencoba koneksi ke database MySQL */
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, (int) DB_PORT);

// Periksa koneksi
if($link === false){
    die("ERROR: Tidak dapat terhubung. " . mysqli_connect_error());
}
?>
