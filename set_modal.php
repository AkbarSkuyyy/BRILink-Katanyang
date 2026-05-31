<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

// Ambil info cabang, shift, dan HAK AKSES REKENING
$q_user = $conn->query("SELECT cabang_id, shift_ke, assigned_banks FROM users WHERE id = '$user_id'");
$user_info = $q_user->fetch_assoc();
$cabang_id = $user_info['cabang_id'];
$shift_pegawai = $user_info['shift_ke'];

$assigned_banks = $user_info['assigned_banks'] ? explode(',', $user_info['assigned_banks']) : [];

$q_cek_beku = $conn->query("SELECT s.id FROM shifts s JOIN users u ON s.user_id = u.id WHERE u.cabang_id = '$cabang_id' AND s.tanggal = '$tanggal_hari_ini' AND s.shift_ke = 2 AND s.status = 'selesai'");
$cabang_dibekukan = ($q_cek_beku->num_rows > 0);

// AMBIL DAFTAR REKENING & KOLOM DB DARI TABEL MASTER (Dinamis)
$db_map = [];
$q_rek = $conn->query("SELECT nama_bank, kolom_db FROM rekening");
while($r = $q_rek->fetch_assoc()) { $db_map[$r['nama_bank']] = $r['kolom_db']; }

// --- PROSES BUKA SHIFT DINAMIS ---
if (isset($_POST['mulai_shift_1']) || isset($_POST['mulai_shift_2'])) {
    $shift_tujuan = isset($_POST['mulai_shift_1']) ? 1 : 2;
    $modal_awal   = (float)str_replace('.', '', $_POST['modal_awal'] ?? '0');

    // Kolom default yang pasti ada
    $cols = ['user_id', 'tanggal', 'shift_ke', 'modal_awal', 'status'];
    $vals = ["'$user_id'", "'$tanggal_hari_ini'", $shift_tujuan, "'$modal_awal'", "'aktif'"];

    // Looping hanya untuk bank yang diizinkan
    foreach($assigned_banks as $bank) {
        if(isset($db_map[$bank])) {
            $col_name = $db_map[$bank];
            $val = (float)str_replace('.', '', $_POST[$col_name] ?? '0');
            $cols[] = $col_name;
            $vals[] = "'$val'";
        }
    }

    $str_cols = implode(', ', $cols);
    $str_vals = implode(', ', $vals);

    $conn->query("INSERT INTO shifts ($str_cols) VALUES ($str_vals)");
    
    if(function_exists('notifShiftBuka')) notifShiftBuka($_SESSION['nama_cabang'], $shift_tujuan, $modal_awal);
    $_SESSION['flash_success'] = "Shift $shift_tujuan Berhasil Dibuka!";
    header("Location: set_modal.php"); exit;
}

// --- PROSES TUTUP SHIFT ---
if (isset($_POST['tutup_shift'])) {
    $shift_id_aktif = $_POST['shift_id_aktif'];
    $conn->query("UPDATE shifts SET status = 'selesai' WHERE id = '$shift_id_aktif'");
    if(function_exists('notifShiftTutup')) notifShiftTutup($_SESSION['nama_cabang'], $shift_pegawai);
    $_SESSION['flash_success'] = "Shift Berhasil Diakhiri. Laporan Telah Terkunci.";
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
        $conn->query("UPDATE shifts SET $nama_kolom = $nama_kolom + $nominal_tambah WHERE id = '$shift_id_aktif'");
        $_SESSION['flash_success'] = "Modal $jenis_modal berhasil ditambahkan sebesar Rp " . number_format($nominal_tambah, 0, ',', '.');
        header("Location: set_modal.php"); exit;
    }
}

$q_shift_saya = $conn->query("SELECT * FROM shifts WHERE user_id = '$user_id' AND tanggal = '$tanggal_hari_ini' ORDER BY id DESC LIMIT 1");
$shift_saya = $q_shift_saya->fetch_assoc();

$saldo_sekarang = ['CASH' => 0];
foreach($assigned_banks as $b) { $saldo_sekarang[$b] = 0; }

if ($shift_saya && $shift_saya['status'] == 'aktif') {
    $sid = $shift_saya['id'];
    $q_in_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
    $q_adm = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$sid'");
    $q_out_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi = 'Tarik Tunai'");
    $saldo_sekarang['CASH'] = $shift_saya['modal_awal'] + ($q_in_cash->fetch_assoc()['tot'] ?? 0) + ($q_adm->fetch_assoc()['tot'] ?? 0) - ($q_out_cash->fetch_assoc()['tot'] ?? 0);

    foreach($assigned_banks as $b_name) {
        if(isset($db_map[$b_name])) {
            $b_col = $db_map[$b_name];
            $q_in_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi = 'Tarik Tunai' AND bank_agen = '$b_name'");
            $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$sid' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang') AND bank_agen = '$b_name'");
            $saldo_sekarang[$b_name] = $shift_saya[$b_col] + ($q_in_b->fetch_assoc()['tot'] ?? 0) - ($q_out_b->fetch_assoc()['tot'] ?? 0);
        }
    }
}

