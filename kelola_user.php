<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

$tanggal_hari_ini = date('Y-m-d');

// --- PROSES TAMBAH USER (KASIR) ---
if (isset($_POST['tambah_user'])) {
    $username = $conn->real_escape_string(trim($_POST['username']));
    $pin_hash = password_hash($_POST['pin'], PASSWORD_DEFAULT);
    $cabang_id = (int)$_POST['cabang_id'];
    $shift_ke = (int)$_POST['shift_ke'];
    
    $banks = isset($_POST['banks']) ? $_POST['banks'] : [];
    $safe_banks = array_map(function($val) use($conn) { return $conn->real_escape_string(trim($val)); }, $banks);
    $safe_banks = array_unique($safe_banks); 
    $assigned_str = implode(', ', $safe_banks);
    
    $cek = $conn->query("SELECT id FROM users WHERE username = '$username'");
    if($cek->num_rows > 0){
        $_SESSION['flash_error'] = "Gagal! Username '$username' sudah terdaftar.";
    } else {
        $conn->query("INSERT INTO users (username, pin, role, cabang_id, shift_ke, assigned_banks) VALUES ('$username', '$pin_hash', 'user', '$cabang_id', '$shift_ke', '$assigned_str')");
        $_SESSION['flash_success'] = "Kasir baru berhasil ditambahkan!";
    }
    header("Location: kelola_user.php"); exit;
}

