<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

$q_user = $conn->query("SELECT cabang_id, assigned_banks FROM users WHERE id = '$user_id'");
$row_user = $q_user->fetch_assoc();
$cabang_id = !empty($row_user['cabang_id']) ? $row_user['cabang_id'] : 0;

$assigned_banks_str = $row_user['assigned_banks'] ?? '';
$assigned_banks = $assigned_banks_str ? array_map('trim', explode(',', $assigned_banks_str)) : [];

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
$cabang_dibekukan = ($q_cek_beku->num_rows > 0);

$q_shift_aktif = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$status_shift_aktif = ($q_shift_aktif->num_rows > 0);
$shift_data = $q_shift_aktif->fetch_assoc();
$sid = $shift_data ? $shift_data['id'] : 0;

// =========================================================================
// 1. AUTO-UPGRADE DATABASE & AUTO-RESET STOK RECEH HARIAN 
// =========================================================================
$check_receh = $conn->query("SHOW COLUMNS FROM uang_receh LIKE 'last_updated'");
if ($check_receh && $check_receh->num_rows == 0) {
    $conn->query("ALTER TABLE uang_receh ADD last_updated DATE NULL DEFAULT NULL");
    $conn->query("UPDATE uang_receh SET last_updated = '$tanggal_hari_ini'");
}

$q_stok_receh = $conn->query("SELECT * FROM uang_receh WHERE cabang_id = '$cabang_id'");
$stok_receh = $q_stok_receh->fetch_assoc();

