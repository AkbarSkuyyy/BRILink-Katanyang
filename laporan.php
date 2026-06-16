<?php
mysqli_report(MYSQLI_REPORT_OFF);
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');
$username_kasir = $_SESSION['username'] ?? 'Kasir';

$q_user = $conn->query("SELECT shift_ke, assigned_banks FROM users WHERE id = '$user_id'");
$row_user = $q_user ? $q_user->fetch_assoc() : null;
$shift_ke = !empty($row_user['shift_ke']) ? $row_user['shift_ke'] : 1;

$assigned_banks_str = $row_user['assigned_banks'] ?? '';
$assigned_banks_array = $assigned_banks_str ? array_map('trim', explode(',', $assigned_banks_str)) : [];
$assigned_banks = array_unique($assigned_banks_array);

$db_map = [];
$q_rek = $conn->query("SELECT alias, kolom_db FROM rekening");
if ($q_rek) {
    while($r = $q_rek->fetch_assoc()) { $db_map[trim($r['alias'])] = trim($r['kolom_db']); }
}

$q_shift_aktif = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1");

$shift_id = null; $tgl_shift = $tanggal_hari_ini; $modal_awal = 0; $uang_masuk = 0; $uang_keluar = 0;
$laba_tukar = 0; $admin_cash = 0; $admin_saldo = 0; $laba_admin = 0; $saldo_akhir = 0;

$saldo_bank = [];
foreach($assigned_banks as $b) { $saldo_bank[$b] = 0; }

