<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

$filter_tgl_mulai = isset($_GET['tgl_mulai']) ? $_GET['tgl_mulai'] : date('Y-m-01');
$filter_tgl_sampai = isset($_GET['tgl_sampai']) ? $_GET['tgl_sampai'] : date('Y-m-d');
$filter_cabang = isset($_GET['cabang']) ? $_GET['cabang'] : 'semua';
$filter_jenis = isset($_GET['jenis']) ? $_GET['jenis'] : 'semua';

$where = "t.tanggal BETWEEN '$filter_tgl_mulai' AND '$filter_tgl_sampai' AND t.jenis_transaksi IN ('Tarik Dana Bos', 'Setor Dana Bos')";

if ($filter_cabang !== 'semua') {
    $where .= " AND u.cabang_id = '$filter_cabang'";
}
if ($filter_jenis !== 'semua') {
    $where .= " AND t.jenis_transaksi = '$filter_jenis'";
}

$q_laporan = $conn->query("
    SELECT t.*, c.nama_cabang, u.username, s.shift_ke
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN cabang c ON u.cabang_id = c.id
    LEFT JOIN shifts s ON t.shift_id = s.id
    WHERE $where
    ORDER BY t.tanggal DESC, t.id DESC
");

$total_tarik = 0;
$total_setor = 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan Dana Bos - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 30px; border: none; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 10px; font-weight: 600; border: none; }
        .table-custom th { background-color: #e6f0ff; color: var(--bri-blue); font-weight: 700; text-transform: uppercase; font-size: 12px; padding: 15px; border-bottom: 2px solid #cce0ff; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f0f0f0; font-size: 14px; font-weight: 500; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0; padding: 20px 15px; padding-top: 85px; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);"><i class="bi bi-wallet2 me-2 text-primary"></i>Laporan Tarik & Setor Bos</h3>
                <p class="text-muted mb-0">Pantau mutasi uang untuk keperluan bos / pemilik (Tanpa potongan admin).</p>
            </div>
            <button onclick="window.print()" class="btn btn-dark rounded-pill px-4"><i class="bi bi-printer me-2"></i>Cetak</button>
        </div>

        <div class="modern-card mb-4 p-3 bg-white">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">Dari Tanggal</label>
                    <input type="date" name="tgl_mulai" class="form-control" value="<?= $filter_tgl_mulai ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">Sampai Tanggal</label>
                    <input type="date" name="tgl_sampai" class="form-control" value="<?= $filter_tgl_sampai ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Toko / Cabang</label>
                    <select name="cabang" class="form-select">
                        <option value="semua">Semua Cabang</option>
                        <?php 
                        $qc = $conn->query("SELECT * FROM cabang");
                        if($qc){ while($c = $qc->fetch_assoc()): $sel = ($filter_cabang == $c['id']) ? 'selected' : ''; ?>
                            <option value="<?= $c['id'] ?>" <?= $sel ?>><?= htmlspecialchars($c['nama_cabang']) ?></option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Jenis</label>
                    <select name="jenis" class="form-select">
                        <option value="semua" <?= $filter_jenis == 'semua' ? 'selected' : '' ?>>Tarik & Setor</option>
                        <option value="Tarik Dana Bos" <?= $filter_jenis == 'Tarik Dana Bos' ? 'selected' : '' ?>>Hanya Penarikan Bos</option>
                        <option value="Setor Dana Bos" <?= $filter_jenis == 'Setor Dana Bos' ? 'selected' : '' ?>>Hanya Penyetoran Bos</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-modern w-100 py-2"><i class="bi bi-search me-1"></i> Tampilkan</button>
                </div>
            </form>
        </div>

        <div class="modern-card">
            <div class="table-responsive">
                <table class="table table-custom text-nowrap align-middle">
                    <thead>
                        <tr>
                            <th>Tgl Transaksi</th>
                            <th>Cabang & Kasir</th>
                            <th>Aktivitas Bos</th>
                            <th>Sumber Dana</th>
                            <th>Keterangan</th>
                            <th class="text-end">Nominal (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($q_laporan && $q_laporan->num_rows > 0): while($r = $q_laporan->fetch_assoc()): 
                            if ($r['jenis_transaksi'] == 'Tarik Dana Bos') { $total_tarik += $r['nominal']; }
                            else { $total_setor += $r['nominal']; }
                        ?>
                        <tr>
                            <td>
                                <span class="fw-bold d-block"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-primary rounded-pill mb-1"><?= htmlspecialchars($r['nama_cabang']) ?></span><br>
                                <small class="text-muted fw-bold">@<?= htmlspecialchars($r['username']) ?> (S<?= $r['shift_ke'] ?>)</small>
                            </td>
                            <td>
                                <?php if($r['jenis_transaksi'] == 'Tarik Dana Bos'): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-arrow-down-circle me-1"></i>Tarik Dana (Prive)</span>
                                <?php else: ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-arrow-up-circle me-1"></i>Setor Dana (Modal)</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold"><?= $r['bank_agen'] == 'CASH' ? '💵 Laci Kasir' : '🏦 '.$r['bank_agen'] ?></td>
                            <td class="text-muted"><small><?= htmlspecialchars($r['keterangan']) ?></small></td>
                            <td class="text-end fw-bolder fs-6 <?= $r['jenis_transaksi'] == 'Tarik Dana Bos' ? 'text-danger' : 'text-success' ?>">
                                <?= $r['jenis_transaksi'] == 'Tarik Dana Bos' ? '-' : '+' ?> Rp <?= number_format($r['nominal'], 0, ',', '.') ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada mutasi dana Bos pada periode ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="4"></td>
                            <td class="text-end fw-bold">Total Ditarik Bos:</td>
                            <td class="text-end fw-bolder text-danger fs-5">- Rp <?= number_format($total_tarik, 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <td colspan="4"></td>
                            <td class="text-end fw-bold">Total Disetor Bos:</td>
                            <td class="text-end fw-bolder text-success fs-5">+ Rp <?= number_format($total_setor, 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</body>
</html>