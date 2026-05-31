<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
    :root {
        --bri-blue: #00529C;
        --bri-light-blue: #e6f0ff;
        --bri-black: #1a1a1a;
        --bri-white: #ffffff;
        --bri-grey: #f4f7fa;
        --bri-border: #e9ecef;
        --bri-green: #198754;   
        --bri-red: #dc3545;     
        --bri-light-red: #ffe6e6; 
    }

    /* Base Sidebar */
    .sidebar-modern {
        background-color: var(--bri-white);
        border-right: 1px solid var(--bri-border);
        box-shadow: 4px 0 20px rgba(0,0,0,0.02);
    }

    /* CSS KHUSUS DESKTOP */
    @media (min-width: 768px) {
        #sidebarMenu {
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            /* Menggunakan 100dvh agar presisi di semua perangkat */
            height: 100vh;
            height: 100dvh; 
            z-index: 1040;
        }
    }

    /* Header Mobile yang Kebal Tertumpuk */
    .mobile-header {
        background-color: var(--bri-white);
        border-bottom: 1px solid var(--bri-border);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        position: fixed;
        top: 0; left: 0; width: 100%;
        z-index: 1050 !important;
    }

    /* Style Menu Navigasi */
    .nav-link-modern {
        color: var(--bri-black);
        font-weight: 600;
        border-radius: 12px;
        padding: 12px 20px;
        margin-bottom: 4px;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
    }
    .nav-link-modern i {
        font-size: 1.2rem;
        margin-right: 12px;
        color: #6c757d;
        transition: all 0.3s ease;
    }
    
    .nav-link-modern:hover {
        background-color: var(--bri-grey);
        color: var(--bri-blue);
        transform: translateX(4px);
    }
    .nav-link-modern:hover i { color: var(--bri-blue); }

    .nav-link-modern.active {
        background-color: var(--bri-light-blue) !important;
        color: var(--bri-blue) !important;
        font-weight: 700;
        box-shadow: 0 4px 10px rgba(0, 82, 156, 0.1);
    }
    .nav-link-modern.active i { color: var(--bri-blue) !important; }

    /* CSS Tombol Logout */
    .btn-logout {
        background-color: var(--bri-light-red);
        color: var(--bri-red);
        border: none;
        border-radius: 14px;
        font-weight: 700;
        padding: 12px;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
    }
    .btn-logout:hover {
        background-color: var(--bri-red);
        color: var(--bri-white);
        box-shadow: 0 6px 15px rgba(220, 53, 69, 0.25);
        transform: translateY(-2px);
    }

    .status-dot {
        width: 10px; height: 10px; background-color: var(--bri-green);
        border-radius: 50%; display: inline-block; box-shadow: 0 0 8px rgba(25, 135, 84, 0.5);
    }
</style>

<div class="d-md-none d-flex justify-content-between align-items-center p-3 mobile-header">
    <span class="fs-4 fw-extrabold" style="letter-spacing: -0.5px;">
        <span style="color: var(--bri-blue);">BRILink</span><span style="color: var(--bri-black);">App</span>
    </span>
    <button class="btn border-0 p-0 shadow-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
        <i class="bi bi-list" style="font-size: 2.2rem; color: var(--bri-black);"></i>
    </button>
</div>

