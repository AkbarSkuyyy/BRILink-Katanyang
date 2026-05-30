<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];

// Dapatkan ID Cabang kasir saat ini
$q_user = $conn->query("SELECT cabang_id FROM users WHERE id = '$user_id'");
$row_user = $q_user->fetch_assoc();
$cabang_id = !empty($row_user['cabang_id']) ? $row_user['cabang_id'] : 0;

// Proses Simpan Data
if (isset($_POST['simpan_receh'])) {
    $q_100k = !empty($_POST['q_100k']) ? $_POST['q_100k'] : 0;
    $q_50k  = !empty($_POST['q_50k']) ? $_POST['q_50k'] : 0;
    $q_20k  = !empty($_POST['q_20k']) ? $_POST['q_20k'] : 0;
    $q_10k  = !empty($_POST['q_10k']) ? $_POST['q_10k'] : 0;
    $q_5k   = !empty($_POST['q_5k']) ? $_POST['q_5k'] : 0;
    $q_2k   = !empty($_POST['q_2k']) ? $_POST['q_2k'] : 0;
    $q_1k   = !empty($_POST['q_1k']) ? $_POST['q_1k'] : 0;

    // Hitung Total Uang
    $total = ($q_100k * 100000) + ($q_50k * 50000) + ($q_20k * 20000) + ($q_10k * 10000) + ($q_5k * 5000) + ($q_2k * 2000) + ($q_1k * 1000);

    // Cek apakah cabang sudah punya data, jika ada di-update, jika belum di-insert
    $cek = $conn->query("SELECT id FROM uang_receh WHERE cabang_id = '$cabang_id'");
    if ($cek->num_rows > 0) {
        $conn->query("UPDATE uang_receh SET qty_100k='$q_100k', qty_50k='$q_50k', qty_20k='$q_20k', qty_10k='$q_10k', qty_5k='$q_5k', qty_2k='$q_2k', qty_1k='$q_1k', total='$total' WHERE cabang_id = '$cabang_id'");
    } else {
        $conn->query("INSERT INTO uang_receh (cabang_id, qty_100k, qty_50k, qty_20k, qty_10k, qty_5k, qty_2k, qty_1k, total) VALUES ('$cabang_id', '$q_100k', '$q_50k', '$q_20k', '$q_10k', '$q_5k', '$q_2k', '$q_1k', '$total')");
    }
    
    // --- PELATUK NOTIFIKASI TELEGRAM OTOMATIS (BARU) ---
    notifUpdateReceh($conn, $cabang_id, $_SESSION['nama_cabang'], $total);
    // --------------------------------------------
    
    echo "<script>alert('Stok uang receh berhasil diperbarui!'); window.location='set_uang_receh.php';</script>";
}

// Ambil data receh saat ini
$q_receh = $conn->query("SELECT * FROM uang_receh WHERE cabang_id = '$cabang_id'");
$data_receh = $q_receh->fetch_assoc();

// Menyiapkan variabel aman agar tidak error di HTML
$qty_100k = !empty($data_receh['qty_100k']) ? $data_receh['qty_100k'] : 0;
$qty_50k  = !empty($data_receh['qty_50k']) ? $data_receh['qty_50k'] : 0;
$qty_20k  = !empty($data_receh['qty_20k']) ? $data_receh['qty_20k'] : 0;
$qty_10k  = !empty($data_receh['qty_10k']) ? $data_receh['qty_10k'] : 0;
$qty_5k   = !empty($data_receh['qty_5k']) ? $data_receh['qty_5k'] : 0;
$qty_2k   = !empty($data_receh['qty_2k']) ? $data_receh['qty_2k'] : 0;
$qty_1k   = !empty($data_receh['qty_1k']) ? $data_receh['qty_1k'] : 0;
$total_receh = !empty($data_receh['total']) ? $data_receh['total'] : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Set Uang Receh</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style> 
        body { font-family: 'Montserrat', sans-serif; background-color: #f4f7fa; } 
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .card-receh { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); background: white; padding: 30px; }
        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 15px !important; padding-top: 85px !important; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <h3 class="fw-bold mb-4 text-dark">Pengaturan Uang Receh <span class="text-primary fs-5">(<?= $_SESSION['nama_cabang']; ?>)</span></h3>

        <div class="row g-4">
            <div class="col-md-7">
                <div class="card-receh">
                    <h5 class="fw-bold mb-4 border-bottom pb-2 text-dark">Input Fisik (Lembar / Keping)</h5>
                    <form method="POST">
                        <div class="row mb-3 align-items-center">
                            <div class="col-5 fw-semibold text-muted">Rp 100.000</div>
                            <div class="col-7"><input type="number" name="q_100k" class="form-control form-control-lg bg-light border-0" value="<?= $qty_100k; ?>"></div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <div class="col-5 fw-semibold text-muted">Rp 50.000</div>
                            <div class="col-7"><input type="number" name="q_50k" class="form-control form-control-lg bg-light border-0" value="<?= $qty_50k; ?>"></div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <div class="col-5 fw-semibold text-muted">Rp 20.000</div>
                            <div class="col-7"><input type="number" name="q_20k" class="form-control form-control-lg bg-light border-0" value="<?= $qty_20k; ?>"></div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <div class="col-5 fw-semibold text-muted">Rp 10.000</div>
                            <div class="col-7"><input type="number" name="q_10k" class="form-control form-control-lg bg-light border-0" value="<?= $qty_10k; ?>"></div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <div class="col-5 fw-semibold text-muted">Rp 5.000</div>
                            <div class="col-7"><input type="number" name="q_5k" class="form-control form-control-lg bg-light border-0" value="<?= $qty_5k; ?>"></div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <div class="col-5 fw-semibold text-muted">Rp 2.000</div>
                            <div class="col-7"><input type="number" name="q_2k" class="form-control form-control-lg bg-light border-0" value="<?= $qty_2k; ?>"></div>
                        </div>
                        <div class="row mb-4 align-items-center">
                            <div class="col-5 fw-semibold text-muted">Rp 1.000</div>
                            <div class="col-7"><input type="number" name="q_1k" class="form-control form-control-lg bg-light border-0" value="<?= $qty_1k; ?>"></div>
                        </div>
                        
                        <button type="submit" name="simpan_receh" class="btn btn-primary w-100 fw-bold btn-lg rounded-pill shadow-sm" style="background-color: #00529C; border: none;">Simpan Stok Receh</button>
                    </form>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card-receh text-white text-center h-100 d-flex flex-column justify-content-center" style="background: linear-gradient(135deg, #00529C, #003366);">
                    <i class="bi bi-safe-fill mb-3" style="font-size: 3rem; opacity: 0.8;"></i>
                    <p class="mb-2 opacity-75 fw-semibold text-uppercase tracking-wide">Total Receh Tersedia</p>
                    <h1 class="fw-bolder mb-4">Rp <?= number_format($total_receh, 0, ',', '.'); ?></h1>
                    <p class="small opacity-50 mb-0">Total ini akan langsung terhubung ke layar Dashboard Anda.</p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>