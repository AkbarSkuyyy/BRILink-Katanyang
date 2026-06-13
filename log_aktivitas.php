<?php
require 'config.php';

// Pastikan Zona Waktu Akurat
date_default_timezone_set('Asia/Jakarta');

if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// Filter Tanggal
$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : date('Y-m-d');

$kondisi_query = "DATE(l.waktu) BETWEEN '$start_date' AND '$end_date'";

// =========================================================================
// FITUR BARU: EXPORT KE EXCEL
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $filename = "Audit_Log_Keamanan_" . $start_date . "_sd_" . $end_date . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='1'>";
    echo "<tr><th colspan='5'>AUDIT TRAIL (LOG SISTEM)</th></tr>";
    echo "<tr><th colspan='5'>Periode: $start_date s/d $end_date</th></tr>";
    echo "<tr>
            <th>No</th><th>Waktu Eksekusi</th><th>Pelaku / Akun</th>
            <th>Aktivitas yang Dilakukan</th><th>Alamat IP (IP Address)</th>
          </tr>";

    $q_export = $conn->query("
        SELECT l.*, u.username, u.role, c.nama_cabang, u.shift_ke 
        FROM log_aktivitas l 
        LEFT JOIN users u ON l.user_id = u.id 
        LEFT JOIN cabang c ON u.cabang_id = c.id
        WHERE $kondisi_query 
        ORDER BY l.id DESC
    ");

    $no = 1;
    while ($r = $q_export->fetch_assoc()) {
        $pelaku = "System / Unknown";
        if ($r['role'] == 'admin') {
            $pelaku = "ADMIN PUSAT (" . strtoupper($r['username']) . ")";
        } elseif ($r['role'] == 'user') {
            $pelaku = $r['nama_cabang'] . " - Shift " . $r['shift_ke'] . " (" . $r['username'] . ")";
        }

        echo "<tr>";
        echo "<td>" . $no++ . "</td>";
        echo "<td>" . date('d/m/Y H:i:s', strtotime($r['waktu'])) . "</td>";
        echo "<td>" . htmlspecialchars($pelaku) . "</td>";
        echo "<td>" . htmlspecialchars($r['aktivitas']) . "</td>";
        echo "<td>" . $r['ip_address'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

// =========================================================================
// FITUR BARU: PAGINATION (ANTI-LAG)
// =========================================================================
$limit = 100; // Menampilkan 100 data per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Total Data untuk Pagination
$q_total_data = $conn->query("SELECT COUNT(l.id) as total FROM log_aktivitas l WHERE $kondisi_query");
$total_data = $q_total_data->fetch_assoc()['total'];
$total_pages = ceil($total_data / $limit);

// Query Utama Mengambil Log sesuai Halaman (Limit & Offset)
$query = "SELECT l.*, u.username, u.role, c.nama_cabang, u.shift_ke 
          FROM log_aktivitas l 
          LEFT JOIN users u ON l.user_id = u.id 
          LEFT JOIN cabang c ON u.cabang_id = c.id
          WHERE $kondisi_query 
          ORDER BY l.id DESC 
          LIMIT $limit OFFSET $offset";
$result = $conn->query($query);

// Helper URL untuk tombol pagination & export
function build_url($page_num, $is_export = false) {
    global $start_date, $end_date;
    $url = "?start_date=$start_date&end_date=$end_date";
    if ($is_export) return $url . "&export=excel";
    return $url . "&page=$page_num";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Log Aktivitas - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; --bri-black: #1a1a1a; --bri-grey: #e9ecef; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 20px; padding: 25px; border: none; box-shadow: 0 5px 20px rgba(0,0,0,0.03); }
        
        .form-control { border-radius: 12px; padding: 10px 15px; font-weight: 500; border: 1px solid var(--bri-grey); background-color: var(--bg-body); }
        .form-control:focus { box-shadow: none; border-color: var(--bri-blue); }

        .table-wrapper { max-height: 600px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper::-webkit-scrollbar { width: 6px; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--bri-grey); border-radius: 10px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: #f8f9fa; z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); font-size: 13px; text-transform: uppercase;}

        .pagination .page-link { color: var(--bri-blue); font-weight: bold; border-radius: 8px; margin: 0 3px; border: none; background: #f8f9fa; }
        .pagination .page-item.active .page-link { background-color: var(--bri-blue); color: white; box-shadow: 0 4px 10px rgba(0,82,156,0.3); }

        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
            <div>
                <h3 class="fw-bold text-dark mb-1"><i class="bi bi-shield-lock text-primary me-2"></i>Audit Trail (Log Sistem)</h3>
                <p class="text-muted small mb-0">Pantau seluruh pergerakan, login, dan aksi krusial beserta IP Address pelakunya.</p>
            </div>
            
            <a href="<?= build_url(1, true); ?>" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm align-self-start py-2">
                <i class="bi bi-file-earmark-excel-fill me-2"></i> Export Excel
            </a>
        </div>

        <div class="modern-card mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold text-muted small">Dari Tanggal</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold text-muted small">Sampai Tanggal</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date; ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2" style="border-radius: 12px; background: linear-gradient(135deg, var(--bri-blue), #003366); border: none;">Filter Log</button>
                </div>
            </form>
        </div>

        <div class="modern-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">Rincian Aktivitas Tersimpan</h5>
                <span class="badge bg-secondary">Total: <?= number_format($total_data, 0, ',', '.'); ?> Baris Data</span>
            </div>

            <div class="table-responsive table-wrapper">
                <table class="table table-hover align-middle" style="font-size: 13px;">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Waktu Eksekusi</th>
                            <th>Pelaku / Akun</th>
                            <th>Aktivitas yang Dilakukan</th>
                            <th>Alamat IP (IP Address)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = $offset + 1;
                        if ($result && $result->num_rows > 0): 
                        ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-muted"><?= $no++; ?></td>
                                    <td class="fw-bold text-dark"><?= date('d/m/Y H:i:s', strtotime($row['waktu'])); ?></td>
                                    <td>
                                        <?php if($row['role'] == 'admin'): ?>
                                            <span class="badge bg-dark text-white py-2"><i class="bi bi-person-badge"></i> <?= strtoupper($row['username']); ?></span>
                                        <?php elseif($row['role'] == 'user'): ?>
                                            <span class="fw-bold text-primary"><i class="bi bi-shop me-1"></i><?= $row['nama_cabang']; ?></span><br>
                                            <small class="text-muted fw-semibold">Kasir (Shift <?= $row['shift_ke']; ?>) - <?= $row['username']; ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary py-2">System / Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-wrap" style="max-width: 300px; line-height: 1.4;">
                                        <?= htmlspecialchars($row['aktivitas']); ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border font-monospace px-2 py-1"><i class="bi bi-hdd-network me-1"></i><?= $row['ip_address']; ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted fw-medium">Tidak ada log aktivitas pada rentang tanggal ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>