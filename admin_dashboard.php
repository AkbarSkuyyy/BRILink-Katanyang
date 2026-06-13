<?php
require 'config.php';

// Sinkronisasi Waktu
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

$tanggal_hari_ini = date('Y-m-d');

// ==========================================
// PROSES SIMPAN PENGATURAN TIMER
// ==========================================
if (isset($_POST['simpan_pengaturan'])) {
    $timer_baru = (int)$_POST['lockout_time'];
    $conn->query("UPDATE pengaturan SET nilai_setting = '$timer_baru' WHERE nama_setting = 'lockout_time'");
    $_SESSION['flash_success'] = "Durasi timer blokir berhasil diperbarui menjadi $timer_baru detik!";
    header("Location: admin_dashboard.php"); exit;
}

// Ambil data pengaturan saat ini untuk ditampilkan di form
$q_setting = $conn->query("SELECT nilai_setting FROM pengaturan WHERE nama_setting = 'lockout_time'");
$setting_data = $q_setting ? $q_setting->fetch_assoc() : null;
$current_timer = isset($setting_data['nilai_setting']) ? (int)$setting_data['nilai_setting'] : 300;

// ==========================================
// PROSES BUKA BLOKIR AKUN KASIR/ADMIN
// ==========================================
if (isset($_POST['buka_blokir'])) {
    $id_user_blokir = (int)$_POST['id_user_blokir'];
    $conn->query("UPDATE users SET login_attempts = 0, lock_until = NULL WHERE id = '$id_user_blokir'");
    $_SESSION['flash_success'] = "Akun berhasil dibuka blokirnya dan bisa login kembali!";
    header("Location: admin_dashboard.php"); exit;
}

// Cek apakah ada akun yang sedang terblokir
$q_blokir = $conn->query("SELECT u.*, c.nama_cabang FROM users u LEFT JOIN cabang c ON u.cabang_id = c.id WHERE u.lock_until > NOW()");
$jumlah_diblokir = $q_blokir ? $q_blokir->num_rows : 0;

// ==========================================
// 1. MENGAMBIL DATA METRIK GLOBAL
// ==========================================
$q_laba_all = $conn->query("SELECT SUM(admin_fee) as total FROM transactions");
$row_laba_all = $q_laba_all->fetch_assoc();
$laba_all = !empty($row_laba_all['total']) ? $row_laba_all['total'] : 0;

$q_laba_today = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE tanggal = '$tanggal_hari_ini'");
$row_laba_today = $q_laba_today->fetch_assoc();
$laba_today = !empty($row_laba_today['total']) ? $row_laba_today['total'] : 0;

$q_putar_today = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE tanggal = '$tanggal_hari_ini' AND jenis_transaksi NOT IN ('Tukar Uang')");
$row_putar_today = $q_putar_today->fetch_assoc();
$putar_today = !empty($row_putar_today['total']) ? $row_putar_today['total'] : 0;

$q_cabang_aktif = $conn->query("SELECT COUNT(DISTINCT u.cabang_id) as total FROM shifts s JOIN users u ON s.user_id = u.id WHERE s.tanggal = '$tanggal_hari_ini' AND s.status = 'aktif'");
$row_cabang_aktif = $q_cabang_aktif->fetch_assoc();
$cabang_aktif = !empty($row_cabang_aktif['total']) ? $row_cabang_aktif['total'] : 0;

$q_total_cabang = $conn->query("SELECT COUNT(id) as total FROM cabang");
$row_total_cabang = $q_total_cabang->fetch_assoc();
$total_cabang = !empty($row_total_cabang['total']) ? $row_total_cabang['total'] : 0;


// ==========================================
// 2. KALKULASI SALDO MULTI-CABANG, REKENING & RECEH
// ==========================================
$data_monitoring = [];

// Mapping Kolom Database Rekening
$db_map = [];
$q_rek = $conn->query("SELECT alias, kolom_db FROM rekening");
if($q_rek) {
    while($r = $q_rek->fetch_assoc()) { 
        $db_map[trim($r['alias'])] = trim($r['kolom_db']); 
    }
}

// Tarik data receh untuk dicocokkan dengan cabang
$receh_data = [];
$q_receh = $conn->query("SELECT * FROM uang_receh");
if ($q_receh) {
    while($r = $q_receh->fetch_assoc()){
        $receh_data[$r['cabang_id']] = $r;
    }
}

