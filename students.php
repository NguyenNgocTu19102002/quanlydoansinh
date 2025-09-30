<?php
session_start();
$logged_in = isset($_SESSION['user_id']);
if (!$logged_in) header('Location: login.php');

$conn = new mysqli("localhost", "root", "", "tntt_lap_tri");
if ($conn->connect_error) die("Kết nối thất bại: " . $conn->connect_error);

// Xử lý thêm/sửa/xóa
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_student'])) {
        $stmt = $conn->prepare("INSERT INTO students (ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, dia_chi, ngay_gia_nhap, class_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssi", $_POST['ho_ten'], $_POST['ten_thanh'], $_POST['ngay_sinh'], $_POST['gioi_tinh'], $_POST['so_dien_thoai'], $_POST['dia_chi'], $_POST['ngay_gia_nhap'], $_POST['class_id']);
        $stmt->execute() ? $_SESSION['success'] = "Thêm thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    } elseif (isset($_POST['edit_student'])) {
        $stmt = $conn->prepare("UPDATE students SET ho_ten=?, ten_thanh=?, ngay_sinh=?, gioi_tinh=?, so_dien_thoai=?, dia_chi=?, ngay_gia_nhap=?, class_id=? WHERE id=?");
        $stmt->bind_param("sssssssii", $_POST['ho_ten'], $_POST['ten_thanh'], $_POST['ngay_sinh'], $_POST['gioi_tinh'], $_POST['so_dien_thoai'], $_POST['dia_chi'], $_POST['ngay_gia_nhap'], $_POST['class_id'], $_POST['id']);
        $stmt->execute() ? $_SESSION['success'] = "Sửa thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    } elseif (isset($_POST['delete_student'])) {
        $stmt = $conn->prepare("UPDATE students SET status='inactive' WHERE id=?");
        $stmt->bind_param("i", $_POST['id']);
        $stmt->execute() ? $_SESSION['success'] = "Xóa thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    } elseif (isset($_POST['transfer_student'])) {
        $stmt = $conn->prepare("UPDATE students SET class_id=? WHERE id=?");
        $stmt->bind_param("ii", $_POST['new_class_id'], $_POST['id']);
        $stmt->execute() ? $_SESSION['success'] = "Chuyển lớp thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header("Location: students.php");
    exit;
}

// Lấy danh sách lớp
$classes = $conn->query("SELECT id, ten_lop FROM classes ORDER BY ten_lop")->fetch_all(MYSQLI_ASSOC);

// Lấy danh sách đoàn sinh
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$search_query = $search ? "WHERE ho_ten LIKE '%$search%'" : "";
$students = $conn->query("SELECT s.*, c.ten_lop FROM students s LEFT JOIN classes c ON s.class_id = c.id $search_query ORDER BY ho_ten");
?>

