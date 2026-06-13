<?php
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
                // Catat Log Keamanan
                catatLog($conn, "Membatalkan Transaksi ID: $id_trx (Rp $old_nom). Alasan: $alasan");
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
        
        @media print {
            body.printing-struk #nota-print-area { display: block !important; width: 100%; color: #000; }
            body.printing-struk .sidebar-modern, body.printing-struk .mobile-header, body.printing-struk .main-content { display: none !important; }
            body { background: white !important; margin: 0; padding: 0; }
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
                $q_riwayat->data_seek(0); // Reset pointer query
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
                    // Logika untuk menampilkan maksimal 5 nomor halaman
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
        // PENGATURAN CETAK (Gabungan dari Laporan dan Dashboard)
        function setPrintPageStyle(styleRule) {
            let style = document.getElementById('dynamic-print-style');
            if (!style) { style = document.createElement('style'); style.id = 'dynamic-print-style'; document.head.appendChild(style); }
            style.innerHTML = styleRule;
        }

        // CETAK NOTA UMUM
        function cetakNotaTiket(kode_nota, jenis, nominal, admin) {
            setPrintPageStyle('@page { size: 210mm 90mm; margin: 5mm; }');
            let cekTarik = jenis.toLowerCase().includes('tarik') ? '☑' : '☐';
            let cekTransfer = (jenis.toLowerCase().includes('transfer') || jenis.toLowerCase().includes('setor')) ? '☑' : '☐';
            let cekBayar = (jenis.toLowerCase().includes('bayar') || jenis.toLowerCase().includes('angsuran') || jenis.toLowerCase().includes('pulsa') || jenis.toLowerCase().includes('token')) ? '☑' : '☐';
            let tglWaktu = new Date().toLocaleString('id-ID');

            let notaContent = `
                <style>
                    #nota-print-area { font-family: Arial, sans-serif; font-size: 11px; line-height: 1.3; color: #000; width: 100%; max-width: 200mm; height: 80mm; margin: auto; position: relative; border: 2px solid #000; border-radius: 10px; background-color: #fff; display: flex; overflow: hidden; box-sizing: border-box; }
                    .ticket-main { flex: 3; padding: 10px 15px; border-right: 2px dashed #000; display: flex; flex-direction: column; justify-content: space-between; }
                    .ticket-stub { flex: 1; padding: 10px; background-color: #f9f9f9; display: flex; flex-direction: column; justify-content: space-between; }
                    .header-main { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 5px; }
                    .logo-box img { max-height: 30px; object-fit: contain; }
                    .title-main { text-align: right; }
                    .title-main strong { font-size: 16px; color: #00529C; letter-spacing: 1px; text-transform: uppercase;}
                    .sub-title { font-size: 9px; display: block; color: #555;}
                    .checkbox-group { display: flex; gap: 15px; margin-bottom: 5px; font-weight: bold; font-size: 11px; background: #e0e0e0; padding: 3px 10px; border-radius: 5px; width: max-content;}
                    .form-grid { display: flex; gap: 15px; }
                    .form-col { flex: 1; }
                    .form-group { display: flex; align-items: flex-end; margin-bottom: 3px; }
                    .label { width: 90px; font-weight: bold; font-size: 10px; color: #333;}
                    .titik-dua { width: 10px; text-align: center; }
                    .garis-bawah { flex: 1; border-bottom: 1px dotted #000; padding-left: 5px; min-height: 14px; font-size: 11px; }
                    .total-amount { font-size: 13px; font-weight: bold; }
                    .stub-title { font-weight: bold; font-size: 12px; text-align: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 5px; color: #00529C;}
                    .stub-info { font-size: 10px; margin-bottom: 3px; }
                    .ttd-section { display: flex; justify-content: space-between; margin-top: 15px; text-align: center; font-size: 9px;}
                    .ttd-box { width: 45%; }
                    .garis-ttd { border-bottom: 1px solid #000; margin-top: 25px; width: 100%; display: block; }
                </style>
                <div class="ticket-main">
                    <div class="header-main">
                        <div class="logo-box"><img src="assets/img/logo-katanyang.png" alt="Logo"></div>
                        <div class="title-main"><strong>BUKTI TRANSAKSI</strong><span class="sub-title">Jln. Poros Desa Katayang Kab.Seruyan</span></div>
                    </div>
                    <div class="checkbox-group"><span>${cekTarik} Tarik Tunai</span><span>${cekTransfer} Transfer</span><span>${cekBayar} Pembayaran</span></div>
                    <div class="form-grid">
                        <div class="form-col">
                            <div class="form-group"><div class="label">Kode Ref</div><div class="titik-dua">:</div><div class="garis-bawah"><strong>${kode_nota}</strong></div></div>
                            <div class="form-group"><div class="label">Waktu</div><div class="titik-dua">:</div><div class="garis-bawah">${tglWaktu}</div></div>
                            <div class="form-group"><div class="label">Penyetor</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                            <div class="form-group"><div class="label">No. Telp</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                        </div>
                        <div class="form-col">
                            <div class="form-group"><div class="label">Penerima</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                            <div class="form-group"><div class="label">No. Rek/Id</div><div class="titik-dua">:</div><div class="garis-bawah"></div></div>
                            <div class="form-group"><div class="label">Nominal</div><div class="titik-dua">:</div><div class="garis-bawah"><span class="total-amount">Rp ${nominal}</span></div></div>
                            <div class="form-group"><div class="label">Biaya Admin</div><div class="titik-dua">:</div><div class="garis-bawah">Rp ${admin}</div></div>
                        </div>
                    </div>
                </div>
                <div class="ticket-stub">
                    <div class="stub-title">TANDA TERIMA</div>
                    <div><div class="stub-info"><strong>Ref:</strong><br>${kode_nota}</div><div class="stub-info"><strong>Nominal:</strong><br>Rp ${nominal}</div></div>
                    <div class="ttd-section"><div class="ttd-box">Petugas<span class="garis-ttd"></span></div><div class="ttd-box">Nasabah<span class="garis-ttd"></span></div></div>
                </div>
            `;
            let printArea = document.getElementById('nota-print-area');
            printArea.innerHTML = notaContent;
            document.body.classList.add('printing-struk');
            setTimeout(function() { window.print(); }, 800);
            window.onafterprint = function() { document.body.classList.remove('printing-struk'); printArea.innerHTML = ''; };
        }

        // CETAK STRUK BOS
        function cetakStrukSetoranBos(id, tanggal, penyetor, target, nominal, kasir) {
            setPrintPageStyle('@page { size: A5 landscape; margin: 15mm; }');
            let htmlPrint = `
                <style>
                    #nota-print-area { font-family: 'Arial', sans-serif; font-size: 13px; line-height: 1.5; color: #000; width: 100%; max-width: 185mm; margin: auto; }
                    .hdr-bos { text-align: center; border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 20px; }
                    .hdr-bos h2 { margin: 0; font-size: 22px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase; }
                    .hdr-bos p { margin: 3px 0 0 0; font-size: 12px; }
                    .tb-info { width: 100%; margin-bottom: 20px; font-size: 13px; }
                    .tb-info td { padding: 4px 5px; vertical-align: middle; }
                    .lbl { font-weight: bold; width: 130px; }
                    .val { border-bottom: 1px dashed #000; }
                    .box-nominal { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; border: 2px solid #000; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; margin-bottom: 30px; border-radius: 5px;}
                    .box-nominal span { font-size: 12px; font-weight: normal; display: block; margin-bottom: 5px; }
                    .sign-grid { display: flex; justify-content: space-between; text-align: center; margin-top: 50px; font-size: 12px; }
                    .sign-col { width: 22%; display: flex; flex-direction: column; justify-content: space-between; }
                    .sign-line { border-bottom: 1px solid #000; margin-top: 60px; font-weight: bold; padding-bottom: 3px; display: block; }
                </style>
                <div class="hdr-bos"><h2>BUKTI SETORAN DANA BOS</h2><p>INJEKSI PENAMBAHAN MODAL SHIFT KASIR (REPRINT)</p></div>
                <table class="tb-info">
                    <tr><td class="lbl">No. Referensi</td><td width="10">:</td><td class="val"><strong>STB-${id}</strong></td><td width="30"></td><td class="lbl">Masuk Ke</td><td width="10">:</td><td class="val" style="font-size: 15px;"><strong>${target}</strong></td></tr>
                    <tr><td class="lbl">Tanggal / Waktu</td><td>:</td><td class="val">${tanggal}</td><td></td><td class="lbl">Nama Penyetor</td><td>:</td><td class="val">${penyetor}</td></tr>
                </table>
                <div class="box-nominal"><span>JUMLAH MODAL DITERIMA</span>Rp ${nominal}</div>
                <div class="sign-grid">
                    <div class="sign-col"><span>Penyetor,</span><span class="sign-line">${penyetor}</span></div>
                    <div class="sign-col"><span>Shift BRILink,</span><span class="sign-line">${kasir}</span></div>
                    <div class="sign-col"><span>Manajer,</span><span class="sign-line">(..........................)</span></div>
                    <div class="sign-col"><span>Bos / Pimpinan,</span><span class="sign-line">(..........................)</span></div>
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