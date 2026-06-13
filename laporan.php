<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');
$username_kasir = $_SESSION['username'] ?? 'Kasir';

// Dapatkan Hak Akses Rekening Cabang Saat Ini
$q_user = $conn->query("SELECT shift_ke, assigned_banks FROM users WHERE id = '$user_id'");
$row_user = $q_user ? $q_user->fetch_assoc() : null;
$shift_ke = !empty($row_user['shift_ke']) ? $row_user['shift_ke'] : 1;

// ANTI DUPLIKASI NAMA BANK
$assigned_banks_str = $row_user['assigned_banks'] ?? '';
$assigned_banks_array = $assigned_banks_str ? array_map('trim', explode(',', $assigned_banks_str)) : [];
$assigned_banks = array_unique($assigned_banks_array);

// AMBIL DAFTAR REKENING & KOLOM DB DARI TABEL MASTER (Dinamis)
$db_map = [];
$q_rek = $conn->query("SELECT alias, kolom_db FROM rekening");
if ($q_rek) {
    while($r = $q_rek->fetch_assoc()) { $db_map[trim($r['alias'])] = trim($r['kolom_db']); }
}

// Dapatkan Shift Kasir yang sedang berjalan
$q_shift_aktif = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1");

$shift_id = null; $tgl_shift = $tanggal_hari_ini; $modal_awal = 0; $uang_masuk = 0; $uang_keluar = 0;
$laba_tukar = 0; $admin_cash = 0; $admin_saldo = 0; $laba_admin = 0; $saldo_akhir = 0;

// Array untuk menyimpan saldo bank yang DIIZINKAN saja
$saldo_bank = [];
foreach($assigned_banks as $b) { $saldo_bank[$b] = 0; }

