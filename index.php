<?php
require 'config.php';
// Pastikan session berjalan
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// Jika sudah login, tendang ke dashboard masing-masing
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] == 'admin') header("Location: admin_dashboard.php");
    else header("Location: user_dashboard.php");
    exit;
}

$error_notif = "";

// PROSES LOGIN KASIR (USER)
if (isset($_POST['login_kasir'])) {
    $cabang_id = $_POST['cabang_id'];
    $shift_ke  = $_POST['shift_ke'];
    $pin       = $_POST['pin'];

    $stmt = $conn->prepare("SELECT u.*, c.nama_cabang FROM users u JOIN cabang c ON u.cabang_id = c.id WHERE u.cabang_id = ? AND u.shift_ke = ? AND u.role = 'user'");
    $stmt->bind_param("ii", $cabang_id, $shift_ke);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($pin, $row['pin'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['nama_cabang'] = $row['nama_cabang']; 
            $_SESSION['shift_ke'] = $row['shift_ke'];       
            $_SESSION['username'] = $row['username'];       
            
            // --- MENCATAT LOG AKTIVITAS KASIR ---
            catatLog($conn, "Berhasil Login ke sistem sebagai Kasir.", $row['id']);
            
            header("Location: user_dashboard.php");
            exit;
        } else {
            $error_notif = "PIN Keamanan Kasir yang dimasukkan SALAH!";
        }
    } else {
        $error_notif = "Akun untuk Cabang dan Shift ini tidak ditemukan!";
    }
}

