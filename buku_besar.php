<?php
require 'config.php';
// Pastikan Zona Waktu Akurat
date_default_timezone_set('Asia/Jakarta');

if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { 
    header("Location: index.php"); 
    exit; 
}

// Ambil daftar rekening dari database untuk opsi filter
$rekening_list = [];
$q_rek = $conn->query("SELECT alias FROM rekening ORDER BY alias ASC");
if ($q_rek) {
    while($r = $q_rek->fetch_assoc()) { 
        $rekening_list[] = $r['alias']; 
    }
}

// Inisialisasi Filter
$filter_akun = isset($_GET['akun']) ? $conn->real_escape_string($_GET['akun']) : 'CASH';
$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : date('Y-m-d');

// Logika Filter SQL per Akun
$sql_akun_filter = "";
if ($filter_akun === 'CASH') {
    // Relevan untuk laci: Tarik Tunai, Tukar Uang, atau transaksi digital yang masuk laci
    $sql_akun_filter = " AND (t.jenis_transaksi IN ('Tarik Tunai', 'Tukar Uang') OR t.bank_agen != '-')";
    // Logika Debet/Kredit dalam SQL
    $sql_debet = "CASE WHEN t.jenis_transaksi = 'Tukar Uang' THEN t.admin_fee WHEN t.jenis_transaksi != 'Tarik Tunai' AND t.bank_agen != '-' THEN t.nominal + t.admin_fee ELSE 0 END";
    $sql_kredit = "CASE WHEN t.jenis_transaksi = 'Tarik Tunai' THEN t.nominal ELSE 0 END";
} else {
    // Relevan untuk bank
    $sql_akun_filter = " AND t.bank_agen = '$filter_akun'";
    // Logika Debet/Kredit dalam SQL
    $sql_debet = "CASE WHEN t.jenis_transaksi = 'Tarik Tunai' THEN t.nominal ELSE 0 END";
    $sql_kredit = "CASE WHEN t.jenis_transaksi != 'Tarik Tunai' AND t.jenis_transaksi != 'Tukar Uang' THEN t.nominal ELSE 0 END";
}

