<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// ========================================================
// AUTO-UPDATE DATABASE: Menambahkan kolom jenis cabang
// ========================================================
$check_jenis = $conn->query("SHOW COLUMNS FROM cabang LIKE 'jenis'");
if ($check_jenis && $check_jenis->num_rows == 0) {
    $conn->query("ALTER TABLE cabang ADD jenis VARCHAR(50) NOT NULL DEFAULT 'BRILink' AFTER nama_cabang");
}

// --- PROSES TAMBAH CABANG / TOKO ---
if (isset($_POST['tambah_cabang'])) {
    $nama_cabang = trim($_POST['nama_cabang']);
    $jenis = trim($_POST['jenis']);
    
    $cek = $conn->prepare("SELECT id FROM cabang WHERE nama_cabang = ?");
    $cek->bind_param("s", $nama_cabang);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        $_SESSION['flash_error'] = "Gagal! Lokasi dengan nama '$nama_cabang' sudah ada.";
    } else {
        $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang, jenis) VALUES (?, ?)");
        $stmt->bind_param("ss", $nama_cabang, $jenis);
        if ($stmt->execute()) {
            $_SESSION['flash_success'] = "Lokasi baru berhasil ditambahkan!";
        } else {
            $_SESSION['flash_error'] = "Gagal menambahkan Lokasi!";
        }
    }
    header("Location: kelola_cabang.php"); exit;
}

// --- PROSES EDIT CABANG / TOKO ---
if (isset($_POST['edit_cabang'])) {
    $id_cabang = $_POST['id_cabang'];
    $nama_cabang = trim($_POST['nama_cabang']);
    $jenis = trim($_POST['jenis']);
    
    $stmt = $conn->prepare("UPDATE cabang SET nama_cabang = ?, jenis = ? WHERE id = ?");
    $stmt->bind_param("ssi", $nama_cabang, $jenis, $id_cabang);
    if ($stmt->execute()) {
        $_SESSION['flash_success'] = "Informasi Lokasi berhasil diperbarui!";
    } else {
        $_SESSION['flash_error'] = "Gagal memperbarui Lokasi!";
    }
    header("Location: kelola_cabang.php"); exit;
}

// --- PROSES HAPUS CABANG / TOKO ---
if (isset($_GET['hapus'])) {
    $id_hapus = $_GET['hapus'];
    try {
        $stmt = $conn->prepare("DELETE FROM cabang WHERE id = ?");
        $stmt->bind_param("i", $id_hapus);
        if ($stmt->execute()) {
            $_SESSION['flash_success'] = "Lokasi berhasil dihapus!";
        } else {
            $_SESSION['flash_error'] = "GAGAL! Lokasi tidak bisa dihapus karena masih ada data kasir atau transaksi terhubung.";
        }
    } catch (mysqli_sql_exception $e) {
        $_SESSION['flash_error'] = "GAGAL! Lokasi tidak bisa dihapus karena masih ada data kasir atau transaksi yang terhubung dengan cabang ini.";
    }
    header("Location: kelola_cabang.php"); exit;
}

// Menghitung Metrik Cepat untuk Header
$q_tot_cabang = $conn->query("SELECT COUNT(id) as tot FROM cabang");
$tot_cabang = $q_tot_cabang->fetch_assoc()['tot'];