// --- PROSES EDIT DATA USER ---
if (isset($_POST['edit_user'])) {
    $id_user = (int)$_POST['user_id'];
    $username = $conn->real_escape_string(trim($_POST['username']));
    $cabang_id = (int)$_POST['cabang_id'];
    $shift_ke = (int)$_POST['shift_ke'];
    
    $banks = isset($_POST['banks']) ? $_POST['banks'] : [];
    $safe_banks = array_map(function($val) use($conn) { return $conn->real_escape_string(trim($val)); }, $banks);
    $safe_banks = array_unique($safe_banks); 
    $assigned_str = implode(', ', $safe_banks);
    
    if(!empty($_POST['pin'])) {
        $pin_hash = password_hash($_POST['pin'], PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET username='$username', pin='$pin_hash', cabang_id='$cabang_id', shift_ke='$shift_ke', assigned_banks='$assigned_str' WHERE id='$id_user'");
    } else {
        $conn->query("UPDATE users SET username='$username', cabang_id='$cabang_id', shift_ke='$shift_ke', assigned_banks='$assigned_str' WHERE id='$id_user'");
    }
    $_SESSION['flash_success'] = "Data kasir & akses rekening berhasil diperbarui!";
    header("Location: kelola_user.php"); exit;
}

// --- PROSES HAPUS USER ---
if (isset($_GET['hapus'])) {
    $id_user = (int)$_GET['hapus'];
    $conn->query("DELETE FROM users WHERE id='$id_user'");
    $_SESSION['flash_success'] = "Akun kasir berhasil dihapus!";
    header("Location: kelola_user.php"); exit;
}

// =====================================================================
// PENDETEKSI REKENING YANG SUDAH DIPAKAI KASIR LAIN
// =====================================================================
$global_used_banks = [];
$q_all_users = $conn->query("SELECT id, username, assigned_banks FROM users WHERE role='user'");
if($q_all_users) {
    while($u = $q_all_users->fetch_assoc()) {
        if(!empty($u['assigned_banks'])) {
            $b_arr = array_map('trim', explode(',', $u['assigned_banks']));
            foreach($b_arr as $b_name) {
                if($b_name !== '') {
                    if(!isset($global_used_banks[$b_name])) { $global_used_banks[$b_name] = []; }
                    $global_used_banks[$b_name][] = ['id' => $u['id'], 'username' => $u['username']];
                }
            }
        }
    }
}

// =====================================================================
// PENDETEKSI MAPPING REKENING DAN BOS & ALIAS KEMBAR
// =====================================================================
$master_rekening = [];
$alias_to_bos = []; 
$kolom_bos = null;
$duplicate_aliases = [];
$possible_columns = ['pemilik', 'atas_nama', 'nama_pemilik', 'bos', 'nama_bos'];

foreach($possible_columns as $col) {
    $check = $conn->query("SHOW COLUMNS FROM rekening LIKE '$col'");
    if($check && $check->num_rows > 0) {
        $kolom_bos = $col;
        break;
    }
}

if ($kolom_bos) {
    $q_rek = $conn->query("SELECT alias, $kolom_bos FROM rekening ORDER BY $kolom_bos ASC, alias ASC");
    if($q_rek) {
        while($r = $q_rek->fetch_assoc()) { 
            $owner = !empty($r[$kolom_bos]) ? trim($r[$kolom_bos]) : 'Umum';
            $alias = trim($r['alias']);
            $master_rekening[$owner][] = $alias; 
            $alias_to_bos[$alias] = $owner; 
        }
    }
} else {
    $q_rek = $conn->query("SELECT alias FROM rekening ORDER BY alias ASC");
    if($q_rek) {
        while($r = $q_rek->fetch_assoc()) { 
            $alias = trim($r['alias']);
            $master_rekening['Semua Rekening'][] = $alias; 
            $alias_to_bos[$alias] = 'Umum';
        }
    }
}

// Cek Alias Kembar
$q_dup = $conn->query("SELECT alias, COUNT(*) as jml FROM rekening GROUP BY alias HAVING jml > 1");
if($q_dup && $q_dup->num_rows > 0) {
    while($d = $q_dup->fetch_assoc()) {
        $duplicate_aliases[] = htmlspecialchars($d['alias']);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Manajemen Kasir - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-light-blue: #e6f0ff; --bri-black: #1a1a1a; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 30px; border: none; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 12px; padding: 10px 20px; font-weight: 600; border: none; transition: all 0.3s; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 82, 156, 0.3); color: white; }
        
        .table-custom th { background-color: var(--bri-light-blue); color: var(--bri-blue); font-weight: 700; text-transform: uppercase; font-size: 12px; padding: 15px; border-bottom: 2px solid #cce0ff; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f0f0f0; font-size: 14px; font-weight: 500; }
        .badge-bank { background-color: #f8f9fa; border: 1px solid #dee2e6; color: #495057; font-weight: 600; font-size: 11px; padding: 6px 10px; border-radius: 8px; margin: 3px; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        
        .form-check-input:disabled { opacity: 0.5; background-color: #e9ecef; }
        .form-check-input:disabled ~ label { opacity: 0.6; cursor: not-allowed !important; }
        
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 20px !important; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0; padding: 20px 15px; padding-top: 85px; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        
        <?php if(!empty($duplicate_aliases)): ?>
        <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 mb-4">
            <h5 class="fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Peringatan: Ditemukan Nama Alias Rekening Ganda!</h5>
            <p class="mb-2">Sistem mendeteksi ada Nama Alias rekening yang <b>sama persis</b> yaitu: <strong class="badge bg-danger fs-6"><?= implode(', ', $duplicate_aliases) ?></strong>.</p>
            <p class="mb-2">Inilah penyebab utama mengapa saat Anda memilih rekening Bos 2, rekening Bos Utama "ikut terkunci". Sistem kebingungan membedakannya karena namanya identik!</p>
            <hr>
            <p class="mb-1 fw-bold">Cara Menyembuhkannya:</p>
            <ol class="mb-0">
                <li>Buka menu <strong>Kelola Rekening</strong>.</li>
                <li>Edit nama alias tersebut agar unik (Contoh: ubah menjadi <em>"BRI Bos Utama"</em> dan <em>"BRI Bos 2"</em>).</li>
                <li>Setelah itu, kembali ke halaman ini dan <strong>Edit Akses Kasir</strong> untuk mencentang kembali nama alias yang baru tersebut.</li>
            </ol>
        </div>
        <?php endif; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);"><i class="bi bi-people-fill me-2 text-primary"></i>Manajemen Akun Kasir</h3>
                <p class="fw-medium text-muted mb-0">Kelola akun, penempatan cabang, dan pantau saldo kasir.</p>
            </div>
            <button class="btn-modern" data-bs-toggle="modal" data-bs-target="#modalTambahUser"><i class="bi bi-person-plus-fill me-2"></i>Tambah Kasir Baru</button>
        </div>

        <div class="modern-card">
            <div class="table-responsive">
                <table class="table table-custom text-nowrap mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Username</th>
                            <th>Cabang</th>
                            <th class="text-center">Status & Saldo Laci (CASH)</th>
                            <th>Hak Akses Rekening</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        $q_users = $conn->query("
                            SELECT u.*, c.nama_cabang 
                            FROM users u 
                            LEFT JOIN cabang c ON u.cabang_id = c.id 
                            WHERE u.role = 'user' 
                            ORDER BY c.nama_cabang ASC, u.shift_ke ASC
                        ");
                        
                        if($q_users->num_rows > 0):
                            while($row = $q_users->fetch_assoc()):
                                $user_banks = $row['assigned_banks'] ? array_unique(array_map('trim', explode(',', $row['assigned_banks']))) : [];
                                
                                // KALKULASI SALDO LACI KASIR SECARA LIVE
                                $user_iter_id = $row['id'];
                                $q_cek_shift = $conn->query("SELECT id, modal_awal, tanggal FROM shifts WHERE user_id = '$user_iter_id' AND tanggal = '$tanggal_hari_ini' AND status = 'aktif' ORDER BY id DESC LIMIT 1");
                                
                                $status_shift_html = '<span class="badge bg-secondary opacity-75">Belum Buka Shift</span>';
                                
                                if ($q_cek_shift && $q_cek_shift->num_rows > 0) {
                                    $shift_aktif = $q_cek_shift->fetch_assoc();
                                    $sid = $shift_aktif['id'];
                                    $tgl_shift = $shift_aktif['tanggal'];
                                    
                                    $in_cash = $conn->query("SELECT IFNULL(SUM(nominal), 0) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi')")->fetch_assoc()['tot'];
                                    $adm_cash = $conn->query("SELECT IFNULL(SUM(admin_fee), 0) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Pengeluaran / Rugi')")->fetch_assoc()['tot'];
                                    $out_cash = $conn->query("SELECT IFNULL(SUM(nominal), 0) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi = 'Pengeluaran / Rugi' AND bank_agen = 'CASH'))")->fetch_assoc()['tot'];
                                    
                                    $saldo_laci_user = $shift_aktif['modal_awal'] + $in_cash + $adm_cash - $out_cash;
                                    
                                    $status_shift_html = '<span class="badge bg-success mb-1 px-3">Sedang Bertugas</span><br><span class="fw-bolder text-primary" style="font-size: 15px;">Rp ' . number_format($saldo_laci_user, 0, ',', '.') . '</span>';
                                }
                        ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><i class="bi bi-person-circle text-secondary me-2"></i><?= htmlspecialchars($row['username']); ?></div>
                                <small class="text-muted">Slot Shift: <?= htmlspecialchars($row['shift_ke']); ?></small>
                            </td>
                            <td><span class="badge bg-primary px-3 py-2 rounded-pill"><?= htmlspecialchars($row['nama_cabang'] ?? 'Belum Diatur'); ?></span></td>
                            <td class="text-center"><?= $status_shift_html; ?></td>
                            <td style="white-space: normal; max-width: 350px;">
                                <?php if(empty($user_banks)): ?>
                                    <span class="badge bg-danger">Belum Diberi Akses</span>
                                <?php else: ?>
                                    <?php foreach($user_banks as $b): 
                                        $nama_bos = isset($alias_to_bos[$b]) ? $alias_to_bos[$b] : 'Umum';
                                    ?>
                                        <span class="badge-bank">
                                            <i class="bi bi-person-fill text-secondary"></i> <small class="text-muted me-1"><?= htmlspecialchars($nama_bos); ?>:</small>
                                            <i class="bi bi-bank ms-1 me-1 text-primary"></i><?= htmlspecialchars($b); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group shadow-sm border border-light rounded-3">
                                    <button class="btn btn-sm btn-light text-primary fw-bold" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $row['id'] ?>" title="Edit Akun & Akses"><i class="bi bi-pencil-square me-1"></i> Edit</button>
                                    <button class="btn btn-sm btn-light text-danger fw-bold" onclick="konfirmasiHapus(<?= $row['id']; ?>, '<?= htmlspecialchars($row['username']); ?>')" title="Hapus"><i class="bi bi-trash3-fill"></i></button>
                                </div>
                            </td>
                        </tr>

                        <div class="modal fade" id="modalEdit<?= $row['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0">
                                    <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                                        <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Akun & Akses Rekening</h5>
                                        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <form method="POST">
                                            <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                            
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted small fw-bold">Username Akun</label>
                                                    <input type="text" name="username" class="form-control fw-bold" value="<?= htmlspecialchars($row['username']) ?>" required style="border-radius: 12px; background-color: var(--bg-body);">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted small fw-bold">PIN Kasir (Angka) <span class="text-danger fw-normal">(Kosongkan jika tdk diubah)</span></label>
                                                    <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin" class="form-control" placeholder="******" style="border-radius: 12px; background-color: var(--bg-body);">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted small fw-bold">Cabang Penempatan</label>
                                                    <select name="cabang_id" class="form-select" required style="border-radius: 12px; background-color: var(--bg-body);">
                                                        <?php 
                                                        $qc = $conn->query("SELECT * FROM cabang");
                                                        while($c = $qc->fetch_assoc()): 
                                                            $sel = ($c['id'] == $row['cabang_id']) ? 'selected' : '';
                                                        ?>
                                                            <option value="<?= $c['id'] ?>" <?= $sel ?>><?= htmlspecialchars($c['nama_cabang']) ?></option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted small fw-bold">Slot Shift Pekerjaan</label>
                                                    <select name="shift_ke" class="form-select" required style="border-radius: 12px; background-color: var(--bg-body);">
                                                        <option value="1" <?= $row['shift_ke']==1?'selected':'' ?>>Shift 1</option>
                                                        <option value="2" <?= $row['shift_ke']==2?'selected':'' ?>>Shift 2</option>
                                                        <option value="3" <?= $row['shift_ke']==3?'selected':'' ?>>Shift 3</option>
                                                        <option value="4" <?= $row['shift_ke']==4?'selected':'' ?>>Shift 4</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="mb-4">
                                                <label class="form-label text-muted small fw-bold mb-2">Hak Akses Rekening</label>
                                                <p class="text-danger" style="font-size: 11px; margin-top: -5px;">*Rekening yang telah dipakai oleh kasir lain akan dikunci otomatis untuk mencegah bentrok.</p>
                                                <div class="border rounded-4 p-3 bg-light shadow-sm" style="max-height: 250px; overflow-y: auto;">
                                                    <?php if(empty($master_rekening)): ?>
                                                        <div class="alert alert-warning py-2 small mb-0">Master rekening kosong. Silakan tambah rekening di menu Kelola Rekening.</div>
                                                    <?php else: ?>
                                                        <?php foreach($master_rekening as $owner => $aliases): ?>
                                                            <div class="mb-3">
                                                                <span class="badge bg-secondary mb-2"><i class="bi bi-person-fill me-1"></i> Bos: <?= htmlspecialchars($owner) ?></span>
                                                                <div class="row g-2">
                                                                    <?php foreach($aliases as $alias): 
                                                                        // LOGIKA PENGUNCIAN REKENING & ANTI BENTROK ID HTML
                                                                        $html_id = md5($owner . '_' . $alias); // Menggabungkan bos dan alias agar ID klik tidak tertukar
                                                                        
                                                                        $is_used_by_other = false;
                                                                        $other_users = [];
                                                                        if(isset($global_used_banks[$alias])) {
                                                                            foreach($global_used_banks[$alias] as $ub) {
                                                                                if($ub['id'] != $row['id']) { 
                                                                                    $is_used_by_other = true;
                                                                                    $other_users[] = $ub['username'];
                                                                                }
                                                                            }
                                                                        }

                                                                        $checked = in_array($alias, $user_banks) ? 'checked' : '';
                                                                        if($is_used_by_other) $checked = ''; 
                                                                        $disabled = $is_used_by_other ? 'disabled' : '';
                                                                        
                                                                        $used_by_text = '';
                                                                        if($is_used_by_other) {
                                                                            $used_by_text = '<span class="badge bg-danger ms-2" style="font-size: 9px;"><i class="bi bi-lock-fill"></i> Dipakai: '.htmlspecialchars(implode(', ', $other_users)).'</span>';
                                                                        }
                                                                    ?>
                                                                    <div class="col-12 col-sm-6">
                                                                        <div class="form-check form-switch p-2 bg-white rounded-3 border border-light shadow-sm d-flex align-items-center" style="padding-left: 2.5rem !important; min-height: 45px;">
                                                                            <input class="form-check-input" type="checkbox" name="banks[]" value="<?= htmlspecialchars($alias) ?>" id="edit_<?= $row['id'] ?>_<?= $html_id ?>" <?= $checked ?> <?= $disabled ?>>
                                                                            <label class="form-check-label fw-bold text-dark small ms-2" for="edit_<?= $row['id'] ?>_<?= $html_id ?>" style="cursor: pointer;">
                                                                                <?= htmlspecialchars($alias) ?>
                                                                                <?= $used_by_text ?>
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <button type="submit" name="edit_user" class="btn-modern w-100 py-3 fs-6 rounded-pill">Simpan Perubahan & Akses</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-5">Belum ada akun kasir. Silakan tambahkan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalTambahUser" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0">
                <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-person-plus-fill text-primary me-2"></i>Tambah Kasir Baru</h5>
                    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold">Username (Login)</label>
                                <input type="text" name="username" class="form-control fw-bold" required placeholder="Contoh: kasir_pagi" style="border-radius: 12px; background-color: var(--bg-body);">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold">PIN Kasir (Angka)</label>
                                <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin" class="form-control" required placeholder="******" style="border-radius: 12px; background-color: var(--bg-body);">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold">Cabang Penempatan</label>
                                <select name="cabang_id" class="form-select" required style="border-radius: 12px; background-color: var(--bg-body);">
                                    <option value="" disabled selected>-- Pilih Cabang --</option>
                                    <?php 
                                    $qc = $conn->query("SELECT * FROM cabang");
                                    while($c = $qc->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nama_cabang']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold">Slot Shift</label>
                                <select name="shift_ke" class="form-select" required style="border-radius: 12px; background-color: var(--bg-body);">
                                    <option value="" disabled selected>-- Pilih --</option>
                                    <option value="1">Shift 1</option>
                                    <option value="2">Shift 2</option>
                                    <option value="3">Shift 3</option>
                                    <option value="4">Shift 4</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold mb-2">Hak Akses Rekening</label>
                            <p class="text-danger" style="font-size: 11px; margin-top: -5px;">*Rekening yang telah dipakai oleh kasir lain akan dikunci otomatis untuk mencegah bentrok.</p>
                            <div class="border rounded-4 p-3 bg-light shadow-sm" style="max-height: 250px; overflow-y: auto;">
                                <?php if(empty($master_rekening)): ?>
                                    <div class="alert alert-warning py-2 small mb-0">Master rekening kosong. Silakan tambah rekening di menu Kelola Rekening.</div>
                                <?php else: ?>
                                    <?php foreach($master_rekening as $owner => $aliases): ?>
                                        <div class="mb-3">
                                            <span class="badge bg-secondary mb-2"><i class="bi bi-person-fill me-1"></i> Bos: <?= htmlspecialchars($owner) ?></span>
                                            <div class="row g-2">
                                                <?php foreach($aliases as $alias): 
                                                    $html_id = md5($owner . '_' . $alias); // Anti bentrok ID HTML
                                                    
                                                    // LOGIKA PENGUNCIAN UNTUK KASIR BARU
                                                    $is_used = isset($global_used_banks[$alias]);
                                                    $disabled = $is_used ? 'disabled' : '';
                                                    $used_by_text = '';
                                                    if($is_used) {
                                                        $names = array_column($global_used_banks[$alias], 'username');
                                                        $used_by_text = '<span class="badge bg-danger ms-2" style="font-size: 9px;"><i class="bi bi-lock-fill"></i> Dipakai: '.htmlspecialchars(implode(', ', $names)).'</span>';
                                                    }
                                                ?>
                                                <div class="col-12 col-sm-6">
                                                    <div class="form-check form-switch p-2 bg-white rounded-3 border border-light shadow-sm d-flex align-items-center" style="padding-left: 2.5rem !important; min-height: 45px;">
                                                        <input class="form-check-input" type="checkbox" name="banks[]" value="<?= htmlspecialchars($alias) ?>" id="add_<?= $html_id ?>" <?= $disabled ?>>
                                                        <label class="form-check-label fw-bold text-dark small ms-2" for="add_<?= $html_id ?>" style="cursor: pointer;">
                                                            <?= htmlspecialchars($alias) ?>
                                                            <?= $used_by_text ?>
                                                        </label>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button type="submit" name="tambah_user" class="btn-modern w-100 py-3 fs-6 rounded-pill">Daftarkan Kasir & Simpan Akses</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function konfirmasiHapus(id, nama) {
            Swal.fire({
                title: 'Hapus Akun ' + nama + '?',
                text: "Semua laporan yang terhubung dengan akun ini mungkin akan terdampak.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Hapus!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'kelola_user.php?hapus=' + id;
                }
            });
        }

        // SweetAlert2 Notifikasi Toast
        <?php if (isset($_SESSION['flash_success'])): ?>
            Swal.fire({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true,
                icon: 'success', title: '<?= $_SESSION['flash_success']; ?>'
            });
        <?php unset($_SESSION['flash_success']); endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
            Swal.fire({
                icon: 'error', title: 'Oops...', text: '<?= $_SESSION['flash_error']; ?>', confirmButtonColor: '#00529C'
            });
        <?php unset($_SESSION['flash_error']); endif; ?>
    </script>
</body>
</html>