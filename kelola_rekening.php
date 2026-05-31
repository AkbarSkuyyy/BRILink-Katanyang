<?php
require 'config.php';
// Pastikan session sudah berjalan untuk menampung notifikasi
if (session_status() == PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { 
    header("Location: index.php"); 
    exit; 
}

// ==========================================
// 1. AUTO-CREATE & UPGRADE TABEL 
// ==========================================
$conn->query("CREATE TABLE IF NOT EXISTS rekening (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pemilik VARCHAR(100) NOT NULL,
    bank VARCHAR(50) NOT NULL,
    nomor_rekening VARCHAR(50) NOT NULL,
    alias VARCHAR(50) NOT NULL,
    kolom_db VARCHAR(50) NOT NULL
)");

$check_pemilik = $conn->query("SHOW COLUMNS FROM rekening LIKE 'pemilik'");
if ($check_pemilik && $check_pemilik->num_rows == 0) {
    $conn->query("ALTER TABLE rekening ADD pemilik VARCHAR(100) NOT NULL DEFAULT 'Bos Utama' AFTER id");
    $conn->query("ALTER TABLE rekening ADD bank VARCHAR(50) NOT NULL DEFAULT 'Lainnya' AFTER pemilik");
    $conn->query("ALTER TABLE rekening ADD nomor_rekening VARCHAR(50) NOT NULL DEFAULT '-' AFTER bank");
    $conn->query("ALTER TABLE rekening ADD alias VARCHAR(50) NOT NULL DEFAULT '-' AFTER nomor_rekening");
    $conn->query("UPDATE rekening SET alias = nama_bank WHERE alias = '-'");
}

// ==========================================
// 2. SCRIPT AUTO-FIX: MENGATASI DUPLIKAT KOLOM
// ==========================================
$q_fix = $conn->query("SELECT id, pemilik, alias FROM rekening");
if ($q_fix) {
    while($c = $q_fix->fetch_assoc()) {
        $id_rek = $c['id'];
        $p_clean = preg_replace('/[^a-zA-Z0-9]/', '', $c['pemilik']);
        $a_clean = preg_replace('/[^a-zA-Z0-9]/', '', $c['alias']);
        $kolom_db_unik = 'modal_' . strtolower($p_clean . $a_clean);
        
        $conn->query("UPDATE rekening SET kolom_db = '$kolom_db_unik' WHERE id = '$id_rek'");
        try { $conn->query("ALTER TABLE shifts ADD $kolom_db_unik DOUBLE DEFAULT 0"); } catch (Exception $e) {}
    }
}

// ==========================================
// 3. PROSES TAMBAH REKENING BARU
// ==========================================
if (isset($_POST['tambah_rekening'])) {
    $pemilik = $conn->real_escape_string(trim($_POST['pemilik']));
    $bank    = $conn->real_escape_string(trim($_POST['bank']));
    $no_rek  = $conn->real_escape_string(trim($_POST['no_rek']));
    $alias   = $conn->real_escape_string(trim($_POST['alias'])); 

    $p_clean = preg_replace('/[^a-zA-Z0-9]/', '', $pemilik);
    $a_clean = preg_replace('/[^a-zA-Z0-9]/', '', $alias);
    $kolom_db = 'modal_' . strtolower($p_clean . $a_clean);

    try { $conn->query("ALTER TABLE shifts ADD $kolom_db DOUBLE DEFAULT 0"); } catch (Exception $e) {}

    $insert = $conn->query("INSERT INTO rekening (pemilik, bank, nomor_rekening, alias, kolom_db) VALUES ('$pemilik', '$bank', '$no_rek', '$alias', '$kolom_db')");
    
    if($insert) {
        $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Rekening berhasil ditambahkan & tersinkronisasi!'];
    } else {
        $_SESSION['notif'] = ['type' => 'error', 'msg' => 'Gagal menambahkan data rekening.'];
    }
    header("Location: kelola_rekening.php"); exit;
}

// ==========================================
// 4. PROSES EDIT DATA REKENING
// ==========================================
if (isset($_POST['edit_rekening'])) {
    $id_rek  = (int)$_POST['id_rekening'];
    $pemilik = $conn->real_escape_string(trim($_POST['pemilik']));
    $bank    = $conn->real_escape_string(trim($_POST['bank']));
    $no_rek  = $conn->real_escape_string(trim($_POST['no_rek']));
    $alias   = $conn->real_escape_string(trim($_POST['alias'])); 

    $update = $conn->query("UPDATE rekening SET pemilik = '$pemilik', bank = '$bank', nomor_rekening = '$no_rek', alias = '$alias' WHERE id = $id_rek");
    
    if($update) {
        $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Perubahan data rekening berhasil disimpan!'];
    } else {
        $_SESSION['notif'] = ['type' => 'error', 'msg' => 'Gagal memperbarui data rekening.'];
    }
    header("Location: kelola_rekening.php"); exit;
}

// ==========================================
// 5. PROSES HAPUS REKENING
// ==========================================
if (isset($_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    $conn->query("DELETE FROM rekening WHERE id = $id_hapus");
    
    $_SESSION['notif'] = ['type' => 'success', 'msg' => 'Rekening berhasil dihapus dari daftar.'];
    header("Location: kelola_rekening.php"); exit;
}

// ==========================================
// 6. MENGAMBIL & MENGELOMPOKKAN DATA
// ==========================================
$q_rekening = $conn->query("SELECT * FROM rekening ORDER BY pemilik ASC, bank ASC");

$data_rekening = [];
$daftar_pemilik = [];
$semua_rekening_flat = [];

if ($q_rekening && $q_rekening->num_rows > 0) {
    while($row = $q_rekening->fetch_assoc()) {
        $pemilik = $row['pemilik'];
        $data_rekening[$pemilik][] = $row;
        $semua_rekening_flat[] = $row;
        
        if (!in_array($pemilik, $daftar_pemilik)) {
            $daftar_pemilik[] = $pemilik;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Kelola Rekening - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css" rel="stylesheet">
    
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
        
        .modern-card {
            background: var(--bri-white);
            border-radius: 24px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            padding: 24px;
            transition: all 0.3s ease;
        }
        .modern-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 82, 156, 0.08);
            border: 1px solid var(--bri-light-blue);
        }

        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: var(--bri-white); border-radius: 50px; padding: 12px 28px; font-weight: 700; transition: all 0.3s ease; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); color: white;}
        .form-control, .form-select { border-radius: 14px; border: 1px solid var(--bri-grey); background-color: var(--bg-body); padding: 12px 15px; color: var(--bri-black); font-weight: 600; }
        .form-control:focus, .form-select:focus { box-shadow: none; border-color: var(--bri-blue); }

        .bank-badge { font-weight: 800; padding: 6px 14px; border-radius: 50px; font-size: 11px; letter-spacing: 0.5px; }
        .bg-bri { background-color: #00529C; color: white; }
        .bg-bca { background-color: #0066AE; color: white; }
        .bg-mandiri { background-color: #F2A900; color: #fff; }
        .bg-bni { background-color: #F15A24; color: white; }
        .bg-bsi { background-color: #00A651; color: white; }
        .bg-default { background-color: #607D8B; color: white; } 

        .nav-pills { gap: 12px; margin-bottom: 30px; }
        .nav-pills .nav-link { border-radius: 50px; color: #6c757d; font-weight: 600; padding: 10px 24px; background-color: var(--bri-white); border: 1px solid var(--bri-grey); transition: all 0.3s ease; font-size: 14px; }
        .nav-pills .nav-link:hover { background-color: var(--bri-light-blue); color: var(--bri-blue); }
        .nav-pills .nav-link.active { background-color: var(--bri-blue); color: var(--bri-white); border-color: var(--bri-blue); box-shadow: 0 4px 12px rgba(0, 82, 156, 0.3); }

        .modal-content { border-radius: 24px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .modal-header { border-bottom: 1px solid var(--bri-grey); padding: 20px 24px; }
        .modal-body { padding: 24px; }

        /* Custom SweetAlert Font */
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 16px !important; }

        @media (max-width: 767.98px) {
            .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; }
            .btn-modern { width: 100%; text-align: center; }
            .nav-pills { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 10px; }
            .nav-pills::-webkit-scrollbar { display: none; }
            .nav-link { white-space: nowrap; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Manajemen Rekening Master</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Kelola dan filter pembagian rekening berdasarkan pemiliknya</p>
            </div>
            
            <button class="btn btn-modern align-self-start" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-lg me-2"></i> Tambah Rekening
            </button>
        </div>

        <ul class="nav nav-pills" id="pills-tab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="pills-semua-tab" data-bs-toggle="pill" data-bs-target="#pills-semua" type="button" role="tab"><i class="bi bi-grid-fill me-2"></i>Semua Rekening</button>
            </li>
            <?php foreach($daftar_pemilik as $index => $pem): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pills-<?= md5($pem); ?>-tab" data-bs-toggle="pill" data-bs-target="#pills-<?= md5($pem); ?>" type="button" role="tab">
                    <i class="bi bi-person-fill me-2"></i><?= htmlspecialchars($pem); ?>
                </button>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content" id="pills-tabContent">
            
            <div class="tab-pane fade show active" id="pills-semua" role="tabpanel">
                <div class="row g-4">
                    <?php if(!empty($data_rekening)): foreach($data_rekening as $pemilik => $rekenings): foreach($rekenings as $rek): 
                        $bank_lower = strtolower($rek['bank']);
                        $badge_class = 'bg-default';
                        if($bank_lower == 'bri') $badge_class = 'bg-bri';
                        elseif($bank_lower == 'bca') $badge_class = 'bg-bca';
                        elseif($bank_lower == 'mandiri') $badge_class = 'bg-mandiri';
                        elseif($bank_lower == 'bni') $badge_class = 'bg-bni';
                        elseif($bank_lower == 'bsi') $badge_class = 'bg-bsi';
                    ?>
                    <div class="col-md-6 col-lg-4 col-xl-3">
                        <div class="modern-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="bank-badge <?= $badge_class; ?>"><?= strtoupper($rek['bank']); ?></span>
                                    <h5 class="fw-bolder mt-3 mb-1" style="color: var(--bri-black);"><?= htmlspecialchars($rek['alias']); ?></h5>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-warning rounded-circle p-2" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $rek['id']; ?>" style="line-height: 1;"><i class="bi bi-pencil-fill"></i></button>
                                    <a href="?hapus=<?= $rek['id']; ?>" class="btn btn-sm btn-outline-danger rounded-circle p-2 btn-hapus" style="line-height: 1;"><i class="bi bi-trash3-fill"></i></a>
                                </div>
                            </div>
                            
                            <div class="p-3 mt-3 rounded-4" style="background-color: var(--bri-light-blue); border: 1px dashed #b3d4ff;">
                                <small class="text-muted fw-bold d-block mb-1" style="font-size: 11px;">NO REKENING</small>
                                <span class="fw-extrabold fs-5 font-monospace" style="color: var(--bri-blue); letter-spacing: 1px;"><?= htmlspecialchars($rek['nomor_rekening']); ?></span>
                            </div>
                            
                            <div class="mt-4 d-flex justify-content-between align-items-end">
                                <div>
                                    <small class="text-muted fw-bold d-block" style="font-size: 10px;">KOLOM DATABASE</small>
                                    <span class="badge bg-light text-dark border px-2 py-1 mt-1"><i class="bi bi-database me-1"></i><?= $rek['kolom_db']; ?></span>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted fw-bold d-block" style="font-size: 10px;">PEMILIK ASET</small>
                                    <span class="fw-bold" style="color: var(--bri-black); font-size: 13px;"><?= htmlspecialchars($rek['pemilik']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endforeach; else: ?>
                    <div class="col-12 text-center py-5">
                        <div class="bg-white rounded-circle d-inline-flex justify-content-center align-items-center mb-3 shadow-sm" style="width: 80px; height: 80px;">
                            <i class="bi bi-wallet2 text-muted fs-1"></i>
                        </div>
                        <h5 class="fw-bold text-dark">Belum ada rekening</h5>
                        <p class="text-muted fw-medium">Silakan tambah data rekening baru untuk mulai mengelola.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach($data_rekening as $pemilik => $rekenings): ?>
            <div class="tab-pane fade" id="pills-<?= md5($pemilik); ?>" role="tabpanel">
                <div class="row g-4">
                    <?php foreach($rekenings as $rek): 
                        $bank_lower = strtolower($rek['bank']);
                        $badge_class = 'bg-default';
                        if($bank_lower == 'bri') $badge_class = 'bg-bri';
                        elseif($bank_lower == 'bca') $badge_class = 'bg-bca';
                        elseif($bank_lower == 'mandiri') $badge_class = 'bg-mandiri';
                        elseif($bank_lower == 'bni') $badge_class = 'bg-bni';
                        elseif($bank_lower == 'bsi') $badge_class = 'bg-bsi';
                    ?>
                    <div class="col-md-6 col-lg-4 col-xl-3">
                        <div class="modern-card h-100">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="bank-badge <?= $badge_class; ?>"><?= strtoupper($rek['bank']); ?></span>
                                    <h5 class="fw-bolder mt-3 mb-1" style="color: var(--bri-black);"><?= htmlspecialchars($rek['alias']); ?></h5>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-warning rounded-circle p-2" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $rek['id']; ?>" style="line-height: 1;"><i class="bi bi-pencil-fill"></i></button>
                                    <a href="?hapus=<?= $rek['id']; ?>" class="btn btn-sm btn-outline-danger rounded-circle p-2 btn-hapus" style="line-height: 1;"><i class="bi bi-trash3-fill"></i></a>
                                </div>
                            </div>
                            
                            <div class="p-3 mt-3 rounded-4" style="background-color: var(--bri-light-blue); border: 1px dashed #b3d4ff;">
                                <small class="text-muted fw-bold d-block mb-1" style="font-size: 11px;">NO REKENING</small>
                                <span class="fw-extrabold fs-5 font-monospace" style="color: var(--bri-blue); letter-spacing: 1px;"><?= htmlspecialchars($rek['nomor_rekening']); ?></span>
                            </div>
                            
                            <div class="mt-4 d-flex justify-content-between align-items-end">
                                <div>
                                    <small class="text-muted fw-bold d-block" style="font-size: 10px;">KOLOM DATABASE</small>
                                    <span class="badge bg-light text-dark border px-2 py-1 mt-1"><i class="bi bi-database me-1"></i><?= $rek['kolom_db']; ?></span>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted fw-bold d-block" style="font-size: 10px;">PEMILIK ASET</small>
                                    <span class="fw-bold" style="color: var(--bri-black); font-size: 13px;"><?= htmlspecialchars($rek['pemilik']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
        </div>
    </div>

    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bolder" style="color: var(--bri-blue);"><i class="bi bi-wallet2 me-2"></i>Tambah Rekening Baru</h5>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Nama Pemilik</label>
                            <input class="form-control form-control-lg" list="listPemilik" name="pemilik" placeholder="Contoh: Bos A" required>
                            <small class="text-muted mt-1 d-block" style="font-size: 11px;"><i class="bi bi-info-circle me-1"></i>Ketik nama baru atau pilih nama yang sudah ada.</small>
                            <datalist id="listPemilik">
                                <?php foreach($daftar_pemilik as $pem): ?>
                                    <option value="<?= htmlspecialchars($pem); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Jenis Bank</label>
                                <select class="form-select form-select-lg" name="bank" required>
                                    <option value="" selected disabled>Pilih Bank...</option>
                                    <option value="BRI">BRI</option>
                                    <option value="BCA">BCA</option>
                                    <option value="Mandiri">Mandiri</option>
                                    <option value="BNI">BNI</option>
                                    <option value="BSI">BSI</option>
                                    <option value="Lainnya">Lainnya</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Nama Alias (Display)</label>
                                <input type="text" class="form-control form-control-lg" name="alias" placeholder="Contoh: BRI 1" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Nomor Rekening</label>
                            <input type="number" class="form-control form-control-lg font-monospace" name="no_rek" placeholder="Ketik angka..." required>
                        </div>

                    </div>
                    <div class="modal-footer border-top-0 pt-0 px-4 pb-4">
                        <button type="submit" name="tambah_rekening" class="btn btn-modern w-100 fs-6 py-3">Simpan Rekening ke Sistem</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach($semua_rekening_flat as $rek): ?>
    <div class="modal fade" id="modalEdit<?= $rek['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bolder text-warning"><i class="bi bi-pencil-square me-2"></i>Edit Data Rekening</h5>
                    <button type="button" class="btn-close bg-light rounded-circle p-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <input type="hidden" name="id_rekening" value="<?= $rek['id']; ?>">
                    <div class="modal-body">
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Nama Pemilik</label>
                            <input class="form-control form-control-lg" list="listPemilikEdit" name="pemilik" value="<?= htmlspecialchars($rek['pemilik']); ?>" required>
                            <datalist id="listPemilikEdit">
                                <?php foreach($daftar_pemilik as $pem): ?>
                                    <option value="<?= htmlspecialchars($pem); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Jenis Bank</label>
                                <select class="form-select form-select-lg" name="bank" required>
                                    <option value="BRI" <?= $rek['bank'] == 'BRI' ? 'selected' : ''; ?>>BRI</option>
                                    <option value="BCA" <?= $rek['bank'] == 'BCA' ? 'selected' : ''; ?>>BCA</option>
                                    <option value="Mandiri" <?= $rek['bank'] == 'Mandiri' ? 'selected' : ''; ?>>Mandiri</option>
                                    <option value="BNI" <?= $rek['bank'] == 'BNI' ? 'selected' : ''; ?>>BNI</option>
                                    <option value="BSI" <?= $rek['bank'] == 'BSI' ? 'selected' : ''; ?>>BSI</option>
                                    <option value="Lainnya" <?= $rek['bank'] == 'Lainnya' ? 'selected' : ''; ?>>Lainnya</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Nama Alias (Display)</label>
                                <input type="text" class="form-control form-control-lg" name="alias" value="<?= htmlspecialchars($rek['alias']); ?>" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold" style="color: var(--bri-black); font-size: 14px;">Nomor Rekening</label>
                            <input type="number" class="form-control form-control-lg font-monospace" name="no_rek" value="<?= htmlspecialchars($rek['nomor_rekening']); ?>" required>
                        </div>
                        
                        <div class="mt-3 p-3 bg-light rounded text-muted" style="font-size: 12px;">
                            <i class="bi bi-info-circle me-1"></i> <strong>Catatan:</strong> Nama kolom database (<code><?= $rek['kolom_db']; ?></code>) tidak dapat diubah dari sini untuk menjaga integritas data histori saldo pekerja.
                        </div>

                    </div>
                    <div class="modal-footer border-top-0 pt-0 px-4 pb-4">
                        <button type="submit" name="edit_rekening" class="btn btn-warning w-100 fs-6 py-3 fw-bold text-dark rounded-pill shadow-sm">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>
    
    <script>
        // 1. Toast Notification untuk Notifikasi Berhasil/Gagal
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

        // 2. Alert Konfirmasi Hapus Rekening
        document.querySelectorAll('.btn-hapus').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault(); 
                const url = this.getAttribute('href');
                
                Swal.fire({
                    title: 'Hapus Rekening Ini?',
                    text: "Rekening akan dihapus dari sistem, namun riwayat saldo di laporan shift tidak akan hilang.",
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
    </script>
</body>
</html>