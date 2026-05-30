<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

// Dapatkan Shift Kasir yang sedang berjalan
$q_shift_aktif = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1");

$shift_id = null; $modal_awal = 0; $uang_masuk = 0; $uang_keluar = 0;
$laba_tukar = 0; $laba_admin = 0; $saldo_akhir = 0;
$shift_ke = isset($_SESSION['shift_ke']) ? $_SESSION['shift_ke'] : '-';

if ($q_shift_aktif && $q_shift_aktif->num_rows > 0) {
    $shift_data = $q_shift_aktif->fetch_assoc();
    $shift_id = $shift_data['id'];
    $modal_awal = $shift_data['modal_awal'];

    // PERBAIKAN LOGIKA FETCH_ASSOC AGAR TIDAK DOUBLE CALL
    $q_masuk = $conn->query("SELECT SUM(nominal + admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
    $row_masuk = $q_masuk->fetch_assoc();
    $uang_masuk = $row_masuk['total'] ?? 0;

    $q_keluar = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi = 'Tarik Tunai'");
    $row_keluar = $q_keluar->fetch_assoc();
    $uang_keluar = $row_keluar['total'] ?? 0;

    $q_tukar = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi = 'Tukar Uang'");
    $row_tukar = $q_tukar->fetch_assoc();
    $laba_tukar = $row_tukar['total'] ?? 0;

    $q_admin = $conn->query("SELECT SUM(admin_fee) as total FROM transactions WHERE shift_id = '$shift_id'");
    $row_admin = $q_admin->fetch_assoc();
    $laba_admin = $row_admin['total'] ?? 0;

    $saldo_akhir = $modal_awal + $uang_masuk + $laba_tukar - $uang_keluar;
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
        @media (max-width: 767.98px) { 
            .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } 
        }
        
        /* CSS Print untuk Laporan Shift Global */
        @media print {
            body { background-color: white; margin: 0; padding: 20px; }
            .sidebar-modern, .mobile-header, .btn, .no-print, .aksi-kolom { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .modern-card { box-shadow: none; border: 1px solid #ddd; }
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
                <div class="col-md-7">
                    <div class="modern-card h-100">
                        <h6 class="fw-bold text-muted mb-4"><i class="bi bi-wallet2 me-2"></i> Perhitungan Fisik Laci</h6>
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
                <div class="col-md-5">
                    <div class="modern-card h-100 bg-primary text-white d-flex flex-column justify-content-center">
                        <h6 class="opacity-75"><i class="bi bi-graph-up-arrow me-2"></i> Keuntungan Bersih (Admin)</h6>
                        <h1 class="fw-bolder my-3">Rp <?= number_format($laba_admin, 0, ',', '.'); ?></h1>
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
                                <th>Info</th>
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
                                    <td><small class="text-muted"><?= htmlspecialchars($row['keterangan']) ?: '-'; ?></small></td>
                                    <td>Rp <?= number_format($row['nominal'], 0, ',', '.'); ?></td>
                                    <td class="fw-bold text-success">+ Rp <?= number_format($row['admin_fee'], 0, ',', '.'); ?></td>
                                    <td class="aksi-kolom">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" 
                                            onclick="cetakNotaA5(
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function cetakNotaA5(jenis, nominal, admin) {
        // Ceklist otomatis berdasarkan jenis
        let cekTarik = jenis.toLowerCase().includes('tarik') ? '☑' : '☐';
        let cekTransfer = (jenis.toLowerCase().includes('transfer') || jenis.toLowerCase().includes('setor')) ? '☑' : '☐';
        let cekBayar = (jenis.toLowerCase().includes('bayar') || jenis.toLowerCase().includes('angsuran')) ? '☑' : '☐';

        let tglWaktu = new Date().toLocaleString('id-ID');

        let notaHTML = `
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <title>Cetak Nota A5 Landscape</title>
            <style>
                /* Menentukan ukuran kertas A5 dan orientasi Landscape */
                @page { size: A5 landscape; margin: 8mm; }
                body { 
                    font-family: Arial, sans-serif; 
                    font-size: 13px; 
                    line-height: 1.5;
                    color: #000;
                    margin: 0; padding: 0;
                }
                /* Mengatur lebar maksimum agar proporsional untuk landscape */
                .container { width: 100%; max-width: 190mm; margin: auto; }
                
                /* Header dengan Logo Katanyang */
                .header-nota { display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; }
                .logo-box img { max-height: 55px; object-fit: contain; }
                .title-nota { text-align: right; font-weight: bold; font-size: 18px; color: #00529C; line-height: 1.2; }
                .sub-title-nota { font-size: 11px; color: #555; font-weight: normal; }

                .checkbox-group { display: flex; justify-content: flex-start; gap: 15px; margin-bottom: 12px; font-weight: bold; font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 8px;}
                
                /* Membagi form menjadi 2 kolom untuk landscape */
                .form-row { display: flex; justify-content: space-between; gap: 20px; }
                .form-col { flex: 1; }

                .form-group { display: flex; align-items: flex-end; margin-bottom: 7px; }
                .label { width: 135px; }
                .titik-dua { width: 15px; text-align: center; }
                /* Garis bawah kosong untuk ditulis manual */
                .garis-bawah { flex: 1; border-bottom: 1px dashed #000; padding-left: 5px; min-height: 18px; }
                
                .section-title { font-weight: bold; margin-top: 10px; margin-bottom: 5px; text-decoration: underline;}
                .sub-form { padding-left: 10px; }
                
                .ttd-section { display: flex; justify-content: space-between; margin-top: 25px; text-align: center; font-size: 12px;}
                .ttd-box { width: 35%; display: flex; flex-direction: column; align-items: center;}
                .garis-ttd { border-bottom: 1px solid #000; margin-top: 45px; width: 80%; display: block; min-height: 1px;}
                
                .footer-nota { margin-top: 20px; font-weight: bold; font-size: 12px; border-top: 1px solid #ddd; padding-top: 8px; text-align: center;}
                
                /* Tebalkan Nominal Utama */
                .total-amount { font-size: 16px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header-nota">
                    <div class="logo-box">
                        <img src="assets/img/logo-katanyang.png" alt="Logo">
                    </div>
                    <div class="title-nota">
                        Bukti Transaksi<br>
                        <span class="sub-title-nota">Agen BRILink Resmi</span>
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
            </div>
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() { window.close(); }, 500);
                }
            <\/script>
        </body>
        </html>
        `;

        let printWindow = window.open('', '_blank', 'width=800,height=600');
        printWindow.document.open();
        printWindow.document.write(notaHTML);
        printWindow.document.close();
    }
    </script>
</body>
</html>