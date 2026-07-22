<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("location: data_user-index.php");
    exit;
}

if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])
) {
    $_SESSION['error'] = "Token keamanan tidak valid.";
    header("location: data_user-index.php");
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = "User tidak valid.";
    header("location: data_user-index.php");
    exit;
}

if ($id === (int) $_SESSION['id']) {
    $_SESSION['error'] = "Akun yang sedang dipakai tidak boleh dihapus.";
    header("location: data_user-index.php");
    exit;
}

$stmt = mysqli_prepare($link, "DELETE FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
if (mysqli_stmt_execute($stmt)) {
    $_SESSION['success'] = "User berhasil dihapus.";
} else {
    $_SESSION['error'] = "Gagal menghapus user.";
}
mysqli_stmt_close($stmt);

header("location: data_user-index.php");
exit;
