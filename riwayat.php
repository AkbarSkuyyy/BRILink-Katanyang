<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];

// Default: Filter tanggal
$filter_tanggal = isset($_GET['tanggal_filter']) ? $_GET['tanggal_filter'] : "";
$kondisi_query = "WHERE user_id = '$user_id'";

if (!empty($filter_tanggal)) {
    $tgl = $conn->real_escape_string($filter_tanggal);
    $kondisi_query .= " AND tanggal = '$tgl'";
}

// Menghitung ringkasan
$q_ringkasan = $conn->query("SELECT IFNULL(SUM(nominal), 0) as tot_nominal, IFNULL(SUM(admin_fee), 0) as tot_admin FROM transactions $kondisi_query");
$d_ringkasan = $q_ringkasan->fetch_assoc();
$total_perputaran = (float)$d_ringkasan['tot_nominal'];
$total_laba = (float)$d_ringkasan['tot_admin'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Riwayat Transaksi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style> 
        :root {
            --bri-blue: #00529C; --bri-black: #1a1a1a;
            --bri-white: #ffffff; --bri-grey: #f4f7fa;
        }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bri-grey); } 
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        .metric-card-mini { border-radius: 20px; padding: 20px; border: 1px solid #e9ecef; display: flex; align-items: center; gap: 15px; background: white; box-shadow: 0 4px 10px rgba(0,0,0,0.02); height: 100%; }
        .metric-icon { width: 55px; height: 55px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; background: var(--bri-light-blue); color: var(--bri-blue); }

        /* Elemen div tempat cetak nota, disembunyikan jika tidak diprint */
        #nota-print-area { display: none; } 

        /* Mobile Layout Adjustments */
        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; }
            .hide-on-mobile { display: none !important; }
        }
        
        /* Kartu Transaksi Mobile */
        .trx-card { background: white; border-radius: 16px; padding: 15px; margin-bottom: 12px; border-left: 5px solid var(--bri-blue); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }

        /* === CSS PRINT AMAN UNTUK HP === */
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
        <h3 class="fw-extrabold mb-4" style="color: var(--bri-black);">Riwayat Transaksi</h3>

        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <div class="modern-card h-100">
                    <label class="form-label text-muted small fw-bold text-uppercase">Filter Tanggal</label>
                    <form method="GET" class="d-flex gap-2">
                        <input type="date" name="tanggal_filter" class="form-control" value="<?= $filter_tanggal; ?>">
                        <button type="submit" class="btn btn-primary fw-bold px-3">Cari</button>
                    </form>
                </div>
            </div>
            <div class="col-md-7 mb-3">
                <div class="modern-card h-100 d-flex justify-content-around align-items-center flex-row">
                    <div class="text-center" style="width: 50%;">
                        <p class="text-muted small mb-1 fw-bold text-uppercase" style="font-size: 10px;">Total Perputaran</p>
                        <h5 class="fw-bold text-dark mb-0 text-truncate" style="font-size: clamp(0.9rem, 4vw, 1.25rem);">
                            Rp <?= number_format($total_perputaran, 0, ',', '.'); ?>
                        </h5>
                    </div>
                    <div class="border-end h-75"></div>
                    <div class="text-center" style="width: 50%;">
                        <p class="text-muted small mb-1 fw-bold text-uppercase" style="font-size: 10px;">Laba Admin</p>
                        <h5 class="fw-bold text-success mb-0 text-truncate" style="font-size: clamp(0.9rem, 4vw, 1.25rem);">
                            + Rp <?= number_format($total_laba, 0, ',', '.'); ?>
                        </h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <div class="d-none d-md-block table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="text-muted small text-uppercase">
                        <tr>
                            <th>Waktu</th>
                            <th>Layanan</th>
                            <th>Keterangan</th>
                            <th>Nominal</th>
                            <th>Admin</th>
                            <th class="aksi-kolom text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $q_trans = $conn->query("SELECT * FROM transactions $kondisi_query ORDER BY id DESC");
                        while ($row = $q_trans->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?= date('d/m/y', strtotime($row['tanggal'])); ?></td>
                                <td><span class="badge bg-light text-dark border"><?= $row['jenis_transaksi']; ?></span></td>
                                <td><?= $row['keterangan'] ?: '-'; ?></td>
                                <td>Rp <?= number_format($row['nominal'], 0, ',', '.'); ?></td>
                                <td class="text-success fw-bold">+ Rp <?= number_format($row['admin_fee'], 0, ',', '.'); ?></td>
                                <td class="aksi-kolom text-end">
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

            <div class="d-md-none">
                <?php 
                $q_trans = $conn->query("SELECT * FROM transactions $kondisi_query ORDER BY id DESC");
                while ($row = $q_trans->fetch_assoc()): ?>
                    <div class="trx-card">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="badge bg-primary"><?= $row['jenis_transaksi']; ?></span>
                            <small class="text-muted fw-bold"><?= date('d/m/y', strtotime($row['tanggal'])); ?></small>
                        </div>
                        
                        <div class="fw-bold text-dark mb-2 text-break" style="font-size: 0.9rem; line-height: 1.3;">
                            <?= $row['keterangan'] ?: '-'; ?>
                        </div>
                        
                        <div class="pt-2 border-top mb-2">
                            <div class="row align-items-center">
                                <div class="col-6">
                                    <small class="text-muted d-block" style="font-size: 10px;">NOMINAL</small>
                                    <span class="fw-bold d-block" style="font-size: 0.95rem;">
                                        <?= ($row['jenis_transaksi'] == 'Tukar Uang') ? '-' : 'Rp ' . number_format($row['nominal'], 0, ',', '.'); ?>
                                    </span>
                                </div>
                                <div class="col-6 text-end">
                                    <small class="text-muted d-block" style="font-size: 10px;">ADMIN</small>
                                    <span class="text-success fw-bold d-block" style="font-size: 0.95rem;">
                                        + Rp <?= number_format($row['admin_fee'], 0, ',', '.'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="border-top pt-2">
                            <button class="btn btn-sm btn-outline-primary rounded-pill w-100 fw-bold py-2" 
                                onclick="cetakNotaA5(
                                    'TRX-<?= date('Ymd', strtotime($row['tanggal'])); ?>-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?>',
                                    '<?= htmlspecialchars($row['jenis_transaksi']); ?>', 
                                    '<?= number_format($row['nominal'], 0, ',', '.'); ?>', 
                                    '<?= number_format($row['admin_fee'], 0, ',', '.'); ?>'
                                )">
                                <i class="bi bi-printer me-1"></i> Cetak Ulang Nota
                            </button>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <div id="nota-print-area"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // JS Diambil dari laporan.php untuk keseragaman nota
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
                
                /* Penyesuaian Ruang Tanda Tangan untuk Stempel */
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

        // Delay sedikit agar logo sempat terender oleh HP
        setTimeout(function() { window.print(); }, 800);

        // Setelah kembali dari dialog print, hapus elemen nota
        window.onafterprint = function() {
            document.body.classList.remove('printing-nota');
            printArea.innerHTML = ''; 
        };
    }
    </script>
</body>
</html>