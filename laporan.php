<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

// Dapatkan Hak Akses Rekening Cabang Saat Ini
$q_user = $conn->query("SELECT assigned_banks FROM users WHERE id = '$user_id'");
$row_user = $q_user->fetch_assoc();
$assigned_banks_str = $row_user['assigned_banks'] ?? '';
$assigned_banks = $assigned_banks_str ? explode(',', $assigned_banks_str) : [];

// AMBIL DAFTAR REKENING & KOLOM DB DARI TABEL MASTER SECARA DINAMIS
$db_map = [];
$q_rek = $conn->query("SELECT nama_bank, kolom_db FROM rekening");
while($r = $q_rek->fetch_assoc()) { 
    $db_map[$r['nama_bank']] = $r['kolom_db']; 
}

// Dapatkan Shift Kasir yang sedang berjalan
$q_shift_aktif = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1");

$shift_id = null; $modal_awal = 0; $uang_masuk = 0; $uang_keluar = 0;
$laba_tukar = 0; $laba_admin = 0; $saldo_akhir = 0;
$shift_ke = isset($_SESSION['shift_ke']) ? $_SESSION['shift_ke'] : '-';

// Array untuk menyimpan saldo bank yang DIIZINKAN saja
$saldo_bank = [];
foreach($assigned_banks as $b) { $saldo_bank[$b] = 0; }

