<?php
session_start();

$hostname = "localhost";
$username = "juandade_brilink";
$password = "brilink123!";
$database = "juandade_db_brilink";

$conn = new mysqli($hostname, $username, $password, $database);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// 1. Auto-Create Table Log Aktivitas (Jika Belum Ada)
$conn->query("CREATE TABLE IF NOT EXISTS log_aktivitas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    aktivitas TEXT NOT NULL,
    ip_address VARCHAR(50) NOT NULL,
    waktu TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 2. Fungsi Global untuk Mencatat Log & IP Address
function catatLog($conn, $aktivitas, $custom_user_id = null) {
    // Gunakan user_id dari sesi jika ada, atau gunakan custom (berguna saat login)
    $user_id = $custom_user_id !== null ? $custom_user_id : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0);
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    // Coba dapatkan IP asli jika menggunakan Proxy / Cloudflare
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip_address = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Terkadang X_FORWARDED_FOR mengembalikan beberapa IP, ambil yang pertama
        $ip_address = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    
    $stmt = $conn->prepare("INSERT INTO log_aktivitas (user_id, aktivitas, ip_address) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $aktivitas, $ip_address);
    $stmt->execute();
}

// Panggil Engine Telegram
require_once 'telegram.php';
?>