// Mengambil semua shift aktif
$q_shift_aktif = $conn->query("
    SELECT s.*, c.nama_cabang, c.id as cabang_id, u.username, u.assigned_banks
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    JOIN cabang c ON u.cabang_id = c.id
    WHERE s.tanggal = '$tanggal_hari_ini' AND s.status = 'aktif'
    ORDER BY c.nama_cabang ASC, s.shift_ke ASC
");

if ($q_shift_aktif && $q_shift_aktif->num_rows > 0) {
    while($row = $q_shift_aktif->fetch_assoc()){
        $shift_id_saat_ini = $row['id']; 
        $cabang = $row['nama_cabang'];
        $cabang_id = $row['cabang_id'];
        $tgl_shift = $row['tanggal'];
        
        $assigned_str = $row['assigned_banks'] ?? '';
        $assigned_banks = $assigned_str ? array_unique(array_map('trim', explode(',', $assigned_str))) : [];
        
        $row['rincian_saldo'] = [];
        $total_semua_saldo_shift = 0;

        // A. KALKULASI SALDO FISIK LACI (CASH)
        $q_in_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id_saat_ini' AND tanggal >= '$tgl_shift' AND (jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos') OR (jenis_transaksi = 'Setor Dana Bos' AND bank_agen = 'CASH'))");
        $in_cash = ($q_in_cash && $r_in = $q_in_cash->fetch_assoc()) ? ($r_in['tot'] ?? 0) : 0;

        $q_adm_cash = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$shift_id_saat_ini' AND tanggal >= '$tgl_shift' AND admin_source = 'CASH' AND jenis_transaksi != 'Pengeluaran / Rugi'");
        $adm_cash = ($q_adm_cash && $r_adm = $q_adm_cash->fetch_assoc()) ? ($r_adm['tot'] ?? 0) : 0;

        $q_out_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id_saat_ini' AND tanggal >= '$tgl_shift' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi IN ('Pengeluaran / Rugi', 'Tarik Dana Bos') AND bank_agen = 'CASH'))");
        $out_cash = ($q_out_cash && $r_out = $q_out_cash->fetch_assoc()) ? ($r_out['tot'] ?? 0) : 0;

        $saldo_cash = $row['modal_awal'] + $in_cash + $adm_cash - $out_cash;
        $row['rincian_saldo']['CASH'] = $saldo_cash;
        $total_semua_saldo_shift += $saldo_cash;

        // B. KALKULASI SALDO REKENING BANK
        foreach($assigned_banks as $b_name) {
            if(isset($db_map[$b_name])) {
                $b_col = $db_map[$b_name];
                $modal_bank = isset($row[$b_col]) ? (float)$row[$b_col] : 0;

                $q_in_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id_saat_ini' AND tanggal >= '$tgl_shift' AND jenis_transaksi IN ('Tarik Tunai', 'Setor Dana Bos') AND bank_agen = '$b_name'");
                $in_b = ($q_in_b && $r_in = $q_in_b->fetch_assoc()) ? ($r_in['tot'] ?? 0) : 0;
                
                $q_admin_b = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$shift_id_saat_ini' AND tanggal >= '$tgl_shift' AND admin_source = 'SALDO' AND bank_agen = '$b_name'");
                $admin_b = ($q_admin_b && $r_adm = $q_admin_b->fetch_assoc()) ? ($r_adm['tot'] ?? 0) : 0;
                
                $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id_saat_ini' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Setor Dana Bos') AND bank_agen = '$b_name'");
                $out_b = ($q_out_b && $r_out = $q_out_b->fetch_assoc()) ? ($r_out['tot'] ?? 0) : 0;
                
                $saldo_bank_ini = $modal_bank + $in_b + $admin_b - $out_b;
                
                // Masukkan ke array jika saldo tidak kosong
                $row['rincian_saldo'][$b_name] = $saldo_bank_ini;
                $total_semua_saldo_shift += $saldo_bank_ini;
            }
        }
        
        $row['saldo_berjalan'] = $total_semua_saldo_shift;
        
        // C. Kelompokkan ke dalam array per cabang & Masukkan Data Receh
        if (!isset($data_monitoring[$cabang])) {
            $data_monitoring[$cabang] = [
                'cabang_id' => $cabang_id,
                'total_saldo_cabang' => 0,
                'shifts' => [],
                'receh' => $receh_data[$cabang_id] ?? null
            ];
        }
        
        $data_monitoring[$cabang]['shifts'][] = $row;
        $data_monitoring[$cabang]['total_saldo_cabang'] += $total_semua_saldo_shift;
    }
}