<div class="offcanvas-md offcanvas-start sidebar-modern d-flex flex-column" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel" style="z-index: 1055; height: 100vh; height: 100dvh;">
    
    <div class="offcanvas-header border-bottom d-md-none mt-1" style="border-color: var(--bri-border) !important; flex-shrink: 0;">
        <div class="d-flex flex-column w-100">
            <div class="d-flex justify-content-between align-items-center">
                <span class="fs-4 fw-extrabold" style="letter-spacing: -0.5px;" id="sidebarMenuLabel">
                    <span style="color: var(--bri-blue);">BRILink</span><span style="color: var(--bri-black);">App</span>
                </span>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Close"></button>
            </div>
            <img src="assets/img/logo-katanyang.png" alt="Logo BRILink" class="mt-2 img-fluid" style="max-height: 40px; width: fit-content; object-fit: contain;">
        </div>
    </div>
    
    <div class="p-3 overflow-y-auto flex-grow-1">
        
        <a href="#" class="d-none d-md-flex flex-column align-items-center justify-content-center mb-4 mt-2 text-decoration-none">
            <span class="fs-3 fw-extrabold text-center w-100" style="letter-spacing: -1px;">
                <span style="color: var(--bri-blue);">BRILink</span><span style="color: var(--bri-black);">App</span>
            </span>
            <img src="assets/img/logo-katanyang.png" alt="Logo BRILink" class="mt-2 img-fluid" style="max-width: 120px; object-fit: contain;">
        </a>
        
        <ul class="nav nav-pills flex-column mb-auto gap-1 mt-md-2">
            <?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                <li class="nav-item"><a href="admin_dashboard.php" class="nav-link-modern text-decoration-none <?= $current_page == 'admin_dashboard.php' ? 'active' : ''; ?>"><i class="bi bi-grid-fill"></i> Dashboard Pusat</a></li>
                <li class="nav-item"><a href="kelola_cabang.php" class="nav-link-modern text-decoration-none <?= $current_page == 'kelola_cabang.php' ? 'active' : ''; ?>"><i class="bi bi-shop"></i> Kelola Cabang</a></li>
                <li class="nav-item"><a href="kelola_user.php" class="nav-link-modern text-decoration-none <?= $current_page == 'kelola_user.php' ? 'active' : ''; ?>"><i class="bi bi-people-fill"></i> Kelola Kasir</a></li>
                
                <li class="nav-item"><a href="kelola_rekening.php" class="nav-link-modern text-decoration-none <?= $current_page == 'kelola_rekening.php' ? 'active' : ''; ?>"><i class="bi bi-bank"></i> Kelola Rekening</a></li>
                <li class="nav-item"><a href="kelola_layanan.php" class="nav-link-modern text-decoration-none <?= $current_page == 'kelola_layanan.php' ? 'active' : ''; ?>"><i class="bi bi-menu-button-wide"></i> Kelola Layanan</a></li>
                
                <li class="nav-item"><a href="monitoring_receh.php" class="nav-link-modern text-decoration-none <?= $current_page == 'monitoring_receh.php' ? 'active' : ''; ?>"><i class="bi bi-bar-chart"></i> Monitoring Receh</a></li>
                <li class="nav-item"><a href="laporan_global.php" class="nav-link-modern text-decoration-none <?= $current_page == 'laporan_global.php' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-bar-graph-fill"></i> Laporan Global</a></li>
                
                <li class="nav-item"><a href="buku_besar.php" class="nav-link-modern text-decoration-none <?= $current_page == 'buku_besar.php' ? 'active' : ''; ?>"><i class="bi bi-book-half"></i> Buku Besar</a></li>
                <li class="nav-item"><a href="log_aktivitas.php" class="nav-link-modern text-decoration-none <?= $current_page == 'log_aktivitas.php' ? 'active' : ''; ?>"><i class="bi bi-shield-lock"></i> Log Keamanan</a></li>
            <?php else: ?>
                <li class="nav-item"><a href="user_dashboard.php" class="nav-link-modern text-decoration-none <?= $current_page == 'user_dashboard.php' ? 'active' : ''; ?>"><i class="bi bi-house-door-fill"></i> Beranda</a></li>
                <li class="nav-item"><a href="set_modal.php" class="nav-link-modern text-decoration-none <?= $current_page == 'set_modal.php' ? 'active' : ''; ?>"><i class="bi bi-wallet-fill"></i> Set Modal Laci</a></li>
                <li class="nav-item"><a href="set_uang_receh.php" class="nav-link-modern text-decoration-none <?= $current_page == 'set_uang_receh.php' ? 'active' : ''; ?>"><i class="bi bi-cash-coin"></i> Stok Uang Receh</a></li>
                <li class="nav-item mt-4 mb-2"><small class="fw-bold px-3 text-uppercase" style="color: #adb5bd; font-size: 11px; letter-spacing: 1px;">Rekapitulasi</small></li>
                <li class="nav-item"><a href="laporan.php" class="nav-link-modern text-decoration-none <?= $current_page == 'laporan.php' ? 'active' : ''; ?>"><i class="bi bi-printer-fill"></i> Cetak Laporan</a></li>
                <li class="nav-item"><a href="riwayat.php" class="nav-link-modern text-decoration-none <?= $current_page == 'riwayat.php' ? 'active' : ''; ?>"><i class="bi bi-clock-history"></i> Riwayat Trx</a></li>
            <?php endif; ?>
        </ul>
    </div>
    
    <div class="p-3 border-top mt-auto" style="border-color: var(--bri-border) !important; flex-shrink: 0; background-color: var(--bri-white);">
        <div class="d-flex align-items-center mb-3 px-2">
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                <div class="me-3 bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; color: var(--bri-blue);"><i class="bi bi-shield-lock-fill fs-4"></i></div>
                <div style="line-height: 1.2;">
                    <strong class="d-block mb-1" style="font-size: 14px; color: var(--bri-black);"><?= isset($_SESSION['username']) ? ucfirst($_SESSION['username']) : 'Admin'; ?></strong>
                    <div class="d-flex align-items-center gap-1"><span class="status-dot"></span> <small class="fw-bold" style="color: var(--bri-green); font-size: 11px;">Admin Aktif</small></div>
                </div>
            <?php else: ?>
                <div class="me-3 bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; color: var(--bri-blue);"><i class="bi bi-person-badge-fill fs-4"></i></div>
                <div style="line-height: 1.2;">
                    <strong class="d-block mb-1" style="font-size: 14px; color: var(--bri-black);"><?= isset($_SESSION['nama_cabang']) ? $_SESSION['nama_cabang'] : 'Nama Cabang'; ?></strong>
                    <div class="d-flex align-items-center gap-1"><span class="status-dot"></span> <small class="fw-bold" style="color: var(--bri-green); font-size: 11px;">Shift <?= isset($_SESSION['shift_ke']) ? $_SESSION['shift_ke'] : '-'; ?> (Online)</small></div>
                </div>
            <?php endif; ?>
        </div>
        
        <a href="logout.php" class="btn-logout w-100 text-decoration-none">
            <i class="bi bi-power fs-5"></i> Keluar
        </a>
    </div>
</div>