<?php
mysqli_report(MYSQLI_REPORT_OFF);
require 'config.php';
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') { header("Location: index.php"); exit; }

$user_id = $_SESSION['user_id'];
$nama_kasir = isset($_SESSION['username']) ? $_SESSION['username'] : 'Kasir';
$tanggal_hari_ini = date('Y-m-d');

// =========================================================================
// 1. AUTO-UPDATE DATABASE: Menambahkan kolom 'status' transaksi (jika belum ada)
// =========================================================================
$check_status = $conn->query("SHOW COLUMNS FROM transactions LIKE 'status'");
if ($check_status && $check_status->num_rows == 0) {
    $conn->query("ALTER TABLE transactions ADD status VARCHAR(20) NOT NULL DEFAULT 'sukses' AFTER admin_source");
}

// =========================================================================
// 2. PROSES PEMBATALAN TRANSAKSI (VOID)
// =========================================================================
if (isset($_POST['batal_transaksi'])) {
    $id_trx = (int)$_POST['id_trx'];
    $alasan = $conn->real_escape_string(trim($_POST['alasan_batal']));
    
    // Ambil nominal lama untuk dicatat di keterangan
    $q_old = $conn->query("SELECT nominal, admin_fee, jenis_transaksi, tanggal FROM transactions WHERE id = '$id_trx' AND user_id = '$user_id'");
    
    if ($q_old && $q_old->num_rows > 0) {
        $old = $q_old->fetch_assoc();
        
        // Cek Keamanan: Transaksi hanya boleh dibatalkan pada hari yang sama
        if ($old['tanggal'] != $tanggal_hari_ini) {
            $_SESSION['flash_error'] = "Ditolak! Hanya transaksi hari ini yang dapat dibatalkan.";
        } else {
            $old_nom = $old['nominal'];
            $old_adm = $old['admin_fee'];
            
            // LOGIKA AKUNTANSI: Mengubah nominal jadi 0 agar perhitungan di Dashboard/Laporan ikut ter-koreksi otomatis
            $update = $conn->query("UPDATE transactions SET 
                status = 'batal', 
                nominal = 0, 
                admin_fee = 0, 
                keterangan = CONCAT('[DIBATALKAN | Awal: Rp ', $old_nom, ' | Adm: Rp ', $old_adm, '] ', keterangan, ' | Alasan: ', '$alasan') 
                WHERE id = '$id_trx' AND user_id = '$user_id'");
                
            if ($update) {
                $_SESSION['flash_success'] = "Transaksi berhasil dibatalkan dan nominal telah dikoreksi.";
            }
        }
    }
    header("Location: riwayat.php"); exit;
}

// =========================================================================
// 3. FILTER TANGGAL & PAGINATION (ANTI-LAG)
// =========================================================================
$filter_tgl_mulai = isset($_GET['tgl_mulai']) ? $conn->real_escape_string($_GET['tgl_mulai']) : date('Y-m-d');
$filter_tgl_sampai = isset($_GET['tgl_sampai']) ? $conn->real_escape_string($_GET['tgl_sampai']) : date('Y-m-d');

$kondisi_query = "WHERE user_id = '$user_id' AND tanggal BETWEEN '$filter_tgl_mulai' AND '$filter_tgl_sampai'";

// Setup Pagination
$limit = 50; // Maksimal 50 data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Menghitung total data untuk membuat tombol halaman
$q_total_data = $conn->query("SELECT COUNT(id) as total FROM transactions $kondisi_query");
$total_data = $q_total_data->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

// Menghitung ringkasan (Mengabaikan yang berstatus 'batal')
$q_ringkasan = $conn->query("SELECT SUM(nominal) as tot_nominal, SUM(admin_fee) as tot_admin FROM transactions $kondisi_query AND status = 'sukses'");
$d_ringkasan = $q_ringkasan->fetch_assoc();
$total_perputaran = !empty($d_ringkasan['tot_nominal']) ? $d_ringkasan['tot_nominal'] : 0;
$total_laba = !empty($d_ringkasan['tot_admin']) ? $d_ringkasan['tot_admin'] : 0;

// Mengambil Data sesuai Halaman
$q_riwayat = $conn->query("SELECT * FROM transactions $kondisi_query ORDER BY tanggal DESC, waktu DESC, id DESC LIMIT $limit OFFSET $offset");

// Fungsi pembantu untuk URL Pagination
function build_url($page_num) {
    global $filter_tgl_mulai, $filter_tgl_sampai;
    return "?tgl_mulai=$filter_tgl_mulai&tgl_sampai=$filter_tgl_sampai&page=$page_num";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Riwayat Transaksi - Kasir</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> 
        :root { --bg-body: #f4f7fa; --bri-blue: #00529C; --bri-black: #1a1a1a; --bri-light-blue: #e6f0ff; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
        .modern-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 30px; border: none; }
        .btn-modern { background: linear-gradient(135deg, var(--bri-blue), #003366); color: white; border-radius: 12px; font-weight: 600; border: none; transition: all 0.3s; }
        .btn-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,82,156,0.3); color: white; }
        .form-control { border-radius: 12px; padding: 10px 15px; font-weight: 600; background-color: var(--bg-body); border: 1px solid #dee2e6; }
        .form-control:focus { border-color: var(--bri-blue); box-shadow: none; }
        
        .table-custom th { background-color: #e6f0ff; color: var(--bri-blue); font-weight: 700; text-transform: uppercase; font-size: 12px; padding: 15px; border-bottom: 2px solid #cce0ff; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f0f0f0; font-size: 14px; font-weight: 500; }
        
        /* Mobile Card Styling */
        .trx-card { background: white; border-radius: 16px; padding: 15px; margin-bottom: 12px; border-left: 5px solid var(--bri-blue); box-shadow: 0 2px 8px rgba(0,0,0,0.05); position: relative; overflow: hidden; }
        .trx-card.batal { border-left-color: #dc3545; opacity: 0.7; }
        .trx-card.batal::after { content: 'DIBATALKAN'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-20deg); font-size: 30px; font-weight: 900; color: rgba(220, 53, 69, 0.15); pointer-events: none; }
        
        /* Strikethrough for cancelled rows */
        .row-batal td { text-decoration: line-through; color: #a0a0a0 !important; }
        .row-batal .badge { text-decoration: none !important; opacity: 0.6; }

        .pagination .page-link { color: var(--bri-blue); font-weight: bold; border-radius: 8px; margin: 0 3px; border: none; background: #f8f9fa; }
        .pagination .page-item.active .page-link { background-color: var(--bri-blue); color: white; box-shadow: 0 4px 10px rgba(0,82,156,0.3); }

        #nota-print-area { display: none; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0; padding: 80px 15px 20px 15px; } }
        
        /* CSS CETAK ANTI-ERROR UNTUK PC & HP (Epson LX-310 Compatible) */
        @media print {
            body { background-color: white !important; margin: 0 !important; padding: 0 !important; }
            body > :not(#nota-print-area) { display: none !important; }
            .sidebar-modern, .mobile-header, .main-content { display: none !important; }
            
            body.printing-struk #nota-print-area { display: block !important; width: 100%; margin:0; padding:0; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h3 class="fw-extrabold mb-1" style="color: var(--bri-black);"><i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Transaksi</h3>
        <p class="text-muted mb-4">Pantau aktivitas, cetak ulang bukti, dan batalkan transaksi yang salah input.</p>

        <div class="row g-3 mb-4">
            <div class="col-md-5">
                <div class="modern-card h-100">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted mb-1">Dari Tanggal</label>
                            <input type="date" name="tgl_mulai" class="form-control" value="<?= htmlspecialchars($filter_tgl_mulai) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted mb-1">Sampai Tanggal</label>
                            <input type="date" name="tgl_sampai" class="form-control" value="<?= htmlspecialchars($filter_tgl_sampai) ?>" required>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-modern w-100 py-2"><i class="bi bi-search me-1"></i> Tampilkan Riwayat</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="col-md-7">
                <div class="modern-card h-100 d-flex justify-content-around align-items-center flex-row">
                    <div class="text-center" style="width: 50%;">
                        <p class="text-muted small mb-1 fw-bold text-uppercase" style="font-size: 10px;">Total Perputaran</p>
                        <h5 class="fw-bold text-dark mb-0 text-truncate" style="font-size: clamp(1rem, 4vw, 1.5rem);">
                            Rp <?= number_format($total_perputaran, 0, ',', '.'); ?>
                        </h5>
                    </div>
                    <div class="border-end h-75"></div>
                    <div class="text-center" style="width: 50%;">
                        <p class="text-muted small mb-1 fw-bold text-uppercase" style="font-size: 10px;">Laba Admin</p>
                        <h5 class="fw-bold text-success mb-0 text-truncate" style="font-size: clamp(1rem, 4vw, 1.5rem);">
                            + Rp <?= number_format($total_laba, 0, ',', '.'); ?>
                        </h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <h6 class="fw-bold mb-3 pb-2 border-bottom text-dark">
                Daftar Transaksi <span class="badge bg-primary ms-2"><?= $total_data; ?> Data</span>
            </h6>
            
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-custom text-nowrap align-middle">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Layanan</th>
                            <th>Target / Sumber</th>
                            <th>Nominal (Rp)</th>
                            <th>Admin (Rp)</th>
                            <th>Keterangan</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($q_riwayat && $q_riwayat->num_rows > 0): 
                            while ($r = $q_riwayat->fetch_assoc()): 
                                $is_batal = ($r['status'] == 'batal');
                                $row_class = $is_batal ? 'row-batal' : '';
                                
                                // Deteksi Penyetor Bos
                                $penyetor_bos = "Bos/Pimpinan";
                                if ($r['jenis_transaksi'] == 'Setor Dana Bos') {
                                    $parts = explode('|', $r['keterangan']);
                                    $penyetor_bos = trim(str_replace('Penyetor:', '', $parts[0]));
                                }
                        ?>
                        <tr class="<?= $row_class; ?>">
                            <td>
                                <span class="fw-bold d-block"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></span>
                                <small class="text-muted"><?= isset($r['waktu']) ? date('H:i', strtotime($r['waktu'])) : '-' ?> WIB</small>
                            </td>
                            <td>
                                <?php if ($is_batal): ?>
                                    <span class="badge bg-danger">DIBATALKAN</span>
                                <?php elseif ($r['jenis_transaksi'] == 'Setor Dana Bos'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success"><i class="bi bi-box-arrow-in-down me-1"></i>Setor Bos</span>
                                <?php elseif ($r['jenis_transaksi'] == 'Tarik Dana Bos'): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger"><i class="bi bi-box-arrow-up me-1"></i>Tarik Bos</span>
                                <?php else: ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary"><i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($r['jenis_transaksi']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($r['bank_agen']) ?></span></td>
                            <td class="fw-bolder <?= ($r['jenis_transaksi'] == 'Pengeluaran / Rugi' || $r['jenis_transaksi'] == 'Tarik Dana Bos') ? 'text-danger' : 'text-dark' ?>">
                                <?= number_format($r['nominal'], 0, ',', '.') ?>
                            </td>
                            <td class="fw-bolder text-success">
                                <?= number_format($r['admin_fee'], 0, ',', '.') ?>
                            </td>
                            <td><small class="text-muted text-wrap d-inline-block" style="max-width: 250px; line-height: 1.2;"><?= htmlspecialchars($r['keterangan']) ?></small></td>
                            
                            <td class="text-center">
                                <?php if(!$is_batal): ?>
                                    <div class="btn-group shadow-sm">
                                        <?php if ($r['jenis_transaksi'] == 'Setor Dana Bos'): ?>
                                            <button class="btn btn-sm btn-light text-success fw-bold border" onclick="cetakStrukSetoranBos('<?= str_pad($r['id'], 5, '0', STR_PAD_LEFT) ?>', '<?= date('d M Y / H:i', strtotime($r['tanggal'] . ' ' . ($r['waktu'] ?? '00:00:00'))) ?>', '<?= addslashes($penyetor_bos) ?>', '<?= addslashes($r['bank_agen']) ?>', '<?= number_format($r['nominal'], 0, ',', '.') ?>', '<?= addslashes($nama_kasir) ?>')" title="Cetak Struk"><i class="bi bi-printer-fill"></i></button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light text-primary fw-bold border" onclick="cetakNotaTiket('TRX-<?= date('Ymd', strtotime($r['tanggal'])); ?>-<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT); ?>', '<?= htmlspecialchars($r['jenis_transaksi']); ?>', '<?= number_format($r['nominal'], 0, ',', '.'); ?>', '<?= number_format($r['admin_fee'], 0, ',', '.'); ?>')" title="Cetak Nota"><i class="bi bi-receipt"></i></button>
                                        <?php endif; ?>
                                        
                                        <?php if($r['tanggal'] == $tanggal_hari_ini): ?>
                                            <button class="btn btn-sm btn-light text-danger fw-bold border" onclick="konfirmasiBatal(<?= $r['id'] ?>)" title="Batalkan Transaksi"><i class="bi bi-x-circle-fill"></i></button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-danger small fw-bold"><i class="bi bi-ban"></i> Void</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-5">Belum ada transaksi pada periode ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-md-none">
                <?php 
                $q_riwayat->data_seek(0);
                if ($q_riwayat && $q_riwayat->num_rows > 0):
                    while ($row = $q_riwayat->fetch_assoc()): 
                        $is_batal = ($row['status'] == 'batal');
                        $card_class = $is_batal ? 'batal' : '';
                        
                        $penyetor_bos = "Bos/Pimpinan";
                        if ($row['jenis_transaksi'] == 'Setor Dana Bos') {
                            $parts = explode('|', $row['keterangan']);
                            $penyetor_bos = trim(str_replace('Penyetor:', '', $parts[0]));
                        }
                ?>
                    <div class="trx-card <?= $card_class; ?>">
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                            <?php if ($is_batal): ?>
                                <span class="badge bg-danger">DIBATALKAN</span>
                            <?php else: ?>
                                <span class="badge bg-primary"><?= $row['jenis_transaksi']; ?></span>
                            <?php endif; ?>
                            <small class="text-muted fw-bold"><?= date('d/m/y', strtotime($row['tanggal'])); ?> - <?= date('H:i', strtotime($row['waktu'] ?? '00:00:00')); ?></small>
                        </div>
                        
                        <div class="fw-bold text-dark mb-2 text-break" style="font-size: 0.9rem; line-height: 1.3;">
                            <?= $row['keterangan'] ?: '-'; ?>
                        </div>
                        
                        <div class="pt-2">
                            <div class="row align-items-center">
                                <div class="col-6">
                                    <small class="text-muted d-block" style="font-size: 10px;">NOMINAL</small>
                                    <span class="fw-bold d-block <?= $is_batal ? 'text-muted text-decoration-line-through' : 'text-dark' ?>" style="font-size: 0.95rem;">
                                        Rp <?= number_format($row['nominal'], 0, ',', '.'); ?>
                                    </span>
                                </div>
                                <div class="col-6 text-end">
                                    <small class="text-muted d-block" style="font-size: 10px;">ADMIN</small>
                                    <span class="fw-bold d-block <?= $is_batal ? 'text-muted text-decoration-line-through' : 'text-success' ?>" style="font-size: 0.95rem;">
                                        + Rp <?= number_format($row['admin_fee'], 0, ',', '.'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if(!$is_batal): ?>
                        <div class="border-top pt-3 mt-2 d-flex gap-2">
                            <?php if ($row['jenis_transaksi'] == 'Setor Dana Bos'): ?>
                                <button class="btn btn-sm btn-outline-success rounded-pill w-100 fw-bold py-2" onclick="cetakStrukSetoranBos('<?= str_pad($row['id'], 5, '0', STR_PAD_LEFT) ?>', '<?= date('d M Y / H:i', strtotime($row['tanggal'] . ' ' . ($row['waktu'] ?? '00:00:00'))) ?>', '<?= addslashes($penyetor_bos) ?>', '<?= addslashes($row['bank_agen']) ?>', '<?= number_format($row['nominal'], 0, ',', '.') ?>', '<?= addslashes($nama_kasir) ?>')"><i class="bi bi-printer me-1"></i> Cetak Bos</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-primary rounded-pill w-100 fw-bold py-2" onclick="cetakNotaTiket('TRX-<?= date('Ymd', strtotime($row['tanggal'])); ?>-<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?>', '<?= htmlspecialchars($row['jenis_transaksi']); ?>', '<?= number_format($row['nominal'], 0, ',', '.'); ?>', '<?= number_format($row['admin_fee'], 0, ',', '.'); ?>')"><i class="bi bi-receipt me-1"></i> Cetak Nota</button>
                            <?php endif; ?>
                            
                            <?php if($row['tanggal'] == $tanggal_hari_ini): ?>
                                <button class="btn btn-sm btn-outline-danger rounded-pill fw-bold px-3 py-2" onclick="konfirmasiBatal(<?= $row['id'] ?>)"><i class="bi bi-x-lg"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-center mt-4">
                <ul class="pagination pagination-sm mb-0 shadow-sm rounded-3">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= build_url($page - 1) ?>"><i class="bi bi-chevron-left"></i> Prev</a>
                    </li>
                    <?php 
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                            <a class="page-link" href="<?= build_url($i) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= build_url($page + 1) ?>">Next <i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <form id="formBatalTrx" method="POST" style="display: none;">
        <input type="hidden" name="id_trx" id="batal_id_trx">
        <input type="hidden" name="alasan_batal" id="batal_alasan">
        <input type="hidden" name="batal_transaksi" value="1">
    </form>

    <div id="nota-print-area"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setPrintPageStyle(styleRule) {
            let style = document.getElementById('dynamic-print-style');
            if (!style) { style = document.createElement('style'); style.id = 'dynamic-print-style'; document.head.appendChild(style); }
            style.innerHTML = styleRule;
        }

        // =======================================================
        // 1. FUNGSI CETAK TIKET/NOTA TRANSAKSI (LOGO + CHECKLIST)
        // Didesain Menggunakan Struktur Tabel Murni agar Aman di HP dan Dot Matrix
        // =======================================================
        function cetakNotaTiket(kode_nota, jenis, nominal, admin) {
            // Matikan margin bawaan browser agar kertas dihitung murni 5.5 inci (Half Letter)
            setPrintPageStyle('@page { size: 8.5in 5.5in; margin: 0; }');
            
            let j = jenis.toLowerCase();
            
            // Logika Checklist Cerdas
            let c_tarik = (j.includes('tarik')) ? '☑' : '☐';
            let c_setor = (j.includes('setor') && !j.includes('bos')) ? '☑' : '☐';
            let c_tf    = (j.includes('transfer')) ? '☑' : '☐';
            let c_topup = (j.includes('topup') || j.includes('e-wallet')) ? '☑' : '☐';
            let c_pulsa = (j.includes('pulsa') || j.includes('token') || j.includes('data') || j.includes('listrik')) ? '☑' : '☐';
            let c_bayar = (j.includes('pdam') || j.includes('cicilan') || j.includes('bayar') || j.includes('finance')) ? '☑' : '☐';
            let c_buka  = (j.includes('buka') || j.includes('rekening')) ? '☑' : '☐';
            let c_receh = (j.includes('receh') || j.includes('tukar')) ? '☑' : '☐';
            
            let c_lain = (c_tarik==='☐' && c_setor==='☐' && c_tf==='☐' && c_topup==='☐' && c_pulsa==='☐' && c_bayar==='☐' && c_buka==='☐' && c_receh==='☐') ? '☑' : '☐';

            let tglWaktu = new Date().toLocaleString('id-ID');

            let notaContent = `
                <div style="width: 8in; height: 5in; font-family: Arial, sans-serif; color: #000; border: 3px solid #000; padding: 15px; box-sizing: border-box; background: #fff; margin: 0.2in auto; border-radius: 8px; position: relative; overflow: hidden; page-break-after: avoid;">
                    
                    <table width="100%" border="0" cellpadding="0" cellspacing="0" style="border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 10px;">
                        <tr>
                            <td width="60%">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <img src="assets/img/logo-katanyang.png" style="max-height: 35px; filter: grayscale(100%);">
                                    <h2 style="margin:0; font-size:22px; font-weight:900;">AGEN BRILINK</h2>
                                </div>
                            </td>
                            <td align="right" width="40%" valign="bottom">
                                <strong style="font-size:16px;">BUKTI TRANSAKSI / TANDA TERIMA</strong><br>
                                <span style="font-size:11px;">Jln. Poros Desa Katayang</span>
                            </td>
                        </tr>
                    </table>

                    <table width="100%" border="1" cellpadding="5" cellspacing="0" style="font-size:11px; margin-bottom:12px; border-collapse: collapse; border: 1px solid #000; font-weight: bold;">
                        <tr>
                            <td width="33%">${c_tarik} Tarik Tunai</td>
                            <td width="33%">${c_setor} Setor Tunai</td>
                            <td width="34%">${c_tf} Transfer Dana</td>
                        </tr>
                        <tr>
                            <td>${c_topup} TopUp / E-Wallet</td>
                            <td>${c_pulsa} Pulsa / Token</td>
                            <td>${c_bayar} Tagihan / Cicilan</td>
                        </tr>
                        <tr>
                            <td>${c_buka} Buka Rekening</td>
                            <td>${c_receh} Tukar Receh</td>
                            <td>${c_lain} Transaksi Lainnya</td>
                        </tr>
                    </table>

                    <table width="100%" border="0" cellpadding="4" cellspacing="0" style="font-size:13px;">
                        <tr>
                            <td width="15%"><strong>Kode Ref</strong></td><td width="2%">:</td><td width="33%" style="border-bottom:1px dotted #000;"><strong>${kode_nota}</strong></td>
                            <td width="15%"><strong>Penerima</strong></td><td width="2%">:</td><td width="33%" style="border-bottom:1px dotted #000;"></td>
                        </tr>
                        <tr>
                            <td><strong>Waktu</strong></td><td>:</td><td style="border-bottom:1px dotted #000;">${tglWaktu}</td>
                            <td><strong>No.Rek/ID</strong></td><td>:</td><td style="border-bottom:1px dotted #000;"></td>
                        </tr>
                        <tr>
                            <td><strong>Penyetor</strong></td><td>:</td><td style="border-bottom:1px dotted #000;"></td>
                            <td><strong>Nominal</strong></td><td>:</td><td style="border-bottom:1px dotted #000; font-size:14px;"><strong>Rp ${nominal}</strong></td>
                        </tr>
                        <tr>
                            <td><strong>No. Telp</strong></td><td>:</td><td style="border-bottom:1px dotted #000;"></td>
                            <td><strong>Admin</strong></td><td>:</td><td style="border-bottom:1px dotted #000;">Rp ${admin}</td>
                        </tr>
                    </table>

                    <table width="100%" style="margin-top: 20px; text-align:center; font-size:12px; font-weight: bold;">
                        <tr>
                            <td width="40%">Nasabah / Penyetor<br><br><br>( ...................................... )</td>
                            <td width="20%"></td>
                            <td width="40%">Petugas Kasir<br><br><br>( ...................................... )</td>
                        </tr>
                    </table>
                    
                    <div style="position: absolute; bottom: 5px; left: 0; width: 100%; text-align: center; font-size: 10px; font-style: italic;">
                        * Simpan struk ini sebagai bukti transaksi yang sah. Terima Kasih *
                    </div>
                </div>
            `;
            let printArea = document.getElementById('nota-print-area');
            printArea.innerHTML = notaContent;
            document.body.classList.add('printing-struk');
            setTimeout(function() { window.print(); }, 500);
            window.onafterprint = function() { document.body.classList.remove('printing-struk'); printArea.innerHTML = ''; };
        }

        // =======================================================
        // 2. FUNGSI CETAK BUKTI SETORAN BOS (4 TTD - LOGO GRAYSCALE)
        // =======================================================
        function cetakStrukSetoranBos(id, tanggal, penyetor, target, nominal, kasir) {
            setPrintPageStyle('@page { size: 8.5in 5.5in; margin: 0; }');
            
            let htmlPrint = `
                <div style="width: 8in; height: 5in; font-family: Arial, sans-serif; color: #000; border: 3px solid #000; padding: 15px; box-sizing: border-box; background: #fff; margin: 0.2in auto; border-radius: 8px; position: relative; overflow: hidden; page-break-after: avoid;">
                    
                    <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 10px;">
                        <img src="assets/img/logo-katanyang.png" style="max-height: 40px; filter: grayscale(100%); margin-bottom: 5px; display: block; margin: 0 auto;">
                        <h2 style="margin:0; font-size:20px; font-weight:900; letter-spacing: 1px;">BUKTI SETORAN DANA BOS</h2>
                        <p style="margin: 2px 0 0 0; font-size: 12px; font-weight: bold;">INJEKSI PENAMBAHAN MODAL SHIFT KASIR (REPRINT)</p>
                    </div>

                    <table width="100%" border="0" cellpadding="6" cellspacing="0" style="font-size:13px; margin-bottom: 15px;">
                        <tr>
                            <td width="20%"><strong>No. Referensi</strong></td><td width="2%">:</td><td width="38%" style="border-bottom:1px dotted #000;"><strong>STB-${id}</strong></td>
                            <td width="15%"><strong>Masuk Ke</strong></td><td width="2%">:</td><td width="23%" style="border-bottom:1px dotted #000; font-size: 14px;"><strong>${target}</strong></td>
                        </tr>
                        <tr>
                            <td><strong>Tanggal/Waktu</strong></td><td>:</td><td style="border-bottom:1px dotted #000;">${tanggal}</td>
                            <td><strong>Penyetor</strong></td><td>:</td><td style="border-bottom:1px dotted #000;">${penyetor}</td>
                        </tr>
                    </table>

                    <div style="background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; border: 2px solid #000; padding: 10px; text-align: center; margin-bottom: 20px; border-radius: 5px;">
                        <span style="font-size: 11px; font-weight: bold; display: block; margin-bottom: 3px;">JUMLAH MODAL DITERIMA</span>
                        <strong style="font-size: 22px;">Rp ${nominal}</strong>
                    </div>

                    <table width="100%" style="text-align:center; font-size:11px; font-weight: bold; margin-top: 20px;">
                        <tr>
                            <td width="25%">Penyetor<br><br><br><br>( ${penyetor} )</td>
                            <td width="25%">Shift BRILink<br><br><br><br>( ${kasir} )</td>
                            <td width="25%">Manajer<br><br><br><br>( ......................... )</td>
                            <td width="25%">Bos / Pimpinan<br><br><br><br>( ......................... )</td>
                        </tr>
                    </table>
                </div>
            `;
            let printArea = document.getElementById('nota-print-area');
            printArea.innerHTML = htmlPrint;
            document.body.classList.add('printing-struk');
            setTimeout(function() { window.print(); }, 500);
            window.onafterprint = function() { document.body.classList.remove('printing-struk'); printArea.innerHTML = ''; };
        }

        // FUNGSI KONFIRMASI BATAL TRANSAKSI
        function konfirmasiBatal(id) {
            Swal.fire({
                title: 'Batalkan Transaksi?',
                html: 'Nominal uang dan admin akan di-Nol-kan. Perhitungan laci akan langsung ter-koreksi.<br><br><b>Masukkan alasan pembatalan:</b>',
                input: 'text',
                inputAttributes: { autocapitalize: 'off', required: 'true', placeholder: 'Cth: Salah input nominal' },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Batalkan!',
                cancelButtonText: 'Kembali',
                preConfirm: (alasan) => {
                    if (!alasan) { Swal.showValidationMessage('Alasan pembatalan wajib diisi!'); }
                    return alasan;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('batal_id_trx').value = id;
                    document.getElementById('batal_alasan').value = result.value;
                    document.getElementById('formBatalTrx').submit();
                }
            });
        }

        // SWAL NOTIFICATIONS
        <?php if (isset($_SESSION['flash_success'])): ?>
            Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true, icon: 'success', title: '<?= $_SESSION['flash_success']; ?>' });
        <?php unset($_SESSION['flash_success']); endif; ?>
        
        <?php if (isset($_SESSION['flash_error'])): ?>
            Swal.fire({ icon: 'error', title: 'Ditolak / Gagal!', text: '<?= $_SESSION['flash_error']; ?>', confirmButtonColor: '#00529C' });
        <?php unset($_SESSION['flash_error']); endif; ?>
    </script>
</body>
</html>