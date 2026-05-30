<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

// Ambil info cabang dan shift
$q_user = $conn->query("SELECT cabang_id, shift_ke FROM users WHERE id = '$user_id'");
$user_info = $q_user->fetch_assoc();
$cabang_id = $user_info['cabang_id'];
$shift_pegawai = $user_info['shift_ke'];

$q_cek_beku = $conn->query("SELECT s.id FROM shifts s JOIN users u ON s.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND s.tanggal = '$tanggal_hari_ini' AND s.shift_ke = 2 AND s.status = 'selesai'");
$cabang_dibekukan = ($q_cek_beku->num_rows > 0);

if (isset($_POST['mulai_shift_1'])) {
    $modal_awal = $_POST['modal_awal'];
    $conn->query("INSERT INTO shifts (user_id, tanggal, shift_ke, modal_awal, status) VALUES ('$user_id', '$tanggal_hari_ini', 1, '$modal_awal', 'aktif')");
    if(function_exists('notifShiftBuka')) notifShiftBuka($_SESSION['nama_cabang'], 1, $modal_awal);
    header("Location: set_modal.php"); exit;
}
if (isset($_POST['mulai_shift_2'])) {
    $modal_awal = $_POST['modal_awal'];
    $conn->query("INSERT INTO shifts (user_id, tanggal, shift_ke, modal_awal, status) VALUES ('$user_id', '$tanggal_hari_ini', 2, '$modal_awal', 'aktif')");
    if(function_exists('notifShiftBuka')) notifShiftBuka($_SESSION['nama_cabang'], 2, $modal_awal);
    header("Location: set_modal.php"); exit;
}
if (isset($_POST['tutup_shift'])) {
    $shift_id_aktif = $_POST['shift_id_aktif'];
    $conn->query("UPDATE shifts SET status = 'selesai' WHERE id = '$shift_id_aktif'");
    if(function_exists('notifShiftTutup')) notifShiftTutup($_SESSION['nama_cabang'], $shift_pegawai);
    header("Location: set_modal.php"); exit;
}

$q_shift_saya = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1");
$shift_saya = $q_shift_saya->fetch_assoc();

$saldo_sekarang = 0; $uang_masuk = 0; $uang_keluar = 0;
if ($shift_saya && $shift_saya['status'] == 'aktif') {
    $shift_id = $shift_saya['id'];
    $modal_awal = $shift_saya['modal_awal'];
    $q_masuk = $conn->query("SELECT SUM(nominal + admin_fee) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi != 'Tarik Tunai'");
    $uang_masuk = !empty($q_masuk->fetch_assoc()['total']) ? $q_masuk->fetch_assoc()['total'] : 0;
    $q_keluar = $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$shift_id' AND jenis_transaksi = 'Tarik Tunai'");
    $uang_keluar = !empty($q_keluar->fetch_assoc()['total']) ? $q_keluar->fetch_assoc()['total'] : 0;
    $saldo_sekarang = $modal_awal + $uang_masuk - $uang_keluar;
}