// MEWARISKAN SALDO SHIFT 1 KE SHIFT 2 SECARA OTOMATIS
$saldo_akhir_shift_1 = []; $shift_1_selesai = false; $menunggu_shift_1 = false;
if (!$cabang_dibekukan && !$shift_saya && $shift_pegawai == 2) {
    $q_shift_1 = $conn->query("SELECT * FROM shifts WHERE user_id IN (SELECT id FROM users WHERE cabang_id = '$cabang_id') AND tanggal = '$tanggal_hari_ini' AND shift_ke = 1 ORDER BY id DESC LIMIT 1");
    if ($q_shift_1->num_rows > 0) {
        $data_shift_1 = $q_shift_1->fetch_assoc();
        if ($data_shift_1['status'] == 'selesai') {
            $shift_1_selesai = true; $s1_id = $data_shift_1['id'];
            
            $q_in_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang')");
            $q_adm = $conn->query("SELECT SUM(admin_fee) as tot FROM transactions WHERE shift_id = '$s1_id'");
            $q_out_cash = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi = 'Tarik Tunai'");
            $saldo_akhir_shift_1['CASH'] = $data_shift_1['modal_awal'] + ($q_in_cash->fetch_assoc()['tot'] ?? 0) + ($q_adm->fetch_assoc()['tot'] ?? 0) - ($q_out_cash->fetch_assoc()['tot'] ?? 0);
            
            foreach($assigned_banks as $b_name) {
                if(isset($db_map[$b_name])) {
                    $b_col = $db_map[$b_name];
                    $q_in_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi = 'Tarik Tunai' AND bank_agen = '$b_name'");
                    $q_out_b = $conn->query("SELECT SUM(nominal) as tot FROM transactions WHERE shift_id = '$s1_id' AND jenis_transaksi NOT IN ('Tarik Tunai', 'Tukar Uang') AND bank_agen = '$b_name'");
                    $saldo_akhir_shift_1[$b_name] = $data_shift_1[$b_col] + ($q_in_b->fetch_assoc()['tot'] ?? 0) - ($q_out_b->fetch_assoc()['tot'] ?? 0);
                }
            }
        } else { $menunggu_shift_1 = true; }
    } else { $menunggu_shift_1 = true; }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Set Modal & Shift</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); }
        .btn-modern { border-radius: 14px; padding: 14px; font-weight: 700; }
        .rek-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 12px; padding: 15px; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h3 class="fw-bold mb-4 text-dark"><i class="bi bi-wallet2 me-2 text-primary"></i>Pengaturan Modal & Shift</h3>

        <?php if ($cabang_dibekukan): ?>
            <div class="modern-card text-center p-5"><i class="bi bi-lock-fill text-secondary fs-1"></i><h4 class="fw-bold mt-3">Cabang Dibekukan</h4><p class="text-muted">Operasional hari ini sudah berakhir.</p></div>
        <?php elseif ($shift_saya && $shift_saya['status'] == 'aktif'): ?>
            <div class="modern-card">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                    <span class="badge bg-primary px-3 py-2 rounded-pill fs-6 align-self-start align-self-md-center">Shift <?= $shift_pegawai; ?> Sedang Berjalan</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success btn-sm px-4 py-2 fw-bold shadow-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#modalTambahModal"><i class="bi bi-plus-circle me-1"></i> Tambah Modal</button>
                        <form method="POST" class="m-0" id="formTutupShift">
                            <input type="hidden" name="shift_id_aktif" value="<?= $shift_saya['id']; ?>"><input type="hidden" name="tutup_shift" value="1">
                            <button type="button" class="btn btn-danger btn-sm px-4 py-2 fw-bold shadow-sm rounded-pill" onclick="konfirmasiTutup()">Akhiri Shift</button>
                        </form>
                    </div>
                </div>
                
                <div class="rek-box text-center mb-4 border-primary border-2 bg-light">
                    <p class="text-muted small fw-bold text-uppercase mb-1">Total Laci Fisik (CASH)</p>
                    <h2 class="fw-bolder text-primary mb-0">Rp <?= number_format($saldo_sekarang['CASH'], 0, ',', '.'); ?></h2>
                </div>

                <div class="row g-3">
                    <?php foreach($assigned_banks as $bank): ?>
                    <div class="col-6 col-md-4">
                        <div class="rek-box">
                            <p class="text-muted small fw-bold mb-1"><?= $bank; ?></p>
                            <h5 class="fw-bolder text-primary mb-0">Rp <?= number_format($saldo_sekarang[$bank], 0, ',', '.'); ?></h5>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal fade" id="modalTambahModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 rounded-4 shadow-lg">
                        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4"><h5 class="modal-title fw-bold text-dark"><i class="bi bi-cash-coin text-success me-2"></i>Tambah Modal Aktif</h5><button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body p-4">
                            <form method="POST">
                                <input type="hidden" name="shift_id_aktif" value="<?= $shift_saya['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-bold text-uppercase">Pilih Jenis Modal</label>
                                    <select name="jenis_modal" class="form-select form-select-lg bg-light" style="border-radius: 12px;" required>
                                        <option value="" disabled selected>-- Pilih --</option>
                                        <option value="CASH">Laci Fisik (CASH)</option>
                                        <?php foreach($assigned_banks as $bank): ?>
                                            <option value="<?= $bank ?>"><?= $bank ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label text-muted small fw-bold text-uppercase">Nominal Tambahan (Rp)</label>
                                    <input type="text" name="nominal_tambah" class="form-control form-control-lg fw-bold format-rupiah" style="border-radius: 12px; background-color: var(--bg-body);" required>
                                </div>
                                <button type="submit" name="tambah_modal_aktif" class="btn btn-success w-100 py-3 fw-bold rounded-4 shadow-sm fs-5">Simpan Tambahan</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif (!$shift_saya && $shift_pegawai == 1): ?>
            <div class="modern-card" style="max-width: 800px; margin: auto;">
                <h5 class="fw-bold mb-1">Mulai Shift Pagi (Shift 1)</h5>
                <p class="text-muted small mb-4">Masukkan saldo awal laci fisik dan seluruh rekening operasional.</p>
                <form method="POST">
                    <div class="mb-4">
                        <label class="form-label fw-bold text-success fs-5"><i class="bi bi-cash"></i> Uang Laci Fisik (Cash)</label>
                        <input type="text" name="modal_awal" class="form-control form-control-lg bg-light border-0 fw-bold format-rupiah" required placeholder="0">
                    </div>
                    <div class="row g-3 mb-4">
                        <?php foreach($assigned_banks as $bank): if(isset($db_map[$bank])): $input_name = $db_map[$bank]; ?>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-primary small">Saldo Awal <?= $bank ?></label>
                            <input type="text" name="<?= $input_name ?>" class="form-control bg-light format-rupiah" required placeholder="0">
                        </div>
                        <?php endif; endforeach; ?>
                    </div>
                    <button type="submit" name="mulai_shift_1" class="btn btn-primary w-100 btn-modern shadow-sm fs-5">BUKA SHIFT 1</button>
                </form>
            </div>

        <?php elseif (!$shift_saya && $shift_pegawai == 2): ?>
            <?php if ($menunggu_shift_1): ?>
                <div class="modern-card text-center text-muted"><i class="bi bi-hourglass-split fs-1"></i><h5 class="fw-bold mt-3">Menunggu Shift 1</h5><p>Shift 1 belum tutup laporan harian.</p></div>
            <?php elseif ($shift_1_selesai): ?>
                <div class="modern-card" style="max-width: 800px; margin: auto;">
                    <h5 class="fw-bold mb-1">Mulai Shift Siang (Shift 2)</h5>
                    <p class="text-muted small mb-4">Saldo di bawah ini diteruskan secara otomatis dari penutupan Shift 1.</p>
                    
                    <div class="rek-box text-center mb-3 border-success border-2 bg-light">
                        <p class="text-muted small fw-bold text-uppercase mb-1">Warisan Laci Fisik (CASH)</p>
                        <h3 class="fw-bolder text-success mb-0">Rp <?= number_format($saldo_akhir_shift_1['CASH'], 0, ',', '.'); ?></h3>
                    </div>

                    <div class="row g-2 mb-4">
                        <?php foreach($assigned_banks as $bank): ?>
                        <div class="col-6 col-md-4">
                            <div class="p-2 border rounded bg-light text-center">
                                <small class="text-muted d-block" style="font-size: 11px;"><?= $bank; ?></small>
                                <strong class="text-dark">Rp <?= number_format($saldo_akhir_shift_1[$bank], 0, ',', '.'); ?></strong>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="modal_awal" value="<?= $saldo_akhir_shift_1['CASH'] ?? 0; ?>">
                        <?php foreach($assigned_banks as $bank) {
                            if(isset($db_map[$bank])) {
                                $input_name = $db_map[$bank];
                                $val = $saldo_akhir_shift_1[$bank] ?? 0;
                                echo "<input type='hidden' name='$input_name' value='$val'>";
                            }
                        } ?>
                        <button type="submit" name="mulai_shift_2" class="btn btn-warning w-100 btn-modern fs-5">TERIMA SALDO & BUKA SHIFT 2</button>
                    </form>
                </div>
            <?php endif; ?>
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
            Swal.fire({ title: 'Akhiri Shift?', text: "Laporan akan final setelah ditutup.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#6c757d', confirmButtonText: 'Ya, Tutup Shift!', cancelButtonText: 'Batal' })
            .then((result) => { if (result.isConfirmed) { document.getElementById('formTutupShift').submit(); } });
        }
    </script>
    <?php if (isset($_SESSION['flash_success'])): ?><script>Swal.fire({ title: 'Berhasil!', text: '<?= $_SESSION['flash_success']; ?>', icon: 'success', confirmButtonColor: '#00529C' });</script><?php unset($_SESSION['flash_success']); endif; ?>
</body>
</html>