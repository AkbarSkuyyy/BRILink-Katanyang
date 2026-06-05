<?php
// Mencegah PHP 8.1+ mematikan halaman jika ada kolom DB yang belum tercipta
mysqli_report(MYSQLI_REPORT_OFF);

require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');
$nama_kasir = isset($_SESSION['username']) ? $_SESSION['username'] : 'Kasir';

// 1. CEK SHIFT AKTIF
$q_shift = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$shift_aktif = $q_shift ? $q_shift->fetch_assoc() : null;

// Jika tidak ada shift aktif, arahkan untuk buka modal/shift dulu
if (!$shift_aktif) {
    $_SESSION['flash_error'] = "Anda harus membuka laci/shift terlebih dahulu sebelum dapat melakukan transaksi.";
    header("Location: set_modal.php"); exit;
}

$shift_id = isset($shift_aktif['id']) ? $shift_aktif['id'] : 0;
$shift_ke = isset($shift_aktif['shift_ke']) ? $shift_aktif['shift_ke'] : 1;

// 2. AMBIL DATA REKENING YANG DITUGASKAN KE KASIR INI
$q_user = $conn->query("SELECT cabang_id, assigned_banks FROM users WHERE id = '$user_id'");
$row_user = $q_user ? $q_user->fetch_assoc() : null;
$cabang_id = !empty($row_user['cabang_id']) ? $row_user['cabang_id'] : 0;

$assigned_banks_str = isset($row_user['assigned_banks']) ? $row_user['assigned_banks'] : '';
$assigned_banks_array = $assigned_banks_str ? array_map('trim', explode(',', $assigned_banks_str)) : [];
$assigned_banks = array_unique($assigned_banks_array);

$db_map = [];
$q_rek = $conn->query("SELECT alias, kolom_db FROM rekening");
if($q_rek) {
    while($r = $q_rek->fetch_assoc()) { 
        $db_map[trim($r['alias'])] = trim($r['kolom_db']); 
    }
}

$valid_banks = [];
foreach($assigned_banks as $b) {
    if(isset($db_map[$b])) { $valid_banks[] = $b; }
}
$jumlah_rek_valid = count($valid_banks);

$q_cek_beku = $conn->query("SELECT s.id FROM shifts s JOIN users u ON s.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND s.tanggal = '$tanggal_hari_ini' AND s.shift_ke = 2 AND s.status = 'selesai'");
$cabang_dibekukan = ($q_cek_beku && $q_cek_beku->num_rows > 0);

$status_shift_aktif = true; 
$sid = $shift_id;
$tgl_shift = isset($shift_aktif['tanggal']) ? $shift_aktif['tanggal'] : $tanggal_hari_ini;

// AUTO-FIX DATABASE UNTUK MENCEGAH ERROR 500
$conn->query("ALTER TABLE transactions MODIFY jenis_transaksi VARCHAR(100) NOT NULL");    

$conn->query("CREATE TABLE IF NOT EXISTS uang_receh (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cabang_id INT NOT NULL,
    qty_100k INT DEFAULT 0,
    qty_50k INT DEFAULT 0,
    qty_20k INT DEFAULT 0,
    qty_10k INT DEFAULT 0,
    qty_5k INT DEFAULT 0,
    qty_2k INT DEFAULT 0,
    qty_1k INT DEFAULT 0,
    total DOUBLE DEFAULT 0,
    last_updated DATE NULL DEFAULT NULL
)");

$check_receh = $conn->query("SHOW COLUMNS FROM uang_receh LIKE 'last_updated'");
if ($check_receh && $check_receh->num_rows == 0) {
    $conn->query("ALTER TABLE uang_receh ADD last_updated DATE NULL DEFAULT NULL");
    $conn->query("UPDATE uang_receh SET last_updated = '$tanggal_hari_ini'");
}

$q_stok_receh = $conn->query("SELECT * FROM uang_receh WHERE cabang_id = '$cabang_id'");
$stok_receh = $q_stok_receh ? $q_stok_receh->fetch_assoc() : null;

