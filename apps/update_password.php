<?php
session_start();

// Periksa apakah pengguna sudah login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Jika belum login, arahkan ke halaman login
    header("location: login.php");
    exit;
}
require_once "config.php";


$sql = "SELECT id, username, password FROM users";
$result = mysqli_query($link, $sql);

if (mysqli_num_rows($result) > 0) {
    while($row = mysqli_fetch_assoc($result)) {
        $id = $row["id"];
        $password = $row["password"];
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $update_sql = "UPDATE users SET password = ? WHERE id = ?";
        if($stmt = mysqli_prepare($link, $update_sql)){
            mysqli_stmt_bind_param($stmt, "si", $hashed_password, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    echo "Password berhasil diupdate.";
} else {
    echo "Tidak ada data user.";
}

mysqli_close($link);
?>