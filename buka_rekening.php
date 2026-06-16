<?php
mysqli_report(MYSQLI_REPORT_OFF);
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] == 'admin');
$tanggal_hari_ini = date('Y-m-d');

// 1. AUTO-CREATE TABLE BUKA REKENING
$conn->query("CREATE TABLE IF NOT EXISTS buka_rekening (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    cabang_id INT NOT NULL,
    tanggal_daftar DATE NOT NULL,
    nama_nasabah VARCHAR(100) NOT NULL,
    nik VARCHAR(50) NOT NULL,
    no_hp VARCHAR(20) NOT NULL,
    jenis_layanan VARCHAR(50) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Diproses',
    tanggal_selesai DATE NULL,
    foto_bukti VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Cek & Tambah Kolom Foto
$check_foto = $conn->query("SHOW COLUMNS FROM buka_rekening LIKE 'foto_bukti'");
if ($check_foto && $check_foto->num_rows == 0) {
    $conn->query("ALTER TABLE buka_rekening ADD foto_bukti VARCHAR(255) NULL DEFAULT NULL");
}

// Dapatkan Cabang ID Kasir
$q_user = $conn->query("SELECT cabang_id FROM users WHERE id = '$user_id'");
$cabang_id_user = ($q_user && $row = $q_user->fetch_assoc()) ? $row['cabang_id'] : 0;

// 2. PROSES TAMBAH PENDAFTARAN BARU
if (isset($_POST['tambah_pendaftaran'])) {
    $nama = $conn->real_escape_string(trim($_POST['nama_nasabah']));
    $nik = $conn->real_escape_string(trim($_POST['nik']));
    $hp = $conn->real_escape_string(trim($_POST['no_hp']));
    $layanan = $conn->real_escape_string($_POST['jenis_layanan']);
    
    $cab_input = $is_admin ? (int)$_POST['cabang_id'] : $cabang_id_user;

    $stmt = $conn->prepare("INSERT INTO buka_rekening (user_id, cabang_id, tanggal_daftar, nama_nasabah, nik, no_hp, jenis_layanan) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssss", $user_id, $cab_input, $tanggal_hari_ini, $nama, $nik, $hp, $layanan);
    
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        
        $q_cn = $conn->query("SELECT nama_cabang FROM cabang WHERE id = '$cab_input'");
        $nama_cabang_cetak = ($q_cn && $r_cn = $q_cn->fetch_assoc()) ? $r_cn['nama_cabang'] : 'AGEN BRILINK';

        $_SESSION['print_daftar'] = [
            'id' => $new_id, 
            'nama' => $nama, 
            'nik' => $nik, 
            'hp' => $hp,
            'layanan' => $layanan,
            'cabang' => $nama_cabang_cetak
        ];
        $_SESSION['flash_success'] = "Pendaftaran berhasil dicatat!";
    } else {
        $_SESSION['flash_error'] = "Gagal menyimpan pendaftaran.";
    }
    header("Location: buka_rekening.php"); exit;
}

// 3. PROSES UBAH STATUS & UPLOAD FOTO BUKTI
if (isset($_POST['ubah_status'])) {
    $id_rek = (int)$_POST['id_rekening'];
    $status_baru = $conn->real_escape_string($_POST['status']);
    $tgl_selesai = ($status_baru == 'Selesai') ? "'$tanggal_hari_ini'" : "NULL";

    $query_update = "UPDATE buka_rekening SET status = '$status_baru', tanggal_selesai = $tgl_selesai";

    if ($status_baru == 'Selesai' && isset($_FILES['foto_bukti']) && $_FILES['foto_bukti']['error'] == 0) {
        $target_dir = "uploads/bukti_rekening/";
        if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
        
        $file_extension = pathinfo($_FILES["foto_bukti"]["name"], PATHINFO_EXTENSION);
        $new_filename = "BUKTI_REK_" . $id_rek . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;

        $check = getimagesize($_FILES["foto_bukti"]["tmp_name"]);
        if($check !== false) {
            if (move_uploaded_file($_FILES["foto_bukti"]["tmp_name"], $target_file)) {
                $query_update .= ", foto_bukti = '$target_file'";
            }
        } else {
            $_SESSION['flash_error'] = "File yang diunggah bukan gambar valid.";
            header("Location: buka_rekening.php"); exit;
        }
    }

    $query_update .= " WHERE id = '$id_rek'";
    $conn->query($query_update);

    $_SESSION['flash_success'] = "Status pendaftaran berhasil diperbarui!";
    header("Location: buka_rekening.php"); exit;
}

