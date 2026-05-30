<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

$tanggal_hari_ini = date('Y-m-d');

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
// 2. LOGIKA FILTER GRAFIK & DATA (DIPERBAIKI)
// ==========================================

// Fungsi Bantuan (Helper) agar PHP membaca angka dari Database dengan aman & akurat
function fetchSumVal($conn, $sql) {
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return !empty($row['val']) ? (float)$row['val'] : 0;
    }
    return 0;
}

$periode = isset($_GET['periode']) ? $_GET['periode'] : 'mingguan';

$grafik_label = [];
$grafik_laba = [];
$bal_in = [];
$bal_out = [];
$tgl_start = date('Y-m-d', strtotime("-6 days")); // Default mingguan

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
    // Mingguan
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $grafik_label[] = date('d/m', strtotime($d));
        
        $grafik_laba[] = fetchSumVal($conn, "SELECT SUM(admin_fee) as val FROM transactions WHERE tanggal = '$d'");
        $bal_in[]      = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE tanggal = '$d' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
        $bal_out[]     = fetchSumVal($conn, "SELECT SUM(nominal) as val FROM transactions WHERE tanggal = '$d' AND jenis_transaksi = 'Tarik Tunai'");
    }
}

// Data Pie Chart (Layanan Terlaris berdasarkan periode filter)
$kondisi_tgl = "WHERE tanggal >= '$tgl_start'";
$pie_label = [];
$pie_data = [];
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
            
            <a href="laporan_global.php" class="btn btn-dark fw-bold rounded-pill px-4 shadow-sm align-self-start" style="padding: 12px 28px;">
                <i class="bi bi-file-earmark-text me-2"></i> Laporan Lengkap
            </a>
        </div>

        <div class="row g-3 mb-4">
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
            <div class="col-lg-5">
                <div class="modern-card h-100">
                    <h6 class="fw-bold text-dark mb-4 border-bottom pb-3" style="color: var(--bri-black) !important;">Status Cabang Hari Ini</h6>
                    <div class="table-responsive table-wrapper">
                        <table class="table table-borderless align-middle mb-0" style="font-size: 13px;">
                            <thead>
                                <tr>
                                    <th>Cabang & Shift</th>
                                    <th>Modal Laci</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $q_status_cabang = $conn->query("
                                    SELECT c.nama_cabang, s.shift_ke, s.modal_awal, s.status 
                                    FROM shifts s 
                                    JOIN users u ON s.user_id = u.id 
                                    JOIN cabang c ON u.cabang_id = c.id 
                                    WHERE s.tanggal = '$tanggal_hari_ini' 
                                    ORDER BY s.id DESC
                                ");
                                
                                if ($q_status_cabang->num_rows > 0):
                                    while ($row_cabang = $q_status_cabang->fetch_assoc()):
                                        $badge_class = ($row_cabang['status'] == 'aktif') ? 'bg-success text-white' : 'bg-secondary text-white';
                                ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--bri-blue);"><?= $row_cabang['nama_cabang']; ?></strong><br>
                                            <small class="text-muted fw-semibold">Shift <?= $row_cabang['shift_ke']; ?></small>
                                        </td>
                                        <td class="fw-bold" style="color: var(--bri-black);">Rp <?= number_format($row_cabang['modal_awal'], 0, ',', '.'); ?></td>
                                        <td><span class="badge rounded-pill px-3 py-2 <?= $badge_class; ?>"><?= ucfirst($row_cabang['status']); ?></span></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="3" class="text-center text-muted py-5 fw-medium">Belum ada cabang yang buka hari ini.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="modern-card h-100">
                    <h6 class="fw-bold text-dark mb-4 border-bottom pb-3" style="color: var(--bri-black) !important;">Transaksi Live (50 Terakhir)</h6>
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
                                
                                if ($q_trans_global->num_rows > 0):
                                    while ($row_trans = $q_trans_global->fetch_assoc()):
                                ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--bri-black);"><?= $row_trans['nama_cabang']; ?></strong><br>
                                            <span class="badge mt-1" style="background-color: var(--bri-light-blue); color: var(--bri-blue); font-weight: 600;"><?= $row_trans['jenis_transaksi']; ?></span>
                                            <small class="text-muted ms-1"><?= date('d/m', strtotime($row_trans['tanggal'])); ?></small>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const formatRp = (value) => { return 'Rp ' + value.toLocaleString('id-ID'); };

        // 1. GRAFIK GARIS (TREN LABA ADMIN)
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

        // 2. GRAFIK LINGKARAN (LAYANAN TERLARIS)
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

        // 3. GRAFIK BATANG (BALANCE IN VS OUT)
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
    </script>
</body>
</html>