$saldo_akhir_shift_1 = 0; $shift_1_selesai = false; $menunggu_shift_1 = false;
if (!$cabang_dibekukan && !$shift_saya && $shift_pegawai == 2) {
    $q_shift_1 = $conn->query("SELECT s.id, s.modal_awal, s.status FROM shifts s JOIN users u ON s.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND s.tanggal = '$tanggal_hari_ini' AND s.shift_ke = 1 ORDER BY s.id DESC LIMIT 1");
    if ($q_shift_1->num_rows > 0) {
        $data_shift_1 = $q_shift_1->fetch_assoc();
        if ($data_shift_1['status'] == 'selesai') {
            $shift_1_selesai = true; $s1_id = $data_shift_1['id']; $s1_modal = $data_shift_1['modal_awal'];
            $m1 = !empty($conn->query("SELECT SUM(nominal + admin_fee) as total FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi != 'Tarik Tunai'")->fetch_assoc()['total']) ? $conn->query("SELECT SUM(nominal + admin_fee) as total FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi != 'Tarik Tunai'")->fetch_assoc()['total'] : 0;
            $k1 = !empty($conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi = 'Tarik Tunai'")->fetch_assoc()['total']) ? $conn->query("SELECT SUM(nominal) as total FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi = 'Tarik Tunai'")->fetch_assoc()['total'] : 0;
            $saldo_akhir_shift_1 = $s1_modal + $m1 - $k1;
        } else { $menunggu_shift_1 = true; }
    } else { $menunggu_shift_1 = true; }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Set Modal & Shift</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); }
        .btn-modern { border-radius: 14px; padding: 14px; font-weight: 700; }
        @media (max-width: 767.98px) { 
            .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } 
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h3 class="fw-bold mb-4 text-dark"><i class="bi bi-wallet2 me-2 text-primary"></i>Pengaturan Modal</h3>

        <?php if ($cabang_dibekukan): ?>
            <div class="modern-card text-center p-5">
                <i class="bi bi-lock-fill text-secondary fs-1"></i>
                <h4 class="fw-bold mt-3">Cabang Dibekukan</h4>
                <p class="text-muted">Operasional hari ini sudah berakhir.</p>
            </div>
        <?php elseif ($shift_saya && $shift_saya['status'] == 'aktif'): ?>
            <div class="modern-card">
                <span class="badge bg-primary mb-3 px-3 py-2 rounded-pill">Shift <?= $shift_pegawai; ?> Sedang Berjalan</span>
                <div class="row g-4 mt-2">
                    <div class="col-md-6">
                        <p class="text-muted small fw-bold text-uppercase">Saldo Laci Sekarang</p>
                        <h2 class="fw-bolder text-primary mb-4">Rp <?= number_format($saldo_sekarang, 0, ',', '.'); ?></h2>
                        <form method="POST">
                            <input type="hidden" name="shift_id_aktif" value="<?= $shift_saya['id']; ?>">
                            <button type="submit" name="tutup_shift" class="btn btn-danger w-100 btn-modern shadow-sm" onclick="return confirm('Yakin ingin tutup shift?')">Tutup Shift Sekarang</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif (!$shift_saya && $shift_pegawai == 1): ?>
            <div class="modern-card" style="max-width: 500px; margin: auto;">
                <h5 class="fw-bold mb-3">Mulai Shift Pagi</h5>
                <p class="text-muted small mb-4">Masukkan uang fisik modal awal.</p>
                <form method="POST">
                    <div class="mb-4">
                        <input type="number" name="modal_awal" class="form-control form-control-lg bg-light border-0 fw-bold fs-4" required placeholder="Rp 0">
                    </div>
                    <button type="submit" name="mulai_shift_1" class="btn btn-primary w-100 btn-modern shadow-sm">Mulai Shift 1</button>
                </form>
            </div>
        <?php elseif (!$shift_saya && $shift_pegawai == 2): ?>
            <?php if ($menunggu_shift_1): ?>
                <div class="modern-card text-center text-muted">
                    <i class="bi bi-hourglass-split fs-1"></i>
                    <h5 class="fw-bold mt-3">Menunggu Shift 1</h5>
                    <p>Shift 1 belum tutup laporan.</p>
                </div>
            <?php elseif ($shift_1_selesai): ?>
                <div class="modern-card" style="max-width: 500px; margin: auto;">
                    <h5 class="fw-bold mb-3">Terima Saldo Shift 1</h5>
                    <h2 class="fw-bolder text-primary my-4">Rp <?= number_format($saldo_akhir_shift_1, 0, ',', '.'); ?></h2>
                    <form method="POST">
                        <input type="hidden" name="modal_awal" value="<?= $saldo_akhir_shift_1; ?>">
                        <button type="submit" name="mulai_shift_2" class="btn btn-warning w-100 btn-modern">Mulai Shift 2</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>