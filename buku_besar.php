<?php
require 'config.php';
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
$filter_akun = isset($_GET['akun']) ? $_GET['akun'] : 'CASH';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // Default awal bulan
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Default hari ini

// Query Data Transaksi berdasarkan rentang tanggal
$query_transaksi = "SELECT t.*, u.username 
                    FROM transactions t 
                    LEFT JOIN shifts s ON t.shift_id = s.id
                    LEFT JOIN users u ON s.user_id = u.id
                    WHERE DATE(t.tanggal) BETWEEN '$start_date' AND '$end_date' 
                    ORDER BY t.tanggal ASC, t.id ASC";
$result_transaksi = $conn->query($query_transaksi);
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
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 20px; padding: 25px; border: none; box-shadow: 0 5px 20px rgba(0,0,0,0.03); }
        
        /* CSS KHUSUS CETAK PDF / KERTAS */
        #print-header { display: none; }
        @media print {
            body { background-color: white !important; margin: 0; padding: 0; }
            .sidebar-modern, .mobile-header, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 20px !important; }
            .modern-card { box-shadow: none !important; border: none !important; padding: 0 !important; }
            
            #print-header { display: block; margin-bottom: 25px; }
            .cetak-kop { text-align: center; border-bottom: 3px double #000; padding-bottom: 15px; margin-bottom: 20px; }
            .cetak-kop h2 { margin: 0 0 5px 0; font-weight: bold; font-size: 24px; text-transform: uppercase; letter-spacing: 1px; }
            .cetak-kop h4 { margin: 0 0 10px 0; font-size: 16px; font-weight: normal; color: #333; }
            .cetak-info { display: flex; justify-content: center; gap: 40px; font-size: 14px; }
            
            table { width: 100% !important; border-collapse: collapse !important; font-size: 12px; color: black; }
            table th, table td { border: 1px solid #000 !important; padding: 8px !important; }
            table th { background-color: #f8f9fa !important; text-align: center; font-weight: bold; }
            .text-success, .text-danger, .text-primary { color: black !important; } /* Hitam Putih untuk Cetak */
            .bg-light { background-color: transparent !important; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        
        <!-- ==========================================
             HEADER KHUSUS UNTUK CETAK KERTAS / PDF
        =========================================== -->
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

        <!-- ==========================================
             HEADER UI WEB (TAMPILAN LAYAR)
        =========================================== -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 no-print gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1"><i class="bi bi-book-half text-primary me-2"></i>Laporan Buku Besar</h3>
                <p class="text-muted mb-0" style="font-size: 14px;">Memantau rincian pergerakan debet (masuk) dan kredit (keluar) untuk setiap akun dan laci kas.</p>
            </div>
            <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 shadow-sm" style="height: fit-content;">
                <i class="bi bi-printer me-2"></i>Cetak Laporan PDF
            </button>
        </div>

        <!-- FORM FILTER (HANYA TAMPIL DI LAYAR) -->
        <div class="modern-card mb-4 no-print">
            <form method="GET" action="buku_besar.php" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold text-muted small">Pilih Akun Kas/Bank</label>
                    <select name="akun" class="form-select border-secondary shadow-sm">
                        <option value="CASH" <?= $filter_akun == 'CASH' ? 'selected' : ''; ?>>Laci Tunai (CASH)</option>
                        <?php foreach($rekening_list as $rek): ?>
                            <option value="<?= $rek; ?>" <?= $filter_akun == $rek ? 'selected' : ''; ?>><?= $rek; ?></option>
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
                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm">Tampilkan Mutasi</button>
                </div>
            </form>
        </div>

        <!-- TABEL BUKU BESAR -->
        <div class="modern-card">
            <h5 class="fw-bold mb-3 no-print">Rincian Mutasi Akun: <span class="text-primary"><?= htmlspecialchars($filter_akun); ?></span></h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
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
                        $total_debet = 0;
                        $total_kredit = 0;
                        $mutasi_berjalan = 0;
                        $has_data = false;

                        if ($result_transaksi && $result_transaksi->num_rows > 0) {
                            while ($row = $result_transaksi->fetch_assoc()) {
                                $debet = 0;
                                $kredit = 0;
                                $is_relevant = false;

                                // LOGIK PERHITUNGAN MUTASI BRILINK
                                if ($filter_akun === 'CASH') {
                                    if ($row['jenis_transaksi'] == 'Tarik Tunai') {
                                        $kredit = $row['nominal']; 
                                        $is_relevant = true;
                                    } elseif ($row['jenis_transaksi'] == 'Tukar Uang') {
                                        $debet = $row['admin_fee']; 
                                        $is_relevant = true;
                                    } else {
                                        if ($row['bank_agen'] != '-') { 
                                            $debet = $row['nominal'] + $row['admin_fee']; 
                                            $is_relevant = true;
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

                                if ($is_relevant && ($debet > 0 || $kredit > 0)) {
                                    $has_data = true;
                                    $total_debet += $debet;
                                    $total_kredit += $kredit;
                                    $mutasi_berjalan += ($debet - $kredit);
                                    ?>
                                    <tr>
                                        <td style="font-size: 13px;"><?= date('d/m/Y H:i', strtotime($row['tanggal'])); ?></td>
                                        <td style="font-size: 13px;" class="text-muted">TRX-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                        <td style="font-size: 13px;"><?= htmlspecialchars($row['username']); ?></td>
                                        <td>
                                            <span class="fw-bold"><?= $row['jenis_transaksi']; ?></span><br>
                                            <small class="text-muted"><?= htmlspecialchars($row['keterangan']); ?></small>
                                        </td>
                                        <td class="text-end fw-bold text-success">
                                            <?= $debet > 0 ? 'Rp ' . number_format($debet, 0, ',', '.') : '-'; ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
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
                                <td colspan="7" class="text-center py-4 text-muted">Tidak ada pergerakan mutasi untuk akun dan periode ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="4" class="text-end fw-bold" style="text-transform: uppercase;">Total Pergerakan Mutasi:</td>
                            <td class="text-end fw-bold text-success">Rp <?= number_format($total_debet, 0, ',', '.'); ?></td>
                            <td class="text-end fw-bold text-danger">Rp <?= number_format($total_kredit, 0, ',', '.'); ?></td>
                            <td class="text-end fw-bold text-white">Rp <?= number_format($mutasi_berjalan, 0, ',', '.'); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- TANDA TANGAN (HANYA TAMPIL SAAT CETAK) -->
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