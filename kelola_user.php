<?php
require 'config.php';
// Pastikan session sudah berjalan (biasanya di config.php sudah ada session_start())
if (session_status() == PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }
$tanggal_hari_ini = date('Y-m-d');

// --- AMBIL DAFTAR REKENING BERDASARKAN PEMILIK DARI DATABASE ---
$q_rek = $conn->query("SELECT * FROM rekening ORDER BY pemilik ASC, bank ASC");
$rekening_by_pemilik = [];
if ($q_rek) {
    while($r = $q_rek->fetch_assoc()) { 
        $rekening_by_pemilik[$r['pemilik']][] = $r; 
    }
}

// ====================================================
// BLOK PROSES PHP (Menyimpan Notif ke Session & Redirect)
// ====================================================

// --- PROSES TAMBAH KASIR ---
if (isset($_POST['tambah_kasir'])) {
    $cabang_id = $_POST['cabang_id'];
    $shift_ke  = $_POST['shift_ke'];
    $pin       = $_POST['pin'];
    $assigned_banks = isset($_POST['banks']) ? implode(',', $_POST['banks']) : '';
    
    $cek = $conn->prepare("SELECT id FROM users WHERE cabang_id = ? AND shift_ke = ? AND role = 'user'");
    $cek->bind_param("ii", $cabang_id, $shift_ke);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        $_SESSION['notif'] = ['type' => 'error', 'msg' => 'GAGAL! Slot Shift tersebut sudah terisi di cabang ini.'];
    } else {
        $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (cabang_id, shift_ke, pin, assigned_banks, role) VALUES (?, ?, ?, ?, 'user')");
        $stmt->bind_param("iiss", $cabang_id, $shift_ke, $hashed_pin, $assigned_banks);
        
        if ($stmt->execute()) { 
            $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Akun Kasir berhasil didaftarkan!']; 
        }
    }
    header("Location: kelola_user.php"); exit;
}

// --- PROSES EDIT HAK AKSES REKENING ---
if (isset($_POST['edit_akses_rekening'])) {
    $id_user = $_POST['id_user'];
    $assigned_banks = isset($_POST['banks']) ? implode(',', $_POST['banks']) : '';
    $stmt = $conn->prepare("UPDATE users SET assigned_banks = ? WHERE id = ?");
    $stmt->bind_param("si", $assigned_banks, $id_user);
    if ($stmt->execute()) { 
        $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Akses Rekening berhasil diperbarui!']; 
    }
    header("Location: kelola_user.php"); exit;
}

// --- PROSES RESET PIN KASIR ---
if (isset($_POST['reset_pin'])) {
    $id_user  = $_POST['id_user'];
    $pin_baru = $_POST['pin_baru'];
    $hashed_pin = password_hash($pin_baru, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET pin = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed_pin, $id_user);
    if ($stmt->execute()) { 
        $_SESSION['notif'] = ['type' => 'success', 'msg' => 'PIN Kasir berhasil diubah!']; 
    }
    header("Location: kelola_user.php"); exit;
}

