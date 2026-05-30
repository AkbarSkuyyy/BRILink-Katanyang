<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }
$tanggal_hari_ini = date('Y-m-d');

// --- PROSES TAMBAH KASIR ---
if (isset($_POST['tambah_kasir'])) {
    $cabang_id = $_POST['cabang_id'];
    $shift_ke  = $_POST['shift_ke'];
    $pin       = $_POST['pin'];
    
    $cek = $conn->prepare("SELECT id FROM users WHERE cabang_id = ? AND shift_ke = ? AND role = 'user'");
    $cek->bind_param("ii", $cabang_id, $shift_ke);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        echo "<script>alert('GAGAL! Akun Kasir untuk Cabang dan Shift tersebut sudah terdaftar.'); window.location='kelola_user.php';</script>";
    } else {
        $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (cabang_id, shift_ke, pin, role) VALUES (?, ?, ?, 'user')");
        $stmt->bind_param("iis", $cabang_id, $shift_ke, $hashed_pin);
        
        if ($stmt->execute()) {
            echo "<script>alert('Akun Kasir berhasil ditambahkan!'); window.location='kelola_user.php';</script>";
        } else {
            echo "<script>alert('Terjadi kesalahan sistem.');</script>";
        }
    }
}

// --- PROSES RESET PIN KASIR ---
if (isset($_POST['reset_pin'])) {
    $id_user  = $_POST['id_user'];
    $pin_baru = $_POST['pin_baru'];
    $hashed_pin = password_hash($pin_baru, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("UPDATE users SET pin = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed_pin, $id_user);
    if ($stmt->execute()) {
        echo "<script>alert('PIN Kasir berhasil direset!'); window.location='kelola_user.php';</script>";
    } else {
        echo "<script>alert('Gagal mereset PIN.');</script>";
    }
}

// --- PROSES HAPUS KASIR ---
if (isset($_GET['hapus'])) {
    $id_hapus = $_GET['hapus'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
    $stmt->bind_param("i", $id_hapus);
    
    if ($stmt->execute()) {
        echo "<script>alert('Akun Kasir berhasil dihapus!'); window.location='kelola_user.php';</script>";
    } else {
        echo "<script>alert('GAGAL! Akun ini tidak bisa dihapus karena masih terikat dengan data Riwayat Transaksi.'); window.location='kelola_user.php';</script>";
    }
}

// --- FITUR KELOLA MODAL ---
if (isset($_POST['edit_modal'])) {
    $shift_id = $_POST['shift_id'];
    $modal_baru = $_POST['modal_baru'];
    $stmt = $conn->prepare("UPDATE shifts SET modal_awal = ? WHERE id = ?");
    $stmt->bind_param("di", $modal_baru, $shift_id);
    if ($stmt->execute()) {
        echo "<script>alert('Koreksi Modal Awal berhasil disimpan!'); window.location='kelola_user.php';</script>";
    }
}

if (isset($_POST['batalkan_shift'])) {
    $shift_id = $_POST['shift_id'];
    $cek_trx = $conn->query("SELECT id FROM transactions WHERE shift_id = '$shift_id'");
    if ($cek_trx->num_rows > 0) {
        echo "<script>alert('DITOLAK! Shift ini sudah memiliki riwayat transaksi, tidak bisa dihapus. Ubah nominal Modalnya saja.'); window.location='kelola_user.php';</script>";
    } else {
        $conn->query("DELETE FROM shifts WHERE id = '$shift_id'");
        echo "<script>alert('Shift berhasil dibatalkan. Kasir kini dapat menset modal ulang.'); window.location='kelola_user.php';</script>";
    }
}

if (isset($_POST['buka_kunci_shift'])) {
    $shift_id = $_POST['shift_id'];
    $conn->query("UPDATE shifts SET status = 'aktif' WHERE id = '$shift_id'");
    echo "<script>alert('Shift yang terkunci berhasil DIBUKA KEMBALI!'); window.location='kelola_user.php';</script>";
}

// --- MENGHITUNG METRIK CEPAT ---
$q_tot = $conn->query("SELECT COUNT(id) as tot FROM users WHERE role='user'");
$tot_kasir = $q_tot->fetch_assoc()['tot'];

$q_aktif = $conn->query("SELECT COUNT(id) as tot FROM shifts WHERE tanggal='$tanggal_hari_ini' AND status='aktif'");
$shift_aktif = $q_aktif->fetch_assoc()['tot'];

$q_selesai = $conn->query("SELECT COUNT(id) as tot FROM shifts WHERE tanggal='$tanggal_hari_ini' AND status='selesai'");
$shift_selesai = $q_selesai->fetch_assoc()['tot'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kelola Kasir & Shift - Admin</title>
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
            --bri-green: #198754;
            --bri-orange: #ffc107;
        }
        
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        
        .metric-card-mini {
            border-radius: 20px; padding: 20px; border: 1px solid var(--bri-grey);
            display: flex; align-items: center; gap: 15px; background: white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.02); height: 100%;
        }
        .metric-icon { width: 55px; height: 55px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }

        /* Buttons & Forms */
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); border-radius: 50px; padding: 12px 28px; font-weight: 700; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        .form-control, .form-select { border-radius: 14px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 12px 15px; color: var(--bri-black); font-weight: 600; }
        .form-control:focus { box-shadow: none; border-color: var(--bri-blue); }

        /* Table Styling */
        .table-wrapper { max-height: 550px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-track { background: transparent; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--bri-grey); border-radius: 10px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: var(--bri-white); z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); font-weight: 700; padding: 15px; }
        .table-wrapper tbody td { padding: 15px; border-bottom: 1px solid var(--bri-grey); vertical-align: middle; }
        
        .btn-action { border-radius: 50px; font-weight: 600; padding: 8px 16px; font-size: 12px; letter-spacing: 0.3px; }

        /* Modal Styling */
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .modal-header { border-bottom: 1px solid var(--bri-grey); padding: 20px 24px; }
        .modal-body { padding: 24px; }
        
        .status-box { padding: 10px 15px; border-radius: 12px; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }

        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; }
            .btn-modern { width: 100%; text-align: center; }
            .btn-action { padding: 6px 12px; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Manajemen Akun Kasir</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Kelola akses, performa, PIN, & koreksi Modal laci</p>
            </div>
            
            <button class="btn btn-modern align-self-start" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-person-plus-fill me-2"></i> Daftarkan Kasir
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="metric-card-mini">
                    <div class="metric-icon" style="background-color: var(--bri-light-blue); color: var(--bri-blue);">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Total Kasir Aktif</p>
                        <h4 class="fw-bolder mb-0 text-dark"><?= $tot_kasir; ?> Pegawai</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card-mini">
                    <div class="metric-icon" style="background-color: #d1e7dd; color: var(--bri-green);">
                        <i class="bi bi-play-circle-fill"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Shift Bekerja</p>
                        <h4 class="fw-bolder mb-0 text-dark"><?= $shift_aktif; ?> Shift Aktif</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card-mini">
                    <div class="metric-icon bg-light text-secondary border">
                        <i class="bi bi-lock-fill"></i>
                    </div>
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Shift Selesai Hari Ini</p>
                        <h4 class="fw-bolder mb-0 text-dark"><?= $shift_selesai; ?> Shift Tutup</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <h5 class="fw-bold text-dark mb-4 border-bottom pb-3" style="color: var(--bri-black) !important;">Daftar Akun Kasir & Status Harian</h5>
            <div class="table-responsive table-wrapper">
                <table class="table table-borderless align-middle mb-0 text-nowrap" style="font-size: 14px;">
                    <thead>
                        <tr>
                            <th>Cabang & Shift</th>
                            <th>Status Hari Ini</th>
                            <th class="text-center">Performa Hari Ini</th>
                            <th class="text-center">Manajemen Akses & Laci</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Subquery pintar untuk mengambil total transaksi harian setiap kasir
                        $q_users = $conn->query("
                            SELECT u.id, u.shift_ke, c.nama_cabang, 
                                   (SELECT id FROM shifts WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1) as shift_id,
                                   (SELECT status FROM shifts WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1) as status_shift,
                                   (SELECT modal_awal FROM shifts WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1) as modal_awal,
                                   (SELECT COUNT(id) FROM transactions WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini') as total_trx_hari_ini
                            FROM users u 
                            JOIN cabang c ON u.cabang_id = c.id 
                            WHERE u.role = 'user' 
                            ORDER BY c.nama_cabang ASC, u.shift_ke ASC
                        ");
                        
                        if ($q_users->num_rows > 0):
                            while ($row = $q_users->fetch_assoc()):
                                // Desain Badge Status yang Modern
                                if (empty($row['shift_id'])) {
                                    $status_badge = '<div class="status-box bg-light text-secondary border"><i class="bi bi-dash-circle"></i> Belum Set Modal</div>';
                                } elseif ($row['status_shift'] == 'aktif') {
                                    $status_badge = '<div class="status-box" style="background-color: #d1e7dd; color: var(--bri-green);"><i class="bi bi-activity"></i> Sedang Aktif</div>
                                                     <div class="mt-1 small fw-bold text-muted">Modal: Rp '.number_format($row['modal_awal'],0,',','.').'</div>';
                                } else {
                                    $status_badge = '<div class="status-box bg-dark text-white"><i class="bi bi-lock-fill"></i> Selesai / Terkunci</div>';
                                }
                                
                                $trx_count = !empty($row['total_trx_hari_ini']) ? $row['total_trx_hari_ini'] : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-circle d-flex justify-content-center align-items-center fw-bold" style="width: 45px; height: 45px; background-color: var(--bri-light-blue); color: var(--bri-blue);">
                                            S<?= $row['shift_ke']; ?>
                                        </div>
                                        <div>
                                            <strong class="fs-6 d-block" style="color: var(--bri-black);"><?= $row['nama_cabang']; ?></strong>
                                            <small class="text-muted fw-semibold">Shift Kerja <?= $row['shift_ke']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?= $status_badge; ?></td>
                                <td class="text-center">
                                    <span class="badge rounded-pill <?= ($trx_count > 0) ? 'bg-primary' : 'bg-secondary'; ?> px-3 py-2 fw-bold" style="font-size: 13px;">
                                        <?= $trx_count; ?> Trx
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                                        <button class="btn btn-outline-primary btn-action" data-bs-toggle="modal" data-bs-target="#modalShift<?= $row['id']; ?>">
                                            <i class="bi bi-wallet2"></i> Laci
                                        </button>
                                        <button class="btn btn-outline-warning btn-action text-dark" data-bs-toggle="modal" data-bs-target="#modalReset<?= $row['id']; ?>">
                                            <i class="bi bi-key"></i> PIN
                                        </button>
                                        <a href="kelola_user.php?hapus=<?= $row['id']; ?>" class="btn btn-outline-danger btn-action" onclick="return confirm('Yakin ingin hapus akun kasir ini?');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <div class="modal fade" id="modalShift<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bolder" style="color: var(--bri-black);">Status Shift & Laci</h5>
                                            <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body text-wrap">
                                            <div class="p-3 mb-4 rounded-3 text-center" style="background-color: var(--bri-light-blue); color: var(--bri-blue);">
                                                <h6 class="fw-bold mb-0"><?= $row['nama_cabang']; ?> (Shift <?= $row['shift_ke']; ?>)</h6>
                                            </div>

                                            <?php if(empty($row['shift_id'])): ?>
                                                <div class="text-center py-4">
                                                    <i class="bi bi-exclamation-circle text-muted mb-3 d-block" style="font-size: 40px;"></i>
                                                    <h6 class="fw-bold">Shift Belum Dimulai</h6>
                                                    <p class="text-muted mb-0 small">Kasir belum menyetel Modal Awal hari ini. Tidak ada tindakan yang bisa dilakukan admin.</p>
                                                </div>

                                            <?php elseif($row['status_shift'] == 'aktif'): ?>
                                                <form method="POST" class="mb-4">
                                                    <input type="hidden" name="shift_id" value="<?= $row['shift_id']; ?>">
                                                    <label class="form-label text-muted small fw-bold text-uppercase">Koreksi Angka Modal (Rp)</label>
                                                    <div class="input-group mb-3">
                                                        <span class="input-group-text bg-light fw-bold text-secondary border-end-0 px-3 border-secondary-subtle">Rp</span>
                                                        <input type="number" name="modal_baru" class="form-control form-control-lg border-start-0 fw-bold border-secondary-subtle" value="<?= $row['modal_awal']; ?>" required style="color: var(--bri-blue);">
                                                    </div>
                                                    <button type="submit" name="edit_modal" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm mb-3 py-2" style="background-color: var(--bri-blue);">Simpan Koreksi</button>
                                                </form>
                                                
                                                <div class="border-top pt-3 text-center">
                                                    <p class="small text-muted fw-semibold mb-2">Ingin mereset shift jika ada kesalahan sistem?</p>
                                                    <form method="POST" onsubmit="return confirm('Anda yakin membatalkan shift ini?');">
                                                        <input type="hidden" name="shift_id" value="<?= $row['shift_id']; ?>">
                                                        <button type="submit" name="batalkan_shift" class="btn btn-outline-danger btn-sm fw-bold rounded-pill px-4">Batalkan Shift Ini</button>
                                                    </form>
                                                </div>

                                            <?php elseif($row['status_shift'] == 'selesai'): ?>
                                                <div class="text-center py-2">
                                                    <i class="bi bi-lock-fill text-dark" style="font-size: 50px;"></i>
                                                    <h5 class="fw-bold text-dark mt-3">Shift Telah Terkunci</h5>
                                                    <p class="text-muted small mb-4">Kasir sudah menutup laporan laci. Jika kasir ingin menginput transaksi yang terlewat, Anda harus membuka kuncinya.</p>
                                                    <form method="POST">
                                                        <input type="hidden" name="shift_id" value="<?= $row['shift_id']; ?>">
                                                        <button type="submit" name="buka_kunci_shift" class="btn btn-success w-100 fw-bold btn-lg rounded-pill shadow-sm">🔓 Buka Kunci Shift</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal fade" id="modalReset<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bolder" style="color: var(--bri-black);">Ubah Akses PIN Kasir</h5>
                                            <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="p-3 mb-4 rounded-3 text-center" style="background-color: #fff3cd; color: #856404;">
                                                <h6 class="fw-bold mb-0"><?= $row['nama_cabang']; ?> (Shift <?= $row['shift_ke']; ?>)</h6>
                                            </div>
                                            <form method="POST">
                                                <input type="hidden" name="id_user" value="<?= $row['id']; ?>">
                                                <div class="mb-4">
                                                    <label class="form-label text-muted small fw-bold text-uppercase">Masukkan PIN Baru (6 Angka)</label>
                                                    <input type="password" name="pin_baru" class="form-control form-control-lg text-center fw-bold text-primary" placeholder="••••••" maxlength="6" style="letter-spacing: 10px; font-size: 24px;" required>
                                                </div>
                                                <button type="submit" name="reset_pin" class="btn btn-warning w-100 fw-bold py-3 fs-6 rounded-pill shadow-sm text-dark">Ubah PIN Sekarang</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php 
                            endwhile; 
                        else: 
                        ?>
                            <tr><td colspan="4" class="text-center text-muted py-5 fw-medium border-0">Belum ada akun kasir terdaftar di sistem.</td></tr>
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
                    <h5 class="modal-title fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-person-plus-fill me-2"></i>Daftarkan Kasir Baru</h5>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Penempatan Cabang</label>
                            <select name="cabang_id" class="form-select form-select-lg" required>
                                <option value="" disabled selected>-- Pilih Cabang --</option>
                                <?php
                                $q_all_cabang = $conn->query("SELECT * FROM cabang");
                                while($c = $q_all_cabang->fetch_assoc()) { echo "<option value='{$c['id']}'>{$c['nama_cabang']}</option>"; }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Pilih Shift Kerja</label>
                            <select name="shift_ke" class="form-select form-select-lg" required>
                                <option value="" disabled selected>-- Pilih Shift --</option>
                                <option value="1">Shift 1 (Pagi/Siang)</option>
                                <option value="2">Shift 2 (Sore/Malam)</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Buat PIN Akses Awal (6 Angka)</label>
                            <input type="password" name="pin" class="form-control form-control-lg text-center fw-bold text-primary" placeholder="••••••" maxlength="6" style="letter-spacing: 10px; font-size: 24px;" required>
                            <small class="text-muted mt-2 d-block text-center" style="font-size: 11px;">PIN ini akan digunakan kasir untuk login harian.</small>
                        </div>
                        <button type="submit" name="tambah_kasir" class="btn btn-modern w-100 fs-5 py-3 mt-2">Daftarkan Akun</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>