if (!$stok_receh) {
    $conn->query("INSERT INTO uang_receh (cabang_id, qty_100k, qty_50k, qty_20k, qty_10k, qty_5k, qty_2k, qty_1k, total, last_updated) 
                  VALUES ('$cabang_id', 0, 0, 0, 0, 0, 0, 0, 0, '$tanggal_hari_ini')");
    $stok_receh = ['qty_100k'=>0, 'qty_50k'=>0, 'qty_20k'=>0, 'qty_10k'=>0, 'qty_5k'=>0, 'qty_2k'=>0, 'qty_1k'=>0, 'total'=>0];
} else {
    if ($stok_receh['last_updated'] != $tanggal_hari_ini) {
        $conn->query("UPDATE uang_receh SET 
            qty_100k=0, qty_50k=0, qty_20k=0, qty_10k=0, qty_5k=0, qty_2k=0, qty_1k=0, total=0, 
            last_updated='$tanggal_hari_ini' 
            WHERE cabang_id = '$cabang_id'");
            
        $stok_receh = ['qty_100k'=>0, 'qty_50k'=>0, 'qty_20k'=>0, 'qty_10k'=>0, 'qty_5k'=>0, 'qty_2k'=>0, 'qty_1k'=>0, 'total'=>0];
    }
}

// =========================================================================
// 2. PERHITUNGAN SALDO FISIK, DIGITAL & KERUGIAN 
// =========================================================================
$modal_awal_laci = $shift_data ? (float)$shift_data['modal_awal'] : 0;

$saldo_fisik_sekarang = 0;
$total_saldo_digital = 0;
$admin_hari_ini = 0;
$rugi_hari_ini = 0;
$saldo_per_bank = []; 

if ($status_shift_aktif) {
    $q_in_cash = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi')");
    $uang_masuk = $q_in_cash->fetch_assoc()['total'] ?? 0;

    $q_keluar = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$sid' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi = 'Pengeluaran / Rugi' AND bank_agen = 'CASH'))");
    $uang_keluar = $q_keluar->fetch_assoc()['total'] ?? 0;

    $q_admin_fee = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$sid'");
    $admin_hari_ini = $q_admin_fee->fetch_assoc()['total'] ?? 0;

    $q_rugi = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi = 'Pengeluaran / Rugi'");
    $rugi_hari_ini = $q_rugi->fetch_assoc()['total'] ?? 0;

    $saldo_fisik_sekarang = $modal_awal_laci + $uang_masuk - $uang_keluar + $admin_hari_ini;
    
    foreach($valid_banks as $b_name) {
        $b_col = $db_map[$b_name];
        
        $q_in_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi = 'Tarik Tunai' AND bank_agen = '$b_name'");
        $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang') AND bank_agen = '$b_name'");
        
        $modal_bank = isset($shift_data[$b_col]) ? (float)$shift_data[$b_col] : 0;
        
        $saldo_bank_ini = $modal_bank + ($q_in_b->fetch_assoc()['tot'] ?? 0) - ($q_out_b->fetch_assoc()['tot'] ?? 0);
        $saldo_per_bank[$b_name] = $saldo_bank_ini;
        $total_saldo_digital += $saldo_bank_ini;
    }
}

// --- PROSES KOREKSI LACI (OTOMATIS MENCATAT RUGI) ---
if (isset($_POST['koreksi_laci'])) {
    $shift_id_aktif = $_POST['shift_id'];
    $saldo_riil_baru = (float)str_replace('.', '', $_POST['modal_baru']);
    
    $q_in = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id_aktif' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi')")->fetch_assoc()['tot'] ?? 0;
    $q_out = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id_aktif' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi = 'Pengeluaran / Rugi' AND bank_agen = 'CASH'))")->fetch_assoc()['tot'] ?? 0;
    $q_adm = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$shift_id_aktif'")->fetch_assoc()['tot'] ?? 0;
    $m_awal = $conn->query("SELECT modal_awal FROM shifts WHERE id = '$shift_id_aktif'")->fetch_assoc()['modal_awal'] ?? 0;
    
    $expected_cash = $m_awal + $q_in - $q_out + $q_adm;

    if ($saldo_riil_baru < $expected_cash) {
        $selisih = $expected_cash - $saldo_riil_baru;
        $conn->query("INSERT INTO transactions (user_id, shift_id, tanggal, jenis_transaksi, bank_agen, nominal, admin_fee, keterangan) VALUES ('$user_id', '$shift_id_aktif', '$tanggal_hari_ini', 'Pengeluaran / Rugi', 'CASH', '$selisih', 0, 'Selisih Kurang Laci Fisik')");
        $_SESSION['flash_error'] = "Selisih kurang Rp " . number_format($selisih, 0, ',', '.') . " otomatis dicatat sebagai KERUGIAN/MINUS.";
    } elseif ($saldo_riil_baru > $expected_cash) {
        $selisih = $saldo_riil_baru - $expected_cash;
        $conn->query("UPDATE shifts SET modal_awal = modal_awal + $selisih WHERE id = '$shift_id_aktif'");
        $_SESSION['flash_success'] = "Uang fisik lebih Rp " . number_format($selisih, 0, ',', '.') . " ditambahkan otomatis ke Modal Awal.";
    } else {
        $_SESSION['flash_success'] = "Saldo laci sudah sesuai (Balance).";
    }
    header("Location: user_dashboard.php"); exit;
}

// --- PROSES SIMPAN TRANSAKSI (DENGAN VALIDASI) ---
if (isset($_POST['simpan_transaksi']) && $status_shift_aktif && !$cabang_dibekukan) {
    $jenis   = $_POST['jenis_transaksi'];
    $ket     = $_POST['keterangan'];
    $bank_agen = isset($_POST['bank_agen']) ? $_POST['bank_agen'] : '-';
    $nominal = !empty($_POST['nominal']) ? (float)str_replace('.', '', $_POST['nominal']) : 0;
    $admin   = !empty($_POST['admin_fee']) ? (float)str_replace('.', '', $_POST['admin_fee']) : 0;

    $validasi_lolos = true;
    $pesan_error = "";
    
    if ($jenis == 'Tarik Tunai' || ($jenis == 'Pengeluaran / Rugi' && $bank_agen == 'CASH')) {
        if ($nominal > $saldo_fisik_sekarang) {
            $validasi_lolos = false;
            $pesan_error = "Transaksi Ditolak! Uang di Laci Fisik Anda tidak cukup.";
        }
    } else if ($jenis != 'Tukar Uang Receh' && $jenis != 'Tukar Uang') {
        if ($bank_agen != 'CASH' && $bank_agen != '-') {
            $saldo_bank_terpilih = $saldo_per_bank[$bank_agen] ?? 0;
            if ($nominal > $saldo_bank_terpilih) {
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
        $jenis = 'Tukar Uang'; 
        $bank_agen = '-';

        $out_100k = !empty($_POST['out_100k']) ? (int)$_POST['out_100k'] : 0;
        $out_50k  = !empty($_POST['out_50k']) ? (int)$_POST['out_50k'] : 0;
        $out_20k  = !empty($_POST['out_20k']) ? (int)$_POST['out_20k'] : 0;
        $out_10k  = !empty($_POST['out_10k']) ? (int)$_POST['out_10k'] : 0;
        $out_5k   = !empty($_POST['out_5k']) ? (int)$_POST['out_5k'] : 0;
        $out_2k   = !empty($_POST['out_2k']) ? (int)$_POST['out_2k'] : 0;
        $out_1k   = !empty($_POST['out_1k']) ? (int)$_POST['out_1k'] : 0;

        $net_total = (-$out_100k * 100000) + (-$out_50k * 50000) + (-$out_20k * 20000) + (-$out_10k * 10000) + (-$out_5k * 5000) + (-$out_2k * 2000) + (-$out_1k * 1000);

        $conn->query("UPDATE uang_receh SET 
            qty_100k = qty_100k - $out_100k, qty_50k = qty_50k - $out_50k, qty_20k = qty_20k - $out_20k,
            qty_10k = qty_10k - $out_10k, qty_5k = qty_5k - $out_5k, qty_2k = qty_2k - $out_2k,
            qty_1k = qty_1k - $out_1k, total = total + $net_total, 
            last_updated = '$tanggal_hari_ini' 
            WHERE cabang_id = '$cabang_id'");

        $q_nama_cab = $conn->query("SELECT nama_cabang FROM cabang WHERE id = '$cabang_id'");
        if (function_exists('cekDanNotifReceh')) { cekDanNotifReceh($conn, $cabang_id, $q_nama_cab->fetch_assoc()['nama_cabang']); }
    }
    
    if ($jenis == 'Buka Rekening Baru') { $jenis = 'Buka Rekening'; }

    $stmt = $conn->prepare("INSERT INTO transactions (user_id, shift_id, tanggal, jenis_transaksi, bank_agen, nominal, admin_fee, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssdds", $user_id, $sid, $tanggal_hari_ini, $jenis, $bank_agen, $nominal, $admin, $ket);
    
    if($stmt->execute()) { 
        $_SESSION['flash_success'] = "Transaksi Berhasil Disimpan!";
    } else {
        $_SESSION['flash_error'] = "Gagal memproses transaksi. Cek kembali form Anda.";
    }
    header("Location: user_dashboard.php"); exit; 
}

$total_uang_receh = $stok_receh['total'] ?? 0;

// =========================================================================
// 3. MENYIAPKAN DATA UNTUK GRAFIK (LABA & LAYANAN UTAMA)
// =========================================================================
$grafik_tanggal = []; 
$grafik_pendapatan = [];
$grafik_tarik = [];
$grafik_setor = [];
$grafik_transfer = [];

for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i days"));
    $grafik_tanggal[] = date('d/m', strtotime($tgl));
    
    $q_chart = $conn->query("SELECT IFNULL(SUM(t.admin_fee), 0) as harian FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl'");
    $grafik_pendapatan[] = (float)$q_chart->fetch_assoc()['harian'];

    $q_tarik = $conn->query("SELECT COUNT(t.id) as jml FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl' AND t.jenis_transaksi = 'Tarik Tunai'");
    $grafik_tarik[] = (int)$q_tarik->fetch_assoc()['jml'];

    $q_setor = $conn->query("SELECT COUNT(t.id) as jml FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl' AND t.jenis_transaksi = 'Setor Tunai'");
    $grafik_setor[] = (int)$q_setor->fetch_assoc()['jml'];

    $q_tf = $conn->query("SELECT COUNT(t.id) as jml FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl' AND t.jenis_transaksi = 'Transfer'");
    $grafik_transfer[] = (int)$q_tf->fetch_assoc()['jml'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Dashboard Kasir BRILink</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-light-blue: #e6f0ff; --bri-black: #1a1a1a; --bri-white: #ffffff; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        .metric-card { border-radius: 24px; border: none; padding: 24px; position: relative; overflow: hidden; height: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.04); transition: all 0.3s ease; }
        .metric-card:hover { transform: translateY(-5px); box-shadow: 0 15px 25px rgba(0,0,0,0.08); }
        .metric-card i.bg-icon { position: absolute; right: -15px; bottom: -20px; font-size: 110px; opacity: 0.15; transform: rotate(-10deg); transition: all 0.4s ease; z-index: 0; }
        
        .bg-saldo { background: linear-gradient(135deg, #003366 0%, var(--bri-blue) 100%); color: var(--bri-white); }
        .bg-mbanking { background: linear-gradient(135deg, #198754 0%, #20c997 100%); color: white; }
        .bg-admin { background: linear-gradient(135deg, #b3d4ff 0%, var(--bri-light-blue) 100%); color: var(--bri-blue); }
        .bg-danger-grad { background: linear-gradient(135deg, #dc3545 0%, #a71d2a 100%); color: white; }
        .bg-receh { background: var(--bri-white); color: var(--bri-blue); border: 2px solid var(--bri-light-blue); }
        
        .metric-content { position: relative; z-index: 1; }
        .btn-input-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); border-radius: 50px; padding: 12px 28px; transition: all 0.3s ease; }
        .btn-input-modern:hover { transform: translateY(-2px); box-shadow: 0 12px 20px rgba(0, 82, 156, 0.4); color: var(--bri-white); }
        .chart-container { position: relative; width: 100%; height: 260px; }
        .feature-item { text-align: center; cursor: pointer; transition: all 0.2s ease-in-out; text-decoration: none; display: block; }
        .feature-item:hover { transform: translateY(-5px); }
        .feature-icon-wrapper { width: 65px; height: 65px; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 10px auto; box-shadow: 0 4px 12px rgba(0,0,0,0.04); transition: all 0.3s ease; background-color: var(--bri-light-blue); color: var(--bri-blue); }
        .feature-item:hover .feature-icon-wrapper { box-shadow: 0 8px 20px rgba(0,82,156,0.15); background-color: var(--bri-blue); color: var(--bri-white); }
        .feature-item:hover .icon-danger { background-color: #dc3545; color: white; }
        .feature-text { font-size: 13px; font-weight: 700; color: var(--bri-black); line-height: 1.2; }
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .bg-light-blue-box { background-color: var(--bri-light-blue); border-radius: 16px; }
        .input-error { border: 2px solid #dc3545 !important; background-color: #fff3f3 !important; }
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 16px !important; }
        
        @media (min-width: 1200px) { .col-xl-auto-5 { flex: 0 0 auto; width: 20%; } }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } .btn-input-modern { width: 100%; text-align: center; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Halo, Semangat Bekerja! 👋</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;"><?= date('l, d F Y', strtotime($tanggal_hari_ini)); ?> • Slot Shift <?= isset($_SESSION['shift_ke']) ? $_SESSION['shift_ke'] : '-'; ?></p>
            </div>
            
            <?php if ($cabang_dibekukan): ?>
                <span class="badge bg-dark p-2 px-3 rounded-pill shadow-sm align-self-start"><i class="bi bi-lock-fill"></i> Cabang Tutup</span>
            <?php elseif ($status_shift_aktif): ?>
                <button type="button" class="btn-input-modern fw-bold fs-6 align-self-start" data-bs-toggle="modal" data-bs-target="#modalInput">
                    <i class="bi bi-plus-lg me-1"></i> Input Transaksi
                </button>
            <?php else: ?>
                <span class="badge bg-dark p-2 px-3 rounded-pill shadow-sm align-self-start"><i class="bi bi-exclamation-circle"></i> Set Modal Dulu</span>
            <?php endif; ?>
        </div>

        <div class="row g-3 mb-4">
            <!-- 1. Total Laci Fisik (CASH) -->
            <div class="col-12 col-sm-6 col-lg-4 col-xl-auto-5">
                <div class="metric-card bg-saldo">
                    <div class="metric-content">
                        <div class="d-flex justify-content-between align-items-start">
                            <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Total Laci Fisik (CASH)</p>
                            <?php if($status_shift_aktif): ?>
                            <button class="btn btn-sm text-white border border-light rounded-circle" data-bs-toggle="modal" data-bs-target="#modalKoreksiLaci" style="opacity: 0.8; padding: 2px 6px;" title="Koreksi Laci">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <h3 class="fw-bolder mb-1">Rp <?= number_format($saldo_fisik_sekarang, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-wallet2 bg-icon"></i>
                </div>
            </div>

            <!-- 2. Saldo Bank Digital -->
            <div class="col-12 col-sm-6 col-lg-4 col-xl-auto-5">
                <div class="metric-card bg-mbanking">
                    <div class="metric-content">
                        <div class="d-flex justify-content-between align-items-start">
                            <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Saldo <?= $jumlah_rek_valid ?> Rekening</p>
                            <?php if($status_shift_aktif && $jumlah_rek_valid > 0): ?>
                            <button class="btn btn-sm text-white border border-light rounded-circle" data-bs-toggle="modal" data-bs-target="#modalDetailSaldoBank" style="opacity: 0.8; padding: 2px 6px;" title="Lihat Rincian">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <h3 class="fw-bolder mb-1">Rp <?= number_format($total_saldo_digital, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-phone bg-icon"></i>
                </div>
            </div>

            <!-- 3. Laba Hari Ini -->
            <div class="col-12 col-sm-6 col-lg-4 col-xl-auto-5">
                <div class="metric-card bg-admin">
                    <div class="metric-content">
                        <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.7;">Laba Hari Ini</p>
                        <h3 class="fw-bolder mb-0">Rp <?= number_format($admin_hari_ini, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-graph-up-arrow bg-icon" style="color: var(--bri-blue); opacity: 0.1;"></i>
                </div>
            </div>
            
            <!-- 4. Kerugian Laci -->
            <div class="col-12 col-sm-6 col-lg-6 col-xl-auto-5">
                <div class="metric-card bg-danger-grad">
                    <div class="metric-content">
                        <p class="mb-1 fw-bold small opacity-75 text-uppercase tracking-wide">Kerugian Laci / Rugi</p>
                        <h3 class="fw-bolder mb-0">Rp <?= number_format($rugi_hari_ini, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-graph-down-arrow bg-icon"></i>
                </div>
            </div>
            
            <!-- 5. Stok Receh -->
            <div class="col-12 col-sm-12 col-lg-6 col-xl-auto-5">
                <div class="metric-card bg-receh">
                    <div class="metric-content">
                        <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.6;">Stok Receh</p>
                        <h3 class="fw-bolder mb-0">Rp <?= number_format($total_uang_receh, 0, ',', '.'); ?></h3>
                    </div>
                    <i class="bi bi-coin bg-icon" style="color: var(--bri-blue); opacity: 0.05;"></i>
                </div>
            </div>
        </div>

        <div class="modern-card mb-4">
            <h5 class="fw-bold text-dark mb-4 border-bottom pb-3" style="color: var(--bri-black) !important;">Layanan Transaksi Utama</h5>
            <div class="row row-cols-3 row-cols-sm-4 row-cols-md-5 row-cols-lg-11 g-3 justify-content-center">
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
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="modern-card h-100">
                    <h5 class="fw-bold mb-4" style="color: var(--bri-black);">Pergerakan Laba Admin (7 Hari)</h5>
                    <div class="chart-container"><canvas id="pendapatanChart"></canvas></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="modern-card h-100">
                    <h5 class="fw-bold mb-4" style="color: var(--bri-black);">Aktivitas Layanan Utama (7 Hari)</h5>
                    <div class="chart-container"><canvas id="layananChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

<?php if($status_shift_aktif): ?>
<div class="modal fade" id="modalDetailSaldoBank" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bolder" style="color: var(--bri-black);"><i class="bi bi-phone me-2 text-success"></i>Sisa Saldo Rekening Saat Ini</h5>
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
                <h5 class="modal-title fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-wallet2 me-2"></i>Koreksi Saldo Laci Saat Ini</h5>
                <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="shift_id" value="<?= $sid; ?>">
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold text-uppercase">Berapa sisa uang fisik Anda sekarang?</label>
                        <input type="text" name="modal_baru" class="form-control form-control-lg fw-bold format-rupiah" style="border-radius: 12px; font-size: 24px;" required placeholder="0">
                        <div class="mt-3 p-3 bg-light rounded-3 text-muted" style="font-size: 12px; line-height: 1.5;">
                            <i class="bi bi-info-circle text-primary me-1"></i> <strong>Sistem Otomatis:</strong> Ketik saldo fisik yang benar-benar ada di laci Anda saat ini. Jika jumlahnya kurang dari perhitungan sistem, selisih kurangnya akan dicatat sebagai <strong>Kerugian Laci</strong>.
                        </div>
                    </div>
                    <button type="submit" name="koreksi_laci" class="btn-input-modern w-100 fw-bold fs-5 py-3" style="border-radius: 16px;">Simpan Uang Laci Baru</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalInput" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                <h5 class="modal-title fw-bolder" style="color: var(--bri-black);">Input Transaksi</h5>
                <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formTransaksi" method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase tracking-wide">Jenis Layanan</label>
                        <select name="jenis_transaksi" id="selectJenis" class="form-select form-select-lg" style="border-radius: 12px; background-color: var(--bg-body);" required>
                            <option value="" disabled selected>-- Pilih Layanan --</option>
                            <?php
                            $q_list_layanan = $conn->query("SELECT nama_layanan FROM layanan ORDER BY nama_layanan ASC");
                            while($lay = $q_list_layanan->fetch_assoc()) { echo "<option value='{$lay['nama_layanan']}'>{$lay['nama_layanan']}</option>"; }
                            ?>
                            <option value="Pengeluaran / Rugi">Pengeluaran / Rugi</option>
                        </select>
                    </div>

                    <div class="mb-3" id="formBankAgen">
                        <label class="form-label text-muted small fw-bold text-uppercase tracking-wide">Sumber Dana / Tujuan</label>
                        <select name="bank_agen" id="selectBankAgen" class="form-select form-select-lg" style="border-radius: 12px;" required>
                            <option value="" disabled selected>-- Pilih Sumber Dana / Rekening --</option>
                            <option value="CASH" class="fw-bold" style="color: #198754;">💵 LACI FISIK (UANG TUNAI)</option>
                            <?php foreach($valid_banks as $bank): ?>
                                <option value="<?= htmlspecialchars($bank) ?>" class="fw-bold" style="color: #00529C;">🏦 REK. <?= strtoupper(htmlspecialchars($bank)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if(empty($valid_banks)): ?>
                            <small class="text-danger mt-2 d-block fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i> Akses rekening digital belum disetel Admin. Hanya bisa transaksi tunai.</small>
                        <?php endif; ?>
                    </div>
                    
                    <div id="formPecahan" style="display: none;" class="mb-3 p-3 bg-light-blue-box border-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-2 text-center text-nowrap border-0" style="font-size: 13px;">
                                <thead class="fw-bold border-0" style="color: var(--bri-blue);"><tr><th class="border-0 text-start ps-3">Pecahan</th><th class="text-danger border-0"><i class="bi bi-box-arrow-up"></i> Keluar (Lembar)</th></tr></thead>
                                <tbody>
                                    <?php 
                                    $pecahan_list = [100000=>['label'=>'100k','col'=>'qty_100k'], 50000=>['label'=>'50k','col'=>'qty_50k'], 20000=>['label'=>'20k','col'=>'qty_20k'], 10000=>['label'=>'10k','col'=>'qty_10k'], 5000=>['label'=>'5k','col'=>'qty_5k'], 2000=>['label'=>'2k','col'=>'qty_2k'], 1000=>['label'=>'1k','col'=>'qty_1k']];
                                    foreach ($pecahan_list as $nilai => $item): 
                                        $sisa = isset($stok_receh[$item['col']]) ? $stok_receh[$item['col']] : 0;
                                    ?>
                                    <tr>
                                        <td class="fw-bold align-middle border-0 text-start ps-3" style="color: var(--bri-black); line-height: 1.1;">
                                            <?= $item['label']; ?> <br><small class="text-muted" style="font-size: 10px; font-weight: normal;">Sisa: <?= $sisa; ?></small>
                                        </td>
                                        <td class="border-0"><input type="number" name="out_<?= $item['label']; ?>" class="form-control form-control-sm out-pecahan text-center rounded-3 border-0 shadow-sm mx-auto" data-nilai="<?= $nilai; ?>" data-stok="<?= $sisa; ?>" data-label="<?= $item['label']; ?>" min="0" placeholder="0" style="max-width: 120px;"></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="alertStokLimit" class="alert alert-danger px-3 py-2 mt-2 mb-1 rounded-3 shadow-sm text-center fw-bold" style="display: none; font-size: 12px; line-height: 1.3;"></div>
                        <div class="d-flex justify-content-center px-3 py-2 small fw-bolder mt-2 flex-wrap gap-1 bg-white rounded-3 shadow-sm" style="color: var(--bri-black);">
                            <span class="text-danger">Total Ditukar: <span id="totOut">Rp 0</span></span>
                        </div>
                    </div>

                    <div id="dynamicServiceFields" class="mb-3"></div>

                    <div class="row g-3">
                        <div class="col-7 mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase" id="labelNominal">Nominal Uang (Rp)</label>
                            <input type="text" id="inputNominal" name="nominal" class="form-control form-control-lg fw-bold format-rupiah" style="border-radius: 12px; background-color: var(--bg-body); color: var(--bri-black);" required placeholder="0">
                        </div>
                        <div class="col-5 mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Biaya Admin</label>
                            <input type="text" id="inputAdmin" name="admin_fee" class="form-control form-control-lg fw-bold format-rupiah" style="border-radius: 12px; background-color: var(--bg-body); color: var(--bri-blue);" required placeholder="0">
                        </div>
                    </div>

                    <div class="mb-4 p-3 bg-light-blue-box" style="border-radius: 16px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold small text-uppercase" style="color: var(--bri-blue);">Total Tagihan</span>
                            <span class="fw-bolder fs-3" id="teksTotal" style="color: var(--bri-black); letter-spacing: -1px;">Rp 0</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label id="labelKeterangan" class="form-label text-muted small fw-bold text-uppercase">No Tujuan / Keterangan</label>
                        <input type="text" id="inputKeterangan" name="keterangan" class="form-control form-control-lg" style="border-radius: 12px; background-color: var(--bg-body);" placeholder="Opsional">
                    </div>

                    <button type="submit" id="btnSimpan" name="simpan_transaksi" class="btn-input-modern w-100 fw-bold fs-5 py-3 mt-2" style="border-radius: 16px;">Proses Transaksi</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const dataSaldo = {
        'CASH': <?= $saldo_fisik_sekarang; ?>,
        <?php foreach($valid_banks as $b) { echo "'$b': " . ($saldo_per_bank[$b] ?? 0) . ","; } ?>};
</script>

<script>
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
        inputNom.readOnly = false; inputNom.style.backgroundColor = 'var(--bg-body)';
        inputKet.readOnly = false; inputKet.style.backgroundColor = 'var(--bg-body)';
        inputAdmin.readOnly = false; inputAdmin.value = ''; inputAdmin.style.backgroundColor = 'var(--bg-body)';
        labelKet.innerHTML = 'No Tujuan / Keterangan';
        labelNom.innerHTML = 'Nominal Uang (Rp)';
        document.getElementById('btnSimpan').disabled = false;
        dynamicFields.innerHTML = "";

        if (jenis === 'Tukar Uang Receh' || jenis === 'Tukar Uang') {
            formPecahan.style.display = 'block';
            formBank.style.display = 'none'; selectBank.required = false;
            inputNom.readOnly = true; inputNom.style.backgroundColor = '#e9ecef';
            inputKet.readOnly = true; inputKet.style.backgroundColor = '#e9ecef';
            labelKet.innerHTML = 'Rincian Lembaran Keluar (Otomatis)';
            hitungPecahan();
            return;
        }

        let htmlFields = "";
        
        // --- FITUR BARU: DROPDOWN BUKA REKENING ---
        if (jenis === 'Buka Rekening Baru') {
            labelNom.innerHTML = 'Setoran Awal (Rp)';
            htmlFields = `
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Pilih Paket Rekening</label>
                    <select id="field_jenis_rek" class="form-select form-select-sm trigger-compile" required style="border-radius: 10px;">
                        <option value="" disabled selected>-- Pilih Layanan Tabungan --</option>
                        <option value="ATM">1. ATM</option>
                        <option value="ATM + Buku Rek">2. ATM + Buku Rekening</option>
                        <option value="ATM + Buku + MBanking">3. ATM + Buku Rekening + MBanking</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Nama Nasabah / NIK</label>
                    <input type="text" id="field_nama_nasabah" class="form-control form-control-sm trigger-compile" placeholder="Nama Lengkap / NIK" required style="border-radius: 10px;">
                </div>`;
        } 
        // ------------------------------------------
        else if (jenis === 'Transfer') {
            htmlFields = `<div class="row g-2 mb-3"><div class="col-5"><label class="form-label text-muted small fw-bold text-uppercase">Bank Tujuan</label><input type="text" id="field_bank" class="form-control form-control-sm trigger-compile" required style="border-radius: 10px;" placeholder="BCA/BNI/DLL"></div><div class="col-7"><label class="form-label text-muted small fw-bold text-uppercase">No. Rekening</label><input type="text" id="field_norek" class="form-control form-control-sm trigger-compile" placeholder="Masukkan No Rek" required style="border-radius: 10px;"></div></div>`;
        } else if (jenis === 'Setor Tunai') {
            htmlFields = `<div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">No. Rekening Tujuan</label><input type="text" id="field_setor_rek" class="form-control form-control-sm trigger-compile" placeholder="Masukkan No Rek" required style="border-radius: 10px;"></div>`;
        } else if (jenis === 'TopUp E-Wallet') {
            htmlFields = `<div class="row g-2 mb-3"><div class="col-5"><label class="form-label text-muted small fw-bold text-uppercase">E-Wallet</label><input type="text" id="field_ewallet" class="form-control form-control-sm trigger-compile" required style="border-radius: 10px;" placeholder="DANA/OVO/DLL"></div><div class="col-7"><label class="form-label text-muted small fw-bold text-uppercase">No. HP / ID</label><input type="text" id="field_wallet_id" class="form-control form-control-sm trigger-compile" placeholder="08xxxxxxxxxx" required style="border-radius: 10px;"></div></div>`;
        } else if (jenis === 'Pengeluaran / Rugi') {
            labelNom.innerHTML = 'Nominal Pengeluaran (Rp)';
            inputAdmin.readOnly = true; inputAdmin.value = '0'; inputAdmin.style.backgroundColor = '#e9ecef';
            htmlFields = `<div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">Keterangan / Alasan Rugi</label><input type="text" id="field_general" class="form-control form-control-sm trigger-compile" placeholder="Misal: Uang Hilang / Beli Kertas" required style="border-radius: 10px;"></div>`;
        } else if (jenis === 'Pulsa / Data' || jenis === 'Token Listrik' || jenis === 'PDAM' || jenis === 'Cicilan Finance' || jenis === 'Tarik Tunai') {
            htmlFields = `<div class="mb-3"><label class="form-label text-muted small fw-bold text-uppercase">Detail / Ref / ID (Bila Ada)</label><input type="text" id="field_general" class="form-control form-control-sm trigger-compile" placeholder="..." style="border-radius: 10px;"></div>`;
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
        
        // KOMPILASI KETERANGAN KHUSUS BUKA REKENING
        if (jenis === 'Buka Rekening Baru') {
            let jenisRek = document.getElementById('field_jenis_rek') ? document.getElementById('field_jenis_rek').value : '';
            let nasabah = document.getElementById('field_nama_nasabah') ? document.getElementById('field_nama_nasabah').value : '';
            res = (jenisRek ? "Pkt: " + jenisRek + " | " : "") + "Nasabah: " + nasabah;
        }
        else if (jenis === 'Transfer') res = "Bank: " + (document.getElementById('field_bank') ? document.getElementById('field_bank').value : '') + " | Rek: " + (document.getElementById('field_norek') ? document.getElementById('field_norek').value : '');
        else if (jenis === 'Setor Tunai') res = "Setor Rek: " + (document.getElementById('field_setor_rek') ? document.getElementById('field_setor_rek').value : '');
        else if (jenis === 'TopUp E-Wallet') res = (document.getElementById('field_ewallet') ? document.getElementById('field_ewallet').value : '') + " | ID: " + (document.getElementById('field_wallet_id') ? document.getElementById('field_wallet_id').value : '');
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
                if (qty > stokMax) { validStok = false; errMsg += `• Sisa stok uang ${label} hanya ${stokMax} lembar! <br>`; el.classList.add('input-error'); }
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
        <?php if ($cabang_dibekukan): ?> 
            Swal.fire({ icon: 'error', title: 'Cabang Tutup', text: 'MAAF! Shift sudah dikunci oleh sistem.', confirmButtonColor: '#00529C' }); return;
        <?php elseif (!$status_shift_aktif): ?> 
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Harap Set Modal Awal Anda terlebih dahulu sebelum bertransaksi.', confirmButtonColor: '#00529C' }).then(() => { window.location.href = "set_modal.php"; }); return; 
        <?php endif; ?>
        
        let selectEl = document.getElementById('selectJenis');
        if (!Array.from(selectEl.options).some(opt => opt.value === jenisLayanan)) selectEl.add(new Option(jenisLayanan, jenisLayanan));
        selectEl.value = jenisLayanan; document.getElementById('inputNominal').value = ''; document.getElementById('inputAdmin').value = ''; document.getElementById('inputKeterangan').value = ''; document.getElementById('alertStokLimit').style.display = 'none';
        
        document.getElementById('selectBankAgen').value = ''; 

        document.querySelectorAll('.out-pecahan').forEach(item => { item.value = ''; item.classList.remove('input-error'); }); document.getElementById('teksTotal').innerText = 'Rp 0';
        sesuaikanForm(jenisLayanan); new bootstrap.Modal(document.getElementById('modalInput')).show();
    }
    document.getElementById('selectJenis').addEventListener('change', function() { sesuaikanForm(this.value); });
    
    function hitungTotal() {
        let nRaw = document.getElementById('inputNominal').value.replace(/[^0-9]/g, '');
        let aRaw = document.getElementById('inputAdmin').value.replace(/[^0-9]/g, '');
        let n = parseFloat(nRaw) || 0; 
        let a = parseFloat(aRaw) || 0;
        document.getElementById('teksTotal').innerText = 'Rp ' + formatRp(n + a);
    }

    document.getElementById('formTransaksi').addEventListener('submit', function(e) {
        let jenis = document.getElementById('selectJenis').value;
        let bank = document.getElementById('selectBankAgen').value;
        let nominal = parseFloat(document.getElementById('inputNominal').value.replace(/[^0-9]/g, '')) || 0;

        if (jenis === 'Tarik Tunai' || (jenis === 'Pengeluaran / Rugi' && bank === 'CASH')) {
            if (nominal > dataSaldo['CASH']) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Uang Laci Kurang!', text: 'Sisa uang fisik Anda hanya Rp ' + formatRp(dataSaldo['CASH']) + '. Transaksi ditolak.', confirmButtonColor: '#d33' });
            }
        } else if (jenis !== 'Tukar Uang Receh' && jenis !== 'Tukar Uang') {
            if (bank && bank !== 'CASH') {
                if (nominal > dataSaldo[bank]) {
                    e.preventDefault();
                    Swal.fire({ icon: 'error', title: 'Saldo Rekening Kurang!', text: 'Sisa saldo rekening ' + bank + ' Anda hanya Rp ' + formatRp(dataSaldo[bank]) + '. Transaksi ditolak.', confirmButtonColor: '#d33' });
                }
            }
        }
    });
</script>

<script>
    const ctxLaba = document.getElementById('pendapatanChart').getContext('2d');
    new Chart(ctxLaba, { 
        type: 'line', 
        data: { 
            labels: <?= json_encode($grafik_tanggal); ?>, 
            datasets: [{ 
                label: 'Pendapatan', 
                data: <?= json_encode($grafik_pendapatan); ?>, 
                borderColor: '#00529C', backgroundColor: 'rgba(0, 82, 156, 0.1)', borderWidth: 3, fill: true, tension: 0.4 
            }] 
        }, 
        options: { 
            responsive: true, maintainAspectRatio: false, 
            plugins: { legend: { display: false } }, 
            scales: { y: { border: {display: false}, beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }, x: { grid: { display: false } } } 
        } 
    });

    const ctxLayanan = document.getElementById('layananChart').getContext('2d');
    new Chart(ctxLayanan, {
        type: 'line',
        data: {
            labels: <?= json_encode($grafik_tanggal); ?>,
            datasets: [
                { label: 'Tarik Tunai', data: <?= json_encode($grafik_tarik); ?>, borderColor: '#dc3545', backgroundColor: 'transparent', borderWidth: 3, tension: 0.4 },
                { label: 'Setor Tunai', data: <?= json_encode($grafik_setor); ?>, borderColor: '#198754', backgroundColor: 'transparent', borderWidth: 3, tension: 0.4 },
                { label: 'Transfer', data: <?= json_encode($grafik_transfer); ?>, borderColor: '#00529C', backgroundColor: 'transparent', borderWidth: 3, tension: 0.4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: { family: 'Montserrat', weight: '600' } } }, tooltip: { padding: 12, cornerRadius: 8 } },
            scales: { y: { beginAtZero: true, border: {display: false}, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { stepSize: 1 } }, x: { grid: { display: false }, border: {display: false} } }
        }
    });

    <?php if (isset($_SESSION['flash_success'])): ?>
        Swal.fire({ title: 'Sukses!', text: '<?= $_SESSION['flash_success']; ?>', icon: 'success', confirmButtonColor: '#00529C', timer: 3500, timerProgressBar: true });
    <?php unset($_SESSION['flash_success']); endif; ?>
    
    <?php if (isset($_SESSION['flash_error'])): ?>
        Swal.fire({ title: 'Transaksi Ditolak / Gagal!', text: '<?= $_SESSION['flash_error']; ?>', icon: 'error', confirmButtonColor: '#d33' });
    <?php unset($_SESSION['flash_error']); endif; ?>
</script>
</body>
</html>