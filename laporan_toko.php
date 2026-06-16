<?php
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

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

$check_jenis = $conn->query("SHOW COLUMNS FROM cabang LIKE 'jenis'");
if ($check_jenis && $check_jenis->num_rows == 0) {
    $conn->query("ALTER TABLE cabang ADD jenis VARCHAR(50) NOT NULL DEFAULT 'BRILink'");
}

// Perbaikan validasi penarikan param GET agar aman dari string kosong ('')
$filter_tgl_mulai = !empty($_GET['tgl_mulai']) ? $_GET['tgl_mulai'] : date('Y-m-01');
$filter_tgl_sampai = !empty($_GET['tgl_sampai']) ? $_GET['tgl_sampai'] : date('Y-m-d');
$filter_cabang = !empty($_GET['cabang']) ? $_GET['cabang'] : 'semua';
$filter_penyetor = !empty($_GET['penyetor']) ? $conn->real_escape_string($_GET['penyetor']) : '';

$where = "l.tanggal BETWEEN '$filter_tgl_mulai' AND '$filter_tgl_sampai' AND c.jenis = 'Toko'";
$nama_toko_filter = "Semua Toko";

if ($filter_cabang !== 'semua') {
    $cabang_id_aman = $conn->real_escape_string($filter_cabang);
    $where .= " AND l.cabang_id = '$cabang_id_aman'";
    
    // Perbaikan offset array null saat fetch
    $q_nama_cab = $conn->query("SELECT nama_cabang FROM cabang WHERE id = '$cabang_id_aman'");
    $row_cab = $q_nama_cab ? $q_nama_cab->fetch_assoc() : null;
    if($row_cab && !empty($row_cab['nama_cabang'])) {
        $nama_toko_filter = $row_cab['nama_cabang'];
    }
}

if ($filter_penyetor !== '') {
    $where .= " AND l.nama_penyetor LIKE '%$filter_penyetor%'";
}

