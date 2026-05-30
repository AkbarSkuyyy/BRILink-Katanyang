<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// --- PROSES TAMBAH LAYANAN ---
if (isset($_POST['tambah_layanan'])) {
    $nama_layanan = trim($_POST['nama_layanan']);
    $stmt = $conn->prepare("INSERT INTO layanan (nama_layanan) VALUES (?)");
    $stmt->bind_param("s", $nama_layanan);
    if ($stmt->execute()) {
        echo "<script>alert('Layanan baru berhasil ditambahkan ke sistem!'); window.location='kelola_layanan.php';</script>";
    } else {
        echo "<script>alert('Gagal! Nama layanan mungkin sudah ada.');</script>";
    }
}

// --- PROSES EDIT LAYANAN ---
if (isset($_POST['edit_layanan'])) {
    $id_layanan = $_POST['id_layanan'];
    $nama_layanan = trim($_POST['nama_layanan']);
    $stmt = $conn->prepare("UPDATE layanan SET nama_layanan = ? WHERE id = ?");
    $stmt->bind_param("si", $nama_layanan, $id_layanan);
    if ($stmt->execute()) {
        echo "<script>alert('Nama layanan berhasil diperbarui!'); window.location='kelola_layanan.php';</script>";
    }
}

// --- PROSES HAPUS LAYANAN ---
if (isset($_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    $conn->query("DELETE FROM layanan WHERE id = '$id_hapus'");
    echo "<script>alert('Layanan berhasil dihapus dari daftar pilihan Kasir.'); window.location='kelola_layanan.php';</script>";
}

// Menghitung Metrik Layanan
$q_tot = $conn->query("SELECT COUNT(id) as tot FROM layanan");
$tot_layanan = $q_tot->fetch_assoc()['tot'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kelola Layanan - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style> 
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
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        .metric-card-mini { border-radius: 20px; padding: 20px; border: 1px solid var(--bri-grey); display: flex; align-items: center; gap: 15px; background: white; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
        .metric-icon { width: 50px; height: 50px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); border-radius: 50px; padding: 12px 28px; font-weight: 700; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        .form-control { border-radius: 14px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 12px 15px; color: var(--bri-black); font-weight: 600; }
        .form-control:focus { box-shadow: none; border-color: var(--bri-blue); }
        .table-wrapper { max-height: 500px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: var(--bri-white); z-index: 1; border-bottom: 2px solid var(--bri-grey); }
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Master Layanan</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Kelola daftar layanan yang bisa dipilih kasir</p>
            </div>
            <button class="btn btn-modern align-self-start" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-lg me-2"></i> Tambah Layanan
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <div class="metric-card-mini">
                    <div class="metric-icon" style="background-color: var(--bri-light-blue); color: var(--bri-blue);"><i class="bi bi-grid-fill"></i></div>
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Total Layanan Aktif</p>
                        <h4 class="fw-bolder mb-0 text-dark"><?= $tot_layanan; ?> Pilihan</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <div class="table-responsive table-wrapper">
                <table class="table table-borderless align-middle mb-0">
                    <thead>
                        <tr>
                            <th width="5%">No</th>
                            <th width="70%">Nama Layanan / Transaksi</th>
                            <th width="25%" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_layanan = $conn->query("SELECT * FROM layanan ORDER BY id ASC");
                        $no = 1;
                        if ($q_layanan->num_rows > 0):
                            while ($row = $q_layanan->fetch_assoc()):
                        ?>
                            <tr style="border-bottom: 1px solid var(--bri-grey);">
                                <td class="text-muted fw-bold"><?= $no++; ?></td>
                                <td><strong class="fs-6" style="color: var(--bri-blue);"><?= $row['nama_layanan']; ?></strong></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <button class="btn btn-sm btn-outline-warning fw-bold rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id']; ?>"><i class="bi bi-pencil-square"></i> Edit</button>
                                        <a href="kelola_layanan.php?hapus=<?= $row['id']; ?>" class="btn btn-sm btn-outline-danger fw-bold rounded-pill px-3" onclick="return confirm('Hapus layanan <?= $row['nama_layanan']; ?> dari sistem?');"><i class="bi bi-trash"></i> Hapus</a>
                                    </div>
                                </td>
                            </tr>

                            <div class="modal fade" id="modalEdit<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header border-0 pb-0 pt-4 px-4">
                                            <h5 class="fw-bolder" style="color: var(--bri-black);">Edit Layanan</h5>
                                            <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body px-4 pb-4">
                                            <form method="POST">
                                                <input type="hidden" name="id_layanan" value="<?= $row['id']; ?>">
                                                <div class="mb-4">
                                                    <label class="form-label text-muted small fw-bold text-uppercase">Nama Layanan</label>
                                                    <input type="text" name="nama_layanan" class="form-control form-control-lg" value="<?= $row['nama_layanan']; ?>" required>
                                                </div>
                                                <button type="submit" name="edit_layanan" class="btn btn-warning w-100 fw-bold btn-lg rounded-pill text-dark">Simpan Perubahan</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; else: ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">Belum ada layanan terdaftar.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <h5 class="fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-grid-fill me-2"></i>Tambah Layanan</h5>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pb-4">
                    <form method="POST">
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Nama Transaksi/Layanan Baru</label>
                            <input type="text" name="nama_layanan" class="form-control form-control-lg" placeholder="Misal: Pembayaran BPJS" required>
                            <small class="text-muted mt-2 d-block" style="font-size: 11px;">Layanan ini akan otomatis muncul di form Input Transaksi Kasir.</small>
                        </div>
                        <button type="submit" name="tambah_layanan" class="btn btn-modern w-100 fs-5 py-3">Tambahkan ke Sistem</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>