// ==========================================
// 3. LOGIKA FILTER GRAFIK
// ==========================================
function fetchSumVal($conn, $sql) {
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return !empty($row['val']) ? (float)$row['val'] : 0;
    }
    return 0;
}

$periode = isset($_GET['periode']) ? $_GET['periode'] : 'mingguan';
$grafik_label = []; $grafik_laba = []; $bal_in = []; $bal_out = [];
$tgl_start = date('Y-m-d', strtotime("-6 days")); 

if ($periode == 'tahunan') {
    $tgl_start = date('Y-m-d', strtotime("-11 months"));
    for ($i = 11; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months"));
        $grafik_label[] = date('M y', strtotime($m . '-01'));
        $grafik_laba[] = fetchSumVal($conn, "SELECT SUM(admin_fee) as val FROM transactions WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$m'");
        $bal_in[]      = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$m' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
        $bal_out[]     = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE DATE_FORMAT(tanggal, '%Y-%m') = '$m' AND jenis_transaksi = 'Tarik Tunai'");
    }
} elseif ($periode == 'bulanan') {
    $tgl_start = date('Y-m-d', strtotime("-29 days"));
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $grafik_label[] = date('d/m', strtotime($d));
        $grafik_laba[] = fetchSumVal($conn, "SELECT SUM(admin_fee) as val FROM transactions WHERE tanggal = '$d'");
        $bal_in[]      = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE tanggal = '$d' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
        $bal_out[]     = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE tanggal = '$d' AND jenis_transaksi = 'Tarik Tunai'");
    }
} else { 
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $grafik_label[] = date('d/m', strtotime($d));
        $grafik_laba[] = fetchSumVal($conn, "SELECT SUM(admin_fee) as val FROM transactions WHERE tanggal = '$d'");
        $bal_in[]      = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE tanggal = '$d' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
        $bal_out[]     = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE tanggal = '$d' AND jenis_transaksi = 'Tarik Tunai'");
    }
}

