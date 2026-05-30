<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

// Dapatkan ID Cabang
$q_user = $conn->query("SELECT cabang_id FROM users WHERE id = '$user_id'");
$row_user = $q_user->fetch_assoc();
$cabang_id = !empty($row_user['cabang_id']) ? $row_user['cabang_id'] : 0;

// Cek apakah Cabang sudah dibekukan hari ini
$q_cek_beku = $conn->query("SELECT s.id FROM shifts s JOIN users u ON s.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND s.tanggal = '$tanggal_hari_ini' AND s.shift_ke = 2 AND s.status = 'selesai'");
$cabang_dibekukan = ($q_cek_beku->num_rows > 0);

// Cek apakah ada shift aktif
$q_shift_aktif = $conn->query("SELECT id, modal_awal FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' AND status = 'aktif' ORDER BY id DESC LIMIT 1");
$status_shift_aktif = ($q_shift_aktif->num_rows > 0);

// AMBIL STOK UANG RECEH SAAT INI UNTUK VALIDASI
$q_stok_receh = $conn->query("SELECT * FROM uang_receh WHERE cabang_id = '$cabang_id'");
$stok_receh = $q_stok_receh->fetch_assoc();
if (!$stok_receh) {
    $stok_receh = ['qty_100k'=>0, 'qty_50k'=>0, 'qty_20k'=>0, 'qty_10k'=>0, 'qty_5k'=>0, 'qty_2k'=>0, 'qty_1k'=>0, 'total'=>0];
}

