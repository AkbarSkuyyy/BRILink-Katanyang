<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

$q_user = $conn->query("SELECT cabang_id, shift_ke, assigned_banks FROM users WHERE id = '$user_id'");
$user_info = $q_user ? $q_user->fetch_assoc() : [];
$cabang_id = !empty($user_info['cabang_id']) ? $user_info['cabang_id'] : 0;
$shift_pegawai = !empty($user_info['shift_ke']) ? $user_info['shift_ke'] : 1;

$assigned_banks_str = $user_info['assigned_banks'] ?? '';
$assigned_banks_array = $assigned_banks_str ? array_map('trim', explode(',', $assigned_banks_str)) : [];
$assigned_banks = array_unique($assigned_banks_array);

$q_cek_beku = $conn->query("SELECT s.id FROM shifts s JOIN users u ON s.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND s.tanggal = '$tanggal_hari_ini' AND s.shift_ke = 2 AND s.status = 'selesai'");
$cabang_dibekukan = $q_cek_beku ? ($q_cek_beku->num_rows > 0) : false;

$db_map = [];
$q_rek = $conn->query("SELECT alias, kolom_db FROM rekening");
if($q_rek) {
    while($r = $q_rek->fetch_assoc()) { 
        $db_map[trim($r['alias'])] = trim($r['kolom_db']); 
    }
}

$valid_banks = [];
foreach($assigned_banks as $b) {
    if(isset($db_map[$b])) { $valid_banks[] = $b; }
}

