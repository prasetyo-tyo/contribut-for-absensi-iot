<?php
// Mulai sesi
session_start();

// Periksa apakah pengguna sudah login, jika ya arahkan ke halaman index
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: index.php");
    exit;
}

// Sertakan file konfigurasi
require_once "config.php";

// Definisikan variabel dan inisialisasi dengan nilai kosong
$username = $password = "";
$username_err = $password_err = $login_err = "";

// Proses data formulir saat formulir dikirimkan
if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    // Periksa apakah username kosong
    if(empty(trim($_POST["username"]))){
        $username_err = "Silakan masukkan username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    // Periksa apakah password kosong
    if(empty(trim($_POST["password"]))){
        $password_err = "Silakan masukkan password Anda.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validasi kredensial
    if(empty($username_err) && empty($password_err)){
        // Siapkan pernyataan select
        $sql = "SELECT id, username, password FROM users WHERE username = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            // Ikat variabel ke pernyataan sebagai parameter
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            
            // Set parameter
            $param_username = $username;
            
            // Coba jalankan pernyataan yang telah disiapkan
            if(mysqli_stmt_execute($stmt)){
                // Simpan hasil
                mysqli_stmt_store_result($stmt);
                
                // Periksa apakah username ada, jika ya maka verifikasi password
                if(mysqli_stmt_num_rows($stmt) == 1){                    
                    // Ikat variabel hasil
                    mysqli_stmt_bind_result($stmt, $id, $username, $hashed_password);
                    if(mysqli_stmt_fetch($stmt)){
                        if(password_verify($password, $hashed_password)){
                            // Password benar, mulai sesi baru
                            session_start();
                            
                            // Simpan data dalam variabel sesi
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;                            
                            
                            // Redirect pengguna ke halaman index
                            header("location: index.php");
                        } else{
                            // Password salah, tampilkan pesan error umum
                            $login_err = "Username atau password yang dimasukkan salah.";
                        }
                    }
                } else{
                    // Username tidak ada, tampilkan pesan error umum
                    $login_err = "Username atau password yang dimasukkan salah.";
                }
            } else{
                echo "Oops! Terjadi kesalahan. Silakan coba lagi nanti.";
            }

            // Tutup statement
            mysqli_stmt_close($stmt);
        }
    }
    
    // Tutup koneksi
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="icon" href="../src/img/1.png" type="image/x-icon">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(to right, #4CAF50, #8BC34A);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 15px;
        }
        .wrapper {
            width: 100%;
            max-width: 360px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .btn {
            width: 100%;
        }
        .absen-link {
            position: fixed;
            top: 20px;
            right: 20px;
            transition: transform 0.5s ease;
        }
        .absen-link:hover {
            transform: rotateY(180deg);
        }
        @media (max-width: 576px) {
            .wrapper {
                padding: 15px;
            }
            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <h2 class="text-center mb-4">Login</h2>
        <p class="text-center">Silakan isi kredensial Anda untuk login.</p>

        <?php 
        if(!empty($login_err)){
            echo '<div class="alert alert-danger">' . $login_err . '</div>';
        }        
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>    
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group">
                <input type="submit" class="btn btn-primary btn-block" value="Login">
            </div>
        </form>
        
        <div class="form-group">
            <a href="absen.php" class="btn btn-secondary btn-block">Lihat Absen</a>
        </div>
    </div>
</body>
</html>
