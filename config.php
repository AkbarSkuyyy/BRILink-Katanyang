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

// Panggil Engine Telegram
require_once 'telegram.php';
?>