// 4. MENGAMBIL DATA UNTUK DITAMPILKAN
$filter_cabang = $is_admin ? (isset($_GET['cabang']) ? $_GET['cabang'] : 'semua') : $cabang_id_user;
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'semua';

$where = "1=1";
if ($filter_cabang !== 'semua') { $where .= " AND b.cabang_id = '$filter_cabang'"; }
if ($filter_status !== 'semua') { $where .= " AND b.status = '$filter_status'"; }

$q_data = $conn->query("
    SELECT b.*, c.nama_cabang, u.username as nama_kasir 
    FROM buka_rekening b
    LEFT JOIN cabang c ON b.cabang_id = c.id
    LEFT JOIN users u ON b.user_id = u.id
    WHERE $where
    ORDER BY b.id DESC
");

$where_metric = $is_admin ? "1=1" : "cabang_id = '$cabang_id_user'";
$tot_daftar = $conn->query("SELECT COUNT(id) as c FROM buka_rekening WHERE $where_metric")->fetch_assoc()['c'] ?? 0;
$tot_proses = $conn->query("SELECT COUNT(id) as c FROM buka_rekening WHERE status='Diproses' AND $where_metric")->fetch_assoc()['c'] ?? 0;
$tot_ready  = $conn->query("SELECT COUNT(id) as c FROM buka_rekening WHERE status='Siap Diambil' AND $where_metric")->fetch_assoc()['c'] ?? 0;
$tot_selesai= $conn->query("SELECT COUNT(id) as c FROM buka_rekening WHERE status='Selesai' AND $where_metric")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Pendaftaran Rekening - BRILink</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; --bri-light-blue: #e6f0ff; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 25px; border: none; }
        
        .metric-box { border-radius: 16px; padding: 20px; display: flex; align-items: center; gap: 15px; color: white; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .bg-gradient-1 { background: linear-gradient(135deg, #00529C, #003366); }
        .bg-gradient-2 { background: linear-gradient(135deg, #f39c12, #fd7e14); }
        .bg-gradient-3 { background: linear-gradient(135deg, #0dcaf0, #0097b2); color: white; }
        .bg-gradient-4 { background: linear-gradient(135deg, #198754, #20c997); }

        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 50px; padding: 10px 24px; font-weight: 700; border: none; transition: all 0.3s; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 82, 156, 0.3); color: white; }
        
        .form-control, .form-select { border-radius: 12px; padding: 10px 15px; font-weight: 600; background-color: var(--bg-body); border: 1px solid #dee2e6; }
        .form-control:focus, .form-select:focus { border-color: var(--bri-blue); box-shadow: none; }
        
        .table-custom th { background-color: var(--bri-light-blue); color: var(--bri-blue); text-transform: uppercase; font-size: 12px; font-weight: 700; }
        .table-custom td { vertical-align: middle; font-size: 14px; font-weight: 500; }

        #print-area { display: none; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0; padding: 20px 15px; padding-top: 85px; } }

        @media print {
            body.printing-struk > :not(#print-area) { display: none !important; }
            body.printing-struk { background-color: #fff !important; margin: 0; padding: 0; }
            #print-area { display: flex !important; justify-content: center; align-items: center; width: 100% !important; margin: 0; padding: 0; }
            @page { size: 8.5in 5.5in; margin: 0; } 
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);"><i class="bi bi-person-vcard text-primary me-2"></i>Pendaftaran Rekening</h3>
                <p class="text-muted mb-0 fw-medium">Kelola nasabah yang mendaftar pembuatan buku rekening baru.</p>
            </div>
            <button class="btn btn-modern shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-lg me-1"></i> Input Pendaftar Baru
            </button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="metric-box bg-gradient-1">
                    <i class="bi bi-people fs-1 opacity-75"></i>
                    <div><h4 class="fw-bolder mb-0"><?= $tot_daftar ?></h4><small class="fw-bold opacity-75">Total Daftar</small></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="metric-box bg-gradient-2">
                    <i class="bi bi-hourglass-split fs-1 opacity-75"></i>
                    <div><h4 class="fw-bolder mb-0"><?= $tot_proses ?></h4><small class="fw-bold opacity-75">Diproses Bank</small></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="metric-box bg-gradient-3">
                    <i class="bi bi-box-seam fs-1 opacity-75"></i>
                    <div><h4 class="fw-bolder mb-0"><?= $tot_ready ?></h4><small class="fw-bold opacity-75">Siap Diambil</small></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="metric-box bg-gradient-4">
                    <i class="bi bi-check-circle fs-1 opacity-75"></i>
                    <div><h4 class="fw-bolder mb-0"><?= $tot_selesai ?></h4><small class="fw-bold opacity-75">Telah Diambil</small></div>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <form method="GET" class="row g-2 align-items-end mb-4 bg-light p-3 rounded-4">
                <?php if($is_admin): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Cabang</label>
                    <select name="cabang" class="form-select">
                        <option value="semua">Semua Cabang</option>
                        <?php 
                        $qc = $conn->query("SELECT * FROM cabang");
                        while($c = $qc->fetch_assoc()): ?>
                            <option value="<?= $c['id'] ?>" <?= $filter_cabang == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_cabang']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Status Rekening</label>
                    <select name="status" class="form-select">
                        <option value="semua" <?= $filter_status == 'semua' ? 'selected' : '' ?>>Semua Status</option>
                        <option value="Diproses" <?= $filter_status == 'Diproses' ? 'selected' : '' ?>>⏳ Diproses Bank</option>
                        <option value="Siap Diambil" <?= $filter_status == 'Siap Diambil' ? 'selected' : '' ?>>📦 Siap Diambil di Agen</option>
                        <option value="Selesai" <?= $filter_status == 'Selesai' ? 'selected' : '' ?>>✅ Selesai / Diambil</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-3 fw-bold"><i class="bi bi-funnel me-1"></i> Filter</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover table-custom text-nowrap">
                    <thead>
                        <tr>
                            <th>No. Reg</th>
                            <th>Data Nasabah</th>
                            <th>Jenis Layanan</th>
                            <th>Status & Lokasi</th>
                            <th class="text-center">Aksi / Operator</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($q_data && $q_data->num_rows > 0): while($row = $q_data->fetch_assoc()): 
                            $bg_status = 'bg-warning text-dark';
                            if($row['status'] == 'Siap Diambil') $bg_status = 'bg-info text-dark';
                            if($row['status'] == 'Selesai') $bg_status = 'bg-success text-white';
                        ?>
                        <tr>
                            <td class="text-muted fw-bold">REG-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></td>
                            <td>
                                <span class="fw-bolder text-dark d-block"><?= htmlspecialchars($row['nama_nasabah']) ?></span>
                                <small class="text-muted"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($row['nik']) ?> | <i class="bi bi-telephone"></i> <?= htmlspecialchars($row['no_hp']) ?></small>
                            </td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($row['jenis_layanan']) ?></td>
                            <td>
                                <span class="badge <?= $bg_status ?> px-3 py-2 rounded-pill mb-1"><?= $row['status'] ?></span>
                                <small class="d-block text-muted fw-bold" style="font-size: 11px;">📍 <?= htmlspecialchars($row['nama_cabang'] ?? 'Cabang') ?></small>
                            </td>
                            <td class="text-center">
                                <div class="btn-group shadow-sm">
                                    <button class="btn btn-sm btn-light border fw-bold" data-bs-toggle="modal" data-bs-target="#modalStatus<?= $row['id'] ?>"><i class="bi bi-pencil-square text-warning"></i> Status</button>
                                    
                                    <?php if($row['status'] != 'Selesai'): ?>
                                        <button class="btn btn-sm btn-light border fw-bold text-primary" onclick="cetakPendaftaran('<?= $row['id'] ?>', '<?= date('d M Y', strtotime($row['created_at'])) ?>', '<?= htmlspecialchars(addslashes($row['nama_nasabah'])) ?>', '<?= htmlspecialchars(addslashes($row['nik'])) ?>', '<?= htmlspecialchars(addslashes($row['no_hp'])) ?>', '<?= htmlspecialchars(addslashes($row['jenis_layanan'])) ?>', '<?= htmlspecialchars(addslashes($row['nama_cabang'])) ?>')"><i class="bi bi-printer-fill"></i> Cetak Pendaftaran</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-light border fw-bold text-success" onclick="cetakPengambilan('<?= $row['id'] ?>', '<?= date('d M Y', strtotime($row['tanggal_selesai'])) ?>', '<?= htmlspecialchars(addslashes($row['nama_nasabah'])) ?>', '<?= htmlspecialchars(addslashes($row['nik'])) ?>', '<?= htmlspecialchars(addslashes($row['no_hp'])) ?>', '<?= htmlspecialchars(addslashes($row['jenis_layanan'])) ?>', '<?= htmlspecialchars(addslashes($row['nama_cabang'])) ?>')"><i class="bi bi-printer-fill"></i> Cetak Bukti</button>
                                        
                                        <?php if(!empty($row['foto_bukti'])): ?>
                                            <button class="btn btn-sm btn-light border fw-bold text-info" onclick="lihatFoto('<?= $row['foto_bukti'] ?>')"><i class="bi bi-image"></i> Foto</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <div class="modal fade" id="modalStatus<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-sm">
                                <div class="modal-content border-0">
                                    <div class="modal-header border-bottom-0">
                                        <h6 class="fw-bold mb-0">Update Status Buku</h6>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body pt-0">
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="id_rekening" value="<?= $row['id'] ?>">
                                            <p class="small text-muted mb-3">Atas Nama: <strong><?= htmlspecialchars($row['nama_nasabah']) ?></strong></p>
                                            
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="status" value="Diproses" id="st1<?= $row['id'] ?>" <?= $row['status'] == 'Diproses' ? 'checked' : '' ?> onclick="toggleFoto(<?= $row['id'] ?>, 'Diproses')">
                                                <label class="form-check-label fw-bold text-warning" for="st1<?= $row['id'] ?>">Diproses Bank</label>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="status" value="Siap Diambil" id="st2<?= $row['id'] ?>" <?= $row['status'] == 'Siap Diambil' ? 'checked' : '' ?> onclick="toggleFoto(<?= $row['id'] ?>, 'Siap Diambil')">
                                                <label class="form-check-label fw-bold text-info" for="st2<?= $row['id'] ?>">Sudah Jadi (Di Agen)</label>
                                            </div>
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="radio" name="status" value="Selesai" id="st3<?= $row['id'] ?>" <?= $row['status'] == 'Selesai' ? 'checked' : '' ?> onclick="toggleFoto(<?= $row['id'] ?>, 'Selesai')">
                                                <label class="form-check-label fw-bold text-success" for="st3<?= $row['id'] ?>">Telah Diambil Nasabah</label>
                                            </div>

                                            <div class="mb-4 bg-light p-2 rounded-3" id="formFoto<?= $row['id'] ?>" style="display: <?= $row['status'] == 'Selesai' ? 'block' : 'none' ?>;">
                                                <label class="form-label small fw-bold text-dark"><i class="bi bi-camera text-primary"></i> Upload Bukti Foto (Opsional)</label>
                                                <input type="file" name="foto_bukti" class="form-control form-control-sm" accept="image/*" capture="environment">
                                                <small class="text-muted" style="font-size: 10px;">Foto nasabah memegang buku/ATM.</small>
                                            </div>

                                            <button type="submit" name="ubah_status" class="btn btn-dark w-100 rounded-pill">Simpan Status</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-5">Tidak ada data pendaftaran rekening.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                    <h5 class="modal-title fw-bolder text-dark"><i class="bi bi-person-plus text-primary me-2"></i>Pendaftar Rekening Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <?php if($is_admin): ?>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Cabang Penitipan Buku</label>
                            <select name="cabang_id" class="form-select bg-light" required>
                                <?php 
                                $qc = $conn->query("SELECT * FROM cabang");
                                while($c = $qc->fetch_assoc()): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nama_cabang']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Nama Lengkap Nasabah</label>
                            <input type="text" name="nama_nasabah" class="form-control" placeholder="Sesuai KTP" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-muted small fw-bold">Nomor KTP (NIK)</label>
                                <input type="number" name="nik" class="form-control" placeholder="16 Digit NIK" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small fw-bold">Nomor HP / WA</label>
                                <input type="number" name="no_hp" class="form-control" placeholder="08..." required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold">Pilih Jenis Layanan</label>
                            <select name="jenis_layanan" class="form-select" required>
                                <option value="Buka Rekening + ATM">Buka Buku Rekening + ATM</option>
                                <option value="Buka Rekening Saja">Hanya Buka Rekening</option>
                                <option value="Pembuatan ATM Saja">Hanya Pembuatan ATM</option>
                                <option value="Ganti ATM Rusak/Hilang">Ganti ATM Rusak / Hilang</option>
                            </select>
                        </div>
                        <button type="submit" name="tambah_pendaftaran" class="btn btn-modern w-100 py-3 fs-5 rounded-pill">Simpan & Cetak Form</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="print-area"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleFoto(id, status) {
            let container = document.getElementById('formFoto' + id);
            if (status === 'Selesai') container.style.display = 'block';
            else container.style.display = 'none';
        }

        function lihatFoto(url) {
            Swal.fire({
                title: 'Bukti Serah Terima',
                imageUrl: url,
                imageWidth: '100%',
                imageAlt: 'Bukti Foto',
                confirmButtonColor: '#00529C',
                confirmButtonText: 'Tutup'
            });
        }

        function setPrintPageStyle(styleRule) {
            let style = document.getElementById('dynamic-print-style');
            if (!style) { style = document.createElement('style'); style.id = 'dynamic-print-style'; document.head.appendChild(style); }
            style.innerHTML = styleRule;
        }

        function cetakPendaftaran(id, tanggal, nama, nik, hp, layanan, cabang) {
            setPrintPageStyle('@page { size: 8.5in 5.5in; margin: 0.1in; }');
            let htmlPrint = `
                <div style="width: 8in; height: 5.2in; margin: 0.1in auto; padding: 0.2in; border: 3px solid #000; font-family: Arial, sans-serif; color: #000; box-sizing: border-box; background-color: #fff;">
                    <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px;">
                        <img src="assets/img/logo-katanyang.png" alt="Logo" style="height: 45px; filter: grayscale(100%); margin-bottom: 8px;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: bold; text-decoration: underline; letter-spacing: 1px;">TANDA TERIMA</h3>
                        <h2 style="margin: 5px 0; font-size: 20px; font-weight: 900; letter-spacing: 1px;">PENDAFTARAN BUKU REKENING</h2>
                        <p style="margin: 0; font-size: 14px; font-weight: bold;">AGEN BRILINK ${cabang.toUpperCase()}</p>
                    </div>
                    
                    <table style="width: 100%; font-size: 14px; font-weight: bold; margin-bottom: 15px; border-collapse: collapse;">
                        <tr><td style="padding: 4px 0; width: 140px;">TGL DAFTAR</td><td style="width: 15px;">:</td><td>${tanggal}</td></tr>
                        <tr><td style="padding: 4px 0;">NO. REGISTRASI</td><td>:</td><td>REG-${id.padStart(4, '0')}</td></tr>
                        <tr><td style="padding: 4px 0;">NAMA NASABAH</td><td>:</td><td style="text-transform: uppercase;">${nama}</td></tr>
                        <tr><td style="padding: 4px 0;">N. I. K</td><td>:</td><td>${nik}</td></tr>
                        <tr><td style="padding: 4px 0;">NO. HP</td><td>:</td><td>${hp}</td></tr>
                        <tr><td style="padding: 4px 0;">LAYANAN</td><td>:</td><td style="text-transform: uppercase;">${layanan}</td></tr>
                    </table>
                    
                    <table style="width: 100%; text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 10px; border-collapse: collapse;">
                        <tr>
                            <td style="width: 50%;">PENDAFTAR</td>
                            <td style="width: 50%;">PETUGAS</td>
                        </tr>
                        <tr>
                            <td style="padding-top: 60px; text-decoration: underline; text-transform: uppercase;">${nama}</td>
                            <td style="padding-top: 60px;">( ................................... )</td>
                        </tr>
                    </table>
                    
                    <div style="text-align: center; font-size: 12px; font-style: italic; font-weight: bold; border-top: 1px dashed #000; padding-top: 10px;">
                        * PERHATIAN: Tanda terima ini harap dibawa beserta KTP ASLI pada saat pengambilan buku tabungan / ATM.
                    </div>
                </div>
            `;
            let printArea = document.getElementById('print-area');
            printArea.innerHTML = htmlPrint;
            document.body.classList.add('printing-struk');
            setTimeout(function() { window.print(); }, 500);
            window.onafterprint = function() { document.body.classList.remove('printing-struk'); printArea.innerHTML = ''; };
        }

        function cetakPengambilan(id, tanggal, nama, nik, hp, layanan, cabang) {
            setPrintPageStyle('@page { size: 8.5in 5.5in; margin: 0.1in; }');
            let htmlPrint = `
                <div style="width: 8in; height: 5.2in; margin: 0.1in auto; padding: 0.2in; border: 3px solid #000; font-family: Arial, sans-serif; color: #000; box-sizing: border-box; background-color: #fff;">
                    <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px;">
                        <img src="assets/img/logo-katanyang.png" alt="Logo" style="height: 45px; filter: grayscale(100%); margin-bottom: 8px;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: bold; text-decoration: underline; letter-spacing: 1px;">BUKTI PENYERAHAN</h3>
                        <h2 style="margin: 5px 0; font-size: 20px; font-weight: 900; letter-spacing: 1px;">BUKU REKENING / KARTU ATM</h2>
                        <p style="margin: 0; font-size: 14px; font-weight: bold;">AGEN BRILINK ${cabang.toUpperCase()}</p>
                    </div>
                    
                    <table style="width: 100%; font-size: 14px; font-weight: bold; margin-bottom: 15px; border-collapse: collapse;">
                        <tr><td style="padding: 4px 0; width: 140px;">TGL DIAMBIL</td><td style="width: 15px;">:</td><td>${tanggal}</td></tr>
                        <tr><td style="padding: 4px 0;">NO. REGISTRASI</td><td>:</td><td>REG-${id.padStart(4, '0')}</td></tr>
                        <tr><td style="padding: 4px 0;">NAMA NASABAH</td><td>:</td><td style="text-transform: uppercase;">${nama}</td></tr>
                        <tr><td style="padding: 4px 0;">N. I. K</td><td>:</td><td>${nik}</td></tr>
                        <tr><td style="padding: 4px 0;">NO. HP</td><td>:</td><td>${hp}</td></tr>
                        <tr><td style="padding: 4px 0;">LAYANAN</td><td>:</td><td style="text-transform: uppercase;">${layanan}</td></tr>
                    </table>
                    
                    <table style="width: 100%; text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 10px; border-collapse: collapse;">
                        <tr>
                            <td style="width: 50%;">PENERIMA</td>
                            <td style="width: 50%;">PETUGAS PENYERAH</td>
                        </tr>
                        <tr>
                            <td style="padding-top: 60px; text-decoration: underline; text-transform: uppercase;">${nama}</td>
                            <td style="padding-top: 60px;">( ................................... )</td>
                        </tr>
                    </table>
                    
                    <div style="text-align: center; font-size: 12px; font-style: italic; font-weight: bold; border-top: 1px dashed #000; padding-top: 10px;">
                        "Saya menyatakan bahwa Buku Tabungan / Kartu ATM telah saya terima dalam keadaan aktif, tersegel, dan sesuai dengan identitas diri."
                    </div>
                </div>
            `;
            let printArea = document.getElementById('print-area');
            printArea.innerHTML = htmlPrint;
            document.body.classList.add('printing-struk');
            setTimeout(function() { window.print(); }, 500);
            window.onafterprint = function() { document.body.classList.remove('printing-struk'); printArea.innerHTML = ''; };
        }

        <?php if (isset($_SESSION['flash_error'])): ?>Swal.fire({ icon: 'error', title: 'Oops...', text: '<?= $_SESSION['flash_error']; ?>', confirmButtonColor: '#00529C' });<?php unset($_SESSION['flash_error']); endif; ?>
        <?php if (isset($_SESSION['flash_success']) && !isset($_SESSION['print_daftar'])): ?>Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, icon: 'success', title: '<?= $_SESSION['flash_success']; ?>' });<?php unset($_SESSION['flash_success']); endif; ?>

        <?php if (isset($_SESSION['print_daftar'])): $p = $_SESSION['print_daftar']; ?>
            Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, icon: 'success', title: '<?= $_SESSION['flash_success'] ?? "Berhasil!" ?>' });
            cetakPendaftaran('<?= $p['id'] ?>', '<?= date('d M Y') ?>', '<?= addslashes($p['nama']) ?>', '<?= addslashes($p['nik']) ?>', '<?= addslashes($p['hp']) ?>', '<?= addslashes($p['layanan']) ?>', '<?= addslashes($p['cabang']) ?>');
        <?php unset($_SESSION['print_daftar'], $_SESSION['flash_success']); endif; ?>
    </script>
</body>
</html>