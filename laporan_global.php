<?php
require 'config.php';
// Pastikan Zona Waktu Akurat
date_default_timezone_set('Asia/Jakarta');

if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// --- MENGELOLA FILTER PENCARIAN ---
$tgl_mulai = isset($_GET['tgl_mulai']) ? $conn->real_escape_string($_GET['tgl_mulai']) : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $conn->real_escape_string($_GET['tgl_akhir']) : date('Y-m-d');
$filter_cabang = isset($_GET['cabang']) ? $conn->real_escape_string($_GET['cabang']) : 'all';

// Membuat Query Dinamis berdasarkan filter
$kondisi_query = "WHERE t.tanggal BETWEEN '$tgl_mulai' AND '$tgl_akhir'";
if ($filter_cabang != 'all') {
    $kondisi_query .= " AND u.cabang_id = '$filter_cabang'";
}

// =========================================================================
// FITUR BARU: EXPORT KE EXCEL
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $filename = "Laporan_BRILink_" . $tgl_mulai . "_sd_" . $tgl_akhir . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='1'>";
    echo "<tr>
            <th>No</th><th>Tanggal</th><th>Waktu</th><th>Cabang</th><th>Shift</th>
            <th>Layanan</th><th>Sumber Dana</th><th>Keterangan</th>
            <th>Nominal (Rp)</th><th>Laba Admin (Rp)</th><th>Status</th>
          </tr>";

    $q_export = $conn->query("
        SELECT t.*, c.nama_cabang, u.shift_ke 
        FROM transactions t 
        JOIN users u ON t.user_id = u.id 
        JOIN cabang c ON u.cabang_id = c.id 
        $kondisi_query 
        ORDER BY t.tanggal DESC, t.waktu DESC, t.id DESC
    ");

    $no = 1;
    while ($r = $q_export->fetch_assoc()) {
        $status = (isset($r['status']) && $r['status'] == 'batal') ? 'DIBATALKAN' : 'SUKSES';
        echo "<tr>";
        echo "<td>" . $no++ . "</td>";
        echo "<td>" . $r['tanggal'] . "</td>";
        echo "<td>" . ($r['waktu'] ?? '-') . "</td>";
        echo "<td>" . $r['nama_cabang'] . "</td>";
        echo "<td>Shift " . $r['shift_ke'] . "</td>";
        echo "<td>" . $r['jenis_transaksi'] . "</td>";
        echo "<td>" . $r['bank_agen'] . "</td>";
        echo "<td>" . htmlspecialchars($r['keterangan']) . "</td>";
        echo "<td>" . $r['nominal'] . "</td>";
        echo "<td>" . $r['admin_fee'] . "</td>";
        echo "<td>" . $status . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit; // Berhenti di sini agar HTML halaman tidak ikut terdownload
}

// --- MENGHITUNG RINGKASAN METRIK ---
// Hanya menghitung yang statusnya bukan 'batal'
$q_ringkasan = $conn->query("
    SELECT 
        SUM(CASE WHEN t.jenis_transaksi NOT IN ('Tukar Uang', 'Pengeluaran / Rugi') AND (t.status IS NULL OR t.status != 'batal') THEN t.nominal ELSE 0 END) as tot_nominal, 
        SUM(CASE WHEN t.status IS NULL OR t.status != 'batal' THEN t.admin_fee ELSE 0 END) as tot_admin, 
        SUM(CASE WHEN t.jenis_transaksi = 'Pengeluaran / Rugi' AND (t.status IS NULL OR t.status != 'batal') THEN t.nominal ELSE 0 END) as tot_rugi,
        COUNT(t.id) as tot_trx 
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    $kondisi_query
");
$d_ringkasan = $q_ringkasan->fetch_assoc();

$total_perputaran = !empty($d_ringkasan['tot_nominal']) ? $d_ringkasan['tot_nominal'] : 0;
$total_laba       = !empty($d_ringkasan['tot_admin']) ? $d_ringkasan['tot_admin'] : 0;
$total_rugi       = !empty($d_ringkasan['tot_rugi']) ? $d_ringkasan['tot_rugi'] : 0;
$total_transaksi  = !empty($d_ringkasan['tot_trx']) ? $d_ringkasan['tot_trx'] : 0;

$laba_aktual = $total_laba - $total_rugi;

$nama_cabang_cetak = "SEMUA CABANG";
if ($filter_cabang != 'all') {
    $q_nama_cab = $conn->query("SELECT nama_cabang FROM cabang WHERE id = '$filter_cabang'");
    if($q_nama_cab && $q_nama_cab->num_rows > 0) $nama_cabang_cetak = $q_nama_cab->fetch_assoc()['nama_cabang'];
}

// =========================================================================
// FITUR BARU: PAGINATION (ANTI-LAG)
// =========================================================================
$limit = 100; // Tampilkan 100 data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Total Data untuk Pagination
$q_total_data = $conn->query("SELECT COUNT(t.id) as total FROM transactions t JOIN users u ON t.user_id = u.id $kondisi_query");
$total_data = $q_total_data->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

function build_url($page_num, $is_export = false) {
    global $tgl_mulai, $tgl_akhir, $filter_cabang;
    $url = "?tgl_mulai=$tgl_mulai&tgl_akhir=$tgl_akhir&cabang=$filter_cabang";
    if ($is_export) return $url . "&export=excel";
    return $url . "&page=$page_num";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan Global - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        .metric-card:hover i { transform: rotate(0deg) scale(1.1); opacity: 0.25; }
        
        .bg-trx { background: linear-gradient(135deg, #0dcaf0 0%, #087990 100%); color: var(--bri-white); }
        .bg-putar { background: linear-gradient(135deg, #003366 0%, var(--bri-blue) 100%); color: var(--bri-white); }
        .bg-laba { background: linear-gradient(135deg, #b3d4ff 0%, var(--bri-light-blue) 100%); color: var(--bri-blue); }
        .bg-rugi { background: linear-gradient(135deg, #dc3545 0%, #a71d2a 100%); color: var(--bri-white); }

        .form-control, .form-select { border-radius: 14px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 12px 15px; color: var(--bri-black); font-weight: 500; }
        .form-control:focus, .form-select:focus { box-shadow: none; border-color: var(--bri-blue); }
        .form-label { color: var(--bri-black); font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.7; }
        
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); border-radius: 50px; padding: 12px 28px; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        .btn-outline-modern { border: 2px solid var(--bri-grey); color: var(--bri-black); border-radius: 50px; padding: 10px 28px; font-weight: 600; transition: all 0.3s ease; }
        .btn-outline-modern:hover { background: var(--bri-grey); }

        .table-wrapper { max-height: 650px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-track { background: transparent; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--bri-grey); border-radius: 10px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: var(--bri-white); z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); font-weight: 700; padding: 15px; font-size: 13px; text-transform: uppercase; }
        .table-wrapper tbody td { padding: 15px; border-bottom: 1px solid var(--bri-grey); vertical-align: middle; font-size: 14px; }

        /* Styling baris batal */
        .row-batal td { text-decoration: line-through; color: #a0a0a0 !important; }
        .row-batal .badge { text-decoration: none !important; opacity: 0.6; }

        .pagination .page-link { color: var(--bri-blue); font-weight: bold; border-radius: 8px; margin: 0 3px; border: none; background: #f8f9fa; }
        .pagination .page-item.active .page-link { background-color: var(--bri-blue); color: white; box-shadow: 0 4px 10px rgba(0,82,156,0.3); }

        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; }
        }

        .print-only { display: none; }
        @media print {
            body { background-color: white; font-size: 12px; }
            .sidebar, .btn, .filter-section, .no-print, .metric-card i, .pagination { display: none !important; }
            .main-content { margin-left: 0; padding: 0; }
            .modern-card, .metric-card { box-shadow: none !important; border: 1px solid #000 !important; padding: 15px !important; margin-bottom: 15px; background: white !important; color: black !important; }
            .metric-card p, .metric-card h3 { color: black !important; }
            .print-only { display: block; text-align: center; margin-bottom: 20px; }
            .table-wrapper { max-height: none !important; overflow: visible !important; }
            table { font-size: 11px !important; width: 100% !important; border-collapse: collapse; }
            table th, table td { border: 1px solid #000 !important; padding: 6px !important; }
        }
    </style>
</head>
<body>
    <div class="sidebar no-print">
        <?php include 'sidebar.php'; ?>
    </div>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 no-print">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Laporan Global</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Tarik data rekapitulasi, export Excel, dan pantau pembatalan</p>
            </div>
            
            <div class="d-flex gap-2 align-self-start">
                <a href="<?= build_url(1, true); ?>" class="btn btn-success fw-bold rounded-pill shadow-sm" style="padding: 12px 24px;">
                    <i class="bi bi-file-earmark-excel-fill me-2"></i> Export Excel
                </a>
                <button onclick="window.print()" class="btn btn-dark fw-bold rounded-pill shadow-sm" style="padding: 12px 24px;">
                    <i class="bi bi-printer-fill me-2"></i> Cetak A4
                </button>
            </div>
        </div>

        <div class="print-only">
            <h3 style="font-weight: bold; margin-bottom: 2px;">REKAPITULASI TRANSAKSI BRILINK</h3>
            <p style="margin-bottom: 2px; font-size: 14px;"><strong>Cabang Terpilih:</strong> <?= $nama_cabang_cetak; ?></p>
            <p style="font-size: 14px; border-bottom: 2px solid #000; padding-bottom: 10px;"><strong>Periode:</strong> <?= date('d/m/Y', strtotime($tgl_mulai)); ?> s.d <?= date('d/m/Y', strtotime($tgl_akhir)); ?></p>
        </div>

        <div class="modern-card mb-4 filter-section">
            <form method="GET" class="row align-items-end g-3">
                <div class="col-md-3">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="tgl_mulai" class="form-control" value="<?= $tgl_mulai; ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="tgl_akhir" class="form-control" value="<?= $tgl_akhir; ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Filter Cabang</label>
                    <select name="cabang" class="form-select">
                        <option value="all" <?= ($filter_cabang == 'all') ? 'selected' : ''; ?>>-- Semua Cabang --</option>
                        <?php
                        $q_list_cabang = $conn->query("SELECT * FROM cabang");
                        while($c = $q_list_cabang->fetch_assoc()) {
                            $sel = ($filter_cabang == $c['id']) ? 'selected' : '';
                            echo "<option value='{$c['id']}' $sel>{$c['nama_cabang']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-modern fw-bold w-100">Terapkan</button>
                    <a href="laporan_global.php" class="btn btn-outline-modern w-100 text-center text-decoration-none">Reset</a>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="metric-card bg-trx">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Total Transaksi</p>
                    <h3 class="fw-bolder mb-0"><?= number_format($total_transaksi, 0, ',', '.'); ?> <span class="fs-6 opacity-75 fw-normal">Trx</span></h3>
                    <i class="bi bi-receipt"></i>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="metric-card bg-putar">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Total Perputaran</p>
                    <h3 class="fw-bolder mb-0">Rp <?= number_format($total_perputaran, 0, ',', '.'); ?></h3>
                    <i class="bi bi-arrow-repeat"></i>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="metric-card bg-rugi">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.8;">Total Pengeluaran / Rugi</p>
                    <h3 class="fw-bolder mb-0">- Rp <?= number_format($total_rugi, 0, ',', '.'); ?></h3>
                    <i class="bi bi-graph-down-arrow"></i>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="metric-card bg-laba">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.7;">Laba Bersih Aktual</p>
                    <h3 class="fw-bolder mb-0 <?= $laba_aktual < 0 ? 'text-danger' : ''; ?>">
                        <?= $laba_aktual >= 0 ? '+' : ''; ?> Rp <?= number_format($laba_aktual, 0, ',', '.'); ?>
                    </h3>
                    <i class="bi bi-graph-up-arrow" style="color: var(--bri-blue); opacity: 0.1;"></i>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3 filter-section">
                <h5 class="fw-bold text-dark mb-0" style="color: var(--bri-black) !important;">Rincian Transaksi Filter</h5>
                <span class="badge bg-secondary">Total: <?= number_format($total_data, 0, ',', '.'); ?> Baris Data</span>
            </div>

            <div class="table-responsive table-wrapper">
                <table class="table table-borderless align-middle mb-0 text-nowrap">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Waktu</th>
                            <th>Cabang & Shift</th>
                            <th>Layanan</th>
                            <th>Sumber</th>
                            <th>Keterangan / Tujuan</th>
                            <th class="text-end">Nominal</th>
                            <th class="text-end">Admin Jasa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_trans = $conn->query("
                            SELECT t.*, c.nama_cabang, u.shift_ke 
                            FROM transactions t 
                            JOIN users u ON t.user_id = u.id 
                            JOIN cabang c ON u.cabang_id = c.id 
                            $kondisi_query 
                            ORDER BY t.tanggal DESC, t.waktu DESC, t.id DESC
                            LIMIT $limit OFFSET $offset
                        ");
                        
                        $no = $offset + 1;
                        if ($q_trans->num_rows > 0):
                            while ($row = $q_trans->fetch_assoc()):
                                $is_rugi = ($row['jenis_transaksi'] == 'Pengeluaran / Rugi');
                                $is_batal = (isset($row['status']) && $row['status'] == 'batal');
                                
                                $row_class = $is_batal ? 'row-batal bg-light' : ($is_rugi ? 'bg-danger bg-opacity-10' : '');
                        ?>
                            <tr class="<?= $row_class; ?>">
                                <td class="text-muted fw-bold"><?= $no++; ?></td>
                                <td class="fw-medium text-muted">
                                    <span class="d-block text-dark fw-bold"><?= date('d/m/y', strtotime($row['tanggal'])); ?></span>
                                    <small><?= $row['waktu'] ?? '-'; ?> WIB</small>
                                </td>
                                <td>
                                    <strong style="color: var(--bri-blue);"><?= $row['nama_cabang']; ?></strong><br>
                                    <small class="text-muted fw-semibold">Shift <?= $row['shift_ke']; ?></small>
                                </td>
                                <td>
                                    <?php if($is_batal): ?>
                                        <span class="badge bg-danger rounded-pill px-3 py-2 fw-bold"><i class="bi bi-x-circle me-1"></i>DIBATALKAN</span>
                                    <?php elseif($is_rugi): ?>
                                        <span class="badge bg-danger rounded-pill px-3 py-2 fw-bold">Pengeluaran / Rugi</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill px-3 py-2" style="background-color: var(--bri-light-blue); color: var(--bri-blue); font-weight: 600;"><?= $row['jenis_transaksi']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($row['bank_agen'] == 'CASH'): ?>
                                        <span class="fw-bold text-success"><i class="bi bi-cash me-1"></i>CASH</span>
                                    <?php elseif($row['bank_agen'] != '-'): ?>
                                        <span class="fw-bold text-primary"><i class="bi bi-bank me-1"></i><?= $row['bank_agen']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="d-inline-block text-wrap" style="max-width: 250px; line-height: 1.3; font-size: 12px;"><?= empty($row['keterangan']) ? '-' : htmlspecialchars($row['keterangan']); ?></span></td>
                                <td class="fw-bold <?= $is_rugi ? 'text-danger' : 'text-dark'; ?> text-end">
                                    <?= ($row['jenis_transaksi'] == 'Tukar Uang') ? '-' : 'Rp ' . number_format($row['nominal'], 0, ',', '.'); ?>
                                </td>
                                <td class="text-success fw-bolder text-end">+ Rp <?= number_format($row['admin_fee'], 0, ',', '.'); ?></td>
                            </tr>
                        <?php 
                            endwhile; 
                        else: 
                        ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted fw-medium border-0">Tidak ada data transaksi yang ditemukan pada rentang tanggal/cabang ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-center mt-4 no-print">
                <ul class="pagination pagination-sm mb-0 shadow-sm rounded-3">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= build_url($page - 1) ?>"><i class="bi bi-chevron-left"></i> Sebelumnya</a>
                    </li>
                    <?php 
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                            <a class="page-link" href="<?= build_url($i) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= build_url($page + 1) ?>">Selanjutnya <i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>

        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>