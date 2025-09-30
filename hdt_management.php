<?php
session_start();
$logged_in = isset($_SESSION['user_id']);

// Kiểm tra quyền truy cập - chỉ cho phép hdt_manager
if (!$logged_in) {
    header('Location: login.php  ');
    exit;
}
//1 con vịt
//1 con vịt
$conn = new mysqli("localhost", "root", "", "tntt_lap_tri");
if ($conn->connect_error) { die("Kết nối thất bại: " . $conn->connect_error); }

// Kiểm tra role của user
$user_id = $_SESSION['user_id'];
$user_result = $conn->query("SELECT role FROM users WHERE id = $user_id");
if ($user_result && $user_result->num_rows > 0) {
    $user = $user_result->fetch_assoc();
    $user_role = $user['role'];
} else {
    $user_role = 'admin'; // fallback
}

// Chỉ cho phép hdt_manager truy cập
if ($user_role !== 'hdt_manager') {
    die("Bạn không có quyền truy cập trang này. Chỉ tài khoản quản lý Huynh Dự Trưởng mới được phép.");
}

// Kiểm tra xem bảng teachers đã có trường loai_huynh chưa
$check_column = $conn->query("SHOW COLUMNS FROM teachers LIKE 'loai_huynh'");
$has_loai_huynh = $check_column && $check_column->num_rows > 0;

// Lấy danh sách Huynh Trưởng & Huynh Dự Trưởng
$search_query = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

if ($has_loai_huynh) {
    // Nếu đã có trường loai_huynh - lấy cả Huynh Trưởng và Huynh Dự Trưởng
    $sql_hdt = "SELECT t.id, t.ho_ten, t.ten_thanh, t.ngay_sinh, t.gioi_tinh, t.so_dien_thoai, t.email, ct.vai_tro, c.ten_lop, c.nganh, t.loai_huynh, ct.class_id
                FROM class_teachers ct 
                JOIN teachers t ON ct.teacher_id = t.id 
                JOIN classes c ON ct.class_id = c.id 
                WHERE t.loai_huynh IN ('Huynh Trưởng', 'Huynh Dự Trưởng')";
} else {
    // Nếu chưa có trường loai_huynh, lấy tất cả teachers
    $sql_hdt = "SELECT t.id, t.ho_ten, t.ten_thanh, t.ngay_sinh, t.gioi_tinh, t.so_dien_thoai, t.email, ct.vai_tro, c.ten_lop, c.nganh, 'Huynh Trưởng' as loai_huynh, ct.class_id
                FROM class_teachers ct 
                JOIN teachers t ON ct.teacher_id = t.id 
                JOIN classes c ON ct.class_id = c.id 
                WHERE 1=1";
}

if ($search_query) {
    $sql_hdt .= " AND (t.ho_ten LIKE '%$search_query%' OR t.ten_thanh LIKE '%$search_query%' OR c.ten_lop LIKE '%$search_query%')";
}

$sql_hdt .= " ORDER BY c.ten_lop, t.ho_ten";
$result_hdt = $conn->query($sql_hdt);
if (!$result_hdt) { 
    die("Lỗi truy vấn Huynh Dự Trưởng: " . $conn->error . "<br>SQL: " . $sql_hdt); 
}

$hdt_list = [];
if ($result_hdt->num_rows > 0) {
    while ($row = $result_hdt->fetch_assoc()) {
        $hdt_list[] = $row;
    }
}

