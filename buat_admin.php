<?php
require 'config.php';

echo "<h3>Proses Pembuatan Akun Admin Pusat</h3>";

// 1. Ubah struktur kolom cabang_id dan shift_ke agar boleh kosong (NULL) khusus untuk Admin
$ubah_cabang = $conn->query("ALTER TABLE users MODIFY cabang_id INT NULL");
$ubah_shift = $conn->query("ALTER TABLE users MODIFY shift_ke INT NULL");

// 2. Tentukan Data Admin Baru
$username_admin = 'admin_pusat'; 
$pin_mentah     = '123456';      
$hashed_pin     = password_hash($pin_mentah, PASSWORD_DEFAULT);

// 3. Cek apakah username ini sudah terdaftar sebagai admin agar tidak double
$cek_admin = $conn->query("SELECT id FROM users WHERE username = '$username_admin' AND role = 'admin'");
if ($cek_admin->num_rows > 0) {
    echo "ℹ️ Akun admin dengan username <strong>$username_admin</strong> sudah pernah dibuat sebelumnya.<br>";
    echo "👉 Silakan langsung coba login di halaman utama.";
} else {
    // 4. Input ke database (cabang_id dan shift_ke diisi tulisan NULL tanpa tanda kutip)
    $input_admin = $conn->query("INSERT INTO users (username, pin, role, cabang_id, shift_ke) VALUES ('$username_admin', '$hashed_pin', 'admin', NULL, NULL)");
    
    if ($input_admin) {
        echo "<br>🎉 <strong>Akun Admin Berhasil Dibuat!</strong><br>";
        echo "=========================================<br>";
        echo "▶️ Username : <strong>$username_admin</strong><br>";
        echo "▶️ PIN/Password : <strong>$pin_mentah</strong><br>";
        echo "=========================================<br>";
        echo "⚠️ <em>Demi keamanan, segera <strong>HAPUS</strong> file <strong>buat_admin.php</strong> ini setelah berhasil!</em><br>";
    } else {
        echo "❌ Gagal membuat akun admin: " . $conn->error . "<br>";
    }
}
?>