<!DOCTYPE html>
<html lang="vi" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Đoàn Sinh - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        :root { --primary: #6366F1; --accent: #EC4899; --success: #10B981; --bg: #F8FAFC; --card: #FFFFFF; --text: #1E293B; --shadow: 0 10px 25px rgba(0,0,0,0.1); }
        [data-theme="dark"] { --bg: #0F172A; --card: #1E293B; --text: #F1F5F9; }
        body { background: linear-gradient(135deg, var(--bg) 0%, #E2E8F0 100%); color: var(--text); font-family: 'Inter', sans-serif; transition: all 0.3s; }
        header { background: linear-gradient(135deg, var(--primary), var(--accent)); box-shadow: var(--shadow); padding: 1rem 0; border-radius: 0 0 2rem 2rem; }
        .header-content { display: flex; align-items: center; justify-content: space-between; }
        header img { max-width: 60px; transition: transform 0.4s; }
        header img:hover { transform: rotate(360deg) scale(1.1); }
        header h1 { font-size: 2rem; font-weight: 700; color: white; margin: 0; }
        .navbar-nav .nav-link { color: white !important; font-weight: 600; padding: 0.5rem 1rem; border-radius: 50px; margin: 0 0.5rem; background: rgba(255,255,255,0.1); }
        .navbar-nav .nav-link:hover { background: white; color: var(--primary) !important; transform: translateY(-2px); }
        .navbar-nav .nav-link.active { background: white; color: var(--primary) !important; }
        main { min-height: 70vh; padding: 3rem 0; }
        .card { border: none; border-radius: 2rem; background: var(--card); box-shadow: var(--shadow); transition: all 0.3s; }
        .card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .card-header { background: linear-gradient(135deg, var(--primary), var(--accent)); color: white; padding: 1.5rem; text-align: center; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--accent)); border: none; border-radius: 50px; padding: 0.75rem 2rem; font-weight: 600; color: white; }
        .btn-primary:hover { transform: scale(1.05); box-shadow: 0 8px 25px rgba(99,102,241,0.6); }
        .table { border-radius: 1rem; overflow: hidden; background: var(--card); box-shadow: var(--shadow); text-align: center; }
        footer { background: linear-gradient(135deg, var(--primary), var(--accent)); color: white; padding: 1.5rem 0; border-radius: 2rem 2rem 0 0; }
        footer a { color: white; text-decoration: none; }
        footer a:hover { color: var(--success); }
        .theme-toggle { background: none; border: 2px solid white; color: white; border-radius: 50%; width: 40px; height: 40px; }
        .theme-toggle:hover { background: white; color: var(--primary); }
        @media (max-width: 768px) { header h1 { font-size: 1.5rem; } .header-content { flex-direction: column; gap: 1rem; } }
    </style>
</head>
<body>
    <header class="text-white text-center">
        <div class="container">
            <div class="header-content">
                <img src="img/logo.png" alt="Logo Đoàn" data-aos="flip-left">
                <h1 data-aos="fade-in">ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</h1>
                <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-moon"></i></button>
            </div>
            <nav class="navbar navbar-expand-lg navbar-dark mt-3">
                <div class="container-fluid">
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse justify-content-center" id="navbarNav">
                        <ul class="navbar-nav">
                            <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Trang chủ</a></li>
                            <li class="nav-item"><a class="nav-link active" href="classes.php"><i class="fas fa-users"></i> Quản lý đoàn sinh</a></li>
                            <?php if ($logged_in): ?>
                                <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a></li>
                            <?php else: ?>
                                <li class="nav-item"><a class="nav-link" href="login.php"><i class="fas fa-sign-in-alt"></i> Đăng nhập</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </nav>
        </header>
        <main class="container my-5">
            <div class="card" data-aos="fade-up">
                <div class="card-header"><i class="fas fa-users me-2"></i>Quản Lý Đoàn Sinh</div>
                <div class="card-body">
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                    <?php elseif (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                    <?php endif; ?>
                    <form method="GET" class="mb-4">
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" placeholder="Tìm kiếm đoàn sinh..." value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </form>
                    <button class="btn btn-primary mb-4" data-bs-toggle="modal" data-bs-target="#addStudentModal"><i class="fas fa-plus me-2"></i>Thêm Đoàn Sinh</button>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Họ Tên</th><th>Tên Thánh</th><th>Ngày Sinh</th><th>Giới Tính</th><th>Lớp</th><th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($student = $students->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['ho_ten']); ?></td>
                                        <td><?php echo htmlspecialchars($student['ten_thanh'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($student['ngay_sinh']); ?></td>
                                        <td><?php echo htmlspecialchars($student['gioi_tinh']); ?></td>
                                        <td><?php echo htmlspecialchars($student['ten_lop'] ?? 'Chưa có lớp'); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="loadStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['ho_ten']); ?>', '<?php echo htmlspecialchars($student['ten_thanh'] ?? ''); ?>', '<?php echo $student['ngay_sinh']; ?>', '<?php echo $student['gioi_tinh']; ?>', '<?php echo htmlspecialchars($student['so_dien_thoai'] ?? ''); ?>', '<?php echo htmlspecialchars($student['dia_chi'] ?? ''); ?>', '<?php echo $student['ngay_gia_nhap']; ?>', '<?php echo $student['class_id'] ?? ''; ?>')" data-bs-toggle="modal" data-bs-target="#editStudentModal"><i class="fas fa-edit"></i></button>
                                            <form method="POST" class="d-inline"><input type="hidden" name="id" value="<?php echo $student['id']; ?>"><button type="submit" name="delete_student" class="btn btn-sm btn-danger" onclick="return confirm('Xác nhận xóa?')"><i class="fas fa-trash"></i></button></form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
        <!-- Modal Thêm -->
        <div class="modal fade" id="addStudentModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Thêm Đoàn Sinh</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <input type="hidden" name="add_student" value="1">
                            <div class="mb-3">
                                <label class="form-label">Họ Tên</label>
                                <input type="text" name="ho_ten" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tên Thánh</label>
                                <input type="text" name="ten_thanh" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Giới Tính</label>
                                <select name="gioi_tinh" class="form-control" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Số Điện Thoại</label>
                                <input type="text" name="so_dien_thoai" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Địa Chỉ</label>
                                <input type="text" name="dia_chi" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Ngày Gia Nhập</label>
                                <input type="date" name="ngay_gia_nhap" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Lớp</label>
                                <select name="class_id" class="form-control" required>
                                    <option value="">Chọn lớp</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['ten_lop']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Thêm</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!-- Modal Sửa -->
        <div class="modal fade" id="editStudentModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Sửa Đoàn Sinh</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST">
                            <input type="hidden" name="edit_student" value="1">
                            <input type="hidden" name="id" id="edit_id">
                            <div class="mb-3">
                                <label class="form-label">Họ Tên</label>
                                <input type="text" name="ho_ten" id="edit_ho_ten" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tên Thánh</label>
                                <input type="text" name="ten_thanh" id="edit_ten_thanh" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" id="edit_ngay_sinh" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Giới Tính</label>
                                <select name="gioi_tinh" id="edit_gioi_tinh" class="form-control" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Số Điện Thoại</label>
                                <input type="text" name="so_dien_thoai" id="edit_so_dien_thoai" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Địa Chỉ</label>
                                <input type="text" name="dia_chi" id="edit_dia_chi" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Ngày Gia Nhập</label>
                                <input type="date" name="ngay_gia_nhap" id="edit_ngay_gia_nhap" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Lớp</label>
                                <select name="class_id" id="edit_class_id" class="form-control" required>
                                    <option value="">Chọn lớp</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['ten_lop']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Lưu</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <footer class="text-center">
            <div class="container">
                <p>Liên hệ: <a href="mailto:tntt.laptri@gmail.com"><i class="fas fa-envelope me-1"></i>tntt.laptri@gmail.com</a> | 
                <a href="https://facebook.com/tntt.laptri"><i class="fab fa-facebook me-1"></i>Facebook</a></p>
            </div>
        </footer>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
        <script>
            AOS.init({ duration: 1000 });
            function toggleTheme() {
                document.documentElement.setAttribute('data-theme', document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
            }
            function loadStudent(id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, dia_chi, ngay_gia_nhap, class_id) {
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_ho_ten').value = ho_ten;
                document.getElementById('edit_ten_thanh').value = ten_thanh;
                document.getElementById('edit_ngay_sinh').value = ngay_sinh;
                document.getElementById('edit_gioi_tinh').value = gioi_tinh;
                document.getElementById('edit_so_dien_thoai').value = so_dien_thoai;
                document.getElementById('edit_dia_chi').value = dia_chi;
                document.getElementById('edit_ngay_gia_nhap').value = ngay_gia_nhap;
                document.getElementById('edit_class_id').value = class_id;
            }
        </script>
</body>
</html>
<?php $conn->close(); ?>