// =========================================================================
// FITUR BARU: EXPORT KE EXCEL
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $filename = "Buku_Besar_" . $filter_akun . "_" . $start_date . "_sd_" . $end_date . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='1'>";
    echo "<tr><th colspan='7'>BUKU BESAR - AKUN: $filter_akun</th></tr>";
    echo "<tr><th colspan='7'>Periode: $start_date s/d $end_date</th></tr>";
    echo "<tr>
            <th>Tanggal</th><th>No. Referensi</th><th>Kasir</th>
            <th>Keterangan Transaksi</th><th>Debet (Masuk)</th><th>Kredit (Keluar)</th><th>Mutasi Berjalan</th>
          </tr>";

    $q_export = $conn->query("SELECT t.*, u.username FROM transactions t LEFT JOIN users u ON t.user_id = u.id WHERE DATE(t.tanggal) BETWEEN '$start_date' AND '$end_date' $sql_akun_filter ORDER BY t.tanggal ASC, t.id ASC");
    
    $mutasi_excel = 0;
    while ($row = $q_export->fetch_assoc()) {
        $debet = 0; $kredit = 0; $is_relevant = false;

        // Perhitungan Akuntansi
        if ($filter_akun === 'CASH') {
            if ($row['jenis_transaksi'] == 'Tarik Tunai') { $kredit = $row['nominal']; $is_relevant = true; } 
            elseif ($row['jenis_transaksi'] == 'Tukar Uang') { $debet = $row['admin_fee']; $is_relevant = true; } 
            else { if ($row['bank_agen'] != '-') { $debet = $row['nominal'] + $row['admin_fee']; $is_relevant = true; } }
        } else {
            if ($row['bank_agen'] === $filter_akun) {
                $is_relevant = true;
                if ($row['jenis_transaksi'] == 'Tarik Tunai') { $debet = $row['nominal']; } 
                elseif ($row['jenis_transaksi'] != 'Tukar Uang') { $kredit = $row['nominal']; }
            }
        }

        if ($is_relevant && ($debet > 0 || $kredit > 0 || (isset($row['status']) && $row['status'] == 'batal'))) {
            $mutasi_excel += ($debet - $kredit);
            $batal_tag = (isset($row['status']) && $row['status'] == 'batal') ? '[DIBATALKAN] ' : '';
            
            echo "<tr>";
            echo "<td>" . date('d/m/Y H:i', strtotime($row['tanggal'] . ' ' . ($row['waktu'] ?? '00:00:00'))) . "</td>";
            echo "<td>TRX-" . str_pad($row['id'], 4, '0', STR_PAD_LEFT) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . $batal_tag . $row['jenis_transaksi'] . " - " . htmlspecialchars($row['keterangan']) . "</td>";
            echo "<td>" . $debet . "</td>";
            echo "<td>" . $kredit . "</td>";
            echo "<td>" . $mutasi_excel . "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    exit;
}

// =========================================================================
// PAGINATION & PERHITUNGAN MUTASI BERJALAN (ADVANCED ACCOUNTING ALGORITHM)
// =========================================================================
$limit = 100; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Total Data untuk Pagination
$q_total_data = $conn->query("SELECT COUNT(t.id) as total FROM transactions t WHERE DATE(t.tanggal) BETWEEN '$start_date' AND '$end_date' $sql_akun_filter");
$total_data = $q_total_data->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

// Menghitung Sisa Saldo Mutasi Berjalan dari halaman-halaman sebelumnya (Jika ada)
$prev_mutasi = 0;
if ($offset > 0) {
    $q_prev = $conn->query("
        SELECT SUM($sql_debet) as prev_debet, SUM($sql_kredit) as prev_kredit
        FROM (
            SELECT t.* FROM transactions t
            WHERE DATE(t.tanggal) BETWEEN '$start_date' AND '$end_date' $sql_akun_filter 
            ORDER BY t.tanggal ASC, t.id ASC
            LIMIT $offset
        ) as sub
    ");
    if ($q_prev) {
        $prev_totals = $q_prev->fetch_assoc();
        $prev_mutasi = ($prev_totals['prev_debet'] ?? 0) - ($prev_totals['prev_kredit'] ?? 0);
    }
}

// Query Utama untuk Halaman Saat Ini
$query_transaksi = "SELECT t.*, u.username 
                    FROM transactions t 
                    LEFT JOIN users u ON t.user_id = u.id
                    WHERE DATE(t.tanggal) BETWEEN '$start_date' AND '$end_date' $sql_akun_filter
                    ORDER BY t.tanggal ASC, t.id ASC
                    LIMIT $limit OFFSET $offset";
$result_transaksi = $conn->query($query_transaksi);

// Hitung Grand Total Keseluruhan (Untuk Footer)
$q_grand = $conn->query("SELECT SUM($sql_debet) as grand_debet, SUM($sql_kredit) as grand_kredit FROM transactions t WHERE DATE(t.tanggal) BETWEEN '$start_date' AND '$end_date' $sql_akun_filter");
$grand_totals = $q_grand->fetch_assoc();
$grand_debet = $grand_totals['grand_debet'] ?? 0;
$grand_kredit = $grand_totals['grand_kredit'] ?? 0;
$grand_mutasi = $grand_debet - $grand_kredit;

function build_url($page_num, $is_export = false) {
    global $start_date, $end_date, $filter_akun;
    $url = "?start_date=$start_date&end_date=$end_date&akun=$filter_akun";
    if ($is_export) return $url . "&export=excel";
    return $url . "&page=$page_num";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan Buku Besar - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; --bri-black: #1a1a1a; --bri-grey: #e9ecef; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 20px; padding: 25px; border: none; box-shadow: 0 5px 20px rgba(0,0,0,0.03); }
        
        .form-control, .form-select { border-radius: 12px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 10px 15px; font-weight: 500; }
        .form-control:focus, .form-select:focus { box-shadow: none; border-color: var(--bri-blue); }

        .table-wrapper { max-height: 650px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--bri-grey); border-radius: 10px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: #f8f9fa; z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); }

        /* Styling Batal / Void */
        .row-batal td { text-decoration: line-through; color: #a0a0a0 !important; }
        .row-batal .badge { text-decoration: none !important; opacity: 0.6; }

        .pagination .page-link { color: var(--bri-blue); font-weight: bold; border-radius: 8px; margin: 0 3px; border: none; background: #f8f9fa; }
        .pagination .page-item.active .page-link { background-color: var(--bri-blue); color: white; box-shadow: 0 4px 10px rgba(0,82,156,0.3); }

        /* CSS KHUSUS CETAK PDF / KERTAS */
        #print-header { display: none; }
        @media print {
            body { background-color: white !important; margin: 0; padding: 0; }
            .sidebar-modern, .mobile-header, .no-print, .pagination { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 20px !important; }
            .modern-card { box-shadow: none !important; border: none !important; padding: 0 !important; }
            .table-wrapper { max-height: none !important; overflow: visible !important; }
            
            #print-header { display: block; margin-bottom: 25px; }
            .cetak-kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 15px; margin-bottom: 20px; }
            .cetak-kop h2 { margin: 0 0 5px 0; font-weight: bold; font-size: 24px; text-transform: uppercase; letter-spacing: 1px; }
            .cetak-kop h4 { margin: 0 0 10px 0; font-size: 16px; font-weight: normal; color: #333; }
            .cetak-info { display: flex; justify-content: center; gap: 40px; font-size: 14px; }
            
            table { width: 100% !important; border-collapse: collapse !important; font-size: 12px; color: black; }
            table th, table td { border: 1px solid #000 !important; padding: 8px !important; }
            table th { background-color: #f8f9fa !important; text-align: center; font-weight: bold; }
            .text-success, .text-danger, .text-primary { color: black !important; }
        }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        
        <div id="print-header">
            <div class="cetak-kop">
                <h2>Laporan Buku Besar</h2>
                <h4>(General Ledger - Mutasi Transaksi Cabang)</h4>
                <div class="cetak-info">
                    <div><strong>Akun / Kas:</strong> <?= htmlspecialchars($filter_akun); ?></div>
                    <div><strong>Periode:</strong> <?= date('d F Y', strtotime($start_date)); ?> s/d <?= date('d F Y', strtotime($end_date)); ?></div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 no-print gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1"><i class="bi bi-book-half text-primary me-2"></i>Laporan Buku Besar</h3>
                <p class="text-muted mb-0" style="font-size: 14px;">Memantau rincian pergerakan debet (masuk) dan kredit (keluar) akun.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= build_url(1, true); ?>" class="btn btn-success rounded-pill px-4 shadow-sm fw-bold">
                    <i class="bi bi-file-earmark-excel-fill me-2"></i>Export Excel
                </a>
                <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 shadow-sm fw-bold">
                    <i class="bi bi-printer me-2"></i>Cetak PDF
                </button>
            </div>
        </div>

        <div class="modern-card mb-4 no-print">
            <form method="GET" action="buku_besar.php" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold text-muted small">Pilih Akun Kas/Bank</label>
                    <select name="akun" class="form-select border-secondary shadow-sm">
                        <option value="CASH" <?= $filter_akun == 'CASH' ? 'selected' : ''; ?>>Laci Tunai (CASH)</option>
                        <?php foreach($rekening_list as $rek): ?>
                            <option value="<?= htmlspecialchars($rek); ?>" <?= $filter_akun == $rek ? 'selected' : ''; ?>><?= htmlspecialchars($rek); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold text-muted small">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control shadow-sm" value="<?= $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold text-muted small">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control shadow-sm" value="<?= $end_date; ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm" style="border-radius: 12px; padding: 10px;">Tampilkan Mutasi</button>
                </div>
            </form>
        </div>

        <div class="modern-card">
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <h5 class="fw-bold mb-0">Rincian Mutasi Akun: <span class="text-primary"><?= htmlspecialchars($filter_akun); ?></span></h5>
                <span class="badge bg-secondary">Total: <?= number_format($total_data, 0, ',', '.'); ?> Baris Data</span>
            </div>
            
            <div class="table-responsive table-wrapper">
                <table class="table table-hover align-middle text-nowrap">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal & Waktu</th>
                            <th>No. Referensi</th>
                            <th>Kasir</th>
                            <th>Keterangan Transaksi</th>
                            <th class="text-end">Debet (Masuk)</th>
                            <th class="text-end">Kredit (Keluar)</th>
                            <th class="text-end">Mutasi Berjalan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $mutasi_berjalan = $prev_mutasi; // Meneruskan saldo dari halaman sebelumnya
                        $has_data = false;

                        if ($result_transaksi && $result_transaksi->num_rows > 0) {
                            while ($row = $result_transaksi->fetch_assoc()) {
                                $debet = 0;
                                $kredit = 0;
                                $is_relevant = false;

                                // LOGIK PERHITUNGAN MUTASI BRILINK
                                if ($filter_akun === 'CASH') {
                                    if ($row['jenis_transaksi'] == 'Tarik Tunai') {
                                        $kredit = $row['nominal']; $is_relevant = true;
                                    } elseif ($row['jenis_transaksi'] == 'Tukar Uang') {
                                        $debet = $row['admin_fee']; $is_relevant = true;
                                    } else {
                                        if ($row['bank_agen'] != '-') { 
                                            $debet = $row['nominal'] + $row['admin_fee']; $is_relevant = true;
                                        }
                                    }
                                } else {
                                    if ($row['bank_agen'] === $filter_akun) {
                                        $is_relevant = true;
                                        if ($row['jenis_transaksi'] == 'Tarik Tunai') {
                                            $debet = $row['nominal']; 
                                        } elseif ($row['jenis_transaksi'] != 'Tukar Uang') {
                                            $kredit = $row['nominal']; 
                                        }
                                    }
                                }

                                $is_batal = (isset($row['status']) && $row['status'] == 'batal');

                                if ($is_relevant && ($debet > 0 || $kredit > 0 || $is_batal)) {
                                    $has_data = true;
                                    $mutasi_berjalan += ($debet - $kredit);
                                    
                                    $row_class = $is_batal ? 'row-batal bg-light' : '';
                                    ?>
                                    <tr class="<?= $row_class; ?>">
                                        <td style="font-size: 13px;">
                                            <span class="fw-bold text-dark d-block"><?= date('d/m/Y', strtotime($row['tanggal'])); ?></span>
                                            <small class="text-muted"><?= $row['waktu'] ?? '-'; ?> WIB</small>
                                        </td>
                                        <td style="font-size: 13px;" class="text-muted">TRX-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                        <td style="font-size: 13px;"><?= htmlspecialchars($row['username']); ?></td>
                                        <td>
                                            <?php if($is_batal): ?>
                                                <span class="badge bg-danger rounded-pill mb-1">DIBATALKAN</span><br>
                                            <?php else: ?>
                                                <span class="fw-bold"><?= $row['jenis_transaksi']; ?></span><br>
                                            <?php endif; ?>
                                            <small class="text-muted d-inline-block text-wrap" style="max-width: 250px;"><?= htmlspecialchars($row['keterangan']); ?></small>
                                        </td>
                                        <td class="text-end fw-bold <?= $is_batal ? 'text-muted' : 'text-success'; ?>">
                                            <?= $debet > 0 ? 'Rp ' . number_format($debet, 0, ',', '.') : '-'; ?>
                                        </td>
                                        <td class="text-end fw-bold <?= $is_batal ? 'text-muted' : 'text-danger'; ?>">
                                            <?= $kredit > 0 ? 'Rp ' . number_format($kredit, 0, ',', '.') : '-'; ?>
                                        </td>
                                        <td class="text-end fw-bold text-primary bg-light">
                                            Rp <?= number_format($mutasi_berjalan, 0, ',', '.'); ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            }
                        }

                        if (!$has_data): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted fw-medium">Tidak ada pergerakan mutasi untuk akun dan periode ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="4" class="text-end fw-bold" style="text-transform: uppercase;">Total Mutasi Keseluruhan (All Pages):</td>
                            <td class="text-end fw-bold text-success">Rp <?= number_format($grand_debet, 0, ',', '.'); ?></td>
                            <td class="text-end fw-bold text-danger">Rp <?= number_format($grand_kredit, 0, ',', '.'); ?></td>
                            <td class="text-end fw-bold text-white fs-6">Rp <?= number_format($grand_mutasi, 0, ',', '.'); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-center mt-4 no-print">
                <ul class="pagination pagination-sm mb-0 shadow-sm rounded-3">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= build_url($page - 1) ?>"><i class="bi bi-chevron-left"></i> Prev</a>
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
                        <a class="page-link" href="<?= build_url($page + 1) ?>">Next <i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
            
            <div id="print-signature" class="d-none mt-5 text-center d-print-flex justify-content-between px-5">
                <div style="width: 200px;">
                    <p class="mb-5 pb-4">Mengetahui,<br><strong>Pimpinan / Pemilik</strong></p>
                    <div style="border-bottom: 1px solid #000; width: 100%;"></div>
                </div>
                <div style="width: 200px;">
                    <p class="mb-5 pb-4">Dibuat Oleh,<br><strong>Admin Keuangan</strong></p>
                    <div style="border-bottom: 1px solid #000; width: 100%;"></div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        @media print {
            .d-print-flex { display: flex !important; }
            .d-none.d-print-flex { display: flex !important; }
        }
    </style>
</body>
</html>