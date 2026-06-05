<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$tanggal_hari_ini = date('Y-m-d');

// Auto-Create Tabel
$conn->query("CREATE TABLE IF NOT EXISTS laporan_toko (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    cabang_id INT NOT NULL,
    tanggal DATE NOT NULL,
    shift_ke INT NOT NULL,
    nama_penyetor VARCHAR(100) NOT NULL,
    jumlah_setoran DOUBLE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Auto-Fix Kolom Jenis Toko (Mencegah Error 500)
$check_jenis = $conn->query("SHOW COLUMNS FROM cabang LIKE 'jenis'");
if ($check_jenis && $check_jenis->num_rows == 0) {
    $conn->query("ALTER TABLE cabang ADD jenis VARCHAR(50) NOT NULL DEFAULT 'BRILink'");
}

$q_user = $conn->query("SELECT cabang_id FROM users WHERE id = '$user_id'");
$user_info = $q_user ? $q_user->fetch_assoc() : [];
$default_cabang_id = !empty($user_info['cabang_id']) ? $user_info['cabang_id'] : 0;
$shift_default = isset($_SESSION['shift_ke']) ? $_SESSION['shift_ke'] : 1;

if (isset($_POST['simpan_laporan'])) {
    $tanggal_input  = $conn->real_escape_string($_POST['tanggal']);
    $cabang_input   = (int)$_POST['cabang_id'];
    $shift_input    = (int)$_POST['shift_ke'];
    $nama_penyetor  = $conn->real_escape_string(trim($_POST['nama_penyetor']));
    $jumlah_setoran = (float)str_replace('.', '', $_POST['jumlah_setoran']);

    $insert = $conn->query("INSERT INTO laporan_toko (user_id, cabang_id, tanggal, shift_ke, nama_penyetor, jumlah_setoran) 
                            VALUES ('$user_id', '$cabang_input', '$tanggal_input', '$shift_input', '$nama_penyetor', '$jumlah_setoran')");
    
    if($insert) {
        $_SESSION['flash_success'] = "Laporan setoran berhasil disimpan!";
    } else {
        $_SESSION['flash_error'] = "Gagal menyimpan laporan setoran.";
    }
    header("Location: input_laporan_toko.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Terima Setoran - Karyawan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 30px; border: none; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 12px; padding: 12px 20px; font-weight: 700; border: none; transition: all 0.3s; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 82, 156, 0.3); color: white; }
        .form-control, .form-select { border-radius: 12px; padding: 12px 15px; font-weight: 600; background-color: var(--bg-body); border: 1px solid #dee2e6; }
        .form-control:focus, .form-select:focus { border-color: var(--bri-blue); box-shadow: none; }
        
        #print-area { display: none; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0; padding: 20px 15px; padding-top: 85px; } }

        @media print {
            body.printing-struk #print-area { display: block !important; width: 100%; color: #000; }
            body.printing-struk .sidebar-modern, body.printing-struk .mobile-header, body.printing-struk .main-content { display: none !important; }
            body { background-color: white !important; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);"><i class="bi bi-box-arrow-in-down me-2 text-primary"></i>Terima Setoran Toko</h3>
        <p class="text-muted mb-4">Input data uang fisik yang disetorkan oleh karyawan/kasir dari cabang toko lain.</p>

        <div class="row g-4">
            <div class="col-md-5">
                <div class="modern-card">
                    <h5 class="fw-bold mb-4 border-bottom pb-2">Form Input Setoran</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">1. Kategori Toko Penyetor</label>
                            <select id="kategoriLokasi" class="form-select border-primary" required>
                                <option value="" disabled selected>-- Pilih Kategori --</option>
                                <option value="BRILink">🏢 Cabang BRILink</option>
                                <option value="Toko">🏪 Toko Umum / Sembako</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">2. Nama Lokasi Asal</label>
                            <select name="cabang_id" id="dropdownLokasi" class="form-select" required disabled>
                                <option value="" disabled selected>-- Pilih Kategori Dahulu --</option>
                            </select>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold text-uppercase">Tanggal Setoran</label>
                                <input type="date" name="tanggal" class="form-control" value="<?= $tanggal_hari_ini ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-bold text-uppercase">Shift Penyetor</label>
                                <input type="number" name="shift_ke" class="form-control" placeholder="1, 2, 3..." min="1" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold text-uppercase">Nama Karyawan Penyetor</label>
                            <input type="text" name="nama_penyetor" class="form-control" placeholder="Ketik nama karyawan..." required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-bold text-uppercase">Jumlah Uang Disetor (Rp)</label>
                            <input type="text" name="jumlah_setoran" class="form-control format-rupiah text-primary fs-5" placeholder="0" required>
                        </div>
                        <button type="submit" name="simpan_laporan" class="btn-modern w-100"><i class="bi bi-send-fill me-2"></i>Terima & Simpan Setoran</button>
                    </form>
                </div>
            </div>

            <div class="col-md-7">
                <div class="modern-card h-100">
                    <h5 class="fw-bold mb-4 border-bottom pb-2">Riwayat Penerimaan (Bulan Ini)</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle text-nowrap">
                            <thead class="table-light">
                                <tr>
                                    <th>Tanggal & Lokasi</th>
                                    <th>Penyetor</th>
                                    <th>Jumlah (Rp)</th>
                                    <th class="text-center">Cetak Bukti</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $bulan_ini = date('m');
                                $q_riwayat = $conn->query("
                                    SELECT l.*, c.nama_cabang, c.jenis 
                                    FROM laporan_toko l
                                    LEFT JOIN cabang c ON l.cabang_id = c.id
                                    WHERE l.user_id = '$user_id' AND MONTH(l.tanggal) = '$bulan_ini' 
                                    ORDER BY l.id DESC LIMIT 10
                                ");
                                if($q_riwayat && $q_riwayat->num_rows > 0):
                                    while($r = $q_riwayat->fetch_assoc()):
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold text-dark d-block"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></span>
                                        <small class="text-muted">
                                            <?php if($r['jenis']=='BRILink') echo '<i class="bi bi-bank2 me-1"></i>'; else echo '<i class="bi bi-shop me-1"></i>'; ?>
                                            <?= htmlspecialchars($r['nama_cabang']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="fw-medium d-block"><?= htmlspecialchars($r['nama_penyetor']) ?></span>
                                        <span class="badge bg-secondary" style="font-size:10px;">Shift <?= $r['shift_ke'] ?></span>
                                    </td>
                                    <td class="fw-bold text-success">Rp <?= number_format($r['jumlah_setoran'], 0, ',', '.') ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-dark rounded-pill px-3 fw-bold shadow-sm" 
                                            onclick="cetakBuktiSetoran(
                                                '<?= str_pad($r['id'], 5, '0', STR_PAD_LEFT) ?>', 
                                                '<?= date('d M Y', strtotime($r['tanggal'])) ?>', 
                                                '<?= htmlspecialchars(addslashes($r['nama_cabang'])) ?>', 
                                                '<?= htmlspecialchars(addslashes($r['nama_penyetor'])) ?>', 
                                                '<?= number_format($r['jumlah_setoran'], 0, ',', '.') ?>',
                                                '<?= $r['shift_ke'] ?>'
                                            )">
                                            <i class="bi bi-printer-fill me-1"></i> Cetak NCR
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Belum ada riwayat penerimaan setoran.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="print-area"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const dataLokasi = [
            <?php 
            $qc = $conn->query("SELECT id, nama_cabang, jenis FROM cabang");
            if($qc){
                while($c = $qc->fetch_assoc()) {
                    $jenis = isset($c['jenis']) ? $c['jenis'] : 'BRILink';
                    echo "{id: ".$c['id'].", nama: '".addslashes($c['nama_cabang'])."', jenis: '".addslashes($jenis)."'},";
                }
            }
            ?>
        ];

        document.getElementById('kategoriLokasi').addEventListener('change', function() {
            const jenisTerpilih = this.value;
            const dropdownLokasi = document.getElementById('dropdownLokasi');
            
            dropdownLokasi.innerHTML = '<option value="" disabled selected>-- Pilih Nama Lokasi --</option>';
            let count = 0;
            
            dataLokasi.forEach(loc => {
                if (loc.jenis === jenisTerpilih) {
                    dropdownLokasi.innerHTML += `<option value="${loc.id}">${loc.nama}</option>`;
                    count++;
                }
            });
            
            dropdownLokasi.disabled = false;
            
            if (count === 0) {
                dropdownLokasi.innerHTML = '<option value="" disabled selected>-- Belum ada data di kategori ini --</option>';
                dropdownLokasi.disabled = true;
            }
        });

        document.querySelectorAll('.format-rupiah').forEach(function(input) {
            input.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value !== '') { this.value = new Intl.NumberFormat('id-ID').format(value); } else { this.value = ''; }
            });
        });

        function setPrintPageStyle(styleRule) {
            let style = document.getElementById('dynamic-print-style');
            if (!style) {
                style = document.createElement('style');
                style.id = 'dynamic-print-style';
                document.head.appendChild(style);
            }
            style.innerHTML = styleRule;
        }

        function cetakBuktiSetoran(id, tanggal, toko, penyetor, jumlah, shift) {
            setPrintPageStyle('@page { size: A5 landscape; margin: 15mm; }');
            
            let htmlPrint = `
                <style>
                    #print-area { font-family: 'Arial', sans-serif; font-size: 13px; line-height: 1.5; color: #000; width: 100%; max-width: 185mm; margin: auto; }
                    .ncr-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px dashed #000; padding-bottom: 12px; margin-bottom: 15px; }
                    .ncr-logo-wrapper { display: flex; align-items: center; }
                    .ncr-logo-wrapper img { max-height: 55px; margin-right: 15px; filter: grayscale(100%); }
                    .ncr-logo-text { font-size: 20px; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; font-family: 'Arial', sans-serif; }
                    .ncr-logo-sub { font-size: 11px; font-weight: normal; letter-spacing: normal; display: block; margin-top: 2px; }
                    .ncr-title-box { text-align: right; }
                    .ncr-title { font-size: 18px; font-weight: bold; letter-spacing: 1px; font-family: 'Arial', sans-serif; }
                    .ncr-ref { font-size: 12px; font-weight: bold; margin-top: 3px; display: block; }
                    .ncr-info { display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 13px; font-family: 'Arial', sans-serif; }
                    .ncr-info-table { width: 100%; }
                    .ncr-info-table td { padding: 3px 5px; vertical-align: top; }
                    .ncr-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-family: 'Arial', sans-serif; }
                    .ncr-table th, .ncr-table td { border: 1px solid #000; padding: 10px; }
                    .ncr-table th { text-align: center; font-weight: bold; background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
                    .ncr-ttd { display: flex; justify-content: space-between; margin-top: 20px; text-align: center; font-size: 13px; font-family: 'Arial', sans-serif; }
                    .ttd-box { width: 35%; }
                    .ttd-name { border-bottom: 1px solid #000; margin-top: 60px; font-weight: bold; display: block; padding-bottom: 3px; }
                </style>
                
                <div class="ncr-header">
                    <div class="ncr-logo-wrapper">
                        <img src="assets/img/logo-katanyang.png" alt="Logo">
                        <div>
                            <div class="ncr-logo-text">${toko}</div>
                            <span class="ncr-logo-sub">Tanda Terima Penyerahan Uang Kasir</span>
                        </div>
                    </div>
                    <div class="ncr-title-box">
                        <div class="ncr-title">BUKTI SETORAN</div>
                        <span class="ncr-ref">NO: STR-${id}</span>
                    </div>
                </div>

                <div class="ncr-info">
                    <div style="width: 48%;">
                        <table class="ncr-info-table">
                            <tr><td width="100">Disetor Oleh</td><td width="10">:</td><td><strong>${penyetor}</strong></td></tr>
                            <tr><td>Shift Penyetor</td><td>:</td><td>Shift ${shift}</td></tr>
                        </table>
                    </div>
                    <div style="width: 48%;">
                        <table class="ncr-info-table">
                            <tr><td width="100">Tanggal</td><td width="10">:</td><td><strong>${tanggal}</strong></td></tr>
                            <tr><td>Lokasi / Toko</td><td>:</td><td>${toko}</td></tr>
                        </table>
                    </div>
                </div>

                <table class="ncr-table">
                    <thead>
                        <tr><th width="10%">QTY</th><th width="60%">DESKRIPSI SETORAN</th><th width="30%">JUMLAH (Rp)</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="text-align: center; height: 60px; vertical-align: top;">1</td>
                            <td style="vertical-align: top;">
                                <strong>Setoran Uang Fisik Akhir Shift</strong><br>
                                <small>Pendapatan tunai laci kasir (telah disesuaikan dengan laporan sistem).</small>
                            </td>
                            <td style="text-align: right; font-size: 16px; font-weight: bold; vertical-align: top;">${jumlah}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="text-align: right; font-weight: bold; padding: 10px;">TOTAL SETORAN KASIR :</td>
                            <td style="text-align: right; font-size: 18px; font-weight: bold; background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact;">Rp ${jumlah}</td>
                        </tr>
                    </tfoot>
                </table>

                <div class="ncr-ttd">
                    <div class="ttd-box">
                        <span>Diserahkan Oleh,</span>
                        <span class="ttd-name">${penyetor}</span>
                        <span style="font-size: 11px;">Kasir Bertugas</span>
                    </div>
                    <div class="ttd-box">
                        <span>Diterima Oleh,</span>
                        <span class="ttd-name">&nbsp;</span>
                        <span style="font-size: 11px;">Pimpinan / Admin Pusat</span>
                    </div>
                </div>
            `;

            let printArea = document.getElementById('print-area');
            printArea.innerHTML = htmlPrint;
            document.body.classList.add('printing-struk');

            setTimeout(function() { window.print(); }, 500);

            window.onafterprint = function() {
                document.body.classList.remove('printing-struk');
                printArea.innerHTML = ''; 
            };
        }

        <?php if (isset($_SESSION['flash_success'])): ?>
            Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, icon: 'success', title: '<?= $_SESSION['flash_success']; ?>' });
        <?php unset($_SESSION['flash_success']); endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            Swal.fire({ icon: 'error', title: 'Oops...', text: '<?= $_SESSION['flash_error']; ?>', confirmButtonColor: '#00529C' });
        <?php unset($_SESSION['flash_error']); endif; ?>
    </script>
</body>
</html>