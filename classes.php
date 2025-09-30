<?php
session_start();
$logged_in = isset($_SESSION['user_id']);

// Kết nối DB
$conn = new mysqli("localhost", "root", "", "tntt_lap_tri");
if ($conn->connect_error) die("Kết nối thất bại: " . $conn->connect_error);

// Xử lý chỉnh sửa lớp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['edit_class']) && !empty($_POST['class_id'])) {
    $class_id = (int)$_POST['class_id'];
    $ten_lop = $conn->real_escape_string($_POST['ten_lop']);
    $nganh = $conn->real_escape_string($_POST['nganh']);
    $huynh_truong = isset($_POST['huynh_truong']) ? array_filter($_POST['huynh_truong'], fn($id) => !empty($id)) : [];
    $vai_tro = isset($_POST['vai_tro']) ? $_POST['vai_tro'] : [];

    // Cập nhật thông tin lớp
    $sql_update_class = "UPDATE classes SET ten_lop = '$ten_lop', nganh = '$nganh' WHERE id = $class_id";
    if ($conn->query($sql_update_class)) {
        // Xóa Huynh Trưởng cũ
        $conn->query("DELETE FROM class_teachers WHERE class_id = $class_id");
        // Thêm Huynh Trưởng mới
        foreach ($huynh_truong as $index => $teacher_id) {
            if (!empty($teacher_id) && isset($vai_tro[$index]) && in_array($vai_tro[$index], ['Trưởng Lớp', 'Phó Lớp'])) {
                $teacher_id = (int)$teacher_id;
                $vai_tro_val = $conn->real_escape_string($vai_tro[$index]);
                $conn->query("INSERT INTO class_teachers (class_id, teacher_id, vai_tro) VALUES ($class_id, $teacher_id, '$vai_tro_val')");
            }
        }
        $_SESSION['success'] = "Cập nhật lớp thành công";
    } else {
        $_SESSION['error'] = "Lỗi khi cập nhật lớp: " . $conn->error;
    }
    header("Location: classes.php");
    exit;
}

// Xử lý thêm lớp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['add_class'])) {
    $stmt = $conn->prepare("INSERT INTO classes (ten_lop, nganh, nien_khoa) VALUES (?, ?, ?)");
    $nien_khoa = date('Y') . '-' . (date('Y') + 1);
    $stmt->bind_param("sss", $_POST['ten_lop'], $_POST['nganh'], $nien_khoa);
    $stmt->execute() ? $_SESSION['success'] = "Thêm lớp thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    header("Location: classes.php");
    exit;
}

// Xử lý xóa lớp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['delete_class'])) {
    $stmt = $conn->prepare("DELETE FROM classes WHERE id=?");
    $stmt->bind_param("i", $_POST['id']);
    $stmt->execute() ? $_SESSION['success'] = "Xóa lớp thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    header("Location: classes.php");
    exit;
}

// Thống kê sĩ số theo ngành
$stats = ['Chiên Con' => 0, 'Ấu Nhi' => 0, 'Thiếu Nhi' => 0, 'Nghĩa Sĩ' => 0, 'Hiệp Sĩ' => 0];
$result_stats = $conn->query("SELECT nganh, COUNT(*) as si_so FROM classes c LEFT JOIN students s ON c.id = s.class_id GROUP BY nganh");
while ($row = $result_stats->fetch_assoc()) $stats[$row['nganh']] = $row['si_so'];

// Sắp xếp theo thứ tự yêu cầu
$ordered_stats = [
    'Chiên Con' => $stats['Chiên Con'],
    'Ấu Nhi' => $stats['Ấu Nhi'],
    'Thiếu Nhi' => $stats['Thiếu Nhi'],
    'Nghĩa Sĩ' => $stats['Nghĩa Sĩ'],
    'Hiệp Sĩ' => $stats['Hiệp Sĩ']
];

// Thống kê huynh trưởng và đoàn sinh
$total_teachers = $conn->query("SELECT COUNT(*) as total FROM teachers")->fetch_assoc()['total'] ?? 0;
$total_students = $conn->query("SELECT COUNT(*) as total FROM students")->fetch_assoc()['total'] ?? 0;

// Lấy danh sách huynh trưởng cho modal chỉnh sửa
$all_teachers = $conn->query("SELECT id, ho_ten FROM teachers ORDER BY ho_ten")->fetch_all(MYSQLI_ASSOC);

// Tìm kiếm lớp
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$search_query = $search ? "WHERE LOWER(c.ten_lop) LIKE LOWER('%$search%') OR EXISTS (SELECT 1 FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = c.id AND LOWER(t.ho_ten) LIKE LOWER('%$search%'))" : "";