if ($q_shift_aktif && $q_shift_aktif->num_rows > 0) {
    $shift_data = $q_shift_aktif->fetch_assoc();
    $shift_id = $shift_data['id'];
    $tgl_shift = $shift_data['tanggal'];
    $modal_awal = $shift_data['modal_awal'];

    // ====================================================================
    // 1. PERHITUNGAN FISIK LACI CASH
    // ====================================================================
    
    // Uang Masuk Fisik Pokok
    $q_masuk = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND (jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos') OR (jenis_transaksi = 'Setor Dana Bos' AND bank_agen = 'CASH'))");
    $uang_masuk = ($q_masuk && $row = $q_masuk->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    // Admin yang dibayar cash
    $q_admin_cash = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND admin_source = 'CASH' AND jenis_transaksi != 'Pengeluaran / Rugi'");
    $admin_cash = ($q_admin_cash && $row = $q_admin_cash->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    // Uang Keluar Fisik
    $q_keluar = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi IN ('Pengeluaran / Rugi', 'Tarik Dana Bos') AND bank_agen = 'CASH'))");
    $uang_keluar = ($q_keluar && $row = $q_keluar->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    // SISA FISIK AKHIR LACI
    $saldo_akhir = $modal_awal + $uang_masuk + $admin_cash - $uang_keluar;

    // ====================================================================
    // 2. PERHITUNGAN SALDO DIGITAL & LABA
    // ====================================================================
    
    // Total Admin Keseluruhan
    $q_admin = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift'");
    $laba_admin = ($q_admin && $row = $q_admin->fetch_assoc()) ? ($row['total'] ?? 0) : 0;
    
    // Admin yang masuk/dipotong dari saldo bank digital
    $q_admin_saldo = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND admin_source = 'SALDO'");
    $admin_saldo = ($q_admin_saldo && $row = $q_admin_saldo->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    foreach($assigned_banks as $b_name) {
        if(isset($db_map[$b_name])) {
            $b_col = $db_map[$b_name];
            $modal_awal_bank = isset($shift_data[$b_col]) ? (float)$shift_data[$b_col] : 0;

            // DIGITAL IN
            $q_in_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND jenis_transaksi IN ('Tarik Tunai', 'Setor Dana Bos') AND bank_agen = '$b_name'");
            $in_b = ($q_in_b && $row = $q_in_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
            
            // ADMIN DIGITAL IN
            $q_admin_b = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND admin_source = 'SALDO' AND bank_agen = '$b_name'");
            $admin_b = ($q_admin_b && $row = $q_admin_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
            
            // DIGITAL OUT
            $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Setor Dana Bos') AND bank_agen = '$b_name'");
            $out_b = ($q_out_b && $row = $q_out_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
            
            $saldo_bank[$b_name] = $modal_awal_bank + $in_b + $admin_b - $out_b;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan Kasir</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 24px; padding: 25px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); }
        .rek-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 12px; padding: 10px; margin-bottom: 10px; }
        
        #nota-print-area, #laporan-print-area { display: none; } 
        
        @media (max-width: 767.98px) { 
            .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } 
        }
        
        /* SISTEM CETAK */
        @media print {
            body { background-color: white !important; margin: 0 !important; padding: 0 !important; }
            .sidebar-modern, .mobile-header, .main-content { display: none !important; }
            body.printing-nota #nota-print-area { display: block !important; width: 100%; }
            body.printing-laporan #laporan-print-area { display: block !important; width: 100%; font-family: Arial, sans-serif; color: #000; }
        }

        #laporan-print-area { max-width: 100%; padding: 20px; }
        .lap-header { text-align: center; border-bottom: 3px double #000; padding-bottom: 15px; margin-bottom: 20px; }
        .lap-header h2 { font-weight: bold; font-size: 22px; margin: 0 0 5px 0; color: #000; text-transform: uppercase;}
        .lap-header p { margin: 0; font-size: 13px; color: #333; }
        .lap-info { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 13px; }
        .lap-info-col div { margin-bottom: 5px; }
        
        .lap-section-title { font-weight: bold; font-size: 14px; background-color: #f0f0f0; padding: 6px 10px; border: 1px solid #000; margin-bottom: 0; border-bottom: none;}
        .lap-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
        .lap-table th, .lap-table td { border: 1px solid #000; padding: 8px 10px; }
        .lap-table th { background-color: #f8f9fa; font-weight: bold; text-align: center;}
        
        .lap-grid { display: flex; gap: 20px; align-items: stretch; }
        .lap-grid > div { flex: 1; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        
        .lap-signature { display: flex; justify-content: space-between; margin-top: 40px; text-align: center; font-size: 13px; }
        .lap-sign-box { width: 250px; }
        .lap-sign-line { border-bottom: 1px solid #000; margin-top: 70px; display: block; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold text-dark">Laporan Shift <?= $shift_ke; ?></h3>
            <button onclick="cetakLaporanShift()" class="btn btn-dark rounded-pill px-4 shadow-sm">
                <i class="bi bi-printer-fill me-2"></i> Cetak PDF Laporan
            </button>
        </div>

        <?php if ($shift_id): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="modern-card h-100">
                        <h6 class="fw-bold text-muted mb-4"><i class="bi bi-wallet2 me-2"></i> Perhitungan Fisik Laci (CASH)</h6>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">Modal Awal:</span> <span class="fw-bold">Rp <?= number_format($modal_awal, 0, ',', '.'); ?></span></div>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">Pemasukan Pokok:</span> <span class="text-success fw-bold">+ Rp <?= number_format($uang_masuk, 0, ',', '.'); ?></span></div>
                        
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Admin Tunai (Masuk Laci):</span> 
                            <span class="text-success fw-bold">+ Rp <?= number_format($admin_cash, 0, ',', '.'); ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-3"><span class="text-muted">Pengeluaran Laci:</span> <span class="text-danger fw-bold">- Rp <?= number_format($uang_keluar, 0, ',', '.'); ?></span></div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <h5 class="fw-bold mb-0 text-dark">Sisa Fisik Akhir</h5>
                            <h3 class="fw-bolder text-primary mb-0">Rp <?= number_format($saldo_akhir, 0, ',', '.'); ?></h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="modern-card h-100">
                        <h6 class="fw-bold text-muted mb-3"><i class="bi bi-phone me-2"></i> Saldo <?= count($assigned_banks); ?> Rekening (Akhir Shift)</h6>
                        <div class="row g-2">
                            <?php foreach($assigned_banks as $bank): ?>
                            <div class="col-6 col-sm-4">
                                <div class="rek-box text-center h-100 d-flex flex-column justify-content-center">
                                    <span class="d-block text-muted fw-bold" style="font-size: 11px;"><?= $bank; ?></span>
                                    <span class="d-block fw-bolder text-dark" style="font-size: 13px;">Rp <?= number_format($saldo_bank[$bank], 0, ',', '.'); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-3 p-3 bg-primary text-white rounded-4 shadow-sm">
                            <div class="text-center mb-2">
                                <h6 class="opacity-75 mb-1"><i class="bi bi-graph-up-arrow me-1"></i> Laba Keseluruhan</h6>
                                <h3 class="fw-bolder mb-0">Rp <?= number_format($laba_admin, 0, ',', '.'); ?></h3>
                            </div>
                            <div class="d-flex justify-content-between border-top border-light border-opacity-25 pt-2 fw-semibold" style="font-size: 12px;">
                                <span><i class="bi bi-cash me-1"></i>Di Laci: Rp <?= number_format($admin_cash, 0, ',', '.'); ?></span>
                                <span><i class="bi bi-phone me-1"></i>Di Rekening: Rp <?= number_format($admin_saldo, 0, ',', '.'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modern-card">
                <h6 class="fw-bold text-dark mb-3">Detail Transaksi Shift</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-nowrap">
                        <thead class="table-light">
                            <tr>
                                <th>No</th>
                                <th>Layanan</th>
                                <th>Rekening</th>
                                <th>Info / Tujuan</th>
                                <th>Nominal</th>
                                <th>Biaya Admin</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_trans = $conn->query("SELECT * FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' ORDER BY id DESC");
                            $no = 1;
                            while ($row = $q_trans->fetch_assoc()): 
                                // Label Sumber Admin
                                $sumber_admin = isset($row['admin_source']) ? $row['admin_source'] : 'CASH';
                                $badge_admin = ($sumber_admin == 'SALDO') ? '<span class="badge bg-primary ms-1" style="font-size:9px;">SALDO</span>' : '<span class="badge bg-success ms-1" style="font-size:9px;">CASH</span>';
                            ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td class="fw-bold text-primary"><?= $row['jenis_transaksi']; ?></td>
                                    <td>
                                        <?php if($row['bank_agen'] != '-'): ?>
                                            <span class="badge bg-secondary"><?= $row['bank_agen']; ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">CASH</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= htmlspecialchars($row['keterangan']) ?: '-'; ?></small></td>
                                    <td>Rp <?= number_format($row['nominal'], 0, ',', '.'); ?></td>
                                    
                                    <td class="fw-bold text-dark">
                                        + Rp <?= number_format($row['admin_fee'], 0, ',', '.'); ?> <?= ($row['admin_fee'] > 0) ? $badge_admin : ''; ?>
                                    </td>
                                    
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" 
                                            onclick="cetakNotaTiket(
                                                'TRX-<?= date('Ymd', strtotime($row['tanggal'])); ?>-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?>',
                                                '<?= htmlspecialchars($row['jenis_transaksi']); ?>', 
                                                '<?= number_format($row['nominal'], 0, ',', '.'); ?>', 
                                                '<?= number_format($row['admin_fee'], 0, ',', '.'); ?>'
                                            )">
                                            <i class="bi bi-ticket-detailed"></i> Cetak Tiket
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="modern-card text-center p-5">
                <i class="bi bi-info-circle fs-1 text-warning mb-3"></i>
                <h5 class="fw-bold">Belum Ada Shift Aktif</h5>
                <p class="text-muted">Silakan mulai shift Anda melalui menu Pengaturan Modal.</p>
            </div>
        <?php endif; ?>
    </div>

    <div id="nota-print-area"></div>

    <?php if ($shift_id): ?>
    <div id="laporan-print-area">
        <div class="lap-header">
            <h2>LAPORAN TUTUP SHIFT KASIR</h2>
            <p><strong>AGEN BRILINK RESMI</strong><br>Jln. Poros Desa Katayang Kab.Seruyan Kec.Hanau</p>
        </div>

        <div class="lap-info">
            <div class="lap-info-col">
                <div><strong>Nama Kasir &nbsp;&nbsp;&nbsp;&nbsp;:</strong> <?= strtoupper($username_kasir); ?></div>
                <div><strong>Cabang &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</strong> <?= $_SESSION['nama_cabang'] ?? '-'; ?></div>
            </div>
            <div class="lap-info-col">
                <div><strong>Shift Ke &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:</strong> <?= $shift_ke; ?></div>
                <div><strong>Tanggal Cetak :</strong> <?= date('d F Y', strtotime($tanggal_hari_ini)); ?></div>
            </div>
        </div>

        <div class="lap-grid">
            <div>
                <div class="lap-section-title">1. PERHITUNGAN FISIK LACI (CASH)</div>
                <table class="lap-table">
                    <tr>
                        <td>Modal Awal Shift</td>
                        <td class="text-right">Rp <?= number_format($modal_awal, 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td>Pemasukan Pokok</td>
                        <td class="text-right">Rp <?= number_format($uang_masuk, 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td>Admin Diterima Tunai (CASH)</td>
                        <td class="text-right">Rp <?= number_format($admin_cash, 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td>Pengeluaran Tunai</td>
                        <td class="text-right" style="color:red;">(Rp <?= number_format($uang_keluar, 0, ',', '.'); ?>)</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">TOTAL SISA FISIK DI LACI</td>
                        <td class="text-right fw-bold" style="font-size:14px;">Rp <?= number_format($saldo_akhir, 0, ',', '.'); ?></td>
                    </tr>
                </table>
            </div>

            <div>
                <div class="lap-section-title">2. SALDO REKENING & LABA DIGITAL</div>
                <table class="lap-table">
                    <?php foreach($assigned_banks as $bank): ?>
                    <tr>
                        <td>Sisa Saldo <?= $bank; ?></td>
                        <td class="text-right">Rp <?= number_format($saldo_bank[$bank], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td>Admin Masuk Rekening (SALDO)</td>
                        <td class="text-right">Rp <?= number_format($admin_saldo, 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">TOTAL LABA (SEMUA ADMIN)</td>
                        <td class="text-right fw-bold" style="font-size:14px; background:#f0f8ff;">Rp <?= number_format($laba_admin, 0, ',', '.'); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="lap-section-title" style="margin-top: 10px;">3. RINCIAN TRANSAKSI SHIFT</div>
        <table class="lap-table">
            <thead>
                <tr>
                    <th width="5%">No</th>
                    <th width="18%">Layanan</th>
                    <th width="15%">Rekening</th>
                    <th width="32%">Keterangan / Tujuan</th>
                    <th width="15%" class="text-right">Nominal (Rp)</th>
                    <th width="15%" class="text-right">Admin (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $q_trans->data_seek(0);
                $no = 1;
                if ($q_trans->num_rows > 0) {
                    while ($row = $q_trans->fetch_assoc()): 
                        $sumber = isset($row['admin_source']) ? $row['admin_source'] : 'CASH';
                        $lbl_admin = ($row['admin_fee'] > 0) ? " ($sumber)" : "";
                    ?>
                        <tr>
                            <td class="text-center"><?= $no++; ?></td>
                            <td><?= $row['jenis_transaksi']; ?></td>
                            <td class="text-center"><?= $row['bank_agen'] != '-' ? $row['bank_agen'] : 'CASH'; ?></td>
                            <td><?= htmlspecialchars($row['keterangan']) ?: '-'; ?></td>
                            <td class="text-right"><?= number_format($row['nominal'], 0, ',', '.'); ?></td>
                            <td class="text-right"><?= number_format($row['admin_fee'], 0, ',', '.') . $lbl_admin; ?></td>
                        </tr>
                    <?php endwhile;
                } else {
                    echo "<tr><td colspan='6' class='text-center'>Belum ada transaksi pada shift ini.</td></tr>";
                }
                ?>
            </tbody>
        </table>

        <div class="lap-signature">
            <div class="lap-sign-box">
                Dibuat Oleh,<br><strong>Kasir Bertugas</strong>
                <span class="lap-sign-line"></span>
                <?= strtoupper($username_kasir); ?>
            </div>
            <div class="lap-sign-box">
                Diperiksa & Disetujui Oleh,<br><strong>Pimpinan / Pemilik</strong>
                <span class="lap-sign-line"></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function setPrintPageStyle(styleRule) {
        let style = document.getElementById('dynamic-print-style');
        if (!style) {
            style = document.createElement('style');
            style.id = 'dynamic-print-style';
            document.head.appendChild(style);
        }
        style.innerHTML = styleRule;
    }

    function cetakLaporanShift() {
        setPrintPageStyle('@page { size: A4 portrait; margin: 1.5cm; }');
        document.body.classList.add('printing-laporan');
        setTimeout(function() { window.print(); }, 300);
        window.onafterprint = function() { document.body.classList.remove('printing-laporan'); };
    }

    function cetakNotaTiket(kode_nota, jenis, nominal, admin) {
        setPrintPageStyle('@page { size: 210mm 90mm; margin: 5mm; }');
        let cekTarik = jenis.toLowerCase().includes('tarik') ? '☑' : '☐';
        let cekTransfer = (jenis.toLowerCase().includes('transfer') || jenis.toLowerCase().includes('setor')) ? '☑' : '☐';
        let cekBayar = (jenis.toLowerCase().includes('bayar') || jenis.toLowerCase().includes('angsuran') || jenis.toLowerCase().includes('pulsa') || jenis.toLowerCase().includes('token')) ? '☑' : '☐';
        let tglWaktu = new Date().toLocaleString('id-ID');

        let notaContent = `
            <style>
                #nota-print-area { 
                    font-family: Arial, sans-serif; font-size: 11px; line-height: 1.3; color: #000; 
                    width: 100%; max-width: 200mm; height: 80mm; margin: auto; position: relative;
                    border: 2px solid #000; border-radius: 10px; background-color: #fff; display: flex; overflow: hidden; box-sizing: border-box;
                }
                .ticket-main { flex: 3; padding: 10px 15px; border-right: 2px dashed #000; display: flex; flex-direction: column; justify-content: space-between; }
                .ticket-stub { flex: 1; padding: 10px; background-color: #f9f9f9; display: flex; flex-direction: column; justify-content: space-between; }
                .header-main { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 5px; }
                .logo-box img { max-height: 30px; object-fit: contain; }
                .title-main { text-align: right; }
                .title-main strong { font-size: 16px; color: #00529C; letter-spacing: 1px; text-transform: uppercase;}
                .sub-title { font-size: 9px; display: block; color: #555;}
                .checkbox-group { display: flex; gap: 15px; margin-bottom: 5px; font-weight: bold; font-size: 11px; background: #e0e0e0; padding: 3px 10px; border-radius: 5px; width: max-content;}
                .form-grid { display: flex; gap: 15px; }
                .form-col { flex: 1; }
                .form-group { display: flex; align-items: flex-end; margin-bottom: 3px; }
                .label { width: 90px; font-weight: bold; font-size: 10px; color: #333;}
                .titik-dua { width: 10px; text-align: center; }
                .garis-bawah { flex: 1; border-bottom: 1px dotted #000; padding-left: 5px; min-height: 14px; font-size: 11px; }
                .total-amount { font-size: 13px; font-weight: bold; }
                .stub-title { font-weight: bold; font-size: 12px; text-align: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 5px; color: #00529C;}
                .stub-info { font-size: 10px; margin-bottom: 3px; }
                .ttd-section { display: flex; justify-content: space-between; margin-top: 15px; text-align: center; font-size: 9px;}
                .ttd-box { width: 45%; }
                .garis-ttd { border-bottom: 1px solid #000; margin-top: 25px; width: 100%; display: block; }
            </style>
            <div class="ticket-main">
                <div class="header-main">
                    <div class="logo-box"><img src="assets/img/logo-katanyang.png" alt="Logo"></div>
                    <div class="title-main"><strong>BUKTI TRANSAKSI</strong><span class="sub-title">Jln. Poros Desa Katayang Kab.Seruyan</span></div>
                </div>
                <div class="checkbox-group"><span>${cekTarik} Tarik Tunai</span><span>${cekTransfer} Transfer</span><span>${cekBayar} Pembayaran</span></div>
                <div class="form-grid">
                    <div class="form-col">
                        <div class="form-group"><div class="label">Kode Ref</div><div class="titik-dua">:</div><div class="garis-bawah"><strong>${kode_nota}</strong></div></div>
                        <div class="form-group"><div class="label">Waktu</div><div class="titik-dua">:</div><div class="garis-bawah">${tglWaktu}</div></div>
                        <div class="form-group"><div class="label">Penyetor</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                        <div class="form-group"><div class="label">No. Telp</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                    </div>
                    <div class="form-col">
                        <div class="form-group"><div class="label">Penerima</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                        <div class="form-group"><div class="label">No. Rek/Id</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                        <div class="form-group"><div class="label">Nominal</div><div class="titik-dua">:</div><div class="garis-bawah"><span class="total-amount">Rp ${nominal}</span></div></div>
                        <div class="form-group"><div class="label">Biaya Admin</div><div class="titik-dua">:</div><div class="garis-bawah">Rp ${admin}</div></div>
                    </div>
                </div>
            </div>
            <div class="ticket-stub">
                <div class="stub-title">TANDA TERIMA</div>
                <div><div class="stub-info"><strong>Ref:</strong><br>${kode_nota}</div><div class="stub-info"><strong>Nominal:</strong><br>Rp ${nominal}</div></div>
                <div class="ttd-section"><div class="ttd-box">Petugas<span class="garis-ttd"></span></div><div class="ttd-box">Nasabah<span class="garis-ttd"></span></div></div>
            </div>
        `;
        let printArea = document.getElementById('nota-print-area');
        printArea.innerHTML = notaContent;
        document.body.classList.add('printing-nota');
        setTimeout(function() { window.print(); }, 800);
        window.onafterprint = function() { document.body.classList.remove('printing-nota'); printArea.innerHTML = ''; };
    }
    </script>
</body>
</html>