$q_tot_kasir = $conn->query("SELECT COUNT(id) as tot FROM users WHERE role = 'user'");
$tot_kasir = $q_tot_kasir->fetch_assoc()['tot'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kelola Cabang & Toko - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-light-blue: #e6f0ff; --bri-black: #1a1a1a; --bri-white: #ffffff; --bri-grey: #e9ecef; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        .metric-card-mini { border-radius: 20px; padding: 20px; border: 1px solid var(--bri-grey); display: flex; align-items: center; gap: 15px; background: white; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
        .metric-icon { width: 50px; height: 50px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); border-radius: 50px; padding: 12px 28px; font-weight: 700; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        .form-control, .form-select { border-radius: 14px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 12px 15px; color: var(--bri-black); font-weight: 600; }
        .form-control:focus, .form-select:focus { box-shadow: none; border-color: var(--bri-blue); }
        .table-wrapper { max-height: 500px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--bri-grey); border-radius: 10px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: var(--bri-white); z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); font-weight: 700; padding: 15px; }
        .table-wrapper tbody td { padding: 15px; border-bottom: 1px solid var(--bri-grey); vertical-align: middle; }
        .btn-action { border-radius: 50px; font-weight: 600; padding: 6px 16px; font-size: 13px; }
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .modal-header { border-bottom: 1px solid var(--bri-grey); padding: 20px 24px; }
        .modal-body { padding: 24px; }
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 16px !important; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } .btn-modern { width: 100%; text-align: center; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Manajemen Toko & Cabang</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Kelola daftar lokasi bisnis dan kategorinya</p>
            </div>
            
            <button class="btn btn-modern align-self-start" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-lg me-2"></i> Tambah Lokasi
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="metric-card-mini">
                    <div class="metric-icon" style="background-color: var(--bri-light-blue); color: var(--bri-blue);">
                        <i class="bi bi-shop"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Total Cabang / Toko</p>
                        <h4 class="fw-bolder mb-0 text-dark"><?= $tot_cabang; ?> Lokasi</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="metric-card-mini">
                    <div class="metric-icon bg-light text-dark border">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Total Kasir Aktif</p>
                        <h4 class="fw-bolder mb-0 text-dark"><?= $tot_kasir; ?> Pegawai</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <div class="table-responsive table-wrapper">
                <table class="table table-borderless align-middle mb-0" style="font-size: 14px;">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th width="30%">Nama Lokasi</th>
                            <th width="15%" class="text-center">Kategori</th>
                            <th width="15%" class="text-center">Total Kasir</th>
                            <th width="15%">Total Laba (All Time)</th>
                            <th width="20%" class="text-center">Aksi Manajemen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_cabang = $conn->query("
                            SELECT 
                                c.id, 
                                c.nama_cabang,
                                c.jenis,
                                (SELECT COUNT(id) FROM users WHERE cabang_id = c.id AND role = 'user') as total_kasir,
                                (SELECT SUM(t.admin_fee) FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = c.id) as total_pendapatan
                            FROM cabang c 
                            ORDER BY c.jenis ASC, c.nama_cabang ASC
                        ");
                        $no = 1;
                        if ($q_cabang->num_rows > 0):
                            while ($row = $q_cabang->fetch_assoc()):
                        ?>
                            <tr>
                                <td class="text-muted fw-bold"><?= $no++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="bg-light rounded-circle d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;">
                                            <?php if($row['jenis'] == 'BRILink'): ?>
                                                <i class="bi bi-bank2 text-primary fs-5"></i>
                                            <?php else: ?>
                                                <i class="bi bi-shop text-success fs-5"></i>
                                            <?php endif; ?>
                                        </div>
                                        <strong class="fs-6" style="color: var(--bri-blue);"><?= htmlspecialchars($row['nama_cabang']); ?></strong>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if($row['jenis'] == 'BRILink'): ?>
                                        <span class="badge bg-primary px-3 py-2 rounded-pill">BRILink</span>
                                    <?php else: ?>
                                        <span class="badge bg-success px-3 py-2 rounded-pill">Toko</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge rounded-pill" style="background-color: var(--bri-light-blue); color: var(--bri-blue); font-size: 13px;">
                                        <i class="bi bi-person-badge"></i> <?= $row['total_kasir']; ?>
                                    </span>
                                </td>
                                <td class="fw-bolder text-success">
                                    + Rp <?= number_format($row['total_pendapatan'] ?: 0, 0, ',', '.'); ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <button class="btn btn-outline-warning btn-action" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id']; ?>">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </button>
                                        
                                        <a href="kelola_cabang.php?hapus=<?= $row['id']; ?>" class="btn btn-outline-danger btn-action btn-hapus">
                                            <i class="bi bi-trash"></i> Hapus
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <div class="modal fade" id="modalEdit<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bolder" style="color: var(--bri-black);">Edit Informasi Lokasi</h5>
                                            <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form method="POST">
                                                <input type="hidden" name="id_cabang" value="<?= $row['id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label text-muted small fw-bold">Kategori Lokasi</label>
                                                    <select name="jenis" class="form-select form-select-lg" required>
                                                        <option value="BRILink" <?= $row['jenis'] == 'BRILink' ? 'selected' : '' ?>>🏢 Cabang BRILink</option>
                                                        <option value="Toko" <?= $row['jenis'] == 'Toko' ? 'selected' : '' ?>>🏪 Toko Umum / Sembako</option>
                                                    </select>
                                                </div>

                                                <div class="mb-4">
                                                    <label class="form-label text-muted small fw-bold">Nama Toko / Cabang (Terbaru)</label>
                                                    <input type="text" name="nama_cabang" class="form-control form-control-lg" value="<?= htmlspecialchars($row['nama_cabang']); ?>" required>
                                                </div>
                                                <button type="submit" name="edit_cabang" class="btn btn-warning w-100 fw-bold btn-lg rounded-pill shadow-sm text-dark">Simpan Perubahan</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php 
                            endwhile; 
                        else: 
                        ?>
                            <tr><td colspan="6" class="text-center text-muted py-5 fw-medium">Belum ada data cabang/toko terdaftar.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-shop me-2"></i>Tambah Lokasi Baru</h5>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Kategori Lokasi</label>
                            <select name="jenis" class="form-select form-select-lg" required>
                                <option value="BRILink" selected>🏢 Cabang BRILink</option>
                                <option value="Toko">🏪 Toko Umum / Sembako</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold">Masukkan Nama Lengkap</label>
                            <input type="text" name="nama_cabang" class="form-control form-control-lg" placeholder="Misal: BRILink Sudirman / Toko Sembako Jaya" required>
                        </div>
                        <button type="submit" name="tambah_cabang" class="btn btn-modern w-100 fs-5 py-3">Daftarkan Lokasi</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.btn-hapus').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault(); 
                const url = this.getAttribute('href');
                
                Swal.fire({
                    title: 'Hapus Lokasi Ini?',
                    text: "Pastikan tidak ada data Kasir / Transaksi yang masih terikat dengan lokasi ini.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Ya, Hapus!',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) { window.location.href = url; }
                })
            });
        });

        <?php if (isset($_SESSION['flash_success'])): ?>
            Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true, icon: 'success', title: '<?= $_SESSION['flash_success']; ?>' });
        <?php unset($_SESSION['flash_success']); endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
            Swal.fire({ icon: 'error', title: 'Ditolak / Gagal!', text: '<?= $_SESSION['flash_error']; ?>', confirmButtonColor: '#00529C' });
        <?php unset($_SESSION['flash_error']); endif; ?>
    </script>
</body>
</html>