// --- PROSES BUKA SHIFT UNIVERSAL ---
if (isset($_POST['mulai_shift'])) {
    $modal_awal = (float)str_replace('.', '', $_POST['modal_awal'] ?? '0');
    $cols = ['user_id', 'tanggal', 'shift_ke', 'modal_awal', 'status'];
    $vals = ["'$user_id'", "'$tanggal_hari_ini'", "'$shift_pegawai'", "'$modal_awal'", "'aktif'"];

    foreach($valid_banks as $bank) {
        $col_name = $db_map[$bank];
        $val = (float)str_replace('.', '', $_POST[$col_name] ?? '0');
        $cols[] = $col_name;
        $vals[] = "'$val'";
    }

    $str_cols = implode(', ', $cols);
    $str_vals = implode(', ', $vals);

    try {
        $insert = $conn->query("INSERT INTO shifts ($str_cols) VALUES ($str_vals)");
        if($insert) {
            if(function_exists('notifShiftBuka')) notifShiftBuka($_SESSION['nama_cabang'] ?? 'Cabang', $shift_pegawai, $modal_awal);
            $_SESSION['flash_success'] = "Laci Shift $shift_pegawai berhasil dibuka! Selamat bertransaksi.";
        } else {
            $_SESSION['flash_error'] = "Gagal membuka shift. Silakan coba lagi.";
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Terjadi Kesalahan SQL: " . $e->getMessage();
    }
    header("Location: set_modal.php"); exit;
}

// --- PROSES TUTUP SHIFT ---
if (isset($_POST['tutup_shift'])) {
    $shift_id_aktif = $_POST['shift_id_aktif'];
    try {
        $conn->query("UPDATE shifts SET status = 'selesai' WHERE id = '$shift_id_aktif'");
        if(function_exists('notifShiftTutup')) notifShiftTutup($_SESSION['nama_cabang'] ?? 'Cabang', $shift_pegawai);
        
        $_SESSION['flash_success'] = 'Shift Berhasil Diakhiri. Laporan Telah Terkunci.';
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Gagal mengakhiri shift: " . $e->getMessage();
    }
    header("Location: set_modal.php"); exit;
}

// --- PROSES TAMBAH MODAL DI TENGAH SHIFT ---
if (isset($_POST['tambah_modal_aktif'])) {
    $shift_id_aktif = $_POST['shift_id_aktif'];
    $jenis_modal    = $_POST['jenis_modal'] ?? '';
    $nominal_tambah = (float)str_replace('.', '', $_POST['nominal_tambah'] ?? '0');

    $nama_kolom = '';
    if ($jenis_modal == 'CASH') { $nama_kolom = 'modal_awal'; }
    elseif (isset($db_map[$jenis_modal])) { $nama_kolom = $db_map[$jenis_modal]; }

    if ($nama_kolom != '') {
        try {
            $conn->query("UPDATE shifts SET $nama_kolom = $nama_kolom + $nominal_tambah WHERE id = '$shift_id_aktif'");
            $_SESSION['flash_success'] = "Modal tambahan Rp " . number_format($nominal_tambah, 0, ',', '.') . " berhasil dimasukkan ke $jenis_modal.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Gagal update modal tambahan: " . $e->getMessage();
        }
        header("Location: set_modal.php"); exit;
    }
}

// CEK STATUS SHIFT & KALKULASI SALDO BERJALAN (SINKRON DENGAN DANA BOS)
$q_shift_saya = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1");
$shift_saya = $q_shift_saya ? $q_shift_saya->fetch_assoc() : null;

$saldo_sekarang = ['CASH' => 0];
foreach($valid_banks as $b) { $saldo_sekarang[$b] = 0; }

if ($shift_saya && $shift_saya['status'] == 'aktif') {
    $sid = $shift_saya['id'];
    $tgl_shift = $shift_saya['tanggal'];
    
    // Uang Masuk Fisik Pokok
    $q_in_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND (jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos') OR (jenis_transaksi = 'Setor Dana Bos' AND bank_agen = 'CASH'))");
    $uang_masuk = ($q_in_cash && $row = $q_in_cash->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

    // Admin yang dibayar cash
    $q_adm_cash = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Pengeluaran / Rugi', 'Tarik Dana Bos', 'Setor Dana Bos')");
    $admin_cash = ($q_adm_cash && $row = $q_adm_cash->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

    // Uang Keluar Fisik
    $q_out_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND (jenis_transaksi = 'Tarik Tunai' OR (jenis_transaksi IN ('Pengeluaran / Rugi', 'Tarik Dana Bos') AND bank_agen = 'CASH'))");
    $uang_keluar = ($q_out_cash && $row = $q_out_cash->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;

    $saldo_sekarang['CASH'] = $shift_saya['modal_awal'] + $uang_masuk + $admin_cash - $uang_keluar;

    // Perhitungan Rekening Digital
    foreach($valid_banks as $b_name) {
        $b_col = $db_map[$b_name];
        
        // Digital IN
        $q_in_b = $conn->query("SELECT SUM(CASE WHEN jenis_transaksi = 'Tarik Tunai' THEN nominal + admin_fee ELSE nominal END) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi IN ('Tarik Tunai', 'Setor Dana Bos') AND bank_agen = '$b_name'");
        $in_b = ($q_in_b && $row = $q_in_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
        
        // Digital OUT
        $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND tanggal >= '$tgl_shift' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang', 'Setor Dana Bos') AND bank_agen = '$b_name'");
        $out_b = ($q_out_b && $row = $q_out_b->fetch_assoc()) ? ($row['tot'] ?? 0) : 0;
        
        $modal_bank = isset($shift_saya[$b_col]) ? (float)$shift_saya[$b_col] : 0;
        $saldo_sekarang[$b_name] = $modal_bank + $in_b - $out_b;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Set Modal & Shift</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; --bri-black: #1a1a1a; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); border: none; color: white; border-radius: 14px; padding: 14px; font-weight: 700; transition: all 0.3s; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0, 82, 156, 0.3); }
        .rek-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 16px; padding: 20px; transition: all 0.3s; }
        .rek-box:hover { background: #ffffff; border-color: var(--bri-blue); box-shadow: 0 5px 15px rgba(0, 82, 156, 0.1); }
        .form-control, .form-select { border-radius: 12px; border: 1px solid #dee2e6; padding: 12px 15px; font-weight: 600; }
        .form-control:focus, .form-select:focus { border-color: var(--bri-blue); box-shadow: 0 0 0 4px rgba(0, 82, 156, 0.1); }
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 20px !important; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h3 class="fw-extrabold mb-4" style="color: var(--bri-black);"><i class="bi bi-wallet2 me-2 text-primary"></i>Pengaturan Modal & Shift</h3>

        <?php if ($cabang_dibekukan): ?>
            <div class="modern-card text-center p-5 border-top border-4 border-danger">
                <i class="bi bi-lock-fill text-danger mb-3" style="font-size: 60px; display: inline-block;"></i>
                <h4 class="fw-bold text-dark">Cabang Ditutup / Dibekukan</h4>
                <p class="text-muted mb-0">Operasional untuk hari ini telah diakhiri. Silakan hubungi Admin Pusat jika ini adalah kesalahan.</p>
            </div>
            
        <?php elseif ($shift_saya && $shift_saya['status'] == 'aktif'): ?>
            <div class="modern-card">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 border-bottom pb-4">
                    <div>
                        <span class="badge bg-primary px-3 py-2 rounded-pill fs-6 mb-2">Slot Shift <?= $shift_pegawai; ?> Berjalan</span>
                        <p class="text-muted fw-semibold mb-0 small">Pastikan saldo laci sesuai sebelum mengakhiri shift.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-success px-4 py-2 fw-bold rounded-pill" data-bs-toggle="modal" data-bs-target="#modalTambahModal">
                            <i class="bi bi-plus-circle me-1"></i> Tambah Modal
                        </button>
                        <form method="POST" class="m-0" id="formTutupShift">
                            <input type="hidden" name="shift_id_aktif" value="<?= $shift_saya['id']; ?>">
                            <input type="hidden" name="tutup_shift" value="1">
                            <button type="button" class="btn btn-danger px-4 py-2 fw-bold shadow-sm rounded-pill" onclick="konfirmasiTutup()">Akhiri Shift</button>
                        </form>
                    </div>
                </div>
                
                <h6 class="fw-bold mb-3 text-secondary">Rincian Saldo Berjalan (Live)</h6>
                <div class="rek-box text-center mb-4 border-primary border-2" style="background-color: #f0f7ff;">
                    <p class="text-muted small fw-bold text-uppercase mb-1 tracking-wide">Total Laci Fisik (CASH)</p>
                    <h2 class="fw-bolder text-primary mb-0">Rp <?= number_format($saldo_sekarang['CASH'], 0, ',', '.'); ?></h2>
                </div>

                <div class="row g-3">
                    <?php foreach($valid_banks as $bank): ?>
                    <div class="col-6 col-md-4">
                        <div class="rek-box text-center">
                            <p class="text-muted small fw-bold mb-2 text-uppercase"><?= htmlspecialchars($bank); ?></p>
                            <h5 class="fw-bolder text-dark mb-0">Rp <?= number_format($saldo_sekarang[$bank], 0, ',', '.'); ?></h5>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal fade" id="modalTambahModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 rounded-4 shadow-lg">
                        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                            <h5 class="modal-title fw-bolder text-dark"><i class="bi bi-cash-coin text-success me-2"></i>Tambah Modal Tambahan</h5>
                            <button type="button" class="btn-close shadow-none bg-light rounded-circle p-2" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <form method="POST">
                                <input type="hidden" name="shift_id_aktif" value="<?= $shift_saya['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-bold text-uppercase">Pilih Target Penambahan</label>
                                    <select name="jenis_modal" class="form-select bg-light" required>
                                        <option value="" disabled selected>-- Pilih Jenis Modal --</option>
                                        <option value="CASH" class="fw-bold" style="color: #198754;">💵 Laci Fisik (CASH)</option>
                                        <?php foreach($valid_banks as $bank): ?>
                                            <option value="<?= htmlspecialchars($bank) ?>" class="fw-bold" style="color: #00529C;">🏦 Rek. <?= htmlspecialchars($bank) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label text-muted small fw-bold text-uppercase">Nominal Tambahan (Rp)</label>
                                    <input type="text" name="nominal_tambah" class="form-control form-control-lg fw-bold format-rupiah" style="background-color: var(--bg-body);" placeholder="Ketik nominal..." required>
                                </div>
                                <button type="submit" name="tambah_modal_aktif" class="btn btn-success w-100 py-3 fw-bold rounded-pill shadow-sm fs-5">Simpan Tambahan</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif (!$shift_saya): ?>
            <div class="modern-card" style="max-width: 800px; margin: auto;">
                <div class="text-center mb-4">
                    <div class="d-inline-flex justify-content-center align-items-center bg-light text-primary rounded-circle mb-3" style="width: 70px; height: 70px;">
                        <i class="bi bi-door-open-fill fs-1"></i>
                    </div>
                    <h4 class="fw-extrabold mb-1" style="color: var(--bri-black);">Mulai Bekerja (Shift <?= $shift_pegawai ?>)</h4>
                    <p class="text-muted small">Input uang yang benar-benar ada di laci dan rekening Anda saat ini.</p>
                </div>
                
                <form method="POST">
                    <div class="mb-4 p-4 rounded-4" style="background-color: #e6f0ff; border: 1px dashed #b3d4ff;">
                        <label class="form-label fw-bold text-primary fs-5 mb-1"><i class="bi bi-cash me-2"></i>Uang Laci Fisik (Tunai)</label>
                        <p class="text-muted small mb-3">Hitung jumlah seluruh uang fisik yang ada di dalam laci Anda saat ini.</p>
                        <input type="text" name="modal_awal" class="form-control form-control-lg bg-white fw-bolder format-rupiah text-primary" style="font-size: 24px;" required placeholder="0">
                    </div>
                    
                    <?php if(!empty($valid_banks)): ?>
                    <h6 class="fw-bold mb-3 text-secondary border-bottom pb-2">Saldo Awal Rekening Digital</h6>
                    <div class="row g-3 mb-4">
                        <?php foreach($valid_banks as $bank): $input_name = $db_map[$bank]; ?>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-dark small mb-1"><?= htmlspecialchars($bank) ?></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted fw-bold">Rp</span>
                                <input type="text" name="<?= $input_name ?>" class="form-control bg-light border-start-0 format-rupiah fw-semibold" required placeholder="0">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" name="mulai_shift" class="btn btn-modern w-100 fs-5 mt-2 rounded-pill">BUKA LACI SHIFT SEKARANG</button>
                </form>
            </div>
            
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.querySelectorAll('.format-rupiah').forEach(function(input) {
            input.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value !== '') { this.value = new Intl.NumberFormat('id-ID').format(value); } 
                else { this.value = ''; }
            });
        });

        function konfirmasiTutup() {
            Swal.fire({ 
                title: 'Akhiri Shift Pekerjaan?', 
                text: "Laporan pada shift Anda akan difinalisasi dan laci akan dikunci secara otomatis.", 
                icon: 'warning', 
                showCancelButton: true, 
                confirmButtonColor: '#d33', 
                cancelButtonColor: '#6c757d', 
                confirmButtonText: 'Ya, Akhiri Shift!', 
                cancelButtonText: 'Batal' 
            }).then((result) => { 
                if (result.isConfirmed) { document.getElementById('formTutupShift').submit(); } 
            });
        }

        <?php if (isset($_SESSION['flash_success'])): ?>
            Swal.fire({ title: 'Sukses!', text: '<?= $_SESSION['flash_success']; ?>', icon: 'success', confirmButtonColor: '#00529C', timer: 3000, timerProgressBar: true });
        <?php unset($_SESSION['flash_success']); endif; ?>
        
        <?php if (isset($_SESSION['flash_error'])): ?>
            Swal.fire({ title: 'Info Sistem', text: '<?= $_SESSION['flash_error']; ?>', icon: 'info', confirmButtonColor: '#00529C' });
        <?php unset($_SESSION['flash_error']); endif; ?>
    </script>
</body>
</html>