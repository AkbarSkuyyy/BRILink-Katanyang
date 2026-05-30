<?php
require 'config.php';

if (isset($_POST['register'])) {
    $cabang_id = $_POST['cabang_id'];
    $shift_ke  = $_POST['shift_ke'];
    $pin       = $_POST['pin'];
    $konfirmasi_pin = $_POST['konfirmasi_pin'];
    $role      = 'user'; // Otomatis terdaftar sebagai kasir/user

    // 1. Validasi PIN harus sama
    if ($pin !== $konfirmasi_pin) {
        echo "<script>alert('Gagal! PIN dan Konfirmasi PIN tidak cocok.');</script>";
    } else {
        // 2. Cek apakah cabang & shift tersebut sudah punya akun
        $cek = $conn->prepare("SELECT id FROM users WHERE cabang_id = ? AND shift_ke = ?");
        $cek->bind_param("ii", $cabang_id, $shift_ke);
        $cek->execute();
        $result = $cek->get_result();

        if ($result->num_rows > 0) {
            echo "<script>alert('Gagal! Akun untuk Cabang dan Shift tersebut sudah terdaftar.');</script>";
        } else {
            // 3. Enkripsi PIN untuk keamanan
            $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);

            // 4. Simpan ke database
            $stmt = $conn->prepare("INSERT INTO users (cabang_id, shift_ke, pin, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $cabang_id, $shift_ke, $hashed_pin, $role);

            if ($stmt->execute()) {
                echo "<script>alert('Registrasi akun Kasir berhasil! Silakan login.'); window.location='index.php';</script>";
            } else {
                echo "<script>alert('Terjadi kesalahan pada sistem database.');</script>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Registrasi Akun Cabang</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Montserrat', sans-serif; background-color: #f4f6f9; } 
        .register-card { border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: none; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100">
    <div class="card p-5 register-card bg-white" style="width: 450px;">
        <div class="text-center mb-4">
            <h3 class="fw-bold text-dark mb-1">Registrasi Akun</h3>
            <p class="text-muted small">Buat akun Kasir untuk Cabang dan Shift baru</p>
        </div>

        <form method="POST">
            <!-- Dropdown Pilih Cabang -->
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Lokasi Cabang</label>
                <select name="cabang_id" class="form-select" required>
                    <option value="" disabled selected>-- Pilih Cabang --</option>
                    <?php
                    $q_cabang = $conn->query("SELECT * FROM cabang");
                    while($c = $q_cabang->fetch_assoc()) {
                        echo "<option value='{$c['id']}'>{$c['nama_cabang']}</option>";
                    }
                    ?>
                </select>
            </div>
            
            <!-- Dropdown Pilih Shift -->
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Shift Kerja</label>
                <select name="shift_ke" class="form-select" required>
                    <option value="" disabled selected>-- Pilih Shift --</option>
                    <option value="1">Shift 1 (Pagi)</option>
                    <option value="2">Shift 2 (Malam)</option>
                </select>
            </div>

            <!-- Input PIN -->
            <div class="row">
                <div class="col-6 mb-4">
                    <label class="form-label small fw-bold text-muted">Buat PIN</label>
                    <input type="password" name="pin" class="form-control text-center" placeholder="••••••" maxlength="6" required style="letter-spacing: 3px; font-size: 18px;">
                </div>
                <div class="col-6 mb-4">
                    <label class="form-label small fw-bold text-muted">Ulangi PIN</label>
                    <input type="password" name="konfirmasi_pin" class="form-control text-center" placeholder="••••••" maxlength="6" required style="letter-spacing: 3px; font-size: 18px;">
                </div>
            </div>

            <button type="submit" name="register" class="btn btn-success btn-lg w-100 fw-bold rounded-pill">Daftarkan Akun</button>
            
            <div class="text-center mt-4">
                <a href="index.php" class="text-decoration-none small fw-semibold text-primary">← Kembali ke Halaman Login</a>
            </div>
        </form>
    </div>
</body>
</html>