// Lấy danh sách lớp để thêm Huynh Dự Trưởng
$sql_classes = "SELECT id, ten_lop, nganh FROM classes ORDER BY ten_lop";
$result_classes = $conn->query($sql_classes);
$classes = [];
if ($result_classes->num_rows > 0) {
    while ($row = $result_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Xử lý thêm Huynh Trưởng/Dự Trưởng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_hdt'])) {
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = $conn->real_escape_string($_POST['ngay_sinh']);
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $so_dien_thoai = $conn->real_escape_string($_POST['so_dien_thoai']);
    $email = $conn->real_escape_string($_POST['email']);
    $vai_tro = $conn->real_escape_string($_POST['vai_tro']);
    $loai_huynh = $conn->real_escape_string($_POST['loai_huynh']);
    $class_id = (int)$_POST['class_id'];
    
    // Validate SĐT
    if ($so_dien_thoai !== '' && !preg_match('/^\d{10}$/', $so_dien_thoai)) {
        $_SESSION['error'] = 'SĐT phải gồm đúng 10 số.';
        header("Location: hdt_management.php");
        exit;
    }
    
    // Thêm vào bảng teachers
    if ($has_loai_huynh) {
        $sql_teacher = "INSERT INTO teachers (ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, email, loai_huynh) 
                        VALUES ('$ho_ten', '$ten_thanh', '$ngay_sinh', '$gioi_tinh', '$so_dien_thoai', '$email', '$loai_huynh')";
    } else {
        // Nếu chưa có các trường mới, chỉ thêm các trường cơ bản
        $sql_teacher = "INSERT INTO teachers (ho_ten, ten_thanh, ngay_sinh, so_dien_thoai, email) 
                        VALUES ('$ho_ten', '$ten_thanh', '$ngay_sinh', '$so_dien_thoai', '$email')";
    }
    
    if ($conn->query($sql_teacher)) {
        $teacher_id = $conn->insert_id;
        // Thêm vào class_teachers
        $sql_class_teacher = "INSERT INTO class_teachers (class_id, teacher_id, vai_tro) VALUES ($class_id, $teacher_id, '$vai_tro')";
        $conn->query($sql_class_teacher) ? $_SESSION['success'] = "Thêm Huynh Trưởng/Dự Trưởng thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    } else {
        $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header("Location: hdt_management.php");
    exit;
}

// Xử lý sửa Huynh Trưởng/Dự Trưởng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_hdt'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = $conn->real_escape_string($_POST['ngay_sinh']);
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $so_dien_thoai = $conn->real_escape_string($_POST['so_dien_thoai']);
    $email = $conn->real_escape_string($_POST['email']);
    $vai_tro = $conn->real_escape_string($_POST['vai_tro']);
    $loai_huynh = $conn->real_escape_string($_POST['loai_huynh']);
    $class_id = (int)$_POST['class_id'];
    
    // Validate SĐT
    if ($so_dien_thoai !== '' && !preg_match('/^\d{10}$/', $so_dien_thoai)) {
        $_SESSION['error'] = 'SĐT phải gồm đúng 10 số.';
        header("Location: hdt_management.php");
        exit;
    }
    
    // Cập nhật bảng teachers
    if ($has_loai_huynh) {
        $sql_teacher = "UPDATE teachers SET ho_ten = '$ho_ten', ten_thanh = '$ten_thanh', ngay_sinh = '$ngay_sinh', gioi_tinh = '$gioi_tinh', so_dien_thoai = '$so_dien_thoai', email = '$email', loai_huynh = '$loai_huynh' WHERE id = $teacher_id";
    } else {
        // Nếu chưa có các trường mới, chỉ cập nhật các trường cơ bản
        $sql_teacher = "UPDATE teachers SET ho_ten = '$ho_ten', ten_thanh = '$ten_thanh', ngay_sinh = '$ngay_sinh', so_dien_thoai = '$so_dien_thoai', email = '$email' WHERE id = $teacher_id";
    }
    
    if ($conn->query($sql_teacher)) {
        // Cập nhật vai trò và lớp trong class_teachers
        $sql_class_teacher = "UPDATE class_teachers SET class_id = $class_id, vai_tro = '$vai_tro' WHERE teacher_id = $teacher_id";
        $conn->query($sql_class_teacher);
        $_SESSION['success'] = "Sửa Huynh Trưởng/Dự Trưởng thành công";
    } else {
        $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header("Location: hdt_management.php");
    exit;
}

// Xử lý xóa Huynh Trưởng/Dự Trưởng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_hdt'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    // Xóa khỏi class_teachers trước
    $conn->query("DELETE FROM class_teachers WHERE teacher_id = $teacher_id");
    // Xóa khỏi teachers
    $conn->query("DELETE FROM teachers WHERE id = $teacher_id");
    $_SESSION['success'] = "Xóa Huynh Trưởng/Dự Trưởng thành công";
    header("Location: hdt_management.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Huynh Trưởng & Huynh Dự Trưởng - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .table td, .table th { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 120px; }
        .table th:first-child, .table td:first-child { width: 100px; }
        .table th:nth-child(2), .table td:nth-child(2) { width: 150px; }
        .table th:nth-child(3), .table td:nth-child(3) { width: 120px; }
        .btn-uniform { width: 200px; }
        
        /* Màu đỏ nhẹ cho header và footer */
        .hdt-header {
            background: linear-gradient(135deg, #dc3545, #c82333) !important;
        }
        .hdt-card {
            border-left: 4px solid #dc3545;
        }
        .hdt-card .card-header {
            background-color: #f8d7da;
            border-bottom: 1px solid #f5c6cb;
            color: #721c24;
        }
    </style>
</head>
<body class="theme-default">
    <header class="text-white text-center hdt-header">
        <div class="container">
            <img src="img/logo.png" alt="Logo Đoàn" style="max-width: 90px; margin-bottom: 15px;">
            <h1>Quản Lý Huynh Trưởng & Huynh Dự Trưởng</h1>
            <p class="lead mb-0">Hệ thống quản lý thống nhất</p>
        </div>
    </header>

    <main class="container my-5">
        <div class="card hdt-card">
            <div class="card-body p-4">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                
                <?php if (!$has_loai_huynh): ?>
                    <div class="alert alert-warning">
                        <strong>Cảnh báo:</strong> Bạn chưa chạy script SQL để cập nhật database. 
                        Một số tính năng có thể không hoạt động đầy đủ. 
                        Vui lòng chạy file <code>fix_database.sql</code> trong phpMyAdmin.
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="card-title mb-0"><i class="fa-solid fa-user-graduate me-2"></i>Quản Lý Huynh Trưởng & Huynh Dự Trưởng (<?php echo count($hdt_list); ?>)</h4>
                    <div>
                        <a href="hdt_attendance.php" class="btn btn-warning btn-uniform me-2">
                            <i class="fa-solid fa-clipboard-check me-2"></i>Điểm Danh
                        </a>
                        <button class="btn btn-success btn-uniform" data-bs-toggle="modal" data-bs-target="#addHDTModal">
                            <i class="fa-solid fa-plus me-2"></i>Thêm Huynh Trưởng/Dự Trưởng
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <form method="GET" action="hdt_management.php">
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" placeholder="Tìm kiếm theo tên, tên thánh hoặc lớp..." value="<?php echo htmlspecialchars($search_query); ?>">
                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i></button>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>STT</th>
                                <th>Tên Thánh</th>
                                <th>Họ Tên</th>
                                <th>Ngày Sinh</th>
                                <th>Giới Tính</th>
                                <th>Lớp</th>
                                <th>Ngành</th>
                                <th>Loại Huynh</th>
                                <th>Số Điện Thoại</th>
                                <th>Email</th>
                                <th>Vai Trò</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $stt = 1; foreach ($hdt_list as $hdt): ?>
                                <tr>
                                    <td><?php echo $stt++; ?></td>
                                    <td><?php echo htmlspecialchars($hdt['ten_thanh'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($hdt['ho_ten']); ?></td>
                                    <td><?php echo $hdt['ngay_sinh'] ? date("d/m/Y", strtotime($hdt['ngay_sinh'])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($hdt['gioi_tinh'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($hdt['ten_lop']); ?></td>
                                    <td><?php echo htmlspecialchars($hdt['nganh']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $hdt['loai_huynh'] === 'Huynh Dự Trưởng' ? 'bg-warning' : 'bg-danger'; ?>">
                                            <?php echo htmlspecialchars($hdt['loai_huynh'] ?? 'Huynh Trưởng'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($hdt['so_dien_thoai'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($hdt['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($hdt['vai_tro'] ?? 'N/A'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editHDTModal" onclick="loadHDT(<?php echo $hdt['id']; ?>, '<?php echo addslashes(htmlspecialchars($hdt['ho_ten'])); ?>', '<?php echo addslashes(htmlspecialchars($hdt['ten_thanh'] ?? '')); ?>', '<?php echo $hdt['ngay_sinh'] ?? ''; ?>', '<?php echo htmlspecialchars($hdt['gioi_tinh'] ?? ''); ?>', '<?php echo addslashes(htmlspecialchars($hdt['so_dien_thoai'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($hdt['email'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($hdt['vai_tro'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($hdt['loai_huynh'] ?? '')); ?>', <?php echo $hdt['class_id']; ?>)">
                                            <i class="fa-solid fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="teacher_id" value="<?php echo $hdt['id']; ?>">
                                            <button type="submit" name="delete_hdt" class="btn btn-sm btn-danger" onclick="return confirm('Xác nhận xóa?')">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($hdt_list)): ?>
                                <tr><td colspan="12" class="text-center">Chưa có Huynh Trưởng/Huynh Dự Trưởng nào.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <a href="index.php" class="btn btn-theme mt-3"><i class="fa-solid fa-arrow-left me-2"></i>Quay Lại Trang Chủ</a>
            </div>
        </div>
    </main>

    <!-- Modal Thêm Huynh Trưởng/Dự Trưởng -->
    <div class="modal fade" id="addHDTModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm Huynh Trưởng/Dự Trưởng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addHDTForm">
                        <input type="hidden" name="add_hdt" value="1">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên</label>
                                <input type="text" name="ho_ten" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tên Thánh</label>
                                <input type="text" name="ten_thanh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Giới Tính</label>
                                <select name="gioi_tinh" class="form-control" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Lớp</label>
                                <select name="class_id" class="form-control" required>
                                    <option value="">Chọn lớp</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['ten_lop'] . ' - ' . $class['nganh']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Số Điện Thoại</label>
                                <input type="text" name="so_dien_thoai" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Loại Huynh</label>
                                <select name="loai_huynh" class="form-control" required>
                                    <option value="Huynh Trưởng">Huynh Trưởng</option>
                                    <option value="Huynh Dự Trưởng">Huynh Dự Trưởng</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vai Trò</label>
                                <select name="vai_tro" class="form-control" required>
                                    <option value="Trưởng Lớp">Trưởng Lớp</option>
                                    <option value="Phó Lớp">Phó Lớp</option>
                                    <option value="Huynh Dự Trưởng">Huynh Dự Trưởng</option>
                                    <option value="Phó Huynh Dự Trưởng">Phó Huynh Dự Trưởng</option>
                                    <option value="Thư Ký">Thư Ký</option>
                                    <option value="Thủ Quỹ">Thủ Quỹ</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Thêm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Sửa Huynh Trưởng/Dự Trưởng -->
    <div class="modal fade" id="editHDTModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sửa Huynh Trưởng/Dự Trưởng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editHDTForm">
                        <input type="hidden" name="edit_hdt" value="1">
                        <input type="hidden" name="teacher_id" id="edit_hdt_teacher_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên</label>
                                <input type="text" name="ho_ten" id="edit_hdt_ho_ten" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tên Thánh</label>
                                <input type="text" name="ten_thanh" id="edit_hdt_ten_thanh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" id="edit_hdt_ngay_sinh" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Giới Tính</label>
                                <select name="gioi_tinh" id="edit_hdt_gioi_tinh" class="form-control" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Lớp</label>
                                <select name="class_id" id="edit_hdt_class_id" class="form-control" required>
                                    <option value="">Chọn lớp</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['ten_lop'] . ' - ' . $class['nganh']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Số Điện Thoại</label>
                                <input type="text" name="so_dien_thoai" id="edit_hdt_so_dien_thoai" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="edit_hdt_email" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Loại Huynh</label>
                                <select name="loai_huynh" id="edit_hdt_loai_huynh" class="form-control" required>
                                    <option value="Huynh Trưởng">Huynh Trưởng</option>
                                    <option value="Huynh Dự Trưởng">Huynh Dự Trưởng</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Vai Trò</label>
                                <select name="vai_tro" id="edit_hdt_vai_tro" class="form-control" required>
                                    <option value="Trưởng Lớp">Trưởng Lớp</option>
                                    <option value="Phó Lớp">Phó Lớp</option>
                                    <option value="Huynh Dự Trưởng">Huynh Dự Trưởng</option>
                                    <option value="Phó Huynh Dự Trưởng">Phó Huynh Dự Trưởng</option>
                                    <option value="Thư Ký">Thư Ký</option>
                                    <option value="Thủ Quỹ">Thủ Quỹ</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Lưu</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function isValidPhone(value) {
            return /^\d{10}$/.test(value);
        }

        function attachPhoneValidation(formSelector, inputSelector) {
            var $form = $(formSelector);
            if ($form.length === 0) return;
            $form.on('submit', function(e) {
                var phone = $(inputSelector).val().trim();
                if (phone !== '' && !isValidPhone(phone)) {
                    e.preventDefault();
                    alert('SĐT phải gồm đúng 10 số. Không thể lưu.');
                    return false;
                }
                return true;
            });
        }

        function loadHDT(id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, so_dien_thoai, email, vai_tro, loai_huynh, class_id) {
            console.log('Loading HDT:', {id, ho_ten, gioi_tinh, loai_huynh, class_id});
            
            document.getElementById('edit_hdt_teacher_id').value = id || '';
            document.getElementById('edit_hdt_ho_ten').value = ho_ten || '';
            document.getElementById('edit_hdt_ten_thanh').value = ten_thanh || '';
            document.getElementById('edit_hdt_ngay_sinh').value = ngay_sinh || '';
            document.getElementById('edit_hdt_gioi_tinh').value = gioi_tinh || '';
            document.getElementById('edit_hdt_so_dien_thoai').value = so_dien_thoai || '';
            document.getElementById('edit_hdt_email').value = email || '';
            document.getElementById('edit_hdt_vai_tro').value = vai_tro || '';
            document.getElementById('edit_hdt_loai_huynh').value = loai_huynh || '';
            document.getElementById('edit_hdt_class_id').value = class_id || '';
        }

        $(document).ready(function() {
            attachPhoneValidation('#addHDTForm', "input[name='so_dien_thoai']");
            attachPhoneValidation('#editHDTForm', '#edit_hdt_so_dien_thoai');
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