// --- PROSES HAPUS KASIR ---
if (isset($_GET['hapus'])) {
    $id_hapus = $_GET['hapus'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
    $stmt->bind_param("i", $id_hapus);
    if ($stmt->execute()) { 
        $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Akun Kasir berhasil dihapus permanen!']; 
    }
    header("Location: kelola_user.php"); exit;
}

// --- FITUR KELOLA MODAL & SHIFT ---
if (isset($_POST['edit_modal'])) {
    $shift_id = $_POST['shift_id'];
    $modal_baru = (float)str_replace('.', '', $_POST['modal_baru']);
    $conn->query("UPDATE shifts SET modal_awal = '$modal_baru' WHERE id = '$shift_id'");
    $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Koreksi Modal Awal berhasil disimpan!'];
    header("Location: kelola_user.php"); exit;
}

if (isset($_POST['batalkan_shift'])) {
    $shift_id = $_POST['shift_id'];
    $cek_trx = $conn->query("SELECT id FROM transactions WHERE shift_id = '$shift_id'");
    if ($cek_trx->num_rows > 0) {
        $_SESSION['notif'] = ['type' => 'error', 'msg' => 'DITOLAK! Shift ini sudah ada transaksi, tidak bisa dihapus.'];
    } else {
        $conn->query("DELETE FROM shifts WHERE id = '$shift_id'");
        $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Shift berhasil dibatalkan. Kasir dapat set modal ulang.'];
    }
    header("Location: kelola_user.php"); exit;
}

if (isset($_POST['buka_kunci_shift'])) {
    $shift_id = $_POST['shift_id'];
    $conn->query("UPDATE shifts SET status = 'aktif' WHERE id = '$shift_id'");
    $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Shift yang terkunci berhasil DIBUKA KEMBALI!'];
    header("Location: kelola_user.php"); exit;
}

// --- PERSIAPAN DATA METRIK ---
$q_tot = $conn->query("SELECT COUNT(id) as tot FROM users WHERE role='user'"); $tot_kasir = $q_tot->fetch_assoc()['tot'];
$q_aktif = $conn->query("SELECT COUNT(id) as tot FROM shifts WHERE tanggal='$tanggal_hari_ini' AND status='aktif'"); $shift_aktif = $q_aktif->fetch_assoc()['tot'];
$q_selesai = $conn->query("SELECT COUNT(id) as tot FROM shifts WHERE tanggal='$tanggal_hari_ini' AND status='selesai'"); $shift_selesai = $q_selesai->fetch_assoc()['tot'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kelola Kasir & Shift - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Tambahan SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css" rel="stylesheet">
    
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-light-blue: #e6f0ff; --bri-black: #1a1a1a; --bri-white: #ffffff; --bri-grey: #e9ecef; --bri-green: #198754; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        .metric-card-mini { border-radius: 20px; padding: 20px; border: 1px solid var(--bri-grey); display: flex; align-items: center; gap: 15px; background: white; box-shadow: 0 4px 10px rgba(0,0,0,0.02); height: 100%; }
        .metric-icon { width: 55px; height: 55px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); border-radius: 50px; padding: 12px 28px; font-weight: 700; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        .form-control, .form-select { border-radius: 14px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 12px 15px; color: var(--bri-black); font-weight: 600; }
        .table-wrapper { max-height: 550px; overflow-y: auto; border-radius: 12px; }
        .btn-action { border-radius: 50px; font-weight: 600; padding: 8px 16px; font-size: 12px; letter-spacing: 0.3px; }
        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .status-box { padding: 10px 15px; border-radius: 12px; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        
        /* Modifikasi SweetAlert Font */
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 16px !important; }
        
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Manajemen Akun Kasir</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Kelola akses rekening, PIN, & koreksi Modal laci</p>
            </div>
            <button class="btn btn-modern align-self-start" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-person-plus-fill me-2"></i> Daftarkan Kasir
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4"><div class="metric-card-mini"><div class="metric-icon" style="background-color: var(--bri-light-blue); color: var(--bri-blue);"><i class="bi bi-people-fill"></i></div><div><p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Total Kasir Aktif</p><h4 class="fw-bolder mb-0 text-dark"><?= $tot_kasir; ?> Pegawai</h4></div></div></div>
            <div class="col-md-4"><div class="metric-card-mini"><div class="metric-icon" style="background-color: #d1e7dd; color: var(--bri-green);"><i class="bi bi-play-circle-fill"></i></div><div><p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Shift Bekerja</p><h4 class="fw-bolder mb-0 text-dark"><?= $shift_aktif; ?> Shift Aktif</h4></div></div></div>
            <div class="col-md-4"><div class="metric-card-mini"><div class="metric-icon bg-light text-secondary border"><i class="bi bi-lock-fill"></i></div><div><p class="text-muted small fw-bold text-uppercase mb-0 tracking-wide">Shift Selesai Hari Ini</p><h4 class="fw-bolder mb-0 text-dark"><?= $shift_selesai; ?> Shift Tutup</h4></div></div></div>
        </div>

        <div class="modern-card">
            <div class="table-responsive table-wrapper">
                <table class="table table-borderless align-middle mb-0 text-nowrap" style="font-size: 14px;">
                    <thead style="position: sticky; top: 0; background: white; z-index: 10;">
                        <tr class="border-bottom border-2"><th>Cabang & Slot Shift</th><th>Status Hari Ini</th><th class="text-center">Performa</th><th class="text-center">Manajemen Akses & Laci</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $q_users = $conn->query("
                            SELECT u.id, u.shift_ke, u.assigned_banks, c.nama_cabang, 
                                   (SELECT id FROM shifts WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1) as shift_id,
                                   (SELECT status FROM shifts WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1) as status_shift,
                                   (SELECT modal_awal FROM shifts WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1) as modal_awal,
                                   (SELECT COUNT(id) FROM transactions WHERE user_id = u.id AND tanggal = '$tanggal_hari_ini') as total_trx_hari_ini
                            FROM users u JOIN cabang c ON u.cabang_id = c.id WHERE u.role = 'user' ORDER BY c.nama_cabang ASC, u.shift_ke ASC
                        ");
                        
                        if ($q_users->num_rows > 0):
                            while ($row = $q_users->fetch_assoc()):
                                if (empty($row['shift_id'])) { $status_badge = '<div class="status-box bg-light text-secondary border"><i class="bi bi-dash-circle"></i> Belum Set Modal</div>'; } 
                                elseif ($row['status_shift'] == 'aktif') { $status_badge = '<div class="status-box" style="background-color: #d1e7dd; color: var(--bri-green);"><i class="bi bi-activity"></i> Sedang Aktif</div><div class="mt-1 small fw-bold text-muted">Modal: Rp '.number_format($row['modal_awal'],0,',','.').'</div>'; } 
                                else { $status_badge = '<div class="status-box bg-dark text-white"><i class="bi bi-lock-fill"></i> Selesai / Terkunci</div>'; }
                                $trx_count = $row['total_trx_hari_ini'] ?? 0;
                                $user_banks = $row['assigned_banks'] ? explode(',', $row['assigned_banks']) : [];
                        ?>
                            <tr class="border-bottom">
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-circle d-flex justify-content-center align-items-center fw-bold" style="width: 45px; height: 45px; background-color: var(--bri-light-blue); color: var(--bri-blue);">S<?= $row['shift_ke']; ?></div>
                                        <div>
                                            <strong class="fs-6 d-block" style="color: var(--bri-black);"><?= $row['nama_cabang']; ?></strong>
                                            <small class="text-muted fw-semibold">Slot Shift <?= $row['shift_ke']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?= $status_badge; ?></td>
                                <td class="text-center"><span class="badge rounded-pill <?= ($trx_count > 0) ? 'bg-primary' : 'bg-secondary'; ?> px-3 py-2 fw-bold" style="font-size: 13px;"><?= $trx_count; ?> Trx</span></td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                                        <button class="btn btn-outline-info btn-action" data-bs-toggle="modal" data-bs-target="#modalAkses<?= $row['id']; ?>"><i class="bi bi-bank"></i> Akses Rek</button>
                                        <button class="btn btn-outline-primary btn-action" data-bs-toggle="modal" data-bs-target="#modalShift<?= $row['id']; ?>"><i class="bi bi-wallet2"></i> Laci</button>
                                        <button class="btn btn-outline-warning btn-action text-dark" data-bs-toggle="modal" data-bs-target="#modalReset<?= $row['id']; ?>"><i class="bi bi-key"></i> PIN</button>
                                        <!-- Tombol Hapus dengan class khusus untuk memanggil SweetAlert -->
                                        <a href="kelola_user.php?hapus=<?= $row['id']; ?>" class="btn btn-outline-danger btn-action btn-hapus"><i class="bi bi-trash"></i></a>
                                    </div>
                                </td>
                            </tr>

                            <!-- MODAL AKSES REKENING -->
                            <div class="modal fade" id="modalAkses<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header"><h5 class="modal-title fw-bolder">Akses Rekening Cabang</h5><button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">
                                            <div class="p-3 mb-4 rounded-3 text-center" style="background-color: #e0f7fa; color: #00838f;"><h6 class="fw-bold mb-0"><?= $row['nama_cabang']; ?> (Slot Shift <?= $row['shift_ke']; ?>)</h6></div>
                                            <form method="POST">
                                                <input type="hidden" name="id_user" value="<?= $row['id']; ?>">
                                                <label class="form-label text-muted small fw-bold text-uppercase mb-2">Pilih Hak Akses Rekening Berdasarkan Pemilik</label>
                                                
                                                <div class="row g-2 mb-4 bg-light p-3 rounded border">
                                                    <?php foreach($rekening_by_pemilik as $pemilik => $rekenings): ?>
                                                        <div class="col-12 mt-2 mb-1 border-bottom pb-1">
                                                            <span class="badge" style="background-color: #607D8B;"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($pemilik) ?></span>
                                                        </div>
                                                        <?php foreach($rekenings as $rek): ?>
                                                        <div class="col-sm-6 col-md-4">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" name="banks[]" value="<?= htmlspecialchars($rek['alias']) ?>" id="bank_<?= $rek['id'].'_'.$row['id'] ?>" <?= in_array($rek['alias'], $user_banks) ? 'checked' : '' ?>>
                                                                <label class="form-check-label fw-bold small" for="bank_<?= $rek['id'].'_'.$row['id'] ?>"><?= htmlspecialchars($rek['alias']) ?> <span class="text-muted fw-normal" style="font-size: 10px;">(<?= htmlspecialchars($rek['bank']) ?>)</span></label>
                                                            </div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php endforeach; ?>
                                                </div>

                                                <button type="submit" name="edit_akses_rekening" class="btn btn-info w-100 fw-bold py-3 fs-6 rounded-pill text-white shadow-sm">Simpan Hak Akses</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- MODAL STATUS LACI SHIFT -->
                            <div class="modal fade" id="modalShift<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header"><h5 class="modal-title fw-bolder">Status Shift & Laci</h5><button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">
                                            <?php if(empty($row['shift_id'])): ?>
                                                <div class="text-center py-4"><h6 class="fw-bold">Shift Belum Dimulai</h6></div>
                                            <?php elseif($row['status_shift'] == 'aktif'): ?>
                                                <form method="POST" class="mb-4">
                                                    <input type="hidden" name="shift_id" value="<?= $row['shift_id']; ?>">
                                                    <label class="form-label fw-bold">Koreksi Angka Modal Cash (Rp)</label>
                                                    <input type="text" name="modal_baru" class="form-control form-control-lg fw-bold mb-3" value="<?= number_format($row['modal_awal'],0,'','.'); ?>" onkeyup="this.value=this.value.replace(/[^0-9]/g,'').replace(/\B(?=(\d{3})+(?!\d))/g,'.')" required>
                                                    <button type="submit" name="edit_modal" class="btn btn-primary w-100 fw-bold rounded-pill shadow-sm py-2">Simpan Koreksi</button>
                                                </form>
                                                
                                                <div class="border-top pt-3 text-center">
                                                    <form method="POST" id="formBatalShift<?= $row['shift_id']; ?>">
                                                        <input type="hidden" name="shift_id" value="<?= $row['shift_id']; ?>">
                                                        <input type="hidden" name="batalkan_shift" value="1">
                                                        <button type="button" class="btn btn-outline-danger btn-sm fw-bold rounded-pill px-4" onclick="konfirmasiBatalShift('formBatalShift<?= $row['shift_id']; ?>')">Batalkan Shift</button>
                                                    </form>
                                                </div>
                                                
                                            <?php elseif($row['status_shift'] == 'selesai'): ?>
                                                <div class="text-center py-2">
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
                            
                            <!-- MODAL UBAH PIN -->
                            <div class="modal fade" id="modalReset<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header"><h5 class="modal-title fw-bolder">Ubah PIN</h5><button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">
                                            <form method="POST">
                                                <input type="hidden" name="id_user" value="<?= $row['id']; ?>">
                                                <input type="password" name="pin_baru" class="form-control form-control-lg text-center fw-bold text-primary mb-3" placeholder="••••••" maxlength="6" style="letter-spacing: 10px;" required>
                                                <button type="submit" name="reset_pin" class="btn btn-warning w-100 fw-bold py-3 fs-6 rounded-pill text-dark">Ubah PIN Sekarang</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-5 fw-medium border-0">Belum ada akun terdaftar.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL TAMBAH KASIR (FLEKSIBEL) -->
    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-person-plus-fill me-2"></i>Daftarkan Kasir</h5><button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Penempatan Cabang</label>
                            <select name="cabang_id" class="form-select" required><option value="" disabled selected>-- Pilih Cabang --</option><?php $q_all_cabang = $conn->query("SELECT * FROM cabang"); while($c = $q_all_cabang->fetch_assoc()) { echo "<option value='{$c['id']}'>{$c['nama_cabang']}</option>"; } ?></select>
                        </div>
                        
                        <!-- DROP DOWN SHIFT TELAH DIBUAT FLEKSIBEL -->
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Pilih Slot Shift Kerja</label>
                            <select name="shift_ke" class="form-select" required>
                                <option value="" disabled selected>-- Pilih Slot Shift --</option>
                                <option value="1">Shift 1 (Utama / Fleksibel)</option>
                                <option value="2">Shift 2 (Lanjutan / Fleksibel)</option>
                                <option value="3">Shift 3 (Bantuan / Ekstra)</option>
                                <option value="4">Shift 4 (Bantuan / Ekstra)</option>
                            </select>
                            <small class="text-muted mt-1 d-block" style="font-size: 11px;"><i class="bi bi-info-circle me-1"></i>Shift 1 dan Shift 2 bisa aktif bekerja secara bersamaan di hari yang sama.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Pilih Hak Akses Rekening Cabang</label>
                            <div class="row g-2 bg-light p-3 rounded border">
                                <?php foreach($rekening_by_pemilik as $pemilik => $rekenings): ?>
                                    <div class="col-12 mt-2 mb-1 border-bottom pb-1">
                                        <span class="badge" style="background-color: #607D8B;"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($pemilik) ?></span>
                                    </div>
                                    <?php foreach($rekenings as $rek): ?>
                                    <div class="col-sm-6 col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="banks[]" value="<?= htmlspecialchars($rek['alias']) ?>" id="bank_add_<?= $rek['id'] ?>">
                                            <label class="form-check-label fw-bold small" for="bank_add_<?= $rek['id'] ?>"><?= htmlspecialchars($rek['alias']) ?> <span class="text-muted fw-normal" style="font-size: 10px;">(<?= htmlspecialchars($rek['bank']) ?>)</span></label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Buat PIN Akses Awal</label>
                            <input type="password" name="pin" class="form-control text-center fw-bold text-primary" placeholder="••••••" maxlength="6" style="letter-spacing: 10px; font-size: 20px;" required>
                        </div>
                        <button type="submit" name="tambah_kasir" class="btn btn-modern w-100 fs-5 py-3 mt-2">Daftarkan Akun</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>

    <!-- SCRIPT UNTUK SWEET ALERT 2 -->
    <script>
        // 1. Alert Sukses/Gagal (Toast Notification di Pojok Kanan Atas)
        <?php if(isset($_SESSION['notif'])): ?>
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3500,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });

            Toast.fire({
                icon: '<?= $_SESSION['notif']['type']; ?>',
                title: '<?= $_SESSION['notif']['msg']; ?>'
            });
        <?php unset($_SESSION['notif']); endif; ?>

        // 2. Alert Konfirmasi Hapus Akun Kasir
        document.querySelectorAll('.btn-hapus').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault(); 
                const url = this.getAttribute('href');
                
                Swal.fire({
                    title: 'Hapus Kasir Ini?',
                    text: "Seluruh hak akses login kasir ini akan dihilangkan permanen!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Ya, Hapus!',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = url; 
                    }
                })
            });
        });

        // 3. Alert Konfirmasi Pembatalan Shift
        function konfirmasiBatalShift(formId) {
            Swal.fire({
                title: 'Batalkan Shift?',
                text: "Jika shift dibatalkan, Kasir harus memasukkan nominal modal dari awal lagi.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Batalkan',
                cancelButtonText: 'Tutup'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(formId).submit();
                }
            })
        }
    </script>
</body>
</html>