if (!$stok_receh) {
    $conn->query("INSERT INTO uang_receh (cabang_id, qty_100k, qty_50k, qty_20k, qty_10k, qty_5k, qty_2k, qty_1k, total, last_updated) 
                  VALUES ('$cabang_id', 0, 0, 0, 0, 0, 0, 0, 0, '$tanggal_hari_ini')");
    $stok_receh = ['qty_100k'=>0, 'qty_50k'=>0, 'qty_20k'=>0, 'qty_10k'=>0, 'qty_5k'=>0, 'qty_2k'=>0, 'qty_1k'=>0, 'total'=>0];
} else {
    if (isset($stok_receh['last_updated']) && $stok_receh['last_updated'] != $tanggal_hari_ini) {
        $conn->query("UPDATE uang_receh SET qty_100k=0, qty_50k=0, qty_20k=0, qty_10k=0, qty_5k=0, qty_2k=0, qty_1k=0, total=0, last_updated='$tanggal_hari_ini' WHERE cabang_id = '$cabang_id'");
        $stok_receh = ['qty_100k'=>0, 'qty_50k'=>0, 'qty_20k'=>0, 'qty_10k'=>0, 'qty_5k'=>0, 'qty_2k'=>0, 'qty_1k'=>0, 'total'=>0];
    }
}

// ==============================================================
// PROSES 1: SIMPAN "SETORAN DANA BOS"
// ==============================================================
if (isset($_POST['submit_setor_bos'])) {
    $nominal = (float)str_replace('.', '', $_POST['nominal']);
    $target_dana = $conn->real_escape_string($_POST['target_dana']);
    $penyetor = $conn->real_escape_string(trim($_POST['penyetor']));
    $catatan = $conn->real_escape_string(trim($_POST['keterangan']));
    
    $waktu = date('H:i:s');
    $keterangan_full = "Penyetor: " . $penyetor . ($catatan ? " | Catatan: " . $catatan : "");

    $q_insert = $conn->query("INSERT INTO transactions (shift_id, tanggal, waktu, jenis_transaksi, bank_agen, nominal, admin_fee, keterangan) 
                              VALUES ('$shift_id', '$tanggal_hari_ini', '$waktu', 'Setor Dana Bos', '$target_dana', '$nominal', 0, '$keterangan_full')");
    
    if ($q_insert) {
        $trx_id = $conn->insert_id;
        $_SESSION['print_setor_bos'] = [
            'id' => $trx_id,
            'tanggal' => date('d M Y / H:i'),
            'penyetor' => $penyetor,
            'target' => $target_dana,
            'nominal' => number_format($nominal, 0, ',', '.'),
            'kasir' => $nama_kasir
        ];
        $_SESSION['flash_success'] = "Setoran Dana Bos berhasil dicatat dan ditambahkan ke saldo!";
    } else {
        $_SESSION['flash_error'] = "Gagal mencatat Setoran Bos.";
    }
    header("Location: user_dashboard.php"); exit;
}

// ==============================================================
// PROSES 2: SIMPAN TRANSAKSI UMUM
// ==============================================================
if (isset($_POST['simpan_transaksi']) && !$cabang_dibekukan) {
    $jenis   = $_POST['jenis_transaksi'];
    $ket     = $_POST['keterangan'];
    $bank_agen = isset($_POST['bank_agen']) ? $_POST['bank_agen'] : '-';
    $nominal = !empty($_POST['nominal']) ? (float)str_replace('.', '', $_POST['nominal']) : 0;
    
    $admin = ($jenis == 'Tarik Dana Bos' || $jenis == 'Setor Dana Bos') ? 0 : (!empty($_POST['admin_fee']) ? (float)str_replace('.', '', $_POST['admin_fee']) : 0);

    $validasi_lolos = true;
    $pesan_error = "";
    
    // Validasi Laci
    if ($jenis == 'Tarik Tunai' || ($jenis == 'Pengeluaran / Rugi' && $bank_agen == 'CASH') || ($jenis == 'Tarik Dana Bos' && $bank_agen == 'CASH')) {
        $q_in_cash_tmp = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND (jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos') OR (jenis_transaksi = 'Setor Dana Bos' AND bank_agen = 'CASH'))");
        $in_cash_val = ($q_in_cash_tmp && $row = $q_in_cash_tmp->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
        
        $q_keluar_tmp = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi IN ('Pengeluaran / Rugi', 'Tarik Dana Bos') AND bank_agen = 'CASH'))");
        $keluar_val = ($q_keluar_tmp && $row = $q_keluar_tmp->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
        
        $q_admin_cash_tmp = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos')");
        $admin_cash_val = ($q_admin_cash_tmp && $row = $q_admin_cash_tmp->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
        
        $tmp_saldo_fisik = (isset($shift_aktif['modal_awal']) ? $shift_aktif['modal_awal'] : 0) + $in_cash_val + $admin_cash_val - $keluar_val;
        
        if ($nominal > $tmp_saldo_fisik) {
            $validasi_lolos = false;
            $pesan_error = "Transaksi Ditolak! Uang di Laci Fisik Anda tidak cukup.";
        }
    } 
    // Validasi Digital
    else if ($jenis != 'Tukar Uang Receh' && $jenis != 'Tukar Uang' && $jenis != 'Setor Dana Bos') {
        if ($bank_agen != 'CASH' && $bank_agen != '-') {
            $b_col_tmp = $db_map[$bank_agen] ?? '';
            $modal_bank_tmp = isset($shift_aktif[$b_col_tmp]) ? (float)$shift_aktif[$b_col_tmp] : 0;
            
            $q_in_b_tmp = $conn->query("SELECT SUM(CASE WHEN jenis_transaksi = 'Tarik Tunai' THEN nominal + admin_fee ELSE nominal END) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi IN ('Tarik Tunai', 'Setor Dana Bos') AND bank_agen = '$bank_agen'");
            $in_b_val = ($q_in_b_tmp && $row = $q_in_b_tmp->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
            
            $q_out_b_tmp = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Setor Dana Bos') AND bank_agen = '$bank_agen'");
            $out_b_val = ($q_out_b_tmp && $row = $q_out_b_tmp->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
            
            $tmp_saldo_bank = $modal_bank_tmp + $in_b_val - $out_b_val;
            
            if ($nominal > $tmp_saldo_bank) {
                $validasi_lolos = false;
                $pesan_error = "Transaksi Ditolak! Saldo pada rekening $bank_agen tidak cukup.";
            }
        }
    }

    if (!$validasi_lolos) {
        $_SESSION['flash_error'] = $pesan_error;
        header("Location: user_dashboard.php"); exit; 
    }

    if ($jenis == 'Tukar Uang Receh' || $jenis == 'Tukar Uang') { 
        $jenis = 'Tukar Uang'; $bank_agen = '-';
        $out_100k = !empty($_POST['out_100k']) ? (int)$_POST['out_100k'] : 0;
        $out_50k  = !empty($_POST['out_50k']) ? (int)$_POST['out_50k'] : 0;
        $out_20k  = !empty($_POST['out_20k']) ? (int)$_POST['out_20k'] : 0;
        $out_10k  = !empty($_POST['out_10k']) ? (int)$_POST['out_10k'] : 0;
        $out_5k   = !empty($_POST['out_5k']) ? (int)$_POST['out_5k'] : 0;
        $out_2k   = !empty($_POST['out_2k']) ? (int)$_POST['out_2k'] : 0;
        $out_1k   = !empty($_POST['out_1k']) ? (int)$_POST['out_1k'] : 0;

        $net_total = (-$out_100k * 100000) + (-$out_50k * 50000) + (-$out_20k * 20000) + (-$out_10k * 10000) + (-$out_5k * 5000) + (-$out_2k * 2000) + (-$out_1k * 1000);
        $conn->query("UPDATE uang_receh SET qty_100k = qty_100k - $out_100k, qty_50k = qty_50k - $out_50k, qty_20k = qty_20k - $out_20k, qty_10k = qty_10k - $out_10k, qty_5k = qty_5k - $out_5k, qty_2k = qty_2k - $out_2k, qty_1k = qty_1k - $out_1k, total = total + $net_total, last_updated = '$tanggal_hari_ini' WHERE cabang_id = '$cabang_id'");
    }
    
    if ($jenis == 'Buka Rekening Baru') { $jenis = 'Buka Rekening'; }

    $waktu = date('H:i:s');
    $stmt = $conn->prepare("INSERT INTO transactions (user_id, shift_id, tanggal, waktu, jenis_transaksi, bank_agen, nominal, admin_fee, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssssds", $user_id, $sid, $tanggal_hari_ini, $waktu, $jenis, $bank_agen, $nominal, $admin, $ket);
    
    if($stmt->execute()) { 
        $_SESSION['flash_success'] = "Transaksi Berhasil Disimpan!";
    } else {
        $_SESSION['flash_error'] = "Gagal memproses transaksi.";
    }
    header("Location: user_dashboard.php"); exit; 
}

// ==============================================
// HITUNG DATA UNTUK TAMPILAN DASHBOARD
// ==============================================
$modal_awal_laci = isset($shift_aktif['modal_awal']) ? (float)$shift_aktif['modal_awal'] : 0;
$saldo_fisik_sekarang = 0; $total_saldo_digital = 0; $admin_hari_ini = 0; $rugi_hari_ini = 0; $saldo_per_bank = []; 

$q_in_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND (jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos') OR (jenis_transaksi = 'Setor Dana Bos' AND bank_agen = 'CASH'))");
$uang_masuk = ($q_in_cash && $row = $q_in_cash->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

$q_keluar = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi IN ('Pengeluaran / Rugi', 'Tarik Dana Bos') AND bank_agen = 'CASH'))");
$uang_keluar = ($q_keluar && $row = $q_keluar->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

$q_admin_fee = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift'");
$admin_hari_ini = ($q_admin_fee && $row = $q_admin_fee->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

$q_admin_cash = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos')");
$admin_cash = ($q_admin_cash && $row = $q_admin_cash->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

$q_rugi = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi = 'Pengeluaran / Rugi'");
$rugi_hari_ini = ($q_rugi && $row = $q_rugi->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

$saldo_fisik_sekarang = $modal_awal_laci + $uang_masuk + $admin_cash - $uang_keluar;

foreach($valid_banks as $b_name) {
    $b_col = isset($db_map[$b_name]) ? $db_map[$b_name] : '';
    $q_in_b = $conn->query("SELECT SUM(CASE WHEN jenis_transaksi = 'Tarik Tunai' THEN nominal + admin_fee ELSE nominal END) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi IN ('Tarik Tunai', 'Setor Dana Bos') AND bank_agen = '$b_name'");
    $in_b = ($q_in_b && $row = $q_in_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
    
    $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Setor Dana Bos') AND bank_agen = '$b_name'");
    $out_b = ($q_out_b && $row = $q_out_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
    
    $modal_bank = ($b_col && isset($shift_aktif[$b_col])) ? (float)$shift_aktif[$b_col] : 0;
    $saldo_bank_ini = $modal_bank + $in_b - $out_b;
    $saldo_per_bank[$b_name] = $saldo_bank_ini;
    $total_saldo_digital += $saldo_bank_ini;
}

if (isset($_POST['koreksi_laci'])) {
    $saldo_riil_baru = (float)str_replace('.', '', $_POST['modal_baru']);
    if ($saldo_riil_baru < $saldo_fisik_sekarang) {
        $selisih = $saldo_fisik_sekarang - $saldo_riil_baru;
        $conn->query("INSERT INTO transactions (user_id, shift_id, tanggal, jenis_transaksi, bank_agen, nominal, admin_fee, keterangan) VALUES ('$user_id', '$sid', '$tanggal_hari_ini', 'Pengeluaran / Rugi', 'CASH', '$selisih', 0, 'Selisih Kurang Laci Fisik')");
        $_SESSION['flash_error'] = "Kerugian Rp " . number_format($selisih, 0, ',', '.') . " dicatat.";
    } elseif ($saldo_riil_baru > $saldo_fisik_sekarang) {
        $selisih = $saldo_riil_baru - $saldo_fisik_sekarang;
        $conn->query("UPDATE shifts SET modal_awal = modal_awal + $selisih WHERE id = '$sid'");
        $_SESSION['flash_success'] = "Uang fisik berlebih ditambahkan ke Modal Awal.";
    } else {
        $_SESSION['flash_success'] = "Saldo laci Balance.";
    }
    header("Location: user_dashboard.php"); exit;
}

$total_uang_receh = isset($stok_receh['total']) ? $stok_receh['total'] : 0;

$grafik_tanggal = []; $grafik_pendapatan = []; $grafik_tarik = []; $grafik_setor = []; $grafik_transfer = [];
for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i days"));
    $grafik_tanggal[] = date('d/m', strtotime($tgl));
    $q_chart = $conn->query("SELECT IFNULL(SUM(t.admin_fee), 0) as harian FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl'");
    $grafik_pendapatan[] = (float)($q_chart ? $q_chart->fetch_assoc()['harian'] : 0);
    $q_tarik = $conn->query("SELECT COUNT(t.id) as jml FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl' AND t.jenis_transaksi = 'Tarik Tunai'");
    $grafik_tarik[] = (int)($q_tarik ? $q_tarik->fetch_assoc()['jml'] : 0);
    $q_setor = $conn->query("SELECT COUNT(t.id) as jml FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl' AND t.jenis_transaksi = 'Setor Tunai'");
    $grafik_setor[] = (int)($q_setor ? $q_setor->fetch_assoc()['jml'] : 0);
    $q_tf = $conn->query("SELECT COUNT(t.id) as jml FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl' AND t.jenis_transaksi = 'Transfer'");
    $grafik_transfer[] = (int)($q_tf ? $q_tf->fetch_assoc()['jml'] : 0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kasir Dashboard - BRILink</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; --bri-light-blue: #e6f0ff;}
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; border: none; }
        .metric-card { border-radius: 24px; padding: 24px; position: relative; overflow: hidden; height: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.04); }
        .metric-card i.bg-icon { position: absolute; right: -15px; bottom: -20px; font-size: 110px; opacity: 0.15; transform: rotate(-10deg); }
        .bg-saldo { background: linear-gradient(135deg, #003366, var(--bri-blue)); color: white; }
        .bg-mbanking { background: linear-gradient(135deg, #198754, #20c997); color: white; }
        .bg-admin { background: linear-gradient(135deg, #b3d4ff, #e6f0ff); color: var(--bri-blue); }
        .bg-danger-grad { background: linear-gradient(135deg, #dc3545, #a71d2a); color: white; }
        .bg-receh { background: white; color: var(--bri-blue); border: 2px solid #e6f0ff; }
        .metric-content { position: relative; z-index: 1; }
        .btn-input-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 50px; padding: 12px 28px; font-weight: 700; border: none; }
        .btn-bos { background: linear-gradient(135deg, #198754, #146c43); color: white; border-radius: 50px; padding: 12px 28px; font-weight: 700; border: none; }
        .chart-container { position: relative; width: 100%; height: 260px; }
        .feature-item { text-align: center; cursor: pointer; text-decoration: none; display: block; }
        .feature-icon-wrapper { width: 65px; height: 65px; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 10px auto; background-color: #e6f0ff; color: var(--bri-blue); }
        .feature-text { font-size: 13px; font-weight: 700; color: var(--bri-black); line-height: 1.2; }
        .form-control, .form-select { border-radius: 12px; padding: 12px 15px; font-weight: 600; background-color: var(--bg-body); border: 1px solid #dee2e6; }
        .form-control:focus, .form-select:focus { border-color: var(--bri-blue); box-shadow: none; }
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .bg-light-blue-box { background-color: #e6f0ff; border-radius: 16px; }
        #print-area { display: none; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } }
        
        @media print {
            body.printing-struk #print-area { display: block !important; width: 100%; color: #000; }
            body.printing-struk .sidebar-modern, body.printing-struk .mobile-header, body.printing-struk .main-content { display: none !important; }
            body { background: white !important; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);">Halo, Semangat Bekerja! 👋</h3>
                <p class="fw-medium mb-0 text-muted">Shift <?= $shift_ke; ?> Berjalan <span class="badge bg-success ms-2">Aktif</span></p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-bos shadow-sm" data-bs-toggle="modal" data-bs-target="#modalSetorBos">
                    <i class="bi bi-box-arrow-in-down me-1"></i> Terima Setoran Bos
                </button>
                <button type="button" class="btn-input-modern fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalInput">
                    <i class="bi bi-plus-lg me-1"></i> Input Transaksi
                </button>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="metric-card bg-saldo">
                    <div class="metric-content">
                        <div class="d-flex justify-content-between align-items-start">
                            <p class="mb-1 fw-semibold small opacity-75 text-uppercase">Total Laci Fisik (CASH)</p>
                            <button class="btn btn-sm text-white border border-light rounded-circle" data-bs-toggle="modal" data-bs-target="#modalKoreksiLaci" style="opacity: 0.8; padding: 2px 6px;">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                        <h3 class="fw-bolder mb-1">Rp <?= number_format($saldo_fisik_sekarang, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-wallet2 bg-icon"></i>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <div class="metric-card bg-mbanking">
                    <div class="metric-content">
                        <div class="d-flex justify-content-between align-items-start">
                            <p class="mb-1 fw-semibold small opacity-75 text-uppercase">Saldo <?= $jumlah_rek_valid ?> Rekening</p>
                            <?php if($jumlah_rek_valid > 0): ?>
                            <button class="btn btn-sm text-white border border-light rounded-circle" data-bs-toggle="modal" data-bs-target="#modalDetailSaldoBank" style="opacity: 0.8; padding: 2px 6px;">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <h3 class="fw-bolder mb-1">Rp <?= number_format($total_saldo_digital, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-phone bg-icon"></i>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <div class="metric-card bg-admin">
                    <div class="metric-content">
                        <p class="mb-1 fw-bold small text-uppercase" style="opacity: 0.7;">Laba Hari Ini</p>
                        <h3 class="fw-bolder mb-0">Rp <?= number_format($admin_hari_ini, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-graph-up-arrow bg-icon" style="color: var(--bri-blue); opacity: 0.1;"></i>
                </div>
            </div>
            
            <div class="col-12 col-sm-6 col-lg-6">
                <div class="metric-card bg-danger-grad">
                    <div class="metric-content">
                        <p class="mb-1 fw-bold small opacity-75 text-uppercase">Kerugian Laci / Rugi</p>
                        <h3 class="fw-bolder mb-0">Rp <?= number_format($rugi_hari_ini, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-graph-down-arrow bg-icon"></i>
                </div>
            </div>
            
            <div class="col-12 col-sm-12 col-lg-6">
                <div class="metric-card bg-receh">
                    <div class="metric-content">
                        <p class="mb-1 fw-bold small text-uppercase" style="opacity: 0.6;">Stok Receh</p>
                        <h3 class="fw-bolder mb-0">Rp <?= number_format($total_uang_receh, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-coin bg-icon" style="color: var(--bri-blue); opacity: 0.05;"></i>
                </div>
            </div>
        </div>

        <div class="modern-card mb-4">
            <h5 class="fw-bold text-dark mb-4 border-bottom pb-3">Layanan Transaksi Utama</h5>
            <div class="row row-cols-3 row-cols-sm-4 row-cols-md-5 row-cols-lg-12 g-3 justify-content-center">
                <div class="col"><div class="feature-item" onclick="bukaModal('Tarik Tunai')"><div class="feature-icon-wrapper text-danger"><i class="bi bi-arrow-down-circle"></i></div><div class="feature-text">Tarik Tunai</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Setor Tunai')"><div class="feature-icon-wrapper text-success"><i class="bi bi-arrow-up-circle"></i></div><div class="feature-text">Setor Tunai</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Transfer')"><div class="feature-icon-wrapper text-primary"><i class="bi bi-send"></i></div><div class="feature-text">Transfer</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Buka Rekening Baru')"><div class="feature-icon-wrapper" style="color: #20c997;"><i class="bi bi-person-vcard"></i></div><div class="feature-text">Buka Rekening</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Tukar Uang Receh')"><div class="feature-icon-wrapper text-secondary"><i class="bi bi-arrow-left-right"></i></div><div class="feature-text">Tukar Receh</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('TopUp E-Wallet')"><div class="feature-icon-wrapper text-info"><i class="bi bi-phone"></i></div><div class="feature-text">Isi E-Wallet</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Pulsa / Data')"><div class="feature-icon-wrapper text-warning"><i class="bi bi-broadcast"></i></div><div class="feature-text">Pulsa / Data</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Token Listrik')"><div class="feature-icon-wrapper text-warning"><i class="bi bi-lightning-charge"></i></div><div class="feature-text">Token Listrik</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('PDAM')"><div class="feature-icon-wrapper" style="color: #0dcaf0;"><i class="bi bi-droplet"></i></div><div class="feature-text">Bayar PDAM</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Cicilan Finance')"><div class="feature-icon-wrapper" style="color: #6610f2;"><i class="bi bi-bank"></i></div><div class="feature-text">Cicilan Fin</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Pengeluaran / Rugi')"><div class="feature-icon-wrapper text-danger icon-danger"><i class="bi bi-journal-x"></i></div><div class="feature-text">Pengeluaran</div></div></div>
                <div class="col"><div class="feature-item" onclick="bukaModal('Tarik Dana Bos')"><div class="feature-icon-wrapper bg-warning text-dark"><i class="bi bi-person-badge"></i></div><div class="feature-text">Tarik Dana Bos</div></div></div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="modern-card h-100">
                    <h5 class="fw-bold mb-4">Pergerakan Laba Admin (7 Hari)</h5>
                    <div class="chart-container"><canvas id="pendapatanChart"></canvas></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="modern-card h-100">
                    <h5 class="fw-bold mb-4">Aktivitas Layanan Utama (7 Hari)</h5>
                    <div class="chart-container"><canvas id="layananChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

<div class="modal fade" id="modalDetailSaldoBank" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bolder"><i class="bi bi-phone me-2 text-success"></i>Sisa Saldo</h5>
                <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="list-group list-group-flush mb-2">
                    <?php foreach($saldo_per_bank as $bank_name => $sisa_saldo): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-3">
                        <span class="fw-bold text-secondary"><?= htmlspecialchars($bank_name); ?></span>
                        <span class="fw-bolder fs-6 text-success">Rp <?= number_format($sisa_saldo, 0, ',', '.'); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalKoreksiLaci" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bolder text-primary"><i class="bi bi-wallet2 me-2"></i>Koreksi Laci</h5>
                <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="shift_id" value="<?= $sid; ?>">
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">Sisa uang fisik Anda sekarang?</label>
                        <input type="text" name="modal_baru" class="form-control form-control-lg fw-bold format-rupiah fs-4" required placeholder="0">
                    </div>
                    <button type="submit" name="koreksi_laci" class="btn-input-modern w-100 fw-bold fs-5 py-3">Simpan</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalInput" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bolder">Input Transaksi</h5>
                <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formTransaksi" method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Jenis Layanan</label>
                        <select name="jenis_transaksi" id="selectJenis" class="form-select form-select-lg" required>
                            <option value="" disabled selected>-- Pilih Layanan --</option>
                            <?php
                            $q_list_layanan = $conn->query("SELECT nama_layanan FROM layanan ORDER BY nama_layanan ASC");
                            if($q_list_layanan) {
                                while($lay = $q_list_layanan->fetch_assoc()) { echo "<option value='{$lay['nama_layanan']}'>{$lay['nama_layanan']}</option>"; }
                            }
                            ?>
                            <option value="Pengeluaran / Rugi">Pengeluaran / Rugi</option>
                            <option value="Tarik Dana Bos" class="text-danger fw-bold">🔽 Tarik Dana Bos (Prive)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="formBankAgen">
                        <label class="form-label text-muted small fw-bold">Sumber Dana / Tujuan</label>
                        <select name="bank_agen" id="selectBankAgen" class="form-select form-select-lg" required>
                            <option value="" disabled selected>-- Pilih Rekening --</option>
                            <option value="CASH" class="text-success fw-bold">💵 LACI FISIK (CASH)</option>
                            <?php foreach($valid_banks as $bank): ?>
                                <option value="<?= htmlspecialchars($bank) ?>" class="text-primary fw-bold">🏦 REK. <?= strtoupper(htmlspecialchars($bank)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="formPecahan" style="display: none;" class="mb-3 p-3 bg-light-blue-box border-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-2 text-center text-nowrap border-0" style="font-size: 13px;">
                                <thead class="fw-bold border-0 text-primary"><tr><th class="border-0 text-start ps-3">Pecahan</th><th class="text-danger border-0">Keluar (Lbr)</th></tr></thead>
                                <tbody>
                                    <?php 
                                    $pecahan_list = [100000=>['label'=>'100k','col'=>'qty_100k'], 50000=>['label'=>'50k','col'=>'qty_50k'], 20000=>['label'=>'20k','col'=>'qty_20k'], 10000=>['label'=>'10k','col'=>'qty_10k'], 5000=>['label'=>'5k','col'=>'qty_5k'], 2000=>['label'=>'2k','col'=>'qty_2k'], 1000=>['label'=>'1k','col'=>'qty_1k']];
                                    foreach ($pecahan_list as $nilai => $item): 
                                        $sisa = isset($stok_receh[$item['col']]) ? $stok_receh[$item['col']] : 0;
                                    ?>
                                    <tr>
                                        <td class="fw-bold align-middle border-0 text-start ps-3">
                                            <?= $item['label']; ?> <br><small class="text-muted fw-normal" style="font-size:10px;">Sisa: <?= $sisa; ?></small>
                                        </td>
                                        <td class="border-0"><input type="number" name="out_<?= $item['label']; ?>" class="form-control form-control-sm out-pecahan text-center rounded-3 border-0 mx-auto" data-nilai="<?= $nilai; ?>" data-stok="<?= $sisa; ?>" data-label="<?= $item['label']; ?>" min="0" placeholder="0" style="max-width:120px;"></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="alertStokLimit" class="alert alert-danger px-3 py-2 mt-2 mb-1 rounded-3 text-center fw-bold" style="display:none; font-size:12px;"></div>
                        <div class="text-center px-3 py-2 small fw-bolder mt-2 bg-white rounded-3 text-danger">Total Ditukar: <span id="totOut">Rp 0</span></div>
                    </div>

                    <div id="dynamicServiceFields" class="mb-3"></div>

                    <div class="row g-3">
                        <div class="col-7 mb-3">
                            <label class="form-label text-muted small fw-bold" id="labelNominal">Nominal Uang (Rp)</label>
                            <input type="text" id="inputNominal" name="nominal" class="form-control form-control-lg fw-bold format-rupiah text-dark" required placeholder="0">
                        </div>
                        <div class="col-5 mb-3">
                            <label class="form-label text-muted small fw-bold">Biaya Admin</label>
                            <input type="text" id="inputAdmin" name="admin_fee" class="form-control form-control-lg fw-bold format-rupiah text-primary" required placeholder="0">
                        </div>
                    </div>

                    <div class="mb-4 p-3 bg-light-blue-box rounded-3 d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-primary">Total Tagihan</span>
                        <span class="fw-bolder fs-3 text-dark">Rp 0</span>
                    </div>

                    <div class="mb-4">
                        <label id="labelKeterangan" class="form-label text-muted small fw-bold">No Tujuan / Keterangan</label>
                        <input type="text" id="inputKeterangan" name="keterangan" class="form-control form-control-lg" placeholder="Opsional">
                    </div>

                    <button type="submit" id="btnSimpan" name="simpan_transaksi" class="btn-input-modern w-100 fw-bold fs-5 py-3 mt-2 rounded-3">Proses Transaksi</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalSetorBos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bolder text-dark"><i class="bi bi-box-arrow-in-down text-success me-2"></i>Terima Setoran Bos</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="alert alert-success bg-opacity-10 border-success border-opacity-25 pb-0 mb-4">
                        <p class="small text-success fw-medium"><i class="bi bi-info-circle-fill me-1"></i>Pilih masuk ke LACI (CASH) jika Bos menyetor uang fisik secara langsung kepada Anda.</p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Nominal Setoran (Rp)</label>
                        <input type="text" name="nominal" class="form-control form-control-lg format-rupiah fw-bolder text-success fs-4" placeholder="0" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">Masuk Ke (Target Dana)</label>
                        <select name="target_dana" class="form-select bg-light" required>
                            <option value="CASH" class="fw-bold text-success">💵 Masuk ke Laci Fisik (CASH)</option>
                            <?php foreach($assigned_banks as $b): ?>
                                <option value="<?= htmlspecialchars($b) ?>">🏦 Masuk ke Rek. <?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Nama Penyetor</label>
                            <input type="text" name="penyetor" class="form-control" placeholder="Nama bos/wakil..." required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted small fw-bold">Catatan (Opsional)</label>
                            <input type="text" name="keterangan" class="form-control" placeholder="Misal: Tambah modal">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 px-4 pb-4">
                    <button type="submit" name="submit_setor_bos" class="btn-bos w-100 py-3 fs-5 rounded-pill shadow-sm">Simpan & Cetak Struk (4 TTD)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="print-area"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const dataSaldo = { 'CASH': <?= $saldo_fisik_sekarang; ?>, <?php foreach($valid_banks as $b) { echo "'$b': " . ($saldo_per_bank[$b] ?? 0) . ","; } ?>};

    document.querySelectorAll('.format-rupiah').forEach(function(input) {
        input.addEventListener('input', function(e) {
            let value = this.value.replace(/[^0-9]/g, '');
            if (value !== '') { this.value = new Intl.NumberFormat('id-ID').format(value); } 
            else { this.value = ''; }
            if (this.id === 'inputNominal' || this.id === 'inputAdmin') { hitungTotal(); }
        });
    });

    const formatRp = (angka) => new Intl.NumberFormat('id-ID').format(angka);

    function sesuaikanForm(jenis) {
        let formPecahan = document.getElementById('formPecahan');
        let formBank = document.getElementById('formBankAgen'); 
        let selectBank = document.getElementById('selectBankAgen');
        let dynamicFields = document.getElementById('dynamicServiceFields');
        let inputNom = document.getElementById('inputNominal');
        let inputKet = document.getElementById('inputKeterangan');
        let labelKet = document.getElementById('labelKeterangan');
        let labelNom = document.getElementById('labelNominal');
        let inputAdmin = document.getElementById('inputAdmin');

        formPecahan.style.display = 'none';
        formBank.style.display = 'block'; selectBank.required = true;
        inputNom.readOnly = false; inputKet.readOnly = false;
        inputAdmin.readOnly = false; inputAdmin.value = ''; 
        labelKet.innerHTML = 'No Tujuan / Keterangan';
        labelNom.innerHTML = 'Nominal Uang (Rp)';
        document.getElementById('btnSimpan').disabled = false;
        dynamicFields.innerHTML = "";

        if (jenis === 'Tukar Uang Receh' || jenis === 'Tukar Uang') {
            formPecahan.style.display = 'block';
            formBank.style.display = 'none'; selectBank.required = false;
            inputNom.readOnly = true; inputKet.readOnly = true;
            labelKet.innerHTML = 'Rincian Keluar (Otomatis)';
            hitungPecahan(); return;
        }

        let htmlFields = "";
        
        if (jenis === 'Tarik Dana Bos') {
            labelNom.innerHTML = 'Nominal Ditarik Bos (Rp)';
            inputAdmin.readOnly = true; inputAdmin.value = '0';
            htmlFields = `<div class="mb-3"><input type="text" id="field_general" class="form-control form-control-sm trigger-compile" placeholder="Keperluan Tarik Bos" required></div>`;
        } else if (jenis === 'Buka Rekening Baru') {
            labelNom.innerHTML = 'Setoran Awal (Rp)';
            htmlFields = `<div class="mb-3"><select id="field_jenis_rek" class="form-select form-select-sm trigger-compile" required><option value="ATM">1. ATM</option><option value="ATM + Buku Rek">2. ATM + Buku</option></select></div><div class="mb-3"><input type="text" id="field_nama_nasabah" class="form-control form-control-sm trigger-compile" placeholder="Nama Lengkap / NIK" required></div>`;
        } else if (jenis === 'Transfer') {
            htmlFields = `<div class="row g-2 mb-3"><div class="col-5"><input type="text" id="field_bank" class="form-control form-control-sm trigger-compile" required placeholder="Bank Tujuan"></div><div class="col-7"><input type="text" id="field_norek" class="form-control form-control-sm trigger-compile" placeholder="No Rek" required></div></div>`;
        } else if (jenis === 'Pengeluaran / Rugi') {
            labelNom.innerHTML = 'Nominal Pengeluaran (Rp)';
            inputAdmin.readOnly = true; inputAdmin.value = '0';
            htmlFields = `<div class="mb-3"><input type="text" id="field_general" class="form-control form-control-sm trigger-compile" placeholder="Alasan Rugi" required></div>`;
        } else {
            htmlFields = `<div class="mb-3"><input type="text" id="field_general" class="form-control form-control-sm trigger-compile" placeholder="Keterangan / Ref (Opsional)"></div>`;
        }

        dynamicFields.innerHTML = htmlFields;
        document.querySelectorAll('.trigger-compile').forEach(el => { el.addEventListener('input', compileKeterangan); el.addEventListener('change', compileKeterangan); });
        compileKeterangan();
    }

    function compileKeterangan() {
        let jenis = document.getElementById('selectJenis').value;
        let inputKet = document.getElementById('inputKeterangan');
        if (jenis === 'Tukar Uang Receh' || jenis === 'Tukar Uang') return;
        let res = "";
        
        if (jenis === 'Tarik Dana Bos') res = "Keperluan Bos: " + (document.getElementById('field_general') ? document.getElementById('field_general').value : '');
        else if (jenis === 'Transfer') res = "Bank: " + (document.getElementById('field_bank') ? document.getElementById('field_bank').value : '') + " | Rek: " + (document.getElementById('field_norek') ? document.getElementById('field_norek').value : '');
        else if (jenis === 'Pengeluaran / Rugi') res = "Rugi/Keluar: " + (document.getElementById('field_general') ? document.getElementById('field_general').value : '');
        else res = document.getElementById('field_general') ? document.getElementById('field_general').value : '';
        
        inputKet.value = res;
    }

    function hitungPecahan() {
        let totalOut = 0; let detailOut = []; let validStok = true; let errMsg = "";
        let alertBox = document.getElementById('alertStokLimit');

        document.querySelectorAll('.out-pecahan').forEach(el => {
            let qty = parseInt(el.value) || 0; let nilai = parseInt(el.getAttribute('data-nilai'));
            let stokMax = parseInt(el.getAttribute('data-stok')); let label = el.getAttribute('data-label');
            if (qty > 0) { 
                if (qty > stokMax) { validStok = false; errMsg += `• Sisa stok uang ${label} hanya ${stokMax} lbr! <br>`; el.classList.add('input-error'); }
                else { el.classList.remove('input-error'); totalOut += (qty * nilai); detailOut.push((nilai >= 1000 ? nilai/1000 + 'k' : nilai) + "x" + qty); }
            } else { el.classList.remove('input-error'); }
        });

        document.getElementById('totOut').innerText = 'Rp ' + formatRp(totalOut);
        let btnSimpan = document.getElementById('btnSimpan');
        
        if (!validStok) { 
            btnSimpan.disabled = true; alertBox.innerHTML = errMsg; alertBox.style.display = 'block'; 
            document.getElementById('inputNominal').value = ''; document.getElementById('inputKeterangan').value = ''; 
        } else if (totalOut > 0) { 
            btnSimpan.disabled = false; alertBox.style.display = 'none'; 
            document.getElementById('inputNominal').value = new Intl.NumberFormat('id-ID').format(totalOut);
            document.getElementById('inputKeterangan').value = "Keluar: " + detailOut.join(', '); 
        } else { 
            btnSimpan.disabled = true; alertBox.style.display = 'none'; 
            document.getElementById('inputNominal').value = ''; document.getElementById('inputKeterangan').value = ''; 
        }
        hitungTotal();
    }
    document.querySelectorAll('.out-pecahan').forEach(item => { item.addEventListener('input', hitungPecahan); });

    function bukaModal(jenisLayanan) {
        let selectEl = document.getElementById('selectJenis');
        if (!Array.from(selectEl.options).some(opt => opt.value === jenisLayanan)) selectEl.add(new Option(jenisLayanan, jenisLayanan));
        selectEl.value = jenisLayanan; document.getElementById('inputNominal').value = ''; document.getElementById('inputAdmin').value = ''; document.getElementById('inputKeterangan').value = ''; document.getElementById('alertStokLimit').style.display = 'none';
        document.getElementById('selectBankAgen').value = ''; 
        document.querySelectorAll('.out-pecahan').forEach(item => { item.value = ''; item.classList.remove('input-error'); }); document.querySelector('.mb-4.p-3 span:last-child').innerText = 'Rp 0';
        sesuaikanForm(jenisLayanan); new bootstrap.Modal(document.getElementById('modalInput')).show();
    }
    document.getElementById('selectJenis').addEventListener('change', function() { sesuaikanForm(this.value); });
    
    function hitungTotal() {
        let nRaw = document.getElementById('inputNominal').value.replace(/[^0-9]/g, '');
        let aRaw = document.getElementById('inputAdmin').value.replace(/[^0-9]/g, '');
        document.querySelector('.mb-4.p-3 span:last-child').innerText = 'Rp ' + formatRp((parseFloat(nRaw) || 0) + (parseFloat(aRaw) || 0));
    }

    document.getElementById('formTransaksi').addEventListener('submit', function(e) {
        let jenis = document.getElementById('selectJenis').value;
        let bank = document.getElementById('selectBankAgen').value;
        let nominal = parseFloat(document.getElementById('inputNominal').value.replace(/[^0-9]/g, '')) || 0;

        if (jenis === 'Tarik Tunai' || (jenis === 'Pengeluaran / Rugi' && bank === 'CASH') || (jenis === 'Tarik Dana Bos' && bank === 'CASH')) {
            if (nominal > dataSaldo['CASH']) { e.preventDefault(); Swal.fire({ icon: 'error', title: 'Uang Laci Kurang!', text: 'Sisa uang fisik Anda hanya Rp ' + formatRp(dataSaldo['CASH']) + '.', confirmButtonColor: '#d33' }); }
        } else if (jenis !== 'Tukar Uang Receh' && jenis !== 'Tukar Uang') {
            if (bank && bank !== 'CASH') {
                if (nominal > dataSaldo[bank]) { e.preventDefault(); Swal.fire({ icon: 'error', title: 'Saldo Rekening Kurang!', text: 'Sisa saldo rekening ' + bank + ' Anda hanya Rp ' + formatRp(dataSaldo[bank]) + '.', confirmButtonColor: '#d33' }); }
            }
        }
    });

    function setPrintPageStyle(styleRule) {
        let style = document.getElementById('dynamic-print-style');
        if (!style) { style = document.createElement('style'); style.id = 'dynamic-print-style'; document.head.appendChild(style); }
        style.innerHTML = styleRule;
    }

    function cetakStrukSetoranBos(id, tanggal, penyetor, target, nominal, kasir) {
        setPrintPageStyle('@page { size: A5 landscape; margin: 15mm; }');
        
        let htmlPrint = `
            <style>
                #print-area { font-family: 'Arial', sans-serif; font-size: 13px; line-height: 1.5; color: #000; width: 100%; max-width: 185mm; margin: auto; }
                .hdr-bos { text-align: center; border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 20px; }
                .hdr-bos h2 { margin: 0; font-size: 22px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase; }
                .hdr-bos p { margin: 3px 0 0 0; font-size: 12px; }
                .tb-info { width: 100%; margin-bottom: 20px; font-size: 13px; }
                .tb-info td { padding: 4px 5px; vertical-align: middle; }
                .lbl { font-weight: bold; width: 130px; }
                .val { border-bottom: 1px dashed #000; }
                .box-nominal { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; border: 2px solid #000; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; margin-bottom: 30px; border-radius: 5px;}
                .box-nominal span { font-size: 12px; font-weight: normal; display: block; margin-bottom: 5px; }
                .sign-grid { display: flex; justify-content: space-between; text-align: center; margin-top: 50px; font-size: 12px; }
                .sign-col { width: 22%; display: flex; flex-direction: column; justify-content: space-between; }
                .sign-line { border-bottom: 1px solid #000; margin-top: 60px; font-weight: bold; padding-bottom: 3px; display: block; }
            </style>
            <div class="hdr-bos">
                <h2>BUKTI SETORAN DANA BOS</h2>
                <p>INJEKSI PENAMBAHAN MODAL SHIFT KASIR</p>
            </div>
            <table class="tb-info">
                <tr><td class="lbl">No. Referensi</td><td width="10">:</td><td class="val"><strong>STB-${id}</strong></td><td width="30"></td><td class="lbl">Masuk Ke</td><td width="10">:</td><td class="val" style="font-size: 15px;"><strong>${target}</strong></td></tr>
                <tr><td class="lbl">Tanggal / Waktu</td><td>:</td><td class="val">${tanggal}</td><td></td><td class="lbl">Nama Penyetor</td><td>:</td><td class="val">${penyetor}</td></tr>
            </table>
            <div class="box-nominal"><span>JUMLAH MODAL DITERIMA</span>Rp ${nominal}</div>
            <div class="sign-grid">
                <div class="sign-col"><span>Penyetor,</span><span class="sign-line">${penyetor}</span></div>
                <div class="sign-col"><span>Shift BRILink,</span><span class="sign-line">${kasir}</span></div>
                <div class="sign-col"><span>Manajer,</span><span class="sign-line">(..........................)</span></div>
                <div class="sign-col"><span>Bos / Pimpinan,</span><span class="sign-line">(..........................)</span></div>
            </div>
        `;
        let printArea = document.getElementById('print-area');
        printArea.innerHTML = htmlPrint;
        document.body.classList.add('printing-struk');
        setTimeout(function() { window.print(); }, 500);
        window.onafterprint = function() { document.body.classList.remove('printing-struk'); printArea.innerHTML = ''; };
    }

    <?php if (isset($_SESSION['flash_error'])): ?>Swal.fire({ icon: 'error', title: 'Oops...', text: '<?= $_SESSION['flash_error']; ?>', confirmButtonColor: '#00529C' });<?php unset($_SESSION['flash_error']); endif; ?>
    <?php if (isset($_SESSION['flash_success']) && !isset($_SESSION['print_setor_bos'])): ?>Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, icon: 'success', title: '<?= $_SESSION['flash_success']; ?>' });<?php unset($_SESSION['flash_success']); endif; ?>

    <?php if (isset($_SESSION['print_setor_bos'])): $p = $_SESSION['print_setor_bos']; ?>
        Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, icon: 'success', title: '<?= $_SESSION['flash_success'] ?? "Setoran berhasil!" ?>' });
        cetakStrukSetoranBos('<?= str_pad($p['id'], 5, '0', STR_PAD_LEFT) ?>', '<?= $p['tanggal'] ?>', '<?= addslashes($p['penyetor']) ?>', '<?= addslashes($p['target']) ?>', '<?= $p['nominal'] ?>', '<?= addslashes($p['kasir']) ?>');
    <?php unset($_SESSION['print_setor_bos'], $_SESSION['flash_success']); endif; ?>
    
    const ctxLaba = document.getElementById('pendapatanChart').getContext('2d');
    new Chart(ctxLaba, { type: 'line', data: { labels: <?= json_encode($grafik_tanggal); ?>, datasets: [{ label: 'Pendapatan', data: <?= json_encode($grafik_pendapatan); ?>, borderColor: '#00529C', backgroundColor: 'rgba(0, 82, 156, 0.1)', borderWidth: 3, fill: true, tension: 0.4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { border: {display: false}, beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } } });
    const ctxLayanan = document.getElementById('layananChart').getContext('2d');
    new Chart(ctxLayanan, { type: 'line', data: { labels: <?= json_encode($grafik_tanggal); ?>, datasets: [ { label: 'Tarik Tunai', data: <?= json_encode($grafik_tarik); ?>, borderColor: '#dc3545', backgroundColor: 'transparent', borderWidth: 3, tension: 0.4 }, { label: 'Setor Tunai', data: <?= json_encode($grafik_setor); ?>, borderColor: '#198754', backgroundColor: 'transparent', borderWidth: 3, tension: 0.4 }, { label: 'Transfer', data: <?= json_encode($grafik_transfer); ?>, borderColor: '#00529C', backgroundColor: 'transparent', borderWidth: 3, tension: 0.4 } ] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } } }, scales: { y: { beginAtZero: true, border: {display: false}, ticks: { stepSize: 1 } }, x: { grid: { display: false }, border: {display: false} } } } });
</script>
</body>
</html>