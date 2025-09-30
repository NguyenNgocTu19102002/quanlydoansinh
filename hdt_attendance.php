<?php
session_start();
$logged_in = isset($_SESSION['user_id']);

// Kiểm tra quyền truy cập - chỉ cho phép hdt_manager
if (!$logged_in) {
    header('Location: login.php');
    exit;
}

$conn = new mysqli("localhost", "root", "", "tntt_lap_tri");
if ($conn->connect_error) { die("Kết nối thất bại: " . $conn->connect_error); }
// Thiết lập charset để tránh lỗi tiếng Việt
$conn->set_charset('utf8mb4');

// Đảm bảo bảng hdt_attendance tồn tại (an toàn khi chạy nhiều lần)
$conn->query("CREATE TABLE IF NOT EXISTS hdt_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    activity_type ENUM('Đi lễ', 'Sinh hoạt chung', 'Đi Dạy Giáo Lý', 'Làm bác ái') NOT NULL,
    status ENUM('Có mặt', 'Vắng mặt', 'Có phép') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (teacher_id, class_id, attendance_date, activity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

// Lấy danh sách Huynh Trưởng & Huynh Dự Trưởng
$sql_hdt = "SELECT t.id, t.ho_ten, t.ten_thanh, t.loai_huynh, c.ten_lop, c.nganh, ct.class_id
            FROM class_teachers ct 
            JOIN teachers t ON ct.teacher_id = t.id 
            JOIN classes c ON ct.class_id = c.id 
            WHERE t.loai_huynh IN ('Huynh Trưởng', 'Huynh Dự Trưởng')
            ORDER BY c.ten_lop, t.ho_ten";
$result_hdt = $conn->query($sql_hdt);
$hdt_list = [];
if ($result_hdt->num_rows > 0) {
    while ($row = $result_hdt->fetch_assoc()) {
        $hdt_list[] = $row;
    }
}

// Lấy danh sách lớp
$sql_classes = "SELECT id, ten_lop, nganh FROM classes ORDER BY ten_lop";
$result_classes = $conn->query($sql_classes);
$classes = [];
if ($result_classes->num_rows > 0) {
    while ($row = $result_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Xử lý lưu điểm danh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $attendance_date = isset($_POST['attendance_date']) ? trim($_POST['attendance_date']) : '';
    $activity_type = isset($_POST['activity_type']) ? trim($_POST['activity_type']) : '';
    $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
    $attendance = isset($_POST['attendance']) && is_array($_POST['attendance']) ? $_POST['attendance'] : [];

    // Validate inputs
    if ($attendance_date === '' || $activity_type === '') {
        $_SESSION['error'] = 'Thiếu thông tin: vui lòng chọn Ngày và Loại hoạt động trước khi lưu.';
        header("Location: hdt_attendance.php");
        exit;
    }
    if (empty($attendance)) {
        $_SESSION['error'] = 'Không có dữ liệu điểm danh để lưu.';
        header("Location: hdt_attendance.php?date=" . urlencode($attendance_date) . "&activity=" . urlencode($activity_type) . "&class=" . $class_id);
        exit;
    }

    // Transaction: delete then bulk insert
    $conn->begin_transaction();
    try {
        // Xóa dữ liệu cũ
        if ($class_id > 0) {
            // Nếu chọn 1 lớp: xóa theo lớp
            $delete_sql = "DELETE FROM hdt_attendance WHERE class_id = ? AND attendance_date = ? AND activity_type = ?";
            $stmt_delete = $conn->prepare($delete_sql);
            $stmt_delete->bind_param('iss', $class_id, $attendance_date, $activity_type);
            if (!$stmt_delete->execute()) {
                throw new Exception('Xóa dữ liệu cũ thất bại: ' . $stmt_delete->error);
            }
            $stmt_delete->close();
        } else {
            // Tất cả lớp: xóa theo từng giáo lý viên trong payload
            $delete_sql = "DELETE FROM hdt_attendance WHERE teacher_id = ? AND attendance_date = ? AND activity_type = ?";
            $stmt_delete = $conn->prepare($delete_sql);
            if (!$stmt_delete) { throw new Exception('Không thể chuẩn bị xóa: ' . $conn->error); }
            foreach ($attendance as $teacher_id => $_tmp) {
                $teacher_id_int = (int)$teacher_id;
                $stmt_delete->bind_param('iss', $teacher_id_int, $attendance_date, $activity_type);
                if (!$stmt_delete->execute()) {
                    throw new Exception('Xóa cũ thất bại cho ID ' . $teacher_id_int . ': ' . $stmt_delete->error);
                }
            }
            $stmt_delete->close();
        }

        // Insert new records
        $insert_sql = "INSERT INTO hdt_attendance (teacher_id, class_id, attendance_date, activity_type, status, notes) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($insert_sql);
        if (!$stmt_insert) {
            throw new Exception('Không thể chuẩn bị câu lệnh lưu: ' . $conn->error);
        }

        $success_count = 0;
        foreach ($attendance as $teacher_id => $data) {
            $teacher_id_int = (int)$teacher_id;
            $status = isset($data['status']) ? (string)$data['status'] : 'Có mặt';
            $notes = isset($data['notes']) ? (string)$data['notes'] : '';
            // class id theo từng dòng nếu có; nếu không có thì cố gắng lấy từ mục đã chọn
            $row_class_id = isset($data['class_id']) ? (int)$data['class_id'] : 0;
            if ($row_class_id <= 0 && $class_id > 0) { $row_class_id = $class_id; }
            // Bảo vệ khóa ngoại: nếu vẫn không xác định lớp, bỏ qua dòng này
            if ($row_class_id <= 0) { continue; }
            $stmt_insert->bind_param('iissss', $teacher_id_int, $row_class_id, $attendance_date, $activity_type, $status, $notes);
            if (!$stmt_insert->execute()) {
                throw new Exception('Lưu thất bại cho ID: ' . $teacher_id_int . ' - ' . $stmt_insert->error);
            }
            $success_count++;
        }
        $stmt_insert->close();
    
        $conn->commit();
    $_SESSION['success'] = "Đã lưu điểm danh cho $success_count Huynh Trưởng/Dự Trưởng";
        header("Location: hdt_attendance.php?date=" . urlencode($attendance_date) . "&activity=" . urlencode($activity_type) . "&class=" . $class_id);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Không thể lưu điểm danh: ' . $e->getMessage();
        header("Location: hdt_attendance.php?date=" . urlencode($attendance_date) . "&activity=" . urlencode($activity_type) . "&class=" . $class_id);
    exit;
    }
}

// Lấy dữ liệu điểm danh hiện có
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_activity = isset($_GET['activity']) ? $_GET['activity'] : 'Đi lễ';
$selected_class = isset($_GET['class']) ? (int)$_GET['class'] : 0;

$attendance_data = [];
if ($selected_class > 0) {
    $sql_attendance = "SELECT teacher_id, status, notes 
                       FROM hdt_attendance 
                       WHERE class_id = $selected_class 
                       AND attendance_date = '$selected_date' 
                       AND activity_type = '$selected_activity'";
} else {
    $sql_attendance = "SELECT teacher_id, status, notes 
                       FROM hdt_attendance 
                       WHERE attendance_date = '$selected_date' 
                       AND activity_type = '$selected_activity'";
}
    $result_attendance = $conn->query($sql_attendance);
if ($result_attendance && $result_attendance->num_rows > 0) {
        while ($row = $result_attendance->fetch_assoc()) {
            $attendance_data[$row['teacher_id']] = $row;
    }
}

// Lọc danh sách HT theo lớp được chọn (0 = tất cả lớp)
$filtered_hdt_list = [];
if ($selected_class > 0) {
    foreach ($hdt_list as $hdt) {
        if ($hdt['class_id'] == $selected_class) {
            $filtered_hdt_list[] = $hdt;
        }
    }
} else {
    $filtered_hdt_list = $hdt_list;
}

// Lấy 3 lần điểm danh gần nhất (theo hoạt động đã chọn và lớp nếu có)
$recent_dates = [];
if ($selected_class > 0) {
    $sql_recent = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' AND class_id = $selected_class ORDER BY attendance_date DESC LIMIT 3";
} else {
    $sql_recent = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' ORDER BY attendance_date DESC LIMIT 3";
}
$res_recent = $conn->query($sql_recent);
if ($res_recent && $res_recent->num_rows > 0) {
    while ($r = $res_recent->fetch_assoc()) { $recent_dates[] = $r['attendance_date']; }
}

// Dữ liệu 6 tháng gần nhất để tính % nghỉ và xuất Excel
$six_month_start = date('Y-m-d', strtotime('-6 months', strtotime($selected_date)));
$six_month_dates = [];
if ($selected_class > 0) {
    $sql_6m_dates = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$six_month_start' AND class_id = $selected_class ORDER BY attendance_date ASC";
} else {
    $sql_6m_dates = "SELECT DISTINCT attendance_date FROM hdt_attendance WHERE activity_type = '" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$six_month_start' ORDER BY attendance_date ASC";
}
$res_6m_dates = $conn->query($sql_6m_dates);
if ($res_6m_dates && $res_6m_dates->num_rows > 0) {
    while ($r = $res_6m_dates->fetch_assoc()) { $six_month_dates[] = $r['attendance_date']; }
}

// Map dữ liệu lịch sử trong 6 tháng cho các HT đang hiển thị
$teacher_ids = array_map(function($r){ return (int)$r['id']; }, $filtered_hdt_list);
$history_map = [];
if (!empty($teacher_ids) && !empty($six_month_dates)) {
    $ids_sql = implode(',', $teacher_ids);
    $from = $conn->real_escape_string($six_month_start);
    if ($selected_class > 0) {
        $sql_hist = "SELECT teacher_id, attendance_date, status FROM hdt_attendance WHERE activity_type='" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$from' AND class_id = $selected_class AND teacher_id IN ($ids_sql)";
    } else {
        $sql_hist = "SELECT teacher_id, attendance_date, status FROM hdt_attendance WHERE activity_type='" . $conn->real_escape_string($selected_activity) . "' AND attendance_date >= '$from' AND teacher_id IN ($ids_sql)";
    }
    $res_hist = $conn->query($sql_hist);
    if ($res_hist && $res_hist->num_rows > 0) {
        while ($row = $res_hist->fetch_assoc()) {
            $tid = (int)$row['teacher_id'];
            $d = $row['attendance_date'];
            $history_map[$tid][$d] = $row['status'];
        }
    }
}

// Tính % nghỉ trong 6 tháng gần nhất cho riêng buổi Dạy Giáo Lý (để gửi mail)
$percent_teaching_absent = [];
if (!empty($teacher_ids)) {
    $ids_sql = implode(',', $teacher_ids);
    $sql_teaching = "SELECT teacher_id, 
                            COUNT(*) AS total,
                            SUM(CASE WHEN status IN ('Vắng mặt','Vắng KP','vắng không phép','vắng có phép') THEN 1 ELSE 0 END) AS absent
                     FROM hdt_attendance
                     WHERE activity_type = 'Đi Dạy Giáo Lý' AND attendance_date >= '$six_month_start' AND teacher_id IN ($ids_sql)
                     GROUP BY teacher_id";
    $res_teaching = $conn->query($sql_teaching);
    if ($res_teaching) {
        while ($row = $res_teaching->fetch_assoc()) {
            $total = (int)$row['total'];
            $abs = (int)$row['absent'];
            $percent_teaching_absent[(int)$row['teacher_id']] = $total > 0 ? round(($abs/$total)*100, 2) : 0;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Điểm Danh Huynh Trưởng - ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .attendance-card { border-left: 4px solid #dc3545; }
        .attendance-table th { background-color: #f8f9fa; }
        .btn-attendance { min-width: 100px; }
        
        /* Màu đỏ nhẹ cho header */
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
            <h1>Điểm Danh Huynh Trưởng & Huynh Dự Trưởng</h1>
            <p class="lead mb-0">Theo dõi tham gia hoạt động</p>
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

                <!-- Form chọn ngày, loại hoạt động và lớp -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <label class="form-label">Ngày</label>
                        <input type="date" id="attendance_date" class="form-control" value="<?php echo $selected_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Loại Hoạt Động</label>
                        <select id="activity_type" class="form-control">
                            <option value="Đi lễ" <?php echo $selected_activity === 'Đi lễ' ? 'selected' : ''; ?>>Đi lễ</option>
                            <option value="Sinh hoạt chung" <?php echo $selected_activity === 'Sinh hoạt chung' ? 'selected' : ''; ?>>Sinh hoạt chung</option>
                            <option value="Đi Dạy Giáo Lý" <?php echo $selected_activity === 'Đi Dạy Giáo Lý' ? 'selected' : ''; ?>>Đi Dạy Giáo Lý</option>
                            <option value="Làm bác ái" <?php echo $selected_activity === 'Làm bác ái' ? 'selected' : ''; ?>>Làm bác ái</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lớp</label>
                        <select id="class_filter" class="form-control">
                            <option value="0">Tất cả lớp</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['ten_lop'] . ' - ' . $class['nganh']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-primary" onclick="loadAttendance()">
                            <i class="fa-solid fa-search me-2"></i>Tải Điểm Danh
                        </button>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Tìm theo tên HT</label>
                        <div class="input-group">
                            <input type="text" id="search_name" class="form-control" placeholder="Nhập tên gần đúng...">
                            <button class="btn btn-outline-secondary" type="button" onclick="filterByName()"><i class="fa-solid fa-magnifying-glass"></i></button>
                        </div>
                        <small class="text-muted">Không phân biệt dấu và chữ hoa.</small>
                    </div>
                </div>

                <!-- Form điểm danh -->
                <?php if (!empty($filtered_hdt_list)): ?>
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="save_attendance" value="1">
                    <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                    <input type="hidden" name="activity_type" value="<?php echo $selected_activity; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover attendance-table">
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Tên Thánh</th>
                                    <th>Họ Tên</th>
                                    <th>Lớp</th>
                                    <th>Cấp Bậc</th>
                                    <th>Trạng Thái</th>
                                    <?php foreach ($recent_dates as $d): ?>
                                        <th><?php echo date('d/m', strtotime($d)); ?></th>
                                    <?php endforeach; ?>
                                    <th>% Nghỉ (6 tháng)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $stt = 1; foreach ($filtered_hdt_list as $hdt): ?>
                                    <tr>
                                        <td><?php echo $stt++; ?></td>
                                        <td><?php echo htmlspecialchars($hdt['ten_thanh'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($hdt['ho_ten']); ?></td>
                                        <td><?php echo htmlspecialchars($hdt['ten_lop']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $hdt['loai_huynh'] === 'Huynh Dự Trưởng' ? 'bg-warning' : 'bg-danger'; ?>">
                                                <?php echo htmlspecialchars($hdt['loai_huynh'] ?? 'Huynh Trưởng'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <select name="attendance[<?php echo $hdt['id']; ?>][status]" class="form-control btn-attendance">
                                                <option value="Có mặt" <?php echo (isset($attendance_data[$hdt['id']]) && $attendance_data[$hdt['id']]['status'] === 'Có mặt') ? 'selected' : ''; ?>>Có mặt</option>
                                                <option value="Vắng mặt" <?php echo (isset($attendance_data[$hdt['id']]) && $attendance_data[$hdt['id']]['status'] === 'Vắng mặt') ? 'selected' : ''; ?>>Vắng mặt</option>
                                                <option value="Có phép" <?php echo (isset($attendance_data[$hdt['id']]) && $attendance_data[$hdt['id']]['status'] === 'Có phép') ? 'selected' : ''; ?>>Có phép</option>
                                            </select>
                                            <input type="hidden" name="attendance[<?php echo $hdt['id']; ?>][class_id]" value="<?php echo (int)$hdt['class_id']; ?>">
                                        </td>
                                        <?php foreach ($recent_dates as $d): ?>
                                            <?php $st = $history_map[$hdt['id']][$d] ?? null; ?>
                                            <td class="text-center"><?php echo $st ? htmlspecialchars($st) : '-'; ?></td>
                                        <?php endforeach; ?>
                                        <td class="attendance-col">
                                            <?php 
                                                $pct = $percent_teaching_absent[$hdt['id']] ?? 0; 
                                                $cls = $pct > 40 ? 'text-danger fw-bold' : '';
                                                echo '<span class="' . $cls . '">' . $pct . '%</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <a href="hdt_management.php" class="btn btn-secondary">
                            <i class="fa-solid fa-arrow-left me-2"></i>Quay Lại
                        </a>
                        <div>
                            <button type="button" class="btn btn-warning me-2" onclick="sendEmailWarning()">
                                <i class="fa-solid fa-envelope me-2"></i>Gửi mail (>40% dạy)
                            </button>
                            <button type="button" class="btn btn-success me-2" onclick="exportToExcel()">
                                <i class="fa-solid fa-file-excel me-2"></i>Xuất Excel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-save me-2"></i>Lưu Điểm Danh
                            </button>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fa-solid fa-users fa-3x text-muted mb-3"></i>
                        <h5>Chưa có Huynh Trưởng/Dự Trưởng nào</h5>
                        <p class="text-muted">Vui lòng chọn lớp hoặc thêm Huynh Trưởng/Dự Trưởng</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        function loadAttendance() {
            const date = document.getElementById('attendance_date').value;
            const activity = document.getElementById('activity_type').value;
            const classId = document.getElementById('class_filter').value;
            
            if (!date) {
                alert('Vui lòng chọn ngày');
                return;
            }
            
            window.location.href = `hdt_attendance.php?date=${date}&activity=${encodeURIComponent(activity)}&class=${classId}`;
        }

        function exportToExcel() {
            const date = document.getElementById('attendance_date').value;
            const activity = document.getElementById('activity_type').value;
            const classFilter = document.getElementById('class_filter');
            const className = classFilter.options[classFilter.selectedIndex].text;
            
            if (!date) {
                alert('Vui lòng chọn ngày');
                return;
            }
            
            // Header: STT, Tên Thánh, Họ Tên, Lớp, Loại Huynh, ...các ngày trong 6 tháng
            const table = document.querySelector('.attendance-table');
            const dateHeaders = Array.from(table.querySelectorAll('thead th'))
                .map(th => th.textContent.trim())
                .filter(t => /\d{2}\/\d{2}/.test(t));

            const data = [];
            const header = ['STT', 'Tên Thánh', 'Họ Tên', 'Lớp', 'Cấp Bậc', ...dateHeaders, '% Nghỉ (6 tháng)'];
            data.push(header);
            
            let stt = 1;
            document.querySelectorAll('tbody tr').forEach(row => {
                const cells = row.querySelectorAll('td');
                const name = cells[1].textContent.trim();
                const fullName = cells[2].textContent.trim();
                const classInfo = cells[3].textContent.trim();
                const type = cells[4].textContent.trim();
                const statusCells = Array.from(cells).slice(6, 6 + dateHeaders.length).map(c => c.textContent.trim());
                const percent = cells[6 + dateHeaders.length]?.textContent.trim() || '';
                data.push([stt++, name, fullName, classInfo, type, ...statusCells, percent]);
            });
            
            // Tạo workbook
            const ws = XLSX.utils.aoa_to_sheet(data);
            // Kích thước cột & hàng tương tự bảng Đoàn Sinh
            const cols = [];
            cols.push({ wpx: 50 });   // STT
            cols.push({ wpx: 120 });  // Tên Thánh
            cols.push({ wpx: 180 });  // Họ Tên
            cols.push({ wpx: 120 });  // Lớp
            cols.push({ wpx: 110 });  // Loại Huynh
            for (let i = 0; i < dateHeaders.length; i++) cols.push({ wpx: 100 }); // ngày
            cols.push({ wpx: 110 });  // % Nghỉ (6 tháng)
            ws['!cols'] = cols;
            const rows = new Array(data.length).fill(null).map(() => ({ hpx: 28 }));
            rows[0] = { hpx: 32 };
            ws['!rows'] = rows;

            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Điểm Danh HT');
            
            // Xuất file
            const fileName = `DiemDanh_HT_6m_${activity}_${date}_${className.replace(/[^a-zA-Z0-9]/g, '_')}.xlsx`;
            XLSX.writeFile(wb, fileName);
        }

        function sendEmailWarning() {
            alert('Chức năng gửi mail sẽ sử dụng cấu hình PHPMailer sẵn có. Hãy cung cấp SMTP để kích hoạt thực tế.');
        }

        // Auto load khi thay đổi
        document.getElementById('attendance_date').addEventListener('change', loadAttendance);
        document.getElementById('activity_type').addEventListener('change', loadAttendance);
        document.getElementById('class_filter').addEventListener('change', loadAttendance);

        // Lọc theo tên HT (bỏ dấu, không phân biệt hoa thường)
        function normalizeVN(s){
            return s
                .normalize('NFD')
                .replace(/\p{Diacritic}/gu,'')
                .replace(/đ/g,'d').replace(/Đ/g,'D')
                .toLowerCase();
        }
        function filterByName(){
            const q = normalizeVN(document.getElementById('search_name').value.trim());
            const rows = document.querySelectorAll('.attendance-table tbody tr');
            rows.forEach(row => {
                const name = row.children[2]?.textContent || '';
                const saint = row.children[1]?.textContent || '';
                const haystack = normalizeVN(`${saint} ${name}`);
                row.style.display = q === '' || haystack.includes(q) ? '' : 'none';
            });
        }
        document.getElementById('search_name').addEventListener('keyup', function(e){
            if (e.key === 'Enter') filterByName();
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