if ($q_shift_aktif && $q_shift_aktif->num_rows > 0) {
    $shift_data = $q_shift_aktif->fetch_assoc();
    $shift_id = $shift_data['id'];
    $tgl_shift = $shift_data['tanggal'];
    $modal_awal = $shift_data['modal_awal'];

    // FISIK LACI
    $q_masuk = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND (jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos') OR (jenis_transaksi = 'Setor Dana Bos' AND bank_agen = 'CASH'))");
    $uang_masuk = ($q_masuk && $row = $q_masuk->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    $q_admin_cash = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND admin_source = 'CASH' AND jenis_transaksi != 'Pengeluaran / Rugi'");
    $admin_cash = ($q_admin_cash && $row = $q_admin_cash->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    $q_keluar = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi IN ('Pengeluaran / Rugi', 'Tarik Dana Bos') AND bank_agen = 'CASH'))");
    $uang_keluar = ($q_keluar && $row = $q_keluar->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    $saldo_akhir = $modal_awal + $uang_masuk + $admin_cash - $uang_keluar;

    // DIGITAL
    $q_admin = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift'");
    $laba_admin = ($q_admin && $row = $q_admin->fetch_assoc()) ? ($row['total'] ?? 0) : 0;
    
    $q_admin_saldo = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND admin_source = 'SALDO'");
    $admin_saldo = ($q_admin_saldo && $row = $q_admin_saldo->fetch_assoc()) ? ($row['total'] ?? 0) : 0;

    foreach($assigned_banks as $b_name) {
        if(isset($db_map[$b_name])) {
            $b_col = $db_map[$b_name];
            $modal_awal_bank = isset($shift_data[$b_col]) ? (float)$shift_data[$b_col] : 0;

            $q_in_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND jenis_transaksi IN ('Tarik Tunai', 'Setor Dana Bos') AND bank_agen = '$b_name'");
            $in_b = ($q_in_b && $row = $q_in_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
            
            $q_admin_b = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$shift_id' AND tanggal >= '$tgl_shift' AND admin_source = 'SALDO' AND bank_agen = '$b_name'");
            $admin_b = ($q_admin_b && $row = $q_admin_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
            
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
        
        @media print {
            body { background-color: white !important; margin: 0 !important; padding: 0 !important; }
            body > :not(#nota-print-area):not(#laporan-print-area) { display: none !important; }
            .sidebar-modern, .mobile-header, .main-content { display: none !important; }
            
            body.printing-nota #nota-print-area { display: block !important; width: 100%; margin:0; padding:0; }
            body.printing-laporan #laporan-print-area { display: block !important; width: 100%; margin:0; padding:0; font-family: Arial, sans-serif; color: #000; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold text-dark">Laporan Shift <?= $shift_ke; ?></h3>
            <button onclick="cetakLaporanShift()" class="btn btn-dark rounded-pill px-4 shadow-sm">
                <i class="bi bi-printer-fill me-2"></i> Cetak Laporan
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
                                                '<?= htmlspecialchars(addslashes($row['jenis_transaksi'])); ?>', 
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

    <!-- AREA WADAH CETAK (DISEMBUNYIKAN DI LAYAR NORMAL) -->
    <div id="nota-print-area"></div>
    <div id="laporan-print-area"></div>

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

    // =======================================================
    // 1. FUNGSI CETAK LAPORAN SHIFT
    // =======================================================
    function cetakLaporanShift() {
        setPrintPageStyle('@page { size: 8.5in 11in; margin: 0; }');
        
        let htmlLaporan = `
        <div style="font-family: Arial, sans-serif; color: #000; padding: 20px; width: 8.2in; margin: auto; box-sizing: border-box; background: #fff;">
            
            <div style="text-align: center; border-bottom: 3px double #000; padding-bottom: 5px; margin-bottom: 10px;">
                <img src="assets/img/logo-katanyang.png" style="max-height: 45px; filter: grayscale(100%); margin-bottom: 5px; display: block; margin: 0 auto;">
                <h2 style="font-weight: bold; font-size: 18px; margin: 0 0 3px 0;">LAPORAN TUTUP SHIFT KASIR</h2>
                <p style="margin: 0; font-size: 12px;"><strong>AGEN BRILINK RESMI</strong><br>Jln. Poros Desa Katayang Kab.Seruyan Kec.Hanau</p>
            </div>

            <table width="100%" border="0" cellpadding="2" cellspacing="0" style="margin-bottom: 10px; font-size: 12px;">
                <tr>
                    <td width="15%"><strong>Nama Kasir</strong></td><td width="2%">:</td><td width="33%"><?= strtoupper($username_kasir); ?></td>
                    <td width="15%"><strong>Shift Ke</strong></td><td width="2%">:</td><td width="33%"><?= $shift_ke; ?></td>
                </tr>
                <tr>
                    <td><strong>Cabang</strong></td><td>:</td><td><?= $_SESSION['nama_cabang'] ?? '-'; ?></td>
                    <td><strong>Tgl Cetak</strong></td><td>:</td><td><?= date('d F Y', strtotime($tanggal_hari_ini)); ?></td>
                </tr>
            </table>

            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin-bottom: 10px;">
                <tr>
                    <td width="48%" valign="top">
                        <div style="font-weight: bold; font-size: 12px; background-color: #f0f0f0; padding: 4px; border: 1px solid #000; border-bottom: none;">1. PERHITUNGAN FISIK LACI (CASH)</div>
                        <table width="100%" border="1" cellpadding="4" cellspacing="0" style="font-size: 11px; border-collapse: collapse;">
                            <tr><td>Modal Awal Shift</td><td align="right">Rp <?= number_format($modal_awal, 0, ',', '.'); ?></td></tr>
                            <tr><td>Pemasukan Pokok</td><td align="right">Rp <?= number_format($uang_masuk, 0, ',', '.'); ?></td></tr>
                            <tr><td>Admin Diterima Tunai (CASH)</td><td align="right">Rp <?= number_format($admin_cash, 0, ',', '.'); ?></td></tr>
                            <tr><td>Pengeluaran Tunai</td><td align="right">(Rp <?= number_format($uang_keluar, 0, ',', '.'); ?>)</td></tr>
                            <tr><td style="font-weight:bold;">TOTAL SISA FISIK DI LACI</td><td align="right" style="font-weight:bold; font-size:12px;">Rp <?= number_format($saldo_akhir, 0, ',', '.'); ?></td></tr>
                        </table>
                    </td>
                    <td width="4%"></td>
                    <td width="48%" valign="top">
                        <div style="font-weight: bold; font-size: 12px; background-color: #f0f0f0; padding: 4px; border: 1px solid #000; border-bottom: none;">2. SALDO REKENING & LABA DIGITAL</div>
                        <table width="100%" border="1" cellpadding="4" cellspacing="0" style="font-size: 11px; border-collapse: collapse;">
                            <?php foreach($assigned_banks as $bank): ?>
                            <tr><td>Sisa Saldo <?= $bank; ?></td><td align="right">Rp <?= number_format($saldo_bank[$bank], 0, ',', '.'); ?></td></tr>
                            <?php endforeach; ?>
                            <tr><td>Admin Masuk Rekening (SALDO)</td><td align="right">Rp <?= number_format($admin_saldo, 0, ',', '.'); ?></td></tr>
                            <tr><td style="font-weight:bold;">TOTAL LABA (SEMUA ADMIN)</td><td align="right" style="font-weight:bold; font-size:12px;">Rp <?= number_format($laba_admin, 0, ',', '.'); ?></td></tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div style="font-weight: bold; font-size: 12px; background-color: #f0f0f0; padding: 4px; border: 1px solid #000; border-bottom: none;">3. RINCIAN TRANSAKSI SHIFT</div>
            <table width="100%" border="1" cellpadding="4" cellspacing="0" style="font-size: 11px; border-collapse: collapse; margin-bottom: 10px;">
                <thead>
                    <tr>
                        <th width="5%">No</th>
                        <th width="18%">Layanan</th>
                        <th width="15%">Rekening</th>
                        <th width="32%">Keterangan / Tujuan</th>
                        <th width="15%">Nominal (Rp)</th>
                        <th width="15%">Admin (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (isset($q_trans)) {
                        $q_trans->data_seek(0);
                        $no = 1;
                        if ($q_trans->num_rows > 0) {
                            while ($row = $q_trans->fetch_assoc()): 
                                $sumber = isset($row['admin_source']) ? $row['admin_source'] : 'CASH';
                                $lbl_admin = ($row['admin_fee'] > 0) ? " ($sumber)" : "";
                            ?>
                                <tr>
                                    <td align="center"><?= $no++; ?></td>
                                    <td><?= $row['jenis_transaksi']; ?></td>
                                    <td align="center"><?= $row['bank_agen'] != '-' ? $row['bank_agen'] : 'CASH'; ?></td>
                                    <td><?= htmlspecialchars($row['keterangan']) ?: '-'; ?></td>
                                    <td align="right"><?= number_format($row['nominal'], 0, ',', '.'); ?></td>
                                    <td align="right"><?= number_format($row['admin_fee'], 0, ',', '.') . $lbl_admin; ?></td>
                                </tr>
                            <?php endwhile;
                        } else {
                            echo "<tr><td colspan='6' align='center'>Belum ada transaksi pada shift ini.</td></tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>

            <table width="100%" style="text-align: center; font-size: 12px; margin-top: 15px;">
                <tr>
                    <td width="50%">Dibuat Oleh,<br><strong>Kasir Bertugas</strong><br><br><br><u><?= strtoupper($username_kasir); ?></u></td>
                    <td width="50%">Diperiksa & Disetujui Oleh,<br><strong>Pimpinan / Pemilik</strong><br><br><br><u>( ...................................... )</u></td>
                </tr>
            </table>

        </div>
        `;
        
        let printArea = document.getElementById('laporan-print-area');
        printArea.innerHTML = htmlLaporan;
        document.body.classList.add('printing-laporan');
        setTimeout(function() { window.print(); }, 300);
        window.onafterprint = function() { document.body.classList.remove('printing-laporan'); printArea.innerHTML = ''; };
    }

    // =======================================================
    // 2. FUNGSI CETAK TIKET/NOTA (DIPADATKAN AGAR TIDAK LEWAT HALAMAN 2)
    // =======================================================
    function cetakNotaTiket(kode_nota, jenis, nominal, admin) {
        // Matikan margin bawaan browser agar kertas dihitung murni 5.5 inci
        setPrintPageStyle('@page { size: 8.5in 5.5in; margin: 0; }');
        
        let j = jenis.toLowerCase();
        
        // Logika Checklist Cerdas
        let c_tarik = (j.includes('tarik')) ? '☑' : '☐';
        let c_setor = (j.includes('setor') && !j.includes('bos')) ? '☑' : '☐';
        let c_tf    = (j.includes('transfer')) ? '☑' : '☐';
        let c_topup = (j.includes('topup') || j.includes('e-wallet')) ? '☑' : '☐';
        let c_pulsa = (j.includes('pulsa') || j.includes('token') || j.includes('data') || j.includes('listrik')) ? '☑' : '☐';
        let c_bayar = (j.includes('pdam') || j.includes('cicilan') || j.includes('bayar') || j.includes('finance')) ? '☑' : '☐';
        let c_buka  = (j.includes('buka') || j.includes('rekening')) ? '☑' : '☐';
        let c_receh = (j.includes('receh') || j.includes('tukar')) ? '☑' : '☐';
        
        let c_lain = (c_tarik==='☐' && c_setor==='☐' && c_tf==='☐' && c_topup==='☐' && c_pulsa==='☐' && c_bayar==='☐' && c_buka==='☐' && c_receh==='☐') ? '☑' : '☐';

        let tglWaktu = new Date().toLocaleString('id-ID');

        // Tinggi di-set 5 inci persis, dan disematkan overflow:hidden agar tidak pecah ke lembar ke 2
        let notaContent = `
            <div style="width: 8in; height: 5in; font-family: Arial, sans-serif; color: #000; border: 3px solid #000; padding: 15px; box-sizing: border-box; background: #fff; margin: 0.2in auto; border-radius: 8px; position: relative; overflow: hidden; page-break-after: avoid;">
                
                <table width="100%" border="0" cellpadding="0" cellspacing="0" style="border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 10px;">
                    <tr>
                        <td width="60%">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <img src="assets/img/logo-katanyang.png" style="max-height: 35px; filter: grayscale(100%);">
                                <h2 style="margin:0; font-size:22px; font-weight:900;">AGEN BRILINK</h2>
                            </div>
                        </td>
                        <td align="right" width="40%" valign="bottom">
                            <strong style="font-size:16px;">BUKTI TRANSAKSI / TANDA TERIMA</strong><br>
                            <span style="font-size:11px;">Jln. Poros Desa Katayang</span>
                        </td>
                    </tr>
                </table>

                <!-- CHECKLIST LAYANAN TRANSAKSI UTAMA -->
                <table width="100%" border="1" cellpadding="5" cellspacing="0" style="font-size:11px; margin-bottom:12px; border-collapse: collapse; border: 1px solid #000; font-weight: bold;">
                    <tr>
                        <td width="33%">${c_tarik} Tarik Tunai</td>
                        <td width="33%">${c_setor} Setor Tunai</td>
                        <td width="34%">${c_tf} Transfer Dana</td>
                    </tr>
                    <tr>
                        <td>${c_topup} TopUp / E-Wallet</td>
                        <td>${c_pulsa} Pulsa / Token</td>
                        <td>${c_bayar} Tagihan / Cicilan</td>
                    </tr>
                    <tr>
                        <td>${c_buka} Buka Rekening</td>
                        <td>${c_receh} Tukar Receh</td>
                        <td>${c_lain} Transaksi Lainnya</td>
                    </tr>
                </table>

                <table width="100%" border="0" cellpadding="4" cellspacing="0" style="font-size:13px;">
                    <tr>
                        <td width="15%"><strong>Kode Ref</strong></td><td width="2%">:</td><td width="33%" style="border-bottom:1px dotted #000;"><strong>${kode_nota}</strong></td>
                        <td width="15%"><strong>Penerima</strong></td><td width="2%">:</td><td width="33%" style="border-bottom:1px dotted #000;"></td>
                    </tr>
                    <tr>
                        <td><strong>Waktu</strong></td><td>:</td><td style="border-bottom:1px dotted #000;">${tglWaktu}</td>
                        <td><strong>No.Rek/ID</strong></td><td>:</td><td style="border-bottom:1px dotted #000;"></td>
                    </tr>
                    <tr>
                        <td><strong>Penyetor</strong></td><td>:</td><td style="border-bottom:1px dotted #000;"></td>
                        <td><strong>Nominal</strong></td><td>:</td><td style="border-bottom:1px dotted #000; font-size:14px;"><strong>Rp ${nominal}</strong></td>
                    </tr>
                    <tr>
                        <td><strong>No. Telp</strong></td><td>:</td><td style="border-bottom:1px dotted #000;"></td>
                        <td><strong>Admin</strong></td><td>:</td><td style="border-bottom:1px dotted #000;">Rp ${admin}</td>
                    </tr>
                </table>

                <table width="100%" style="margin-top: 20px; text-align:center; font-size:12px; font-weight: bold;">
                    <tr>
                        <td width="40%">Nasabah / Penyetor<br><br><br>( ...................................... )</td>
                        <td width="20%"></td>
                        <td width="40%">Petugas Kasir<br><br><br>( ...................................... )</td>
                    </tr>
                </table>
                
                <div style="position: absolute; bottom: 5px; left: 0; width: 100%; text-align: center; font-size: 10px; font-style: italic;">
                    * Simpan struk ini sebagai bukti transaksi yang sah. Terima Kasih *
                </div>
            </div>
        `;
        
        let printArea = document.getElementById('nota-print-area');
        printArea.innerHTML = notaContent;
        document.body.classList.add('printing-nota');
        setTimeout(function() { window.print(); }, 500);
        window.onafterprint = function() { document.body.classList.remove('printing-nota'); printArea.innerHTML = ''; };
    }
    </script>
</body>
</html>