// PROSES LOGIN ADMIN
if (isset($_POST['login_admin'])) {
    $username = $_POST['username'];
    $pin      = $_POST['pin'];

    // Cari user berdasarkan username khusus role admin
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($pin, $row['pin'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['username'] = $row['username'];
            
            // --- MENCATAT LOG AKTIVITAS ADMIN ---
            catatLog($conn, "Berhasil Login ke sistem sebagai Admin Pusat.", $row['id']);
            
            header("Location: admin_dashboard.php");
            exit;
        } else {
            $error_notif = "PIN / Password Admin SALAH!";
        }
    } else {
        $error_notif = "Username Admin tidak dikenali sistem!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>BRILink System - Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.min.css" rel="stylesheet">
    <style> 
        /* PALET WARNA MODERN */
        :root {
            --bg-body: #f4f7fa; 
            --bri-blue: #00529C;
            --bri-light-blue: #e6f0ff;
            --bri-black: #1a1a1a;
            --bri-white: #ffffff;
            --bri-grey: #e9ecef;
        }

        body { 
            font-family: 'Montserrat', sans-serif; 
            /* Latar belakang gradasi biru mewah */
            background: linear-gradient(135deg, var(--bri-blue) 0%, #002244 100%);
            min-height: 100vh;
        } 

        .login-card { 
            border-radius: 30px; 
            border: none;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3); 
            padding: 40px;
            background-color: var(--bri-white);
        }

        /* Nav Pills (Toggle Switch Style) */
        .custom-tabs {
            background-color: var(--bg-body);
            border-radius: 50px;
            padding: 6px;
            margin-bottom: 30px;
        }
        .custom-tabs .nav-link { 
            border-radius: 50px; 
            font-weight: 700; 
            color: #6c757d; 
            transition: all 0.3s ease;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .custom-tabs .nav-link:hover { color: var(--bri-blue); }
        .custom-tabs .nav-link.active { 
            background-color: var(--bri-white); 
            color: var(--bri-blue); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        /* Form Inputs */
        .form-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }
        .form-control, .form-select {
            border-radius: 16px;
            background-color: var(--bg-body);
            border: 2px solid transparent;
            padding: 14px 18px;
            font-weight: 600;
            color: var(--bri-black);
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--bri-white);
            border-color: var(--bri-blue);
            box-shadow: 0 0 0 4px var(--bri-light-blue);
        }

        /* Buttons */
        .btn-modern {
            background: linear-gradient(135deg, var(--bri-blue), #003366);
            border: none; 
            color: var(--bri-white);
            border-radius: 50px;
            padding: 15px;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
        }
        .btn-modern:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 82, 156, 0.4);
            color: white;
        }
        .btn-admin {
            background: linear-gradient(135deg, var(--bri-black), #333333);
            border: none; 
            color: var(--bri-white);
            border-radius: 50px;
            padding: 15px;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
        }
        .btn-admin:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.4);
            color: white;
        }

        /* Styling Modal SweetAlert Font */
        .swal2-popup { font-family: 'Montserrat', sans-serif !important; border-radius: 20px !important; }

        @media (max-width: 576px) {
            .login-card { padding: 30px 20px; border-radius: 24px; }
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">
    
    <div class="container d-flex justify-content-center">
        <div class="login-card" style="width: 100%; max-width: 440px;">
            <div class="text-center mb-4">
                
                <img src="assets/img/logo-katanyang.png" alt="Logo" style="height: 65px; margin-bottom: 15px; object-fit: contain;">
                
                <h3 class="fw-extrabold mb-1" style="color: var(--bri-black); letter-spacing: -1px;">BRILink Portal</h3>
                <p class="fw-medium text-muted small">Sistem Informasi Manajemen Terpadu</p>
            </div>

            <ul class="nav nav-pills nav-justified custom-tabs" id="pills-tab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active py-2" id="kasir-tab" data-bs-toggle="pill" data-bs-target="#kasir" type="button" role="tab">Masuk Kasir</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-2" id="admin-tab" data-bs-toggle="pill" data-bs-target="#admin" type="button" role="tab">Masuk Admin</button>
                </li>
            </ul>

            <div class="tab-content" id="pills-tabContent">
                <div class="tab-pane fade show active" id="kasir" role="tabpanel">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Lokasi Cabang</label>
                            <select name="cabang_id" class="form-select" required>
                                <option value="" disabled selected>-- Tentukan Cabang --</option>
                                <?php
                                $q_cabang = $conn->query("SELECT * FROM cabang");
                                while($c = $q_cabang->fetch_assoc()) {
                                    echo "<option value='{$c['id']}'>{$c['nama_cabang']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Slot Shift Kerja</label>
                            <select name="shift_ke" class="form-select" required>
                                <option value="" disabled selected>-- Tentukan Shift --</option>
                                <option value="1">Shift 1 (Utama / Fleksibel)</option>
                                <option value="2">Shift 2 (Lanjutan / Fleksibel)</option>
                                <option value="3">Shift 3 (Bantuan / Ekstra)</option>
                                <option value="4">Shift 4 (Bantuan / Ekstra)</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">PIN Keamanan Kasir</label>
                            <input type="password" name="pin" class="form-control text-center text-primary" placeholder="••••••" maxlength="6" required style="letter-spacing: 12px; font-size: 26px; font-weight: 800;">
                        </div>

                        <button type="submit" name="login_kasir" class="btn btn-modern w-100">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Akses Dashboard
                        </button>
                    </form>
                </div>

                <div class="tab-pane fade" id="admin" role="tabpanel">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username Pusat</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-0 text-muted ps-3 pe-2" style="border-radius: 16px 0 0 16px;"><i class="bi bi-person-circle"></i></span>
                                <input type="text" name="username" class="form-control" placeholder="Masukkan Username" required style="border-radius: 0 16px 16px 0;">
                            </div>
                        </div>

                        <div class="mb-4 mt-3">
                            <label class="form-label">PIN / Password Utama</label>
                            <input type="password" name="pin" class="form-control text-center text-dark" placeholder="••••••" required style="letter-spacing: 12px; font-size: 26px; font-weight: 800;">
                        </div>

                        <button type="submit" name="login_admin" class="btn btn-admin w-100">
                            <i class="bi bi-shield-check me-2"></i>Akses Manajemen
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="text-center mt-4 pt-3 border-top">
                <small class="text-muted fw-semibold" style="font-size: 11px;">&copy; <?= date('Y'); ?> Sistem Agen BRILink. All rights reserved.</small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.3/dist/sweetalert2.all.min.js"></script>

    <?php if (!empty($error_notif)): ?>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Gagal Masuk!',
            text: '<?= $error_notif; ?>',
            confirmButtonColor: '#00529C',
            background: '#ffffff',
            color: '#1a1a1a'
        });
    </script>
    <?php endif; ?>
</body>
</html>