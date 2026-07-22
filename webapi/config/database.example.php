<?php
/**
 * Copy file ini ke database.php lalu edit sesuai environment:
 * cp database.example.php database.php
 */
class Database {
    private $host = "127.0.0.1";
    private $db_name = "your_db_name";
    private $username = "your_db_username";
    private $password = "your_db_password";
    private $conn;

    public function getConnection(){
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->exec("set names utf8mb4");
        } catch(PDOException $exception) {
            echo "Database error: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>