// Danh sách lớp, sắp xếp theo ngành rồi ten_lop
$result = $conn->query("SELECT c.id, c.ten_lop, c.nganh, (SELECT COUNT(*) FROM students WHERE class_id = c.id) as si_so, (SELECT GROUP_CONCAT(CONCAT(t.ho_ten, ' (', ct.vai_tro, ')') SEPARATOR ' - ') FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = c.id) as huynh_truong FROM classes c $search_query ORDER BY FIELD(nganh, 'Chiên Con', 'Ấu Nhi', 'Thiếu Nhi', 'Nghĩa Sĩ', 'Hiệp Sĩ'), ten_lop");

// Hàm format danh sách huynh trưởng
function formatTeacherList($huynh_truong) {
    if (empty($huynh_truong)) return 'Chưa có';
    $teachers = explode(' - ', $huynh_truong);
    $output = [];
    $count = 0;
    foreach ($teachers as $teacher) {
        $output[] = htmlspecialchars($teacher);
        $count++;
        if ($count == 2 && count($teachers) > 2) $output[] = '<br>';
    }
    return implode(' - ', $output);
}
?>

<!DOCTYPE html>
<html lang="vi" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Lớp Học - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;600;700&family=Dancing+Script:wght@600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { 
            --primary: #86d3ffff; 
            --accent: #45d4ffff; 
            --success: #10B981; 
            --bg: #f2fdffff; 
            --card: #FFFFFF; 
            --text: #0288D1; 
            --shadow: 0 10px 25px rgba(0,0,0,0.1); 
            --stats-text: #03b4d3ff; 
        }
        [data-theme="dark"] { --bg: #0F172A; --card: #1E293B; --text: #F1F5F9; --stats-text: #CE93D8; }
        body { 
            background: linear-gradient(135deg, var(--bg) 0%, #b7fcf5ff 100%); 
            color: var(--text); 
            font-family: 'Roboto', sans-serif; 
            transition: all 0.3s; 
        }
        header { 
            background: linear-gradient(135deg, var(--primary), var(--accent)); 
            box-shadow: var(--shadow); 
            padding: 1rem 0; 
            border-radius: 0 0 2rem 2rem; 
        }
        .header-content { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
        }
        header img { 
            max-width: 100px; 
            transition: transform 0.4s; 
        }
        header img:hover { 
            transform: rotate(360deg) scale(1.1); 
        }
        header h1 { 
            font-size: 2rem; 
            font-weight: 700; 
            color: white; 
            margin: 0; 
        }
        .navbar-nav .nav-link { 
            color: white !important; 
            font-weight: 600; 
            padding: 0.5rem 1rem; 
            border-radius: 50px; 
            margin: 0 0.5rem; 
            background: rgba(255,255,255,0.1); 
        }
        .navbar-nav .nav-link:hover { 
            background: white; 
            color: var(--accent) !important; 
            transform: translateY(-2px); 
        }
        .navbar-nav .nav-link.active { 
            background: white; 
            color: var(--accent) !important; 
        }
        main { 
            min-height: 70vh; 
            padding: 3rem 0; 
        }
        .container { 
            max-width: 90%; 
        }
        .card { 
            border: none; 
            border-radius: 2rem; 
            background: var(--card); 
            box-shadow: var(--shadow); 
            transition: all 0.3s; 
        }
        .card:hover { 
            transform: translateY(-10px); 
            box-shadow: 0 20px 40px rgba(0,0,0,0.15); 
        }
        .card-header { 
            background: linear-gradient(135deg, var(--primary), var(--accent)); 
            color: white; 
            padding: 1.5rem; 
            text-align: center; 
            border-radius: 2rem 2rem 0 0; 
        }
        .btn-primary { 
            background: linear-gradient(135deg, var(--primary), var(--accent)); 
            border: none; 
            border-radius: 50px; 
            padding: 0.75rem 2rem; 
            font-weight: 600; 
            color: white; 
        }
        .btn-primary:hover { 
            transform: scale(1.05); 
            box-shadow: 0 8px 25px rgba(66,165,245,0.6); 
        }
        .table-responsive { 
            width: 100%; 
            max-width: 90vw; 
            margin: 0 auto; 
        }
        .table { 
            border-radius: 1rem; 
            overflow: hidden; 
            background: var(--card); 
            box-shadow: var(--shadow); 
            text-align: center; 
            width: 100%; 
        }
        .table tr.Chiên-Con { background-color: #FFCDD2; }
        .table tr.Ấu-Nhi { background-color: #C8E6C9; }
        .table tr.Thiếu-Nhi { background-color: #B3E5FC; }
        .table tr.Nghĩa-Sĩ { background-color: #FFF9C4; }
        .table tr.Hiệp-Sĩ { background-color: #D7CCC8; }
        .class-link { 
            text-decoration: none; 
            color: var(--text); 
        }
        .class-link:hover { 
            color: var(--accent); 
        }
        footer { 
            background: linear-gradient(135deg, var(--primary), var(--accent)); 
            color: white; 
            padding: 1.5rem 0; 
            border-radius: 2rem 2rem 0 0; 
        }
        footer a { 
            color: white; 
            text-decoration: none; 
        }
        footer a:hover { 
            color: var(--success); 
        }
        .theme-toggle { 
            background: none; 
            border: 2px solid white; 
            color: white; 
            border-radius: 50%; 
            width: 40px; 
            height: 40px; 
        }
        .theme-toggle:hover { 
            background: white; 
            color: var(--accent); 
        }
        .stats-chart { 
            max-width: 400px; 
            margin: 0 auto; 
        }
        .stats-box { 
            text-align: center; 
            padding: 1rem; 
            border: 1px solid var(--accent); 
            border-radius: 1rem; 
            background: var(--card); 
            box-shadow: var(--shadow); 
            margin-top: 1rem; 
        }
        .stats-box h5 { 
            font-family: 'charm', cursive; 
            font-weight: 800; 
            color: var(--stats-text); 
            margin-bottom: 0.5rem; 
        }
        .stats-box i { 
            margin-right: 0.5rem; 
        }
        @media (max-width: 768px) { 
            header h1 { font-size: 1.5rem; } 
            .header-content { flex-direction: column; gap: 1rem; } 
            .table-responsive { width: 100%; } 
        }
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
                <div class="card-header"><i class="fas fa-chalkboard-teacher me-2"></i>Quản Lý Lớp Học</div>
                <div class="card-body">
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                    <?php elseif (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                    <?php endif; ?>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <form method="GET">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control" placeholder="Tìm kiếm lớp hoặc huynh trưởng..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                                </div>
                            </form>
                        </div>
                        <?php if ($logged_in): ?>
                            <div class="col-md-6 text-end">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal"><i class="fas fa-plus me-2"></i>Thêm Lớp</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h5>Thống Kê Sĩ Số Theo Ngành</h5>
                            <canvas id="statsChart" class="stats-chart"></canvas>
                        </div>
                        <div class="col-md-6">
                            <div class="stats-box">
                                <h5><i class="fas fa-user-tie"></i>Tổng Huynh Trưởng: <?php echo $total_teachers; ?></h5>
                                <h5><i class="fas fa-users"></i>Tổng Đoàn Sinh: <?php echo $total_students; ?></h5>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Tên Lớp</th>
                                    <th>Ngành</th>
                                    <th>Sĩ Số</th>
                                    <th>Huynh Trưởng</th>
                                    <?php if ($logged_in): ?>
                                        <th>Thao tác</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $stt = 1; while ($row = $result->fetch_assoc()): ?>
                                    <tr class="<?php echo str_replace(' ', '-', $row['nganh']); ?>">
                                        <td><?php echo $stt++; ?></td>
                                        <td><a href="class_details.php?id=<?php echo $row['id']; ?>" class="class-link"><?php echo htmlspecialchars($row['ten_lop']); ?></a></td>
                                        <td><?php echo htmlspecialchars($row['nganh']); ?></td>
                                        <td><?php echo $row['si_so']; ?></td>
                                        <td><?php echo formatTeacherList($row['huynh_truong']); ?></td>
                                        <?php if ($logged_in): ?>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="loadClassData(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['ten_lop']); ?>', '<?php echo $row['nganh']; ?>')" data-bs-toggle="modal" data-bs-target="#editClassModal"><i class="fas fa-edit"></i></button>
                                                <form method="POST" class="d-inline"><input type="hidden" name="id" value="<?php echo $row['id']; ?>"><button type="submit" name="delete_class" class="btn btn-sm btn-danger" onclick="return confirm('Xác nhận xóa lớp?')"><i class="fas fa-trash"></i></button></form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
        <!-- Modal Thêm Lớp -->
        <?php if ($logged_in): ?>
            <div class="modal fade" id="addClassModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Thêm Lớp</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form method="POST">
                                <input type="hidden" name="add_class" value="1">
                                <div class="mb-3">
                                    <label class="form-label">Tên Lớp</label>
                                    <input type="text" name="ten_lop" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Ngành</label>
                                    <select name="nganh" class="form-control" required>
                                        <option value="Chiên Con">Chiên Con</option>
                                        <option value="Ấu Nhi">Ấu Nhi</option>
                                        <option value="Thiếu Nhi">Thiếu Nhi</option>
                                        <option value="Nghĩa Sĩ">Nghĩa Sĩ</option>
                                        <option value="Hiệp Sĩ">Hiệp Sĩ</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary">Thêm</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Modal Sửa Lớp -->
            <div class="modal fade" id="editClassModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Chỉnh Sửa Lớp</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form method="POST" id="editClassForm">
                                <input type="hidden" name="edit_class" value="1">
                                <input type="hidden" name="class_id" id="class_id">
                                <div class="mb-3">
                                    <label class="form-label">Tên Lớp</label>
                                    <input type="text" name="ten_lop" id="ten_lop" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Ngành</label>
                                    <select name="nganh" id="nganh" class="form-control" required>
                                        <option value="Chiên Con">Chiên Con</option>
                                        <option value="Ấu Nhi">Ấu Nhi</option>
                                        <option value="Thiếu Nhi">Thiếu Nhi</option>
                                        <option value="Nghĩa Sĩ">Nghĩa Sĩ</option>
                                        <option value="Hiệp Sĩ">Hiệp Sĩ</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Huynh Trưởng</label>
                                    <div id="teacher-list"></div>
                                </div>
                                <button type="submit" class="btn btn-primary">Lưu</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
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
            <?php if ($logged_in): ?>
                const allTeachers = <?php echo json_encode($all_teachers); ?>;
                function loadClassData(id, ten_lop, nganh) {
                    document.getElementById('class_id').value = id;
                    document.getElementById('ten_lop').value = ten_lop;
                    document.getElementById('nganh').value = nganh;
                    fetch('get_class_teachers.php?id=' + id)
                        .then(response => response.json())
                        .then(data => {
                            const teacherList = document.getElementById('teacher-list');
                            teacherList.innerHTML = '';
                            for (let i = 0; i < 4; i++) {
                                const teacher = data[i] || { teacher_id: '', vai_tro: 'Phó Lớp' };
                                addTeacherRow(teacher.teacher_id, teacher.vai_tro, i);
                            }
                            updateTeacherDropdowns();
                        });
                }
                function addTeacherRow(teacher_id = '', vai_tro = 'Phó Lớp', index) {
                    const teacherList = document.getElementById('teacher-list');
                    const row = document.createElement('div');
                    row.className = 'teacher-row mb-2';
                    row.setAttribute('data-index', index);
                    let options = '<option value="">Chọn Huynh Trưởng</option>';
                    allTeachers.forEach(teacher => {
                        options += `<option value="${teacher.id}" ${teacher_id == teacher.id ? 'selected' : ''}>${teacher.ho_ten}</option>`;
                    });
                    row.innerHTML = `
                        <select name="huynh_truong[]" class="form-control teacher-select mb-1" onchange="updateTeacherDropdowns()">${options}</select>
                        <select name="vai_tro[]" class="form-control">
                            <option value="Trưởng Lớp" ${vai_tro === 'Trưởng Lớp' ? 'selected' : ''}>Trưởng Lớp</option>
                            <option value="Phó Lớp" ${vai_tro === 'Phó Lớp' ? 'selected' : ''}>Phó Lớp</option>
                        </select>
                    `;
                    teacherList.appendChild(row);
                }
                function updateTeacherDropdowns() {
                    const selects = document.querySelectorAll('#teacher-list .teacher-select');
                    const selectedValues = Array.from(selects).map(select => select.value).filter(value => value !== '');
                    selects.forEach((select, index) => {
                        const currentValue = select.value;
                        select.innerHTML = '<option value="">Chọn Huynh Trưởng</option>';
                        allTeachers.forEach(teacher => {
                            if (!selectedValues.includes(teacher.id.toString()) || teacher.id.toString() === currentValue) {
                                const option = document.createElement('option');
                                option.value = teacher.id;
                                option.textContent = teacher.ho_ten;
                                if (teacher.id.toString() === currentValue) option.selected = true;
                                select.appendChild(option);
                            }
                        });
                    });
                }
            <?php endif; ?>
            // Biểu đồ thống kê
            const stats = <?php echo json_encode($ordered_stats); ?>;
            const ctx = document.getElementById('statsChart').getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: Object.keys(stats),
                    datasets: [{
                        data: Object.values(stats),
                        backgroundColor: ['#FF8A80', '#4CAF50', '#40C4FF', '#FFCA28', '#8D6E63']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        </script>
</body>
</html>
<?php $conn->close(); ?>