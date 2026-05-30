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

        /* Mobile Layout Adjustments */
        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; }
            .hide-on-mobile { display: none !important; }
        }
        
        /* Kartu Transaksi Mobile */
        .trx-card { background: white; border-radius: 16px; padding: 15px; margin-bottom: 12px; border-left: 5px solid var(--bri-blue); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
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
            <div class="d-none d-md-block">
                <table class="table table-hover align-middle">
                    <thead class="text-muted small text-uppercase">
                        <tr><th>Waktu</th><th>Layanan</th><th>Keterangan</th><th>Nominal</th><th>Admin</th></tr>
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
                            <small class="text-muted"><?= date('d/m/y', strtotime($row['tanggal'])); ?></small>
                        </div>
                        
                        <div class="fw-bold text-dark mb-2 text-break" style="font-size: 0.9rem; line-height: 1.3;">
                            <?= $row['keterangan'] ?: '-'; ?>
                        </div>
                        
                        <div class="pt-2 border-top">
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
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>