<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// --- PROSES TAMBAH REKENING SECARA DINAMIS ---
if (isset($_POST['tambah_rekening'])) {
    $nama_bank = trim($_POST['nama_bank']);
    
    // Generate otomatis nama kolom untuk database (contoh: "BCA Utama" -> "modal_bcautama")
    $kolom_db = 'modal_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama_bank));

    // Cek apakah rekening sudah ada
    $cek = $conn->query("SELECT id FROM rekening WHERE nama_bank = '$nama_bank' OR kolom_db = '$kolom_db'");
    if ($cek->num_rows > 0) {
        echo "<script>alert('Gagal! Nama rekening tersebut sudah ada di sistem.'); window.location='kelola_rekening.php';</script>";
    } else {
        // 1. Tambahkan kolom secara OTOMATIS ke tabel shifts
        try {
            $conn->query("ALTER TABLE shifts ADD $kolom_db DOUBLE DEFAULT 0");
        } catch (Exception $e) {
            // Abaikan error jika kolom kebetulan sudah pernah dibuat manual
        }

        // 2. Simpan ke tabel master rekening
        $stmt = $conn->prepare("INSERT INTO rekening (nama_bank, kolom_db) VALUES (?, ?)");
        $stmt->bind_param("ss", $nama_bank, $kolom_db);
        if ($stmt->execute()) {
            echo "<script>alert('Luar Biasa! Rekening $nama_bank berhasil ditambahkan ke seluruh sistem secara otomatis.'); window.location='kelola_rekening.php';</script>";
        }
    }
}

// --- PROSES HAPUS REKENING ---
if (isset($_GET['hapus'])) {
    $id_hapus = $_GET['hapus'];
    // Kita hanya menghapus dari daftar tampilan, tidak men-drop kolom shift agar history keuangan lama tidak hilang/rusak
    $conn->query("DELETE FROM rekening WHERE id = '$id_hapus'");
    echo "<script>alert('Rekening berhasil disembunyikan dari sistem!'); window.location='kelola_rekening.php';</script>";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kelola Rekening Bank</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; --bri-white: #ffffff; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: white; border-radius: 50px; padding: 12px 28px; font-weight: 700; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-extrabold text-dark mb-0"><i class="bi bi-bank me-2 text-primary"></i>Daftar Rekening Sistem</h3>
            <button class="btn btn-modern shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambah"><i class="bi bi-plus-lg me-2"></i>Tambah Rekening</button>
        </div>
        
        <div class="modern-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>No</th><th>Nama Rekening / Bank</th><th>ID Kolom Database</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                        <?php
                        $q = $conn->query("SELECT * FROM rekening ORDER BY id ASC");
                        $no = 1;
                        while ($r = $q->fetch_assoc()): ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td class="fw-bold text-primary fs-6"><?= $r['nama_bank']; ?></td>
                            <td><span class="badge bg-secondary px-3 py-2 rounded-pill"><?= $r['kolom_db']; ?></span></td>
                            <td class="text-end">
                                <a href="?hapus=<?= $r['id']; ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold" onclick="return confirm('Sembunyikan rekening ini dari daftar sistem?');"><i class="bi bi-trash"></i> Hapus</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalTambah" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <h5 class="fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-bank me-2"></i>Tambah Rekening Baru</h5>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pb-4">
                    <form method="POST">
                        <div class="mb-4 mt-2">
                            <label class="form-label text-muted small fw-bold text-uppercase">Nama Rekening Bank</label>
                            <input type="text" name="nama_bank" class="form-control form-control-lg bg-light fw-bold text-primary" placeholder="Contoh: BRI 5, BCA Utama" required style="border-radius: 12px; border: none;">
                            <small class="text-muted mt-2 d-block" style="font-size: 11px;">Rekening yang ditambahkan akan otomatis dibuatkan kolomnya di database dan langsung muncul di halaman Hak Akses Kasir.</small>
                        </div>
                        <button type="submit" name="tambah_rekening" class="btn btn-modern w-100 fs-5 py-3">Tambahkan ke Sistem</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>