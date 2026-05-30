<?php
echo "<h3>Diagnostik Koneksi Bot Telegram</h3>";

$token = "8680685325:AAG7OxOcc4snHLQxDj9ULa6iOrgBHlJfy_s"; 
$chat_id = "1151150926"; 
$pesan = "Halo! Ini adalah pesan uji coba dari Localhost BRILink.";

$url = "https://api.telegram.org/bot" . $token . "/sendMessage";

$data = [
    'chat_id' => $chat_id,
    'text' => $pesan
];

if (function_exists('curl_init')) {
    echo "<b>Status cURL:</b> AKTIF<br><br>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    // Menggunakan http_build_query agar format data sesuai standar form-url-encoded
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        echo "<span style='color:red;'><b>GAGAL TEROONG (cURL Error):</b> " . $error . "</span>";
    } else {
        echo "<b>RESPON DARI SERVER TELEGRAM:</b><br>";
        echo "<div style='background:#f4f4f4; padding:10px; border:1px solid #ccc; font-family:monospace;'>" . $response . "</div>";
    }
} else {
    echo "<span style='color:red;'><b>ERROR:</b> cURL pada XAMPP Anda tidak aktif!</span>";
}
?>