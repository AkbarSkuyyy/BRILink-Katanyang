<?php
require 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit; }

// 1. Ambil Semua Data dan Hitung Status Cabang
$q_receh = $conn->query("
    SELECT c.nama_cabang, r.* FROM cabang c 
    LEFT JOIN uang_receh r ON c.id = r.cabang_id 
    ORDER BY c.nama_cabang ASC
");

$data_tabel = [];
$total_semua_receh = 0;
$cabang_kritis = 0;
$cabang_aman = 0;
$total_cabang = 0;

$pecahan_kolom = ['qty_100k', 'qty_50k', 'qty_20k', 'qty_10k', 'qty_5k', 'qty_2k', 'qty_1k'];

if ($q_receh && $q_receh->num_rows > 0) {
    while ($row = $q_receh->fetch_assoc()) {
        $total_cabang++;
        $total_semua_receh += (float)$row['total'];
        
        $is_kritis = false;
        $has_data = false;
        
        // Cek apakah cabang ini punya data dan apakah ada yang <= 20
        foreach ($pecahan_kolom as $p) {
            if ($row[$p] !== null && $row[$p] !== '') {
                $has_data = true;
                if ((int)$row[$p] <= 20) {
                    $is_kritis = true;
                }
            }
        }
        
        if ($has_data) {
            if ($is_kritis) {
                $cabang_kritis++;
            } else {
                $cabang_aman++;
            }
        }
        
        $data_tabel[] = $row;
    }
}

// Fungsi untuk mewarnai stok jika <= 20 lembar
function formatStok($qty) {
    if ($qty === null || $qty === '') return "<span class='text-muted small'>Kosong</span>";
    if ($qty <= 20) return "<span class='badge bg-danger rounded-pill px-3 py-2 shadow-sm' style='font-size: 13px; animation: pulse 2s infinite;'>$qty</span>";
    return "<span class='fw-bold text-dark fs-6'>$qty</span>";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Monitoring Receh - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        
        /* Modern Cards */
        .modern-card { background: var(--bri-white); border-radius: 24px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 24px; }
        
        /* Metric Cards */
        .metric-card { border-radius: 24px; border: none; padding: 24px; position: relative; overflow: hidden; height: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.04); transition: all 0.3s ease; }
        .metric-card:hover { transform: translateY(-5px); box-shadow: 0 15px 25px rgba(0,0,0,0.08); }
        .metric-card i { position: absolute; right: -15px; bottom: -20px; font-size: 110px; opacity: 0.15; transform: rotate(-10deg); transition: all 0.4s ease; }
        
        .bg-total { background: linear-gradient(135deg, #003366 0%, var(--bri-blue) 100%); color: var(--bri-white); }
        .bg-aman { background: linear-gradient(135deg, #198754 0%, #20c997 100%); color: var(--bri-white); }
        .bg-kritis { background: linear-gradient(135deg, #dc3545 0%, #ff4d4d 100%); color: var(--bri-white); }
        .bg-cabang { background: var(--bri-white); color: var(--bri-blue); border: 2px solid var(--bri-light-blue); }

        /* Search Input */
        .search-box { background-color: var(--bg-body); border-radius: 50px; padding: 5px 20px; border: 1px solid var(--bri-grey); display: flex; align-items: center; max-width: 350px; }
        .search-box input { border: none; background: transparent; box-shadow: none; outline: none; width: 100%; font-weight: 500; color: var(--bri-black); }
        
        /* Table Styling */
        .table-wrapper { max-height: 550px; overflow-y: auto; border-radius: 12px; }
        .table-wrapper thead th { position: sticky; top: 0; background-color: var(--bri-white); z-index: 1; border-bottom: 2px solid var(--bri-grey); color: var(--bri-black); font-weight: 700; text-align: center; padding: 15px; }
        .table-wrapper tbody td { border-bottom: 1px solid var(--bri-grey); vertical-align: middle; text-align: center; padding: 15px; }
        
        /* Animasi Nadi untuk Kritis */
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }

        /* Print Settings */
        .print-only { display: none; }
        @media print {
            body { background-color: white; margin: 0; padding: 0; }
            .sidebar, .btn, .search-box, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .modern-card { box-shadow: none; border: 1px solid #000; }
            .print-only { display: block; text-align: center; margin-bottom: 20px; }
            table { font-size: 11px !important; width: 100% !important; border-collapse: collapse; }
            table th, table td { border: 1px solid #000 !important; padding: 6px !important; }
            .badge { color: black !important; background: transparent !important; border: none !important; font-weight: bold; }
        }

        @media (max-width: 767.98px) { .main-content { margin-left: 0 !important; padding: 20px 15px !important; padding-top: 85px !important; } .search-box { max-width: 100%; margin-top: 15px; } }
    </style>
</head>
<body>
    <div class="sidebar no-print">
        <?php include 'sidebar.php'; ?>
    </div>
    
    <div class="main-content">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 no-print">
            <div>
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -0.5px;">Monitoring Uang Receh</h3>
                <p class="fw-medium mb-0" style="color: #6c757d;">Pantau dan amankan stok uang kembalian antar cabang</p>
            </div>
            <button onclick="window.print()" class="btn btn-dark fw-bold rounded-pill shadow-sm px-4 py-2">
                <i class="bi bi-printer-fill me-2"></i> Cetak Laporan
            </button>
        </div>

        <div class="print-only">
            <h3 style="font-weight: bold; margin-bottom: 2px;">REKAPITULASI FISIK UANG RECEH</h3>
            <p style="font-size: 14px; border-bottom: 2px solid #000; padding-bottom: 10px;"><strong>Dicetak Pada:</strong> <?= date('d/m/Y H:i'); ?> WIB</p>
        </div>

        <div class="row g-3 mb-4 no-print">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-total">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Aset Receh Global</p>
                    <h3 class="fw-bolder mb-0">Rp <?= number_format($total_semua_receh, 0, ',', '.'); ?></h3>
                    <i class="bi bi-safe-fill"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-kritis">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.8;">Cabang Kritis</p>
                    <h3 class="fw-bolder mb-0"><?= $cabang_kritis; ?> <span class="fs-6 opacity-75 fw-normal">Lokasi</span></h3>
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-aman">
                    <p class="mb-1 fw-semibold small opacity-75 text-uppercase tracking-wide">Cabang Aman</p>
                    <h3 class="fw-bolder mb-0"><?= $cabang_aman; ?> <span class="fs-6 opacity-75 fw-normal">Lokasi</span></h3>
                    <i class="bi bi-shield-check"></i>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card bg-cabang">
                    <p class="mb-1 fw-bold small text-uppercase tracking-wide" style="opacity: 0.6;">Total Terdaftar</p>
                    <h3 class="fw-bolder mb-0"><?= $total_cabang; ?> <span class="fs-6 opacity-75 fw-normal">Cabang</span></h3>
                    <i class="bi bi-shop" style="color: var(--bri-blue); opacity: 0.05;"></i>
                </div>
            </div>
        </div>

        <div class="modern-card">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3 border-bottom pb-3 no-print">
                <h5 class="fw-bold text-dark mb-0" style="color: var(--bri-black) !important;"><i class="bi bi-table me-2"></i>Tabel Pantauan Stok (Lembar/Keping)</h5>
                
                <div class="search-box">
                    <i class="bi bi-search text-muted me-2"></i>
                    <input type="text" id="cariCabang" placeholder="Cari nama cabang...">
                </div>
            </div>

            <div class="table-responsive table-wrapper">
                <table class="table table-borderless align-middle mb-0" id="tabelReceh">
                    <thead>
                        <tr>
                            <th class="text-start">Cabang</th>
                            <th>100k</th>
                            <th>50k</th>
                            <th>20k</th>
                            <th>10k</th>
                            <th>5k</th>
                            <th>2k</th>
                            <th>1k</th>
                            <th class="text-end">Total Uang (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($data_tabel) > 0): ?>
                            <?php foreach ($data_tabel as $row): ?>
                                <tr>
                                    <td class="text-start fw-bold" style="color: var(--bri-blue);"><?= $row['nama_cabang']; ?></td>
                                    <td><?= formatStok($row['qty_100k']); ?></td>
                                    <td><?= formatStok($row['qty_50k']); ?></td>
                                    <td><?= formatStok($row['qty_20k']); ?></td>
                                    <td><?= formatStok($row['qty_10k']); ?></td>
                                    <td><?= formatStok($row['qty_5k']); ?></td>
                                    <td><?= formatStok($row['qty_2k']); ?></td>
                                    <td><?= formatStok($row['qty_1k']); ?></td>
                                    <td class="text-end fw-bolder text-success fs-6">Rp <?= number_format((float)$row['total'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center text-muted py-5 fw-medium border-0">Data tidak tersedia.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-4 text-muted small fw-semibold no-print">
                <span class="badge bg-danger rounded-pill px-2 me-1">!</span> Indikator merah menyala (berdenyut) menandakan stok pecahan di cabang tersebut tersisa <strong>20 lembar/keping atau kurang</strong>. Segera lakukan pengisian silang.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fitur Live Search Pintar
        document.getElementById('cariCabang').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#tabelReceh tbody tr');
            
            rows.forEach(row => {
                // Ambil kolom pertama (Nama Cabang)
                let textCabang = row.querySelector('td:first-child').innerText.toLowerCase();
                if(textCabang.includes(filter)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>