// PROSES SIMPAN TRANSAKSI
if (isset($_POST['simpan_transaksi']) && $status_shift_aktif && !$cabang_dibekukan) {
    $q_shift_aktif_insert = $conn->query("SELECT id FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' AND status = 'aktif' ORDER BY id DESC LIMIT 1");
    $shift_id = $q_shift_aktif_insert->fetch_assoc()['id'];
    
    $jenis   = $_POST['jenis_transaksi'];
    $nominal = !empty($_POST['nominal']) ? (float)$_POST['nominal'] : 0;
    $admin   = !empty($_POST['admin_fee']) ? (float)$_POST['admin_fee'] : 0;
    $ket     = $_POST['keterangan'];

    // Tukar Uang (HANYA MENCATAT UANG KELUAR FISIK RECEH)
    if ($jenis == 'Tukar Uang Receh') { 
        $jenis = 'Tukar Uang'; 

        $out_100k = !empty($_POST['out_100k']) ? (int)$_POST['out_100k'] : 0;
        $out_50k  = !empty($_POST['out_50k']) ? (int)$_POST['out_50k'] : 0;
        $out_20k  = !empty($_POST['out_20k']) ? (int)$_POST['out_20k'] : 0;
        $out_10k  = !empty($_POST['out_10k']) ? (int)$_POST['out_10k'] : 0;
        $out_5k   = !empty($_POST['out_5k']) ? (int)$_POST['out_5k'] : 0;
        $out_2k   = !empty($_POST['out_2k']) ? (int)$_POST['out_2k'] : 0;
        $out_1k   = !empty($_POST['out_1k']) ? (int)$_POST['out_1k'] : 0;

        // Dijadikan minus untuk mengurangi stok receh
        $net_100k = -$out_100k;
        $net_50k  = -$out_50k;
        $net_20k  = -$out_20k;
        $net_10k  = -$out_10k;
        $net_5k   = -$out_5k;
        $net_2k   = -$out_2k;
        $net_1k   = -$out_1k;

        $net_total = ($net_100k * 100000) + ($net_50k * 50000) + ($net_20k * 20000) + ($net_10k * 10000) + ($net_5k * 5000) + ($net_2k * 2000) + ($net_1k * 1000);

        $conn->query("UPDATE uang_receh SET 
            qty_100k = qty_100k + $net_100k, qty_50k = qty_50k + $net_50k, qty_20k = qty_20k + $net_20k,
            qty_10k = qty_10k + $net_10k, qty_5k = qty_5k + $net_5k, qty_2k = qty_2k + $net_2k,
            qty_1k = qty_1k + $net_1k, total = total + $net_total 
            WHERE cabang_id = '$cabang_id'");

        // Notifikasi Telegram
        $q_nama_cab = $conn->query("SELECT nama_cabang FROM cabang WHERE id = '$cabang_id'");
        $nama_cabang_notif = $q_nama_cab->fetch_assoc()['nama_cabang'];
        if (function_exists('cekDanNotifReceh')) { cekDanNotifReceh($conn, $cabang_id, $nama_cabang_notif); }
    }
    
    if ($jenis == 'Buka Rekening Baru') { $jenis = 'Buka Rekening'; }

    $stmt = $conn->prepare("INSERT INTO transactions (user_id, shift_id, tanggal, jenis_transaksi, nominal, admin_fee, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssds", $user_id, $shift_id, $tanggal_hari_ini, $jenis, $nominal, $admin, $ket);
    if($stmt->execute()) { header("Location: user_dashboard.php?status=sukses"); exit; }
}

// PERBAIKAN LOGIKA SALDO FISIK LACI & M-BANKING
$saldo_fisik_sekarang = 0;
$mutasi_mbanking = 0;
$admin_hari_ini = 0;

if ($status_shift_aktif) {
    $q_shift_ulang = $conn->query("SELECT id, modal_awal FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' AND status = 'aktif' ORDER BY id DESC LIMIT 1");
    $shift_data = $q_shift_ulang->fetch_assoc();
    $shift_id_aktif = $shift_data['id'];
    $modal_awal = $shift_data['modal_awal'];

    // 1. Uang Tunai Masuk Fisik (Nasabah kasih cash)
    $q_masuk = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id_aktif' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
    $row_masuk = $q_masuk->fetch_assoc();
    $uang_masuk = $row_masuk['total'] ?? 0;

    // 2. Uang Tunai Keluar Fisik (Kasir ngasih cash ke Nasabah)
    $q_keluar = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id_aktif' AND jenis_transaksi = 'Tarik Tunai'");
    $row_keluar = $q_keluar->fetch_assoc();
    $uang_keluar = $row_keluar['total'] ?? 0;

    // 3. Admin Fee Fisik (Selalu masuk ke laci kasir)
    $q_admin_fee = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id_aktif'");
    $row_admin_fee = $q_admin_fee->fetch_assoc();
    $admin_hari_ini = $row_admin_fee['total'] ?? 0;

    // Kalkulasi Akhir Laci Fisik
    $saldo_fisik_sekarang = $modal_awal + $uang_masuk - $uang_keluar + $admin_hari_ini;

    // Kalkulasi Mutasi M-Banking (Digital)
    // M-Banking bertambah jika Tarik Tunai (Uang nasabah masuk ke rek agen)
    // M-Banking berkurang jika Setor/Transfer/PPOB (Saldo agen dikirim keluar)
    $mutasi_mbanking = $uang_keluar - $uang_masuk; 
}

$total_uang_receh = $stok_receh['total'] ?? 0;

// GRAFIK LABA
$grafik_tanggal = [];
$grafik_pendapatan = [];
for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i days"));
    $grafik_tanggal[] = date('d/m', strtotime($tgl));
    $q_chart = $conn->query("SELECT IFNULL(SUM(t.admin_fee), 0) as harian FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND t.tanggal = '$tgl'");
    $row_chart = $q_chart->fetch_assoc();
    $grafik_pendapatan[] = (float)$row_chart['harian'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Dashboard Modern BRILink</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Pewarnaan Card Metrik */
        .bg-saldo { background: linear-gradient(135deg, #003366 0%, var(--bri-blue) 100%); color: var(--bri-white); }
        .bg-mbanking { background: linear-gradient(135deg, #198754 0%, #20c997 100%); color: white; }
        .bg-mbanking-minus { background: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%); color: white; }
        .bg-admin { background: linear-gradient(135deg, #b3d4ff 0%, var(--bri-light-blue) 100%); color: var(--bri-blue); }
        .bg-receh { background: var(--bri-white); color: var(--bri-blue); border: 2px solid var(--bri-light-blue); }
        
        .btn-input-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); border-radius: 50px; padding: 12px 28px; transition: all 0.3s ease; }
        .btn-input-modern:hover { transform: translateY(-2px); box-shadow: 0 12px 20px rgba(0, 82, 156, 0.4); color: var(--bri-white); }
        .chart-container { position: relative; width: 100%; height: 260px; }
        .feature-item { text-align: center; cursor: pointer; transition: all 0.2s ease-in-out; text-decoration: none; display: block; }
        .feature-item:hover { transform: translateY(-5px); }
        .feature-icon-wrapper { width: 65px; height: 65px; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 10px auto; box-shadow: 0 4px 12px rgba(0,0,0,0.04); transition: all 0.3s ease; background-color: var(--bri-light-blue); color: var(--bri-blue); }
        .feature-item:hover .feature-icon-wrapper { box-shadow: 0 8px 20px rgba(0,82,156,0.15); background-color: var(--bri-blue); color: var(--bri-white); }
        .feature-text { font-size: 13px; font-weight: 700; color: var(--bri-black); line-height: 1.2; }
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .bg-light-blue-box { background-color: var(--bri-light-blue); border-radius: 16px; }
        .table-pecahan th, .table-pecahan td { padding: 4px; border: none; vertical-align: middle; }
        
        /* Error Input Styling */
        .input-error { border: 2px solid #dc3545 !important; background-color: #fff3f3 !important; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } .btn-input-modern { width: 100%; text-align: center; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Halo, Semangat Bekerja! 👋</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;"><?= date('l, d F Y', strtotime($tanggal_hari_ini)); ?> • Shift <?= isset($_SESSION['shift_ke']) ? $_SESSION['shift_ke'] : '-'; ?></p>
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
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-saldo">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Saldo Laci (Fisik)</p>
                    <h3 class="fw-bolder mb-1">Rp <?= number_format($saldo_fisik_sekarang, 0, ',', '.'); ?></h3>
                    <?php if(isset($modal_awal)): ?>
                        <small class="opacity-75 fw-medium" style="font-size: 12px;">(Modal: Rp <?= number_format($modal_awal, 0, ',', '.'); ?>)</small>
                    <?php else: ?>
                        <small class="fw-bold" style="font-size: 12px; color: #ffc107;"><i class="bi bi-exclamation-circle"></i> Tunggu Input Shift</small>
                    <?php endif; ?>
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card <?= ($mutasi_mbanking >= 0) ? 'bg-mbanking' : 'bg-mbanking-minus' ?>">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Mutasi M-Banking</p>
                    <h3 class="fw-bolder mb-1">
                        <?= $mutasi_mbanking < 0 ? '-' : '+' ?> Rp <?= number_format(abs($mutasi_mbanking), 0, ',', '.'); ?>
                    </h3>
                    <small class="opacity-75 fw-medium" style="font-size: 12px;">(Pergerakan Digital Shift Ini)</small>
                    <i class="bi bi-phone"></i>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-admin">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.7;">Laba Hari Ini</p>
                    <h3 class="fw-bolder mb-0">Rp <?= number_format($admin_hari_ini, 0, ',', '.'); ?></h3>
                    <i class="bi bi-graph-up-arrow" style="color: var(--bri-blue); opacity: 0.1;"></i>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-receh">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.6;">Stok Receh</p>
                    <h3 class="fw-bolder mb-0">Rp <?= number_format($total_uang_receh, 0, ',', '.'); ?></h3>
                    <i class="bi bi-coin" style="color: var(--bri-blue); opacity: 0.05;"></i>
                </div>
            </div>
        </div>

        <div class="modern-card mb-4">
            <h5 class="fw-bold text-dark mb-4 border-bottom pb-3" style="color: var(--bri-black) !important;">Layanan Transaksi Utama</h5>
            <div class="row row-cols-3 row-cols-sm-4 row-cols-md-5 row-cols-lg-10 g-3 justify-content-center">
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
            </div>
        </div>

        <div class="modern-card mb-4">
            <h5 class="fw-bold mb-4" style="color: var(--bri-black);">Pergerakan Laba (7 Hari)</h5>
            <div class="chart-container">
                <canvas id="pendapatanChart"></canvas>
            </div>
        </div>
    </div>

<div class="modal fade" id="modalInput" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header pb-3">
                <h5 class="modal-title fw-bolder" style="color: var(--bri-black);">Input Transaksi</h5>
                <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold text-uppercase tracking-wide">Jenis Layanan</label>
                        <select name="jenis_transaksi" id="selectJenis" class="form-select form-select-lg" style="border-radius: 12px; background-color: var(--bg-body);" required>
                            <option value="" disabled selected>-- Pilih Layanan --</option>
                            <?php
                            $q_list_layanan = $conn->query("SELECT nama_layanan FROM layanan ORDER BY nama_layanan ASC");
                            while($lay = $q_list_layanan->fetch_assoc()) {
                                echo "<option value='{$lay['nama_layanan']}'>{$lay['nama_layanan']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div id="formPecahan" style="display: none;" class="mb-3 p-3 bg-light-blue-box border-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-2 text-center text-nowrap border-0" style="font-size: 13px;">
                                <thead class="fw-bold border-0" style="color: var(--bri-blue);">
                                    <tr>
                                        <th class="border-0 text-start ps-3">Pecahan</th>
                                        <th class="text-danger border-0"><i class="bi bi-box-arrow-up"></i> Keluar (Lembar)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $pecahan_list = [
                                        100000 => ['label'=>'100k', 'col'=>'qty_100k'], 
                                        50000  => ['label'=>'50k',  'col'=>'qty_50k'], 
                                        20000  => ['label'=>'20k',  'col'=>'qty_20k'], 
                                        10000  => ['label'=>'10k',  'col'=>'qty_10k'], 
                                        5000   => ['label'=>'5k',   'col'=>'qty_5k'], 
                                        2000   => ['label'=>'2k',   'col'=>'qty_2k'], 
                                        1000   => ['label'=>'1k',   'col'=>'qty_1k']
                                    ];
                                    foreach ($pecahan_list as $nilai => $item): 
                                        $sisa = isset($stok_receh[$item['col']]) ? $stok_receh[$item['col']] : 0;
                                    ?>
                                    <tr>
                                        <td class="fw-bold align-middle border-0 text-start ps-3" style="color: var(--bri-black); line-height: 1.1;">
                                            <?= $item['label']; ?> <br>
                                            <small class="text-muted" style="font-size: 10px; font-weight: normal;">Sisa: <?= $sisa; ?></small>
                                        </td>
                                        <td class="border-0">
                                            <input type="number" name="out_<?= $item['label']; ?>" class="form-control form-control-sm out-pecahan text-center rounded-3 border-0 shadow-sm mx-auto" 
                                            data-nilai="<?= $nilai; ?>" data-stok="<?= $sisa; ?>" data-label="<?= $item['label']; ?>" min="0" placeholder="0" style="max-width: 120px;">
                                        </td>
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
                            <input type="number" id="inputNominal" name="nominal" class="form-control form-control-lg fw-bold" style="border-radius: 12px; background-color: var(--bg-body); color: var(--bri-black);" required placeholder="0" oninput="hitungTotal()">
                        </div>
                        <div class="col-5 mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Biaya Admin</label>
                            <input type="number" id="inputAdmin" name="admin_fee" class="form-control form-control-lg fw-bold" style="border-radius: 12px; background-color: var(--bg-body); color: var(--bri-blue);" required placeholder="0" oninput="hitungTotal()">
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
    const formatRp = (angka) => new Intl.NumberFormat('id-ID').format(angka);

    function sesuaikanForm(jenis) {
        let formPecahan = document.getElementById('formPecahan');
        let dynamicFields = document.getElementById('dynamicServiceFields');
        let inputNom = document.getElementById('inputNominal');
        let inputKet = document.getElementById('inputKeterangan');
        let labelKet = document.getElementById('labelKeterangan');
        let labelNom = document.getElementById('labelNominal');

        formPecahan.style.display = 'none';
        inputNom.readOnly = false; inputNom.style.backgroundColor = 'var(--bg-body)';
        inputKet.readOnly = false; inputKet.style.backgroundColor = 'var(--bg-body)';
        labelKet.innerHTML = 'No Tujuan / Keterangan';
        labelNom.innerHTML = 'Nominal Uang (Rp)';
        document.getElementById('btnSimpan').disabled = false;
        dynamicFields.innerHTML = "";

        if (jenis === 'Tukar Uang Receh' || jenis === 'Tukar Uang') {
            formPecahan.style.display = 'block';
            inputNom.readOnly = true; inputNom.style.backgroundColor = '#e9ecef';
            inputKet.readOnly = true; inputKet.style.backgroundColor = '#e9ecef';
            labelKet.innerHTML = 'Rincian Lembaran Keluar (Otomatis)';
            hitungPecahan();
            return;
        }

        let htmlFields = "";
        
        if (jenis === 'Buka Rekening Baru') {
            labelNom.innerHTML = 'Setoran Awal (Rp)';
            htmlFields = `
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Pilih Paket Rekening</label>
                    <select id="field_paket_rek" class="form-select form-select-sm trigger-compile" required style="border-radius: 10px;">
                        <option value="ATM Aja">ATM Aja</option>
                        <option value="ATM + Buku Tabungan">ATM + Buku Tabungan</option>
                        <option value="ATM + Buku Tabungan + M-Bank">ATM + Buku Tabungan + M-Bank</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">Nama Nasabah / NIK</label>
                    <input type="text" id="field_nama_nasabah" class="form-control form-control-sm trigger-compile" placeholder="Masukkan Nama Lengkap atau NIK" required style="border-radius: 10px;">
                </div>`;
                
        } else if (jenis === 'Transfer') {
            htmlFields = `
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label text-muted small fw-bold text-uppercase">Bank Tujuan</label>
                        <select id="field_bank" class="form-select form-select-sm trigger-compile" required style="border-radius: 10px;">
                            <option value="BRI">BRI</option>
                            <option value="BCA">BCA</option>
                            <option value="Mandiri">Mandiri</option>
                            <option value="BNI">BNI</option>
                            <option value="BTN">BTN</option>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label text-muted small fw-bold text-uppercase">No. Rekening</label>
                        <input type="text" id="field_norek" class="form-control form-control-sm trigger-compile" placeholder="Masukkan No Rek" required style="border-radius: 10px;">
                    </div>
                </div>`;
        } else if (jenis === 'Setor Tunai') {
            htmlFields = `
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">No. Rekening Tujuan</label>
                    <input type="text" id="field_setor_rek" class="form-control form-control-sm trigger-compile" placeholder="Masukkan No Rek BRI / Bank Lain" required style="border-radius: 10px;">
                </div>`;
        } else if (jenis === 'TopUp E-Wallet') {
            htmlFields = `
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label text-muted small fw-bold text-uppercase">E-Wallet</label>
                        <select id="field_ewallet" class="form-select form-select-sm trigger-compile" required style="border-radius: 10px;">
                            <option value="DANA">DANA</option>
                            <option value="OVO">OVO</option>
                            <option value="GOPAY">GOPAY</option>
                            <option value="LINKAJA">LINKAJA</option>
                            <option value="SHOPEEPAY">SHOPEEPAY</option>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label text-muted small fw-bold text-uppercase">No. HP / ID</label>
                        <input type="text" id="field_wallet_id" class="form-control form-control-sm trigger-compile" placeholder="08xxxxxxxxxx" required style="border-radius: 10px;">
                    </div>
                </div>`;
        } else if (jenis === 'Pulsa / Data') {
            htmlFields = `
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label text-muted small fw-bold text-uppercase">Provider</label>
                        <select id="field_provider" class="form-select form-select-sm trigger-compile" required style="border-radius: 10px;">
                            <option value="Telkomsel">Telkomsel</option>
                            <option value="Indosat">Indosat</option>
                            <option value="XL">XL Axiata</option>
                            <option value="Axis">Axis</option>
                            <option value="Smartfren">Smartfren</option>
                            <option value="Tri">Tri</option>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label text-muted small fw-bold text-uppercase">No. Handphone</label>
                        <input type="text" id="field_hp" class="form-control form-control-sm trigger-compile" placeholder="08xxxxxxxxxx" required style="border-radius: 10px;">
                    </div>
                </div>`;
        } else if (jenis === 'Token Listrik') {
            htmlFields = `
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">No. Meter / ID Pelanggan</label>
                    <input type="text" id="field_token_id" class="form-control form-control-sm trigger-compile" placeholder="ID Meteran PLN" required style="border-radius: 10px;">
                </div>`;
        } else if (jenis === 'PDAM') {
            htmlFields = `
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label text-muted small fw-bold text-uppercase">Wilayah</label>
                        <input type="text" id="field_pdam_wil" class="form-control form-control-sm trigger-compile" value="Sampit" required style="border-radius: 10px;">
                    </div>
                    <div class="col-7">
                        <label class="form-label text-muted small fw-bold text-uppercase">ID Pelanggan PDAM</label>
                        <input type="text" id="field_pdam_id" class="form-control form-control-sm trigger-compile" placeholder="Masukkan ID Pelanggan" required style="border-radius: 10px;">
                    </div>
                </div>`;
        } else if (jenis === 'Cicilan Finance') {
            htmlFields = `
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label text-muted small fw-bold text-uppercase">Leasing</label>
                        <select id="field_leasing" class="form-select form-select-sm trigger-compile" required style="border-radius: 10px;">
                            <option value="FIF">FIF Group</option>
                            <option value="Adira">Adira Finance</option>
                            <option value="BAF">BAF</option>
                            <option value="WOM">WOM Finance</option>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label text-muted small fw-bold text-uppercase">No. Kontrak</label>
                        <input type="text" id="field_kontrak" class="form-control form-control-sm trigger-compile" placeholder="Masukkan No Kontrak" required style="border-radius: 10px;">
                    </div>
                </div>`;
        } else if (jenis === 'Tarik Tunai') {
            htmlFields = `
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold text-uppercase">No. Kartu / Referensi (Opsional)</label>
                    <input type="text" id="field_tarik_ref" class="form-control form-control-sm trigger-compile" placeholder="Masukkan kode/no kartu penarikan" style="border-radius: 10px;">
                </div>`;
        }

        dynamicFields.innerHTML = htmlFields;

        document.querySelectorAll('.trigger-compile').forEach(el => {
            el.addEventListener('input', compileKeterangan);
            el.addEventListener('change', compileKeterangan);
        });
        compileKeterangan();
    }

    function compileKeterangan() {
        let jenis = document.getElementById('selectJenis').value;
        let inputKet = document.getElementById('inputKeterangan');
        if (jenis === 'Tukar Uang Receh' || jenis === 'Tukar Uang') return;

        let res = "";
        if (jenis === 'Buka Rekening Baru') {
            res = "Paket: " + (document.getElementById('field_paket_rek') ? document.getElementById('field_paket_rek').value : '') + " | Nasabah: " + (document.getElementById('field_nama_nasabah') ? document.getElementById('field_nama_nasabah').value : '');
        } else if (jenis === 'Transfer') {
            res = "Bank: " + (document.getElementById('field_bank') ? document.getElementById('field_bank').value : '') + " | Rek: " + (document.getElementById('field_norek') ? document.getElementById('field_norek').value : '');
        } else if (jenis === 'Setor Tunai') {
            res = "Setor Rek: " + (document.getElementById('field_setor_rek') ? document.getElementById('field_setor_rek').value : '');
        } else if (jenis === 'TopUp E-Wallet') {
            res = (document.getElementById('field_ewallet') ? document.getElementById('field_ewallet').value : '') + " | ID: " + (document.getElementById('field_wallet_id') ? document.getElementById('field_wallet_id').value : '');
        } else if (jenis === 'Pulsa / Data') {
            res = (document.getElementById('field_provider') ? document.getElementById('field_provider').value : '') + " | No: " + (document.getElementById('field_hp') ? document.getElementById('field_hp').value : '');
        } else if (jenis === 'Token Listrik') {
            res = "PLN ID: " + (document.getElementById('field_token_id') ? document.getElementById('field_token_id').value : '');
        } else if (jenis === 'PDAM') {
            res = "PDAM " + (document.getElementById('field_pdam_wil') ? document.getElementById('field_pdam_wil').value : '') + " | ID: " + (document.getElementById('field_pdam_id') ? document.getElementById('field_pdam_id').value : '');
        } else if (jenis === 'Cicilan Finance') {
            res = (document.getElementById('field_leasing') ? document.getElementById('field_leasing').value : '') + " | No: " + (document.getElementById('field_kontrak') ? document.getElementById('field_kontrak').value : '');
        } else if (jenis === 'Tarik Tunai') {
            let ref = document.getElementById('field_tarik_ref') ? document.getElementById('field_tarik_ref').value : '';
            res = ref ? "Ref: " + ref : "Penarikan Tunai";
        }
        inputKet.value = res;
    }

    function hitungPecahan() {
        let totalOut = 0;
        let detailOut = [];
        
        let validStok = true;
        let errMsg = "";
        let alertBox = document.getElementById('alertStokLimit');

        document.querySelectorAll('.out-pecahan').forEach(el => {
            let qty = parseInt(el.value) || 0; 
            let nilai = parseInt(el.getAttribute('data-nilai'));
            let stokMax = parseInt(el.getAttribute('data-stok'));
            let label = el.getAttribute('data-label');

            if (qty > 0) { 
                if (qty > stokMax) {
                    validStok = false;
                    errMsg += `• Sisa stok uang ${label} hanya ${stokMax} lembar! <br>`;
                    el.classList.add('input-error');
                } else {
                    el.classList.remove('input-error');
                    totalOut += (qty * nilai); 
                    detailOut.push((nilai >= 1000 ? nilai/1000 + 'k' : nilai) + "x" + qty); 
                }
            } else {
                el.classList.remove('input-error');
            }
        });

        document.getElementById('totOut').innerText = 'Rp ' + formatRp(totalOut);
        let btnSimpan = document.getElementById('btnSimpan');
        
        if (!validStok) {
            btnSimpan.disabled = true;
            alertBox.innerHTML = errMsg;
            alertBox.style.display = 'block';
            document.getElementById('inputNominal').value = ''; 
            document.getElementById('inputKeterangan').value = '';
        } else if (totalOut > 0) {
            btnSimpan.disabled = false;
            alertBox.style.display = 'none';
            document.getElementById('inputNominal').value = totalOut;
            document.getElementById('inputKeterangan').value = "Keluar: " + detailOut.join(', ');
        } else {
            btnSimpan.disabled = true;
            alertBox.style.display = 'none';
            document.getElementById('inputNominal').value = ''; 
            document.getElementById('inputKeterangan').value = '';
        }
        hitungTotal();
    }

    document.querySelectorAll('.out-pecahan').forEach(item => { item.addEventListener('input', hitungPecahan); });

    function bukaModal(jenisLayanan) {
        <?php if ($cabang_dibekukan): ?>
            alert("MAAF! Cabang ini telah ditutup untuk hari ini."); return;
        <?php elseif (!$status_shift_aktif): ?>
            alert("Harap Set Modal Awal Anda terlebih dahulu."); window.location.href = "set_modal.php"; return;
        <?php endif; ?>

        let selectEl = document.getElementById('selectJenis');
        
        let optionExists = Array.from(selectEl.options).some(opt => opt.value === jenisLayanan);
        if (!optionExists) {
            let newOpt = new Option(jenisLayanan, jenisLayanan);
            selectEl.add(newOpt);
        }

        selectEl.value = jenisLayanan;
        document.getElementById('inputNominal').value = '';
        document.getElementById('inputAdmin').value = '';
        document.getElementById('inputKeterangan').value = '';
        document.getElementById('alertStokLimit').style.display = 'none';
        
        document.querySelectorAll('.out-pecahan').forEach(item => {
            item.value = '';
            item.classList.remove('input-error');
        });
        document.getElementById('teksTotal').innerText = 'Rp 0';

        sesuaikanForm(jenisLayanan);
        new bootstrap.Modal(document.getElementById('modalInput')).show();
    }

    document.getElementById('selectJenis').addEventListener('change', function() { sesuaikanForm(this.value); });
    
    function hitungTotal() {
        let nominal = parseFloat(document.getElementById('inputNominal').value) || 0;
        let admin = parseFloat(document.getElementById('inputAdmin').value) || 0;
        document.getElementById('teksTotal').innerText = 'Rp ' + formatRp(nominal + admin);
    }
</script>

<script>
    const ctx = document.getElementById('pendapatanChart').getContext('2d');
    const labels = <?= json_encode($grafik_tanggal); ?>;
    const dataPendapatan = <?= json_encode($grafik_pendapatan); ?>;

    let gradientFill = ctx.createLinearGradient(0, 0, 0, 300);
    gradientFill.addColorStop(0, "rgba(0, 82, 156, 0.3)"); 
    gradientFill.addColorStop(1, "rgba(0, 82, 156, 0.0)");

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Pendapatan Admin (Rp)',
                data: dataPendapatan,
                borderColor: '#00529C', 
                backgroundColor: gradientFill,
                borderWidth: 3, pointBackgroundColor: '#ffffff', pointBorderColor: '#00529C',
                pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6, fill: true, tension: 0.4
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { legend: { display: false }, tooltip: { padding: 12, cornerRadius: 8, backgroundColor: '#1a1a1a' } }, 
            scales: { 
                y: { border: {display: false}, beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#1a1a1a', callback: function(value) { return 'Rp ' + value.toLocaleString('id-ID'); } } }, 
                x: { border: {display: false}, ticks: { color: '#1a1a1a' }, grid: { display: false } } 
            } 
        }
    });
</script>
</body>
</html>