if ($q_shift_aktif && $q_shift_aktif->num_rows > 0) {
    $shift_data = $q_shift_aktif->fetch_assoc();
    $shift_id = $shift_data['id'];
    $modal_awal = $shift_data['modal_awal'];

    // 1. PERHITUNGAN FISIK LACI CASH
    $q_masuk = $conn->query("SELECT SUM(nominal + admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
    $uang_masuk = $q_masuk->fetch_assoc()['total'] ?? 0;

    $q_keluar = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi = 'Tarik Tunai'");
    $uang_keluar = $q_keluar->fetch_assoc()['total'] ?? 0;

    $q_tukar = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi = 'Tukar Uang'");
    $laba_tukar = $q_tukar->fetch_assoc()['total'] ?? 0;

    $q_admin = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id'");
    $laba_admin = $q_admin->fetch_assoc()['total'] ?? 0;

    $saldo_akhir = $modal_awal + $uang_masuk + $laba_tukar - $uang_keluar;

    // 2. PERHITUNGAN SALDO DIGITAL SECARA DINAMIS (Hanya bank yang diizinkan)
    foreach($assigned_banks as $b_name) {
        if(isset($db_map[$b_name])) {
            $b_col = $db_map[$b_name];
            
            // Mengambil modal awal bank dari kolom tabel shift
            $modal_awal_bank = isset($shift_data[$b_col]) ? (float)$shift_data[$b_col] : 0;

            $q_in_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi = 'Tarik Tunai' AND bank_agen = '$b_name'");
            $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang') AND bank_agen = '$b_name'");
            
            $in_b = $q_in_b->fetch_assoc()['tot'] ?? 0;
            $out_b = $q_out_b->fetch_assoc()['tot'] ?? 0;
            
            $saldo_bank[$b_name] = $modal_awal_bank + $in_b - $out_b;
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
        
        #nota-print-area { display: none; } 
        
        @media (max-width: 767.98px) { 
            .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } 
        }
        
        @media print {
            body.printing-nota { background-color: white !important; margin: 0 !important; padding: 0 !important; }
            body.printing-nota > *:not(#nota-print-area) { display: none !important; }
            body.printing-nota #nota-print-area { display: block !important; width: 100%; }
            
            body:not(.printing-nota) { background-color: white; margin: 0; padding: 20px; }
            body:not(.printing-nota) .sidebar-modern, 
            body:not(.printing-nota) .mobile-header, 
            body:not(.printing-nota) .btn, 
            body:not(.printing-nota) .no-print, 
            body:not(.printing-nota) .aksi-kolom { display: none !important; }
            body:not(.printing-nota) .main-content { margin-left: 0 !important; padding: 0 !important; }
            body:not(.printing-nota) .modern-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold text-dark">Laporan Shift <?= $shift_ke; ?></h3>
            <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 shadow-sm no-print">
                <i class="bi bi-printer-fill me-2"></i> Cetak Laporan
            </button>
        </div>

        <?php if ($shift_id): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="modern-card h-100">
                        <h6 class="fw-bold text-muted mb-4"><i class="bi bi-wallet2 me-2"></i> Perhitungan Fisik Laci (CASH)</h6>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">Modal Awal:</span> <span class="fw-bold">Rp <?= number_format($modal_awal, 0, ',', '.'); ?></span></div>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">Total Masuk (Setor/Trf):</span> <span class="text-success fw-bold">+ Rp <?= number_format($uang_masuk, 0, ',', '.'); ?></span></div>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">Admin Tukar:</span> <span class="text-success fw-bold">+ Rp <?= number_format($laba_tukar, 0, ',', '.'); ?></span></div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-3"><span class="text-muted">Tarik Tunai:</span> <span class="text-danger fw-bold">- Rp <?= number_format($uang_keluar, 0, ',', '.'); ?></span></div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <h5 class="fw-bold mb-0 text-dark">Sisa Fisik</h5>
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
                        <div class="mt-3 p-3 bg-primary text-white rounded-4 text-center shadow-sm">
                            <h6 class="opacity-75 mb-1"><i class="bi bi-graph-up-arrow me-2"></i> Keuntungan Bersih (Admin)</h6>
                            <h3 class="fw-bolder mb-0">Rp <?= number_format($laba_admin, 0, ',', '.'); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modern-card">
                <h6 class="fw-bold text-dark mb-3">Detail Transaksi</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>No</th>
                                <th>Layanan</th>
                                <th>Rekening</th>
                                <th>Info / Tujuan</th>
                                <th>Nominal</th>
                                <th>Admin</th>
                                <th class="aksi-kolom">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_trans = $conn->query("SELECT * FROM transactions WHERE shift_id = '$shift_id' ORDER BY id DESC");
                            $no = 1;
                            while ($row = $q_trans->fetch_assoc()): ?>
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
                                    <td class="fw-bold text-success">+ Rp <?= number_format($row['admin_fee'], 0, ',', '.'); ?></td>
                                    <td class="aksi-kolom">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" 
                                            onclick="cetakNotaA5(
                                                'TRX-<?= date('Ymd', strtotime($row['tanggal'])); ?>-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?>',
                                                '<?= htmlspecialchars($row['jenis_transaksi']); ?>', 
                                                '<?= number_format($row['nominal'], 0, ',', '.'); ?>', 
                                                '<?= number_format($row['admin_fee'], 0, ',', '.'); ?>'
                                            )">
                                            <i class="bi bi-receipt"></i> Cetak Nota
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function cetakNotaA5(kode_nota, jenis, nominal, admin) {
        let cekTarik = jenis.toLowerCase().includes('tarik') ? '☑' : '☐';
        let cekTransfer = (jenis.toLowerCase().includes('transfer') || jenis.toLowerCase().includes('setor')) ? '☑' : '☐';
        let cekBayar = (jenis.toLowerCase().includes('bayar') || jenis.toLowerCase().includes('angsuran') || jenis.toLowerCase().includes('pulsa') || jenis.toLowerCase().includes('token')) ? '☑' : '☐';

        let tglWaktu = new Date().toLocaleString('id-ID');

        let notaContent = `
            <style>
                @page { size: A5 landscape; margin: 8mm; }
                #nota-print-area { font-family: Arial, sans-serif; font-size: 13px; line-height: 1.5; color: #000; width: 100%; max-width: 190mm; margin: auto; }
                .header-nota { display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; }
                .logo-box img { max-height: 55px; object-fit: contain; }
                .title-nota { text-align: right; font-weight: bold; font-size: 18px; color: #00529C; line-height: 1.2; }
                .sub-title-nota { font-size: 11px; color: #555; font-weight: normal; display: block; margin-top: 2px; }
                
                .alamat-nota { font-size: 10px; color: #333; font-weight: normal; display: block; margin-top: 3px; max-width: 250px; float: right;}
                
                .checkbox-group { display: flex; justify-content: flex-start; gap: 15px; margin-bottom: 12px; font-weight: bold; font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 8px;}
                .form-row { display: flex; justify-content: space-between; gap: 20px; }
                .form-col { flex: 1; }
                .form-group { display: flex; align-items: flex-end; margin-bottom: 7px; }
                .label { width: 135px; }
                .titik-dua { width: 15px; text-align: center; }
                .garis-bawah { flex: 1; border-bottom: 1px dashed #000; padding-left: 5px; min-height: 18px; }
                .section-title { font-weight: bold; margin-top: 10px; margin-bottom: 5px; text-decoration: underline;}
                .sub-form { padding-left: 10px; }
                
                .ttd-section { display: flex; justify-content: space-between; margin-top: 35px; text-align: center; font-size: 12px;}
                .ttd-box { width: 35%; display: flex; flex-direction: column; align-items: center;}
                .garis-ttd { border-bottom: 1px solid #000; margin-top: 80px; width: 80%; display: block; min-height: 1px;}
                
                .footer-nota { margin-top: 20px; font-weight: bold; font-size: 12px; border-top: 1px solid #ddd; padding-top: 8px; text-align: center;}
                .total-amount { font-size: 16px; font-weight: bold; }
            </style>
            
            <div class="header-nota">
                <div class="logo-box">
                    <img src="assets/img/logo-katanyang.png" alt="Logo">
                </div>
                <div class="title-nota">
                    Bukti Transaksi
                    <span class="sub-title-nota">Agen BRILink Resmi</span>
                    <span class="alamat-nota">Jln. Poros Desa Katayang Kab.Seruyan Kec.Hanau</span>
                </div>
            </div>
            
            <div class="checkbox-group">
                <span>${cekTarik} Tarik Tunai</span>
                <span>${cekTransfer} Transfer</span>
                <span>${cekBayar} Pembayaran</span>
            </div>

            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <div class="label">Kode Referensi</div><div class="titik-dua">:</div><div class="garis-bawah"><strong>${kode_nota}</strong></div>
                    </div>
                    <div class="form-group">
                        <div class="label">Tanggal / Waktu</div><div class="titik-dua">:</div><div class="garis-bawah">${tglWaktu}</div>
                    </div>
                    <div class="form-group">
                        <div class="label">Nama Penyetor</div><div class="titik-dua">:</div><div class="garis-bawah"></div>
                    </div>
                    <div class="form-group">
                        <div class="label">No. Telepon</div><div class="titik-dua">:</div><div class="garis-bawah"></div>
                    </div>
                </div>
                
                <div class="form-col">
                    <div class="section-title" style="margin-top: 0;">Detail Tujuan / Layanan :</div>
                    <div class="sub-form">
                        <div class="form-group">
                            <div class="label">- Nama Penerima</div><div class="titik-dua">:</div><div class="garis-bawah"></div>
                        </div>
                        <div class="form-group">
                            <div class="label">- No. Rek / Kontrak</div><div class="titik-dua">:</div><div class="garis-bawah"></div>
                        </div>
                        <div class="form-group">
                            <div class="label">- Jumlah Uang</div><div class="titik-dua">:</div><div class="garis-bawah"><span class="total-amount">Rp ${nominal}</span></div>
                        </div>
                        <div class="form-group">
                            <div class="label">- Biaya Admin Agen</div><div class="titik-dua">:</div><div class="garis-bawah">Rp ${admin}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ttd-section">
                <div class="ttd-box">
                    <div>Petugas Agen</div>
                    <div class="garis-ttd"></div>
                </div>
                <div class="ttd-box">
                    <div>Nasabah / Penyetor</div>
                    <div class="garis-ttd"></div>
                </div>
            </div>

            <div class="footer-nota">
                Simpan bukti ini sebagai tanda terima yang sah. Terima kasih.
            </div>
        `;

        let printArea = document.getElementById('nota-print-area');
        printArea.innerHTML = notaContent;
        document.body.classList.add('printing-nota');

        setTimeout(function() { window.print(); }, 800);

        window.onafterprint = function() {
            document.body.classList.remove('printing-nota');
            printArea.innerHTML = ''; 
        };
    }
    </script>
</body>
</html>