$q_laporan = $conn->query("
    SELECT l.*, c.nama_cabang, c.jenis, u.username 
    FROM laporan_toko l
    LEFT JOIN cabang c ON l.cabang_id = c.id
    LEFT JOIN users u ON l.user_id = u.id
    WHERE $where
    ORDER BY l.tanggal DESC, l.id DESC
");
$total_semua_setoran = 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Rekap Setoran Toko - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 30px; border: none; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 10px; font-weight: 600; border: none; }
        .table-custom th { background-color: #e6f0ff; color: var(--bri-blue); font-weight: 700; text-transform: uppercase; font-size: 12px; padding: 15px; border-bottom: 2px solid #cce0ff; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f0f0f0; font-size: 14px; font-weight: 500; }
        .form-control, .form-select { border-radius: 12px; padding: 10px 15px; font-weight: 600; background-color: var(--bg-body); border: 1px solid #dee2e6; }
        .form-control:focus, .form-select:focus { border-color: var(--bri-blue); box-shadow: none; }
        
        #print-header, #print-footer { display: none; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0; padding: 20px 15px; padding-top: 85px; } }

        @media print {
            @page { size: A4 landscape; margin: 15mm; }
            body { background: #fff !important; margin: 0; padding: 0; font-family: 'Arial', sans-serif; color: #000; }
            .sidebar-modern, .mobile-header, .btn, form, p.text-muted, h3.fw-extrabold { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
            .modern-card { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; background: transparent !important; }
            #print-header { display: block !important; border-bottom: 3px double #000; padding-bottom: 15px; margin-bottom: 20px; text-align: center; }
            #print-header h2 { font-weight: bold; font-size: 22px; margin: 0 0 5px 0; color: #000; letter-spacing: 1px; }
            #print-header p { margin: 0; font-size: 14px; font-weight: bold; color: #333; }
            .print-info-row { display: flex; justify-content: space-between; margin-top: 15px; text-align: left; font-size: 13px; }
            .table-responsive { overflow: visible !important; }
            .table-custom { width: 100% !important; border-collapse: collapse !important; margin-bottom: 20px !important; }
            .table-custom th, .table-custom td { border: 1px solid #000 !important; padding: 8px 10px !important; font-size: 13px !important; color: #000 !important; }
            .table-custom th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; text-align: center; }
            .table-custom tr { page-break-inside: avoid; } 
            .badge { border: none !important; background: transparent !important; color: #000 !important; padding: 0 !important; font-size: 13px !important; font-weight: normal !important; }
            .text-success, .text-dark, .text-muted, .text-primary { color: #000 !important; }
            small.text-muted { font-size: 11px !important; color: #333 !important; }
            .fw-bolder, .fw-bold { font-weight: bold !important; }
            .bi { display: none !important; } 
            #print-footer { display: flex !important; justify-content: space-between; margin-top: 40px; font-size: 13px; text-align: center; page-break-inside: avoid; }
            .sign-box { width: 250px; }
            .sign-line { border-bottom: 1px solid #000; margin-top: 80px; display: block; }
        }

        .pdf-mode { padding: 10px; background: white; color: #000; font-family: 'Arial', sans-serif; }
        .pdf-mode #print-header { display: block !important; border-bottom: 3px double #00529C; padding-bottom: 15px; margin-bottom: 20px; text-align: center; }
        .pdf-mode #print-header h2 { font-weight: bold; font-size: 22px; margin: 0 0 5px 0; color: #00529C; letter-spacing: 1px; }
        .pdf-mode #print-header p { margin: 0; font-size: 14px; font-weight: bold; color: #333; }
        .pdf-mode .print-info-row { display: flex; justify-content: space-between; margin-top: 15px; text-align: left; font-size: 13px; color: #000; }
        .pdf-mode .table-custom { width: 100%; font-size: 12px; }
        .pdf-mode .table-custom th { background-color: #e6f0ff !important; color: #00529C !important; text-align: center; border: 1px solid #cce0ff; }
        .pdf-mode .table-custom td { border-bottom: 1px solid #f0f0f0; }
        .pdf-mode .table-light td { background-color: #f8f9fa !important; }
        .pdf-mode #print-footer { display: flex !important; justify-content: space-between; margin-top: 40px; font-size: 13px; text-align: center; color: #000; }
        .pdf-mode .sign-box { width: 250px; }
        .pdf-mode .sign-line { border-bottom: 1px solid #000; margin-top: 80px; display: block; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);"><i class="bi bi-journal-text me-2 text-primary"></i>Rekap Setoran Toko</h3>
                <p class="text-muted mb-0">Pantau riwayat uang fisik yang disetorkan khusus dari Karyawan Toko Umum/Sembako.</p>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 py-2 shadow-sm fw-bold">
                    <i class="bi bi-printer-fill me-2"></i> Cetak (B&W)
                </button>
                <button onclick="downloadPDF()" class="btn btn-danger rounded-pill px-4 py-2 shadow-sm fw-bold">
                    <i class="bi bi-file-earmark-pdf-fill me-2"></i> Download PDF
                </button>
            </div>
        </div>

        <div class="modern-card mb-4 p-3 bg-white">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">Dari Tanggal</label>
                    <input type="date" name="tgl_mulai" class="form-control" value="<?= htmlspecialchars($filter_tgl_mulai) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">Sampai Tanggal</label>
                    <input type="date" name="tgl_sampai" class="form-control" value="<?= htmlspecialchars($filter_tgl_sampai) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Nama Toko</label>
                    <select name="cabang" class="form-select">
                        <option value="semua">Semua Toko</option>
                        <?php 
                        $qc = $conn->query("SELECT * FROM cabang WHERE jenis = 'Toko' ORDER BY nama_cabang ASC");
                        if($qc){
                            while($c = $qc->fetch_assoc()): 
                                $sel = ($filter_cabang == $c['id']) ? 'selected' : '';
                        ?>
                                <option value="<?= $c['id'] ?>" <?= $sel ?>>🏪 <?= htmlspecialchars($c['nama_cabang']) ?></option>
                        <?php 
                            endwhile; 
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Nama Penyetor (Opsional)</label>
                    <input type="text" name="penyetor" class="form-control" placeholder="Cari nama karyawan..." value="<?= htmlspecialchars($filter_penyetor) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-modern w-100 py-2"><i class="bi bi-search me-1"></i> Filter Data</button>
                </div>
            </form>
        </div>

        <div class="modern-card" id="area-laporan">
            <div id="print-header">
                <h2>LAPORAN REKAPITULASI SETORAN TOKO</h2>
                <p>DITERIMA MELALUI KASIR / ADMIN PUSAT</p>
                <div class="print-info-row">
                    <div>
                        <strong>Periode Pembukuan:</strong> <?= date('d M Y', strtotime($filter_tgl_mulai)) ?> s/d <?= date('d M Y', strtotime($filter_tgl_sampai)) ?><br>
                        <strong>Filter Lokasi:</strong> <?= htmlspecialchars($nama_toko_filter) ?>
                    </div>
                    <div style="text-align: right;">
                        <strong>Tanggal Cetak:</strong> <?= date('d F Y') ?>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-custom text-nowrap align-middle">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tgl & Waktu Setor</th>
                            <th>Nama Toko</th>
                            <th>Nama Penyetor</th>
                            <th>Admin Penerima</th>
                            <th class="text-end">Jumlah Setoran (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($q_laporan && $q_laporan->num_rows > 0): $no=1; while($r = $q_laporan->fetch_assoc()): 
                            $total_semua_setoran += $r['jumlah_setoran'];
                        ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td>
                                <span class="fw-bold d-block"><?= date('d M Y', strtotime($r['tanggal'])) ?></span>
                                <small class="text-muted"><?= date('H:i', strtotime($r['created_at'])) ?> WIB</small>
                            </td>
                            <td>
                                <span class="badge bg-success px-3 py-2 rounded-pill">
                                    <i class="bi bi-shop me-1"></i> <?= htmlspecialchars($r['nama_cabang']) ?>
                                </span><br>
                                <small class="text-muted fw-bold ms-1">Shift <?= $r['shift_ke'] ?></small>
                            </td>
                            <td class="fw-bold text-dark"><i class="bi bi-person-fill text-secondary me-1"></i> <?= htmlspecialchars($r['nama_penyetor']) ?></td>
                            <td class="text-muted">@<?= htmlspecialchars($r['username']) ?></td>
                            <td class="text-end fw-bolder fs-5 text-success">Rp <?= number_format($r['jumlah_setoran'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada data setoran toko pada periode ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if($total_semua_setoran > 0): ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="text-end fw-bolder fs-5 text-dark">TOTAL SETORAN TOKO :</td>
                            <td class="text-end fw-bolder fs-4" style="color: var(--bri-blue);">Rp <?= number_format($total_semua_setoran, 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <div id="print-footer">
                <div class="sign-box">
                    Dibuat Oleh,<br><strong>Admin Pusat</strong>
                    <span class="sign-line"></span>
                </div>
                <div class="sign-box">
                    Mengetahui & Menerima,<br><strong>Pimpinan / Pemilik</strong>
                    <span class="sign-line"></span>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function downloadPDF() {
            const element = document.getElementById('area-laporan');
            element.classList.add('pdf-mode');
            const opt = {
                margin:       0.4,
                filename:     'Laporan_Setoran_Toko_<?= date('d-M-Y') ?>.pdf',
                image:        { type: 'jpeg', quality: 1 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'in', format: 'a4', orientation: 'landscape' }
            };
            html2pdf().set(opt).from(element).save().then(() => {
                element.classList.remove('pdf-mode');
            });
        }
    </script>
</body>
</html>