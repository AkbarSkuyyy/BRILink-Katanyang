<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// --- PROSES TAMBAH CABANG ---
if (isset($_POST['tambah_cabang'])) {
    $nama_cabang = $_POST['nama_cabang'];
    $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang) VALUES (?)");
    $stmt->bind_param("s", $nama_cabang);
    if ($stmt->execute()) {
        echo "<script>alert('Cabang baru berhasil ditambahkan!'); window.location='kelola_cabang.php';</script>";
    } else {
        echo "<script>alert('Gagal menambahkan cabang!');</script>";
    }
}

// --- PROSES EDIT CABANG ---
if (isset($_POST['edit_cabang'])) {
    $id_cabang = $_POST['id_cabang'];
    $nama_cabang = $_POST['nama_cabang'];
    $stmt = $conn->prepare("UPDATE cabang SET nama_cabang = ? WHERE id = ?");
    $stmt->bind_param("si", $nama_cabang, $id_cabang);
    if ($stmt->execute()) {
        echo "<script>alert('Nama cabang berhasil diperbarui!'); window.location='kelola_cabang.php';</script>";
    } else {
        echo "<script>alert('Gagal memperbarui cabang!');</script>";
    }
}

// --- PROSES HAPUS CABANG ---
if (isset($_GET['hapus'])) {
    $id_hapus = $_GET['hapus'];
    // Jika cabang masih punya kasir/transaksi, database akan menolak penghapusan berkat foreign key restrict
    $stmt = $conn->prepare("DELETE FROM cabang WHERE id = ?");
    $stmt->bind_param("i", $id_hapus);
    
    if ($stmt->execute()) {
        echo "<script>alert('Cabang berhasil dihapus!'); window.location='kelola_cabang.php';</script>";
    } else {
        echo "<script>alert('GAGAL! Cabang tidak bisa dihapus karena masih ada data kasir atau transaksi yang terhubung dengan cabang ini.'); window.location='kelola_cabang.php';</script>";
    }
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
    <title>Kelola Cabang - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style> 
        /* PALET WARNA MODERN */
        :root {
            --bg-body: #f4f7fa; 
            --bri-blue: #00529C;
            --bri-light-blue: #e6f0ff;
            --bri-black: #1a1a1a;
            --bri-white: #ffffff;
            --bri-grey: #e9ecef;
        }
        
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        
        /* Modern Cards */
        .modern-card {
            background: var(--bri-white);
            border-radius: 24px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            padding: 24px;
        }

        .metric-card-mini {
            border-radius: 20px; padding: 20px; border: 1px solid var(--bri-grey);
            display: flex; align-items: center; gap: 15px; background: white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.02);
        }
        .metric-icon { width: 50px; height: 50px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 24px; }

        /* Buttons & Forms */
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); border-radius: 50px; padding: 12px 28px; font-weight: 700; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        .form-control, .form-select { border-radius: 14px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 12px 15px; color: var(--bri-black); font-weight: 600; }
        .form-control:focus { box-shadow: none; border-color: var(--bri-blue); }

        /* Table Styling */
        .table-wrapper { max-height: 500px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-track { background: transparent; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--bri-grey); border-radius: 10px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: var(--bri-white); z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); font-weight: 700; padding: 15px; }
        .table-wrapper tbody td { padding: 15px; border-bottom: 1px solid var(--bri-grey); vertical-align: middle; }
        
        .btn-action { border-radius: 50px; font-weight: 600; padding: 6px 16px; font-size: 13px; }

        /* Modal Styling */
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .modal-header { border-bottom: 1px solid var(--bri-grey); padding: 20px 24px; }
        .modal-body { padding: 24px; }

        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; }
            .btn-modern { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Manajemen Cabang</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Kelola daftar cabang dan pantau performanya</p>
            </div>
            
            <button class="btn btn-modern align-self-start" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-lg me-2"></i> Tambah Cabang
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="metric-card-mini">
                    <div class="metric-icon" style="background-color: var(--bri-light-blue); color: var(--bri-blue);">
                        <i class="bi bi-shop"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Total Cabang</p>
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
                            <th width="35%">Nama Cabang</th>
                            <th width="15%" class="text-center">Total Kasir</th>
                            <th width="20%">Total Laba (All Time)</th>
                            <th width="25%" class="text-center">Aksi Manajemen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Menggunakan Subquery untuk mendapatkan total kasir dan pendapatan per cabang
                        $q_cabang = $conn->query("
                            SELECT 
                                c.id, 
                                c.nama_cabang,
                                (SELECT COUNT(id) FROM users WHERE cabang_id = c.id AND role = 'user') as total_kasir,
                                (SELECT SUM(t.admin_fee) FROM transactions t JOIN users u ON t.user_id = u.id WHERE u.cabang_id = c.id) as total_pendapatan
                            FROM cabang c 
                            ORDER BY c.id DESC
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
                                            <i class="bi bi-geo-alt-fill text-primary fs-5"></i>
                                        </div>
                                        <strong class="fs-6" style="color: var(--bri-blue);"><?= $row['nama_cabang']; ?></strong>
                                    </div>
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
                                        
                                        <a href="kelola_cabang.php?hapus=<?= $row['id']; ?>" class="btn btn-outline-danger btn-action" onclick="return confirm('Apakah Anda yakin ingin menghapus Cabang <?= $row['nama_cabang']; ?>? \n\nPastikan tidak ada data Kasir yang masih terikat di cabang ini.');">
                                            <i class="bi bi-trash"></i> Hapus
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <div class="modal fade" id="modalEdit<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bolder" style="color: var(--bri-black);">Edit Informasi Cabang</h5>
                                            <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form method="POST">
                                                <input type="hidden" name="id_cabang" value="<?= $row['id']; ?>">
                                                <div class="mb-4">
                                                    <label class="form-label">Nama Cabang (Terbaru)</label>
                                                    <input type="text" name="nama_cabang" class="form-control form-control-lg" value="<?= $row['nama_cabang']; ?>" required>
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
                            <tr><td colspan="5" class="text-center text-muted py-5 fw-medium">Belum ada data cabang terdaftar.</td></tr>
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
                    <h5 class="modal-title fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-shop me-2"></i>Tambah Lokasi Cabang</h5>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <div class="mb-4">
                            <label class="form-label">Masukkan Nama Cabang Lengkap</label>
                            <input type="text" name="nama_cabang" class="form-control form-control-lg" placeholder="Misal: BRILink Sudirman" required>
                        </div>
                        <button type="submit" name="tambah_cabang" class="btn btn-modern w-100 fs-5 py-3">Daftarkan Cabang</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>