<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// Filter Tanggal
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Query Mengambil Log digabung dengan data User
$query = "SELECT l.*, u.username, u.role, c.nama_cabang, u.shift_ke 
          FROM log_aktivitas l 
          LEFT JOIN users u ON l.user_id = u.id 
          LEFT JOIN cabang c ON u.cabang_id = c.id
          WHERE DATE(l.waktu) BETWEEN '$start_date' AND '$end_date' 
          ORDER BY l.id DESC";
$result = $conn->query($query);
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
        :root { --bri-blue: #00529C; --bg-body: #f4f7fa; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--bg-body); }
        .main-content { margin-left: 250px; padding: 30px; transition: all 0.3s; }
        .modern-card { background: white; border-radius: 20px; padding: 25px; border: none; box-shadow: 0 5px 20px rgba(0,0,0,0.03); }
        .table-wrapper { max-height: 600px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: #fff; z-index: 1; border-bottom: 2px solid #e9ecef; }
        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 80px 15px 20px 15px !important; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1"><i class="bi bi-shield-lock text-primary me-2"></i>Audit Trail (Log Sistem)</h3>
                <p class="text-muted small mb-0">Pantau seluruh pergerakan, login, dan aksi krusial beserta IP Address pelakunya.</p>
            </div>
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
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2">Filter Log</button>
                </div>
            </form>
        </div>

        <div class="modern-card">
            <div class="table-responsive table-wrapper">
                <table class="table table-hover align-middle" style="font-size: 13px;">
                    <thead class="table-light">
                        <tr>
                            <th>Waktu Eksekusi</th>
                            <th>Pelaku / Akun</th>
                            <th>Aktivitas yang Dilakukan</th>
                            <th>Alamat IP (IP Address)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-muted"><?= date('d/m/Y H:i:s', strtotime($row['waktu'])); ?></td>
                                    <td>
                                        <?php if($row['role'] == 'admin'): ?>
                                            <span class="badge bg-dark text-white"><i class="bi bi-person-badge"></i> <?= strtoupper($row['username']); ?></span>
                                        <?php elseif($row['role'] == 'user'): ?>
                                            <span class="fw-bold text-primary"><?= $row['nama_cabang']; ?></span><br>
                                            <small class="text-muted">Kasir (Shift <?= $row['shift_ke']; ?>)</small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">System / Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['aktivitas']); ?></td>
                                    <td><span class="badge bg-light text-dark border font-monospace"><i class="bi bi-hdd-network me-1"></i><?= $row['ip_address']; ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">Tidak ada log aktivitas pada rentang tanggal ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>