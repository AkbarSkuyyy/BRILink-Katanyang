<?php
date_default_timezone_set('Asia/Jakarta'); // Pastikan waktu sesuai WIB

function sendTelegramNotif($pesan) {
    // Token dan Chat ID Anda
    $token = "8680685325:AAG7OxOcc4snHLQxDj9ULa6iOrgBHlJfy_s"; 
    $chat_id = "1151150926"; 
    
    if(empty($token) || empty($chat_id)) return false; 

    $url = "https://api.telegram.org/bot" . $token . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $pesan,
        'parse_mode' => 'HTML'
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($ch);
        curl_close($ch);
    } else {
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
    }
    return $result;
}

// 1. FORMAT PESAN: SHIFT DIBUKA
function notifShiftBuka($nama_cabang, $shift_ke, $modal_awal) {
    $waktu = date('d/m/Y H:i');
    $modal_rp = number_format($modal_awal, 0, ',', '.');
    
    $pesan = "🟢 <b>SHIFT DIBUKA</b>\n\n";
    $pesan .= "Lokasi: <b>$nama_cabang</b>\n";
    $pesan .= "Shift: <b>$shift_ke</b>\n";
    $pesan .= "Modal Awal Laci: <b>Rp $modal_rp</b>\n";
    $pesan .= "Waktu: <i>$waktu WIB</i>";
    
    sendTelegramNotif($pesan);
}

// 2. FORMAT PESAN: SHIFT DITUTUP
function notifShiftTutup($nama_cabang, $shift_ke) {
    $waktu = date('d/m/Y H:i');
    
    $pesan = "🔴 <b>SHIFT DITUTUP</b>\n\n";
    $pesan .= "Lokasi: <b>$nama_cabang</b>\n";
    $pesan .= "Shift: <b>$shift_ke</b>\n";
    $pesan .= "Laporan laci telah dikunci oleh kasir.\n";
    $pesan .= "Waktu: <i>$waktu WIB</i>";
    
    sendTelegramNotif($pesan);
}

// 3. FORMAT PESAN: TRANSAKSI BARU
function notifTransaksiBaru($nama_cabang, $shift_ke, $jenis, $nominal, $admin, $ket) {
    $waktu = date('d/m/Y H:i');
    $nom_rp = number_format($nominal, 0, ',', '.');
    $adm_rp = number_format($admin, 0, ',', '.');
    $keterangan = empty($ket) ? "-" : htmlspecialchars($ket);

    $pesan = "🔵 <b>TRANSAKSI BARU</b>\n\n";
    $pesan .= "Lokasi: <b>$nama_cabang</b> (Shift $shift_ke)\n";
    $pesan .= "Layanan: <b>$jenis</b>\n";
    $pesan .= "Nominal: <b>Rp $nom_rp</b>\n";
    $pesan .= "Admin/Laba: <b>Rp $adm_rp</b>\n";
    $pesan .= "Keterangan: <i>$keterangan</i>\n";
    $pesan .= "Waktu: <i>$waktu WIB</i>";
    
    sendTelegramNotif($pesan);
}

// 4. FORMAT PESAN: UPDATE STOK RECEH
function notifUpdateReceh($conn, $cabang_id, $nama_cabang, $total_receh) {
    $waktu = date('d/m/Y H:i');
    $tot_rp = number_format($total_receh, 0, ',', '.');

    $pesan = "🟡 <b>UPDATE STOK RECEH</b>\n\n";
    $pesan .= "Lokasi: <b>$nama_cabang</b>\n";
    $pesan .= "Total Aset Receh: <b>Rp $tot_rp</b>\n";
    $pesan .= "Waktu: <i>$waktu WIB</i>\n\n";

    // Peringatan otomatis jika ada yang menipis
    $q = $conn->query("SELECT * FROM uang_receh WHERE cabang_id = '$cabang_id'");
    if($q && $q->num_rows > 0) {
        $data = $q->fetch_assoc();
        $alert = "";
        $pecahan = ['100k', '50k', '20k', '10k', '5k', '2k', '1k'];
        foreach($pecahan as $p) {
            $qty = (int)$data['qty_'.$p];
            if($qty <= 20) {
                $alert .= "➖ " . strtoupper($p) . " sisa <b>$qty lbr/kpg</b>\n";
            }
        }
        if($alert != "") {
            $pesan .= "⚠️ <b>PERINGATAN STOK MENIPIS (≤ 20):</b>\n" . $alert;
        }
    }
    sendTelegramNotif($pesan);
}
?>