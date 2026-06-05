<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

// Auto-Create Tabel jika belum ada
$conn->query("CREATE TABLE IF NOT EXISTS laporan_toko (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    cabang_id INT NOT NULL,
    tanggal DATE NOT NULL,
    shift_ke INT NOT NULL,
    nama_penyetor VARCHAR(100) NOT NULL,
    jumlah_setoran DOUBLE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ambil info cabang & shift kasir saat ini
$q_user = $conn->query("SELECT cabang_id, shift_ke FROM users WHERE id = '$user_id'");
$user_info = $q_user ? $q_user->fetch_assoc() : [];
$cabang_id = !empty($user_info['cabang_id']) ? $user_info['cabang_id'] : 0;
$shift_pegawai = !empty($user_info['shift_ke']) ? $user_info['shift_ke'] : 1;

// Proses Simpan Laporan
if (isset($_POST['simpan_laporan'])) {
    $nama_penyetor = $conn->real_escape_string(trim($_POST['nama_penyetor']));
    $jumlah_setoran = (float)str_replace('.', '', $_POST['jumlah_setoran']);

    $insert = $conn->query("INSERT INTO laporan_toko (user_id, cabang_id, tanggal, shift_ke, nama_penyetor, jumlah_setoran) 
                            VALUES ('$user_id', '$cabang_id', '$tanggal_hari_ini', '$shift_pegawai', '$nama_penyetor', '$jumlah_setoran')");
    
    if($insert) {
        $_SESSION['flash_success'] = "Laporan setoran berhasil disimpan!";
    } else {
        $_SESSION['flash_error'] = "Gagal menyimpan laporan setoran.";
    }
    header("Location: input_laporan_toko.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Setoran Toko - Karyawan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 30px; border: none; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 12px; padding: 12px 20px; font-weight: 700; border: none; transition: all 0.3s; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 82, 156, 0.3); color: white; }
        .form-control { border-radius: 12px; padding: 12px 15px; font-weight: 600; background-color: var(--bg-body); border: 1px solid #dee2e6; }
        .form-control:focus { border-color: var(--bri-blue); box-shadow: none; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0; padding: 20px 15px; padding-top: 85px; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);"><i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Setoran Toko</h3>
        <p class="text-muted mb-4">Input jumlah uang fisik yang disetorkan ke admin / bos pada akhir shift Anda.</p>

        <div class="row g-4">
            <div class="col-md-5">
                <div class="modern-card">
                    <h5 class="fw-bold mb-4 border-bottom pb-2">Form Input Setoran</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Nama Penyetor</label>
                            <input type="text" name="nama_penyetor" class="form-control" placeholder="Contoh: Budi (Kasir Pagi)" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Jumlah Uang Setoran (Rp)</label>
                            <input type="text" name="jumlah_setoran" class="form-control format-rupiah text-primary fs-5" placeholder="0" required>
                        </div>
                        <button type="submit" name="simpan_laporan" class="btn-modern w-100"><i class="bi bi-send-fill me-2"></i>Kirim Setoran</button>
                    </form>
                </div>
            </div>

            <div class="col-md-7">
                <div class="modern-card h-100">
                    <h5 class="fw-bold mb-4 border-bottom pb-2">Riwayat Setoran Anda (Bulan Ini)</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Penyetor</th>
                                    <th>Jumlah (Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $bulan_ini = date('m');
                                $q_riwayat = $conn->query("SELECT * FROM laporan_toko WHERE user_id = '$user_id' AND MONTH(tanggal) = '$bulan_ini' ORDER BY id DESC LIMIT 10");
                                if($q_riwayat->num_rows > 0):
                                    while($r = $q_riwayat->fetch_assoc()):
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dark d-block"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></span>
                                        <span class="badge bg-secondary" style="font-size:10px;">Shift <?= $r['shift_ke'] ?></span>
                                    </td>
                                    <td class="fw-medium"><?= htmlspecialchars($r['nama_penyetor']) ?></td>
                                    <td class="fw-bold text-success">Rp <?= number_format($r['jumlah_setoran'], 0, ',', '.') ?></td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">Belum ada riwayat setoran.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.format-rupiah').forEach(function(input) {
            input.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value !== '') { this.value = new Intl.NumberFormat('id-ID').format(value); } else { this.value = ''; }
            });
        });

        <?php if (isset($_SESSION['flash_success'])): ?>
            Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, icon: 'success', title: '<?= $_SESSION['flash_success']; ?>' });
        <?php unset($_SESSION['flash_success']); endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            Swal.fire({ icon: 'error', title: 'Oops...', text: '<?= $_SESSION['flash_error']; ?>', confirmButtonColor: '#00529C' });
        <?php unset($_SESSION['flash_error']); endif; ?>
    </script>
</body>
</html>