$kondisi_tgl = "WHERE tanggal >= '$tgl_start'";
$pie_label = []; $pie_data = [];
$q_pie = $conn->query("SELECT jenis_transaksi, COUNT(id) as jml FROM transactions $kondisi_tgl GROUP BY jenis_transaksi ORDER BY jml DESC LIMIT 6");
if ($q_pie) {
    while($r = $q_pie->fetch_assoc()) {
        $pie_label[] = $r['jenis_transaksi'];
        $pie_data[] = (int)$r['jml'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Dashboard Admin Induk</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root {
            --bg-body: #f4f7fa; 
            --bri-blue: #00529C;
            --bri-light-blue: #e6f0ff;
            --bri-black: #1a1a1a;
            --bri-white: #ffffff;
            --bri-grey: #e9ecef;
        }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        .metric-card { border-radius: 24px; border: none; padding: 24px; position: relative; overflow: hidden; height: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.04); transition: all 0.3s ease; }
        .metric-card:hover { transform: translateY(-5px); box-shadow: 0 15px 25px rgba(0,0,0,0.08); }
        .metric-card i { position: absolute; right: -15px; bottom: -20px; font-size: 110px; opacity: 0.15; transform: rotate(-10deg); transition: all 0.4s ease; }
        
        .bg-saldo { background: linear-gradient(135deg, #003366 0%, var(--bri-blue) 100%); color: var(--bri-white); }
        .bg-admin { background: linear-gradient(135deg, #b3d4ff 0%, var(--bri-light-blue) 100%); color: var(--bri-blue); }
        .bg-total { background: linear-gradient(135deg, #000000 0%, #333333 100%); color: var(--bri-white); }
        .bg-receh { background: var(--bri-white); color: var(--bri-blue); border: 2px solid var(--bri-light-blue); }

        .chart-container { position: relative; width: 100%; height: 280px; }
        .chart-container-sm { position: relative; width: 100%; height: 240px; }
        
        .switch-filter { background-color: var(--bri-grey); border-radius: 50px; padding: 6px; display: inline-flex; }
        .switch-filter .btn { border-radius: 50px; font-weight: 700; font-size: 13px; padding: 8px 24px; border: none; color: var(--bri-black); transition: all 0.3s; }
        .switch-filter .btn.active { background-color: var(--bri-blue); color: var(--bri-white); box-shadow: 0 4px 10px rgba(0, 82, 156, 0.3); }
        .switch-filter .btn:hover:not(.active) { background-color: #d1d8e0; }

        .table-wrapper { max-height: 400px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-track { background: transparent; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--bri-grey); border-radius: 10px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: var(--bri-white); z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); font-weight: 700; padding: 15px; }
        .table-wrapper tbody td { padding: 15px; border-bottom: 1px solid var(--bri-grey); vertical-align: middle; }

        .shift-card-hover { cursor: pointer; transition: all 0.2s ease-in-out; border: 1px solid var(--bri-light-blue); }
        .shift-card-hover:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,82,156,0.15) !important; border-color: var(--bri-blue); }
        
        .modal-content { border-radius: 20px; border: none; }
        .bg-light-blue-box { background-color: var(--bri-light-blue); border-radius: 16px; }

        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 20px !important; }

        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; }
            .switch-filter { display: flex; width: 100%; }
            .switch-filter .btn { flex-grow: 1; padding: 8px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Dashboard Pusat 👋</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Ringkasan aktivitas seluruh cabang BRILink pada <?= date('l, d M Y'); ?></p>
            </div>
            
            <div class="d-flex gap-2 align-self-start">
                <button data-bs-toggle="modal" data-bs-target="#modalPengaturan" class="btn btn-outline-primary fw-bold rounded-pill shadow-sm bg-white" style="padding: 12px 20px;">
                    <i class="bi bi-gear-fill me-2"></i> Pengaturan
                </button>
                <a href="laporan_global.php" class="btn btn-dark fw-bold rounded-pill px-4 shadow-sm" style="padding: 12px 28px;">
                    <i class="bi bi-file-earmark-text me-2"></i> Laporan Lengkap
                </a>
            </div>
        </div>

        <div class="row g-3 mb-5">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-saldo">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Cabang Buka</p>
                    <h3 class="fw-bolder mb-0"><?= $cabang_aktif; ?> <span class="fs-6 opacity-75 fw-normal">/ <?= $total_cabang; ?> Cabang</span></h3>
                    <i class="bi bi-shop"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-admin">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.7;">Perputaran Uang (Hari Ini)</p>
                    <h3 class="fw-bolder mb-0">Rp <?= number_format($putar_today, 0, ',', '.'); ?></h3>
                    <i class="bi bi-arrow-repeat" style="color: var(--bri-blue); opacity: 0.1;"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-total">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Pendapatan Admin (Hari Ini)</p>
                    <h3 class="fw-bolder mb-0">Rp <?= number_format($laba_today, 0, ',', '.'); ?></h3>
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-receh">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.6;">Total Laba Bersih (All Time)</p>
                    <h3 class="fw-bolder mb-0">Rp <?= number_format($laba_all, 0, ',', '.'); ?></h3>
                    <i class="bi bi-cash-stack" style="color: var(--bri-blue); opacity: 0.05;"></i>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center mb-3 mt-2">
            <h4 class="fw-extrabold mb-0" style="color: var(--bri-black);"><i class="bi bi-display me-2 text-primary"></i>Live Monitoring Saldo Tiap Cabang</h4>
            <span class="ms-3 badge bg-white text-primary border border-primary"><i class="bi bi-info-circle me-1"></i>Klik kartu shift untuk info rincian</span>
        </div>
        
        <div class="row g-4 mb-5">
            <?php if (!empty($data_monitoring)): ?>
                <?php foreach ($data_monitoring as $cabang => $data): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="modern-card h-100 border-top border-4" style="border-color: var(--bri-blue) !important; padding: 20px;">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <h6 class="fw-bolder text-dark mb-0 text-truncate pe-2"><i class="bi bi-shop me-2" style="color: var(--bri-blue);"></i><?= htmlspecialchars($cabang); ?></h6>
                            <span class="badge bg-success text-white fw-bold px-3 py-2 rounded-pill shadow-sm" style="font-size: 13px;">
                                Total: Rp <?= number_format($data['total_saldo_cabang'], 0, ',', '.'); ?>
                            </span>
                        </div>
                        
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($data['shifts'] as $shift): ?>
                            
                            <div class="p-3 rounded-4 shadow-sm shift-card-hover" style="background-color: #f8fbff;" data-bs-toggle="modal" data-bs-target="#modalReceh_<?= $data['cabang_id'] ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark" style="font-size: 14px;">Shift <?= $shift['shift_ke']; ?> (<?= htmlspecialchars($shift['username']); ?>)</span>
                                    <?php $bg = ($shift['status'] == 'aktif') ? 'bg-primary' : 'bg-secondary'; ?>
                                    <span class="badge <?= $bg; ?> rounded-pill" style="font-size: 11px;"><?= ucfirst($shift['status']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-end mt-2">
                                    <span class="text-muted fw-medium lh-sm" style="font-size: 11px; max-width: 50%;">Saldo Keseluruhan<br><span style="font-size: 10px;">(Semua Rekening & Laci)</span></span>
                                    <span class="fw-bolder" style="color: var(--bri-blue); font-size: 16px;">Rp <?= number_format($shift['saldo_berjalan'], 0, ',', '.'); ?></span>
                                </div>
                            </div>

                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="modern-card text-center py-5">
                        <i class="bi bi-info-circle text-muted" style="font-size: 40px;"></i>
                        <h6 class="text-muted fw-bold mt-3">Belum ada cabang atau shift yang buka hari ini.</h6>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div id="blok-analisis" class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-3" style="scroll-margin-top: 80px;">
            <h5 class="fw-extrabold mb-0" style="color: var(--bri-black);">Analisis Performa Cabang</h5>
            <div class="switch-filter shadow-sm">
                <a href="?periode=mingguan#blok-analisis" class="btn <?= $periode=='mingguan' ? 'active' : '' ?>">Mingguan</a>
                <a href="?periode=bulanan#blok-analisis" class="btn <?= $periode=='bulanan' ? 'active' : '' ?>">Bulanan</a>
                <a href="?periode=tahunan#blok-analisis" class="btn <?= $periode=='tahunan' ? 'active' : '' ?>">Tahunan</a>
            </div>
        </div>

        <div class="modern-card mb-4">
            <h6 class="fw-bold mb-4" style="color: var(--bri-black);">Tren Pendapatan Admin (<?= ucfirst($periode); ?>)</h6>
            <div class="chart-container">
                <canvas id="lineChart"></canvas>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-5">
                <div class="modern-card h-100">
                    <h6 class="fw-bold mb-4" style="color: var(--bri-black);">Sebaran Layanan Terlaris</h6>
                    <div class="chart-container-sm">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="modern-card h-100">
                    <h6 class="fw-bold mb-4" style="color: var(--bri-black);">Balance: Uang Masuk vs Uang Keluar (Rp)</h6>
                    <div class="chart-container-sm">
                        <canvas id="barChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="modern-card h-100">
                    <h6 class="fw-bold text-dark mb-4 border-bottom pb-3" style="color: var(--bri-black) !important;">Transaksi Live Keseluruhan (50 Terakhir)</h6>
                    <div class="table-responsive table-wrapper">
                        <table class="table table-borderless align-middle mb-0" style="font-size: 13px;">
                            <thead>
                                <tr>
                                    <th>Cabang / Layanan</th>
                                    <th>Perputaran</th>
                                    <th>Laba Masuk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $q_trans_global = $conn->query("
                                    SELECT t.*, c.nama_cabang 
                                    FROM transactions t JOIN users u ON t.user_id = u.id JOIN cabang c ON u.cabang_id = c.id 
                                    ORDER BY t.id DESC LIMIT 50
                                ");
                                
                                if ($q_trans_global && $q_trans_global->num_rows > 0):
                                    while ($row_trans = $q_trans_global->fetch_assoc()):
                                ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--bri-black);"><?= $row_trans['nama_cabang']; ?></strong><br>
                                            <span class="badge mt-1" style="background-color: var(--bri-light-blue); color: var(--bri-blue); font-weight: 600;"><?= $row_trans['jenis_transaksi']; ?></span>
                                            <small class="text-muted ms-1"><?= date('d/m H:i', strtotime($row_trans['tanggal'])); ?></small>
                                        </td>
                                        <td class="fw-semibold text-muted">
                                            <?= ($row_trans['jenis_transaksi'] == 'Tukar Uang') ? '-' : 'Rp ' . number_format($row_trans['nominal'], 0, ',', '.'); ?>
                                        </td>
                                        <td class="text-success fw-bold fs-6">+ Rp <?= number_format($row_trans['admin_fee'], 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="3" class="text-center text-muted py-5 fw-medium">Belum ada transaksi sama sekali.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalPengaturan" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0 rounded-4">
                <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                    <h5 class="modal-title fw-bolder text-dark"><i class="bi bi-gear-fill text-primary me-2"></i>Pengaturan Sistem</h5>
                    <button type="button" class="btn-close shadow-none bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Durasi Timer Blokir Login</label>
                            <p class="small text-muted mb-3">Atur berapa detik kasir/admin diblokir jika salah PIN 3x berturut-turut. (Default: 300 detik = 5 Menit).</p>
                            <div class="input-group input-group-lg shadow-sm" style="border-radius: 12px; overflow: hidden;">
                                <input type="number" name="lockout_time" class="form-control fw-bold border-0 bg-light text-center" value="<?= $current_timer; ?>" min="10" required>
                                <span class="input-group-text bg-white border-0 fw-bold text-primary">Detik</span>
                            </div>
                        </div>
                        <button type="submit" name="simpan_pengaturan" class="btn btn-primary w-100 py-3 fw-bold rounded-pill fs-6 shadow-sm" style="background: linear-gradient(135deg, var(--bri-blue), #003366); border: none;">Simpan Pengaturan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($data_monitoring)): ?>
        <?php foreach ($data_monitoring as $cabang => $data): 
            $rc = $data['receh'];
            $cid = $data['cabang_id'];
            
            // Format agar jika kosong menjadi 0
            $q100 = $rc ? (int)$rc['qty_100k'] : 0;
            $q50  = $rc ? (int)$rc['qty_50k']  : 0;
            $q20  = $rc ? (int)$rc['qty_20k']  : 0;
            $q10  = $rc ? (int)$rc['qty_10k']  : 0;
            $q5   = $rc ? (int)$rc['qty_5k']   : 0;
            $q2   = $rc ? (int)$rc['qty_2k']   : 0;
            $q1   = $rc ? (int)$rc['qty_1k']   : 0;
            $tot  = $rc ? (float)$rc['total']  : 0;
            
            // Fungsi pemberi warna jika kurang dari 20 lembar
            function wWarna($qty) { return $qty <= 20 ? 'text-danger' : 'text-dark'; }
        ?>
        <div class="modal fade" id="modalReceh_<?= $cid ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-md">
                <div class="modal-content shadow-lg border-0">
                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                        <h5 class="modal-title fw-bolder text-dark"><i class="bi bi-display text-primary me-2"></i>Detail Cabang</h5>
                        <button type="button" class="btn-close shadow-none bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 pt-2">
                        <p class="text-primary fs-5 fw-bold text-uppercase text-center mb-4 pb-2 border-bottom" style="letter-spacing: 0.5px;"><?= htmlspecialchars($cabang); ?></p>
                        
                        <h6 class="fw-bold mb-3"><i class="bi bi-wallet2 me-2"></i>Rincian Saldo (Shift Aktif)</h6>
                        <?php foreach($data['shifts'] as $shift): ?>
                            <div class="p-3 mb-3 rounded-4 border" style="background-color: #f8fbff; border-color: #cce0ff !important;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">Shift <?= $shift['shift_ke'] ?> (<?= htmlspecialchars($shift['username']) ?>)</span>
                                    <span class="badge bg-primary fs-6">Rp <?= number_format($shift['saldo_berjalan'], 0, ',', '.') ?></span>
                                </div>
                                <div class="small mt-3">
                                    <?php foreach($shift['rincian_saldo'] as $akun => $sld): ?>
                                        <div class="d-flex justify-content-between border-bottom pb-1 mb-2">
                                            <span class="text-muted fw-bold"><i class="bi <?= $akun=='CASH' ? 'bi-cash-stack text-success' : 'bi-bank text-primary' ?> me-2"></i><?= $akun ?></span>
                                            <span class="fw-bolder text-dark">Rp <?= number_format($sld, 0, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <h6 class="fw-bold mt-4 mb-3"><i class="bi bi-coin text-warning me-2"></i>Stok Uang Receh Cabang</h6>
                        <div class="bg-white border rounded-4 p-3 shadow-sm">
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                <span class="text-muted fw-bold">Rp 100k</span> <span class="fw-bolder <?= wWarna($q100); ?>"><?= $q100 ?> lbr</span>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                <span class="text-muted fw-bold">Rp 50k</span> <span class="fw-bolder <?= wWarna($q50); ?>"><?= $q50 ?> lbr</span>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                <span class="text-muted fw-bold">Rp 20k</span> <span class="fw-bolder <?= wWarna($q20); ?>"><?= $q20 ?> lbr</span>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                <span class="text-muted fw-bold">Rp 10k</span> <span class="fw-bolder <?= wWarna($q10); ?>"><?= $q10 ?> lbr</span>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                <span class="text-muted fw-bold">Rp 5k</span> <span class="fw-bolder <?= wWarna($q5); ?>"><?= $q5 ?> lbr</span>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                <span class="text-muted fw-bold">Rp 2k</span> <span class="fw-bolder <?= wWarna($q2); ?>"><?= $q2 ?> lbr</span>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2">
                                <span class="text-muted fw-bold">Rp 1k</span> <span class="fw-bolder <?= wWarna($q1); ?>"><?= $q1 ?> kpg</span>
                            </div>
                            
                            <div class="mt-4 p-3 bg-light-blue-box rounded-4 text-center">
                                <small class="fw-bold text-primary d-block mb-1">TOTAL RECEH LACI</small>
                                <h4 class="fw-bolder text-dark mb-0">Rp <?= number_format($tot, 0, ',', '.') ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const formatRp = (value) => { return 'Rp ' + value.toLocaleString('id-ID'); };

        // GRAFIK GARIS
        const ctxLine = document.getElementById('lineChart').getContext('2d');
        let gradientLine = ctxLine.createLinearGradient(0, 0, 0, 300);
        gradientLine.addColorStop(0, "rgba(0, 82, 156, 0.3)"); 
        gradientLine.addColorStop(1, "rgba(0, 82, 156, 0.0)");

        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: <?= json_encode($grafik_label); ?>,
                datasets: [{
                    label: 'Pendapatan Admin (Rp)',
                    data: <?= json_encode($grafik_laba); ?>,
                    borderColor: '#00529C', backgroundColor: gradientLine,
                    borderWidth: 3, pointBackgroundColor: '#ffffff', pointBorderColor: '#00529C',
                    pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6, fill: true, tension: 0.4
                }]
            },
            options: { 
                responsive: true, maintainAspectRatio: false, 
                plugins: { legend: { display: false }, tooltip: { padding: 12, cornerRadius: 8, backgroundColor: '#1a1a1a', callbacks: { label: function(context) { return formatRp(context.parsed.y); } } } }, 
                scales: { y: { border: {display: false}, beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#1a1a1a', callback: function(value) { return formatRp(value); } } }, x: { border: {display: false}, ticks: { color: '#1a1a1a' }, grid: { display: false } } } 
            }
        });

        // GRAFIK LINGKARAN
        const ctxPie = document.getElementById('pieChart').getContext('2d');
        new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($pie_label); ?>,
                datasets: [{
                    data: <?= json_encode($pie_data); ?>,
                    backgroundColor: ['#00529C', '#198754', '#ffc107', '#dc3545', '#0dcaf0', '#6f42c1'],
                    borderWidth: 3, borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '65%',
                plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {family: 'Montserrat', weight: '600'} } }, tooltip: { padding: 12, cornerRadius: 8 } }
            }
        });

        // GRAFIK BATANG
        const ctxBar = document.getElementById('barChart').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: <?= json_encode($grafik_label); ?>,
                datasets: [
                    { label: 'Uang Masuk / Setoran', data: <?= json_encode($bal_in); ?>, backgroundColor: '#198754', borderRadius: 4 },
                    { label: 'Uang Keluar / Tarik Tunai', data: <?= json_encode($bal_out); ?>, backgroundColor: '#dc3545', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: {family: 'Montserrat', weight: '600'} } }, tooltip: { padding: 12, cornerRadius: 8, callbacks: { label: function(context) { return context.dataset.label + ': ' + formatRp(context.parsed.y); } } } },
                scales: { 
                    y: { border: {display: false}, beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#1a1a1a', callback: function(value) { return formatRp(value); } } }, 
                    x: { border: {display: false}, ticks: { color: '#1a1a1a' }, grid: { display: false } } 
                }
            }
        });

        <?php if (isset($_SESSION['flash_success'])): ?>
            Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true, icon: 'success', title: '<?= $_SESSION['flash_success']; ?>' });
        <?php unset($_SESSION['flash_success']); endif; ?>
    </script>
</body>
</html>