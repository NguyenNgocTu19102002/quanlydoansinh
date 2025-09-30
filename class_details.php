<?php
session_start();
$logged_in = isset($_SESSION['user_id']);

$conn = new mysqli("localhost", "root", "", "tntt_lap_tri");
if ($conn->connect_error) { die("Kết nối thất bại: " . $conn->connect_error); }

// ================== SMTP CONFIG (điền thông tin Gmail/SMTP của bạn) ==================
// Bật SMTP khi bạn đã nhập tài khoản và mật khẩu ứng dụng (khuyến nghị dùng App Password)
$SMTP_ENABLED   = true;             // bật SMTP
$SMTP_HOST      = 'smtp.gmail.com'; // Gmail SMTP
$SMTP_PORT      = 587;              // TLS
$SMTP_USERNAME  = 'savio220614@gmail.com';
$SMTP_PASSWORD  = str_replace(' ', '', 'nbdt jyey yywg antd');
$SMTP_FROM_EMAIL= 'savio220614@gmail.com';
$SMTP_FROM_NAME = 'Đoàn TNTT Đaminh Savio';

// Tự động load PHPMailer nếu đã cài bằng Composer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$class_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sql_class = "SELECT ten_lop, nganh FROM classes WHERE id = $class_id";
$result_class = $conn->query($sql_class);
if (!$result_class || $result_class->num_rows == 0) { die("Lớp không tồn tại."); }
$class = $result_class->fetch_assoc();

$nganh_theme_map = [
    'Chiên Con' => 'theme-chien-con', 'Ấu Nhi' => 'theme-au-nhi', 'Thiếu Nhi' => 'theme-thieu-nhi',
    'Nghĩa Sĩ' => 'theme-nghia-si', 'Hiệp Sĩ' => 'theme-hiep-si',
];
$theme_class = $nganh_theme_map[$class['nganh']] ?? 'theme-default';

// Lấy dữ liệu Huynh Trưởng
$sql_teachers = "SELECT t.ho_ten, t.ten_thanh, t.ngay_sinh, t.ngay_gia_nhap, t.so_dien_thoai, t.email, ct.vai_tro 
                 FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = $class_id";
$result_teachers = $conn->query($sql_teachers);
if (!$result_teachers) { die("Lỗi truy vấn Huynh Trưởng: " . $conn->connect_error); }

// Lấy dữ liệu Đoàn Sinh
$search_query = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sql_students = $logged_in 
    ? "SELECT id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, ngay_rua_toi, ngay_them_suc, ngay_gia_nhap, ho_ten_bo, ho_ten_me, sdt_lien_lac, email_phu_huynh, dia_chi, ghi_chu FROM students WHERE class_id = $class_id"
    : "SELECT id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh FROM students WHERE class_id = $class_id";
if ($search_query) {
    $sql_students .= " AND (ho_ten LIKE '%$search_query%' OR ten_thanh LIKE '%$search_query%')";
}
$result_students = $conn->query($sql_students);
if (!$result_students) { die("Lỗi truy vấn Đoàn Sinh: " . $conn->connect_error); }

$students = [];
if ($result_students->num_rows > 0) {
    while ($row = $result_students->fetch_assoc()) {
        $students[] = $row;
    }
}

// Lấy 4 ngày điểm danh gần nhất
$sql_attendance_history = "SELECT DISTINCT ngay_diem_danh FROM attendance WHERE class_id = $class_id ORDER BY ngay_diem_danh DESC LIMIT 4";
$result_attendance_history = $conn->query($sql_attendance_history);
$attendance_dates = [];
if ($result_attendance_history && $result_attendance_history->num_rows > 0) {
    while ($row = $result_attendance_history->fetch_assoc()) {
        $attendance_dates[] = $row['ngay_diem_danh'];
    }
}

// Lấy attendance cho từng ngày
$attendance_data = [];
$total_sessions = [];
// Chuẩn hóa trạng thái điểm danh từ DB cũ (present/absent_*) sang giá trị dùng trong app
function map_status_label($raw) {
    switch (trim((string)$raw)) {
        case 'present': return 'có';
        case 'absent_excused': return 'vắng có phép';
        case 'absent_unexcused': return 'vắng không phép';
        default: return $raw; // trả về nguyên để không làm mất dữ liệu khác
    }
}
function map_status_db($label) {
    $label = trim((string)$label);
    if ($label === 'có') return 'present';
    if ($label === 'vắng có phép') return 'absent_excused';
    if ($label === 'vắng không phép') return 'absent_unexcused';
    return $label; // giữ nguyên nếu đã là dạng cũ
}
// Tối ưu: gom truy vấn theo mảng id học sinh và mảng ngày
$student_ids = array_column($students, 'id');
if (!empty($student_ids)) {
    $ids_list = implode(',', array_map('intval', $student_ids));
    // Tổng số buổi và số buổi vắng cho toàn bộ học sinh trong lớp
    $sql_totals = "SELECT student_id, COUNT(*) AS total, SUM(CASE WHEN status IN ('vắng có phép','vắng không phép','absent_excused','absent_unexcused') THEN 1 ELSE 0 END) AS absent
                   FROM attendance
                   WHERE class_id = $class_id AND student_id IN ($ids_list)
                   GROUP BY student_id";
    if ($res_totals = $conn->query($sql_totals)) {
        while ($row = $res_totals->fetch_assoc()) {
            $total_sessions[(int)$row['student_id']] = [
                'total' => (int)$row['total'],
                'absent' => (int)$row['absent']
            ];
        }
    }

    // Dữ liệu điểm danh cho 4 ngày gần nhất bằng 1 truy vấn IN
    if (!empty($attendance_dates)) {
        $date_list = array_map(function($d){ return "'" . $d . "'"; }, $attendance_dates);
        $dates_in = implode(',', $date_list);
        $sql_att_multi = "SELECT ngay_diem_danh, student_id, status
                          FROM attendance
                          WHERE class_id = $class_id AND ngay_diem_danh IN ($dates_in) AND student_id IN ($ids_list)";
        if ($res_att = $conn->query($sql_att_multi)) {
            while ($row = $res_att->fetch_assoc()) {
                $attendance_data[$row['ngay_diem_danh']][(int)$row['student_id']] = map_status_label($row['status']);
            }
        }
    }
}

// Chọn ngày đang xem điểm danh (để preload trạng thái)
$selected_attendance_date = isset($_GET['ngay_diem_danh']) && $_GET['ngay_diem_danh'] !== ''
    ? $conn->real_escape_string($_GET['ngay_diem_danh'])
    : date('Y-m-d');
$attendance_selected = [];
$attendance_selected_code = [];
if (!empty($student_ids)) {
    $sql_att_selected = "SELECT student_id, status FROM attendance WHERE class_id = $class_id AND ngay_diem_danh = '$selected_attendance_date' AND student_id IN ($ids_list)";
    if ($res_sel = $conn->query($sql_att_selected)) {
        while ($row = $res_sel->fetch_assoc()) {
            $attendance_selected[(int)$row['student_id']] = map_status_label($row['status']);
            $attendance_selected_code[(int)$row['student_id']] = $row['status'];
        }
    }
}

// Lấy điểm, tính trung bình và xếp hạng
$sql_grades = "SELECT g.student_id, g.loai_diem, g.diem_so FROM grades g WHERE g.class_id = $class_id";
$result_grades = $conn->query($sql_grades);
$grades_data = [];
if ($result_grades) {
    while ($row = $result_grades->fetch_assoc()) {
        $grades_data[$row['student_id']][$row['loai_diem']] = $row['diem_so'];
    }
}

$he_so_map = ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];

// Hàm tính TB cho 1 học sinh dựa trên grades_data
$compute_tb = function($sid) use ($grades_data, $he_so_map) {
    $grades = $grades_data[$sid] ?? [];
    $tb = 0; $total_he_so = 0;
    foreach ($grades as $loai => $diem) {
        $he_so = $he_so_map[$loai] ?? 1;
        $tb += ((float)$diem) * $he_so;
        $total_he_so += $he_so;
    }
    return $total_he_so ? round($tb / $total_he_so, 2) : 0;
};

// Lấy toàn bộ học sinh trong lớp để xếp hạng (không phụ thuộc kết quả tìm kiếm)
$all_ids = [];
if ($res_all_s = $conn->query("SELECT id FROM students WHERE class_id = $class_id")) {
    while ($r = $res_all_s->fetch_assoc()) { $all_ids[] = (int)$r['id']; }
}

// Tính TB cho toàn bộ lớp để xếp hạng chuẩn
$tb_all = [];
foreach ($all_ids as $sid) { $tb_all[$sid] = $compute_tb($sid); }
arsort($tb_all);
$rank = 1; $previous_tb = null; $ranks_all = [];
$keys = array_keys($tb_all);
foreach ($keys as $index => $id) {
    $tb = $tb_all[$id];
    if ($tb !== $previous_tb) { $ranks_all[$id] = $rank; $previous_tb = $tb; }
    else { $ranks_all[$id] = $ranks_all[$keys[$index - 1]] ?? $rank; }
    $rank++;
}

// Tính TB cho danh sách đang hiển thị (để render cột Điểm TB)
$tb_display = [];
foreach ($students as $student) { $tb_display[$student['id']] = $compute_tb($student['id']); }

// Xử lý thêm Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['add_student'])) {
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = $conn->real_escape_string($_POST['ngay_sinh']);
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $ngay_rua_toi = $conn->real_escape_string($_POST['ngay_rua_toi']);
    $ngay_them_suc = $conn->real_escape_string($_POST['ngay_them_suc']);
    $ngay_gia_nhap = $conn->real_escape_string($_POST['ngay_gia_nhap']);
    $ho_ten_bo = $conn->real_escape_string($_POST['ho_ten_bo']);
    $ho_ten_me = $conn->real_escape_string($_POST['ho_ten_me']);
    $sdt_lien_lac = $conn->real_escape_string($_POST['sdt_lien_lac']);
    $email_phu_huynh = $conn->real_escape_string($_POST['email_phu_huynh']);
    $dia_chi = $conn->real_escape_string($_POST['dia_chi']);
    $ghi_chu = $conn->real_escape_string($_POST['ghi_chu']);
    // Validate SĐT: đúng 10 số nếu có nhập
    if ($sdt_lien_lac !== '' && !preg_match('/^\d{10}$/', $sdt_lien_lac)) {
        $_SESSION['error'] = 'SĐT Liên Lạc phải gồm đúng 10 số.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $sql = "INSERT INTO students (class_id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, ngay_rua_toi, ngay_them_suc, ngay_gia_nhap, ho_ten_bo, ho_ten_me, sdt_lien_lac, email_phu_huynh, dia_chi, ghi_chu) 
            VALUES ($class_id, '$ho_ten', '$ten_thanh', '$ngay_sinh', '$gioi_tinh', '$ngay_rua_toi', '$ngay_them_suc', '$ngay_gia_nhap', '$ho_ten_bo', '$ho_ten_me', '$sdt_lien_lac', '$email_phu_huynh', '$dia_chi', '$ghi_chu')";
    $conn->query($sql) ? $_SESSION['success'] = "Thêm Đoàn Sinh thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý sửa Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['edit_student'])) {
    $student_id = (int)$_POST['student_id'];
    $ho_ten = $conn->real_escape_string($_POST['ho_ten']);
    $ten_thanh = $conn->real_escape_string($_POST['ten_thanh']);
    $ngay_sinh = $conn->real_escape_string($_POST['ngay_sinh']);
    $gioi_tinh = $conn->real_escape_string($_POST['gioi_tinh']);
    $ngay_rua_toi = $conn->real_escape_string($_POST['ngay_rua_toi']);
    $ngay_them_suc = $conn->real_escape_string($_POST['ngay_them_suc']);
    $ngay_gia_nhap = $conn->real_escape_string($_POST['ngay_gia_nhap']);
    $ho_ten_bo = $conn->real_escape_string($_POST['ho_ten_bo']);
    $ho_ten_me = $conn->real_escape_string($_POST['ho_ten_me']);
    $sdt_lien_lac = $conn->real_escape_string($_POST['sdt_lien_lac']);
    $email_phu_huynh = $conn->real_escape_string($_POST['email_phu_huynh']);
    $dia_chi = $conn->real_escape_string($_POST['dia_chi']);
    $ghi_chu = $conn->real_escape_string($_POST['ghi_chu']);
    // Validate SĐT: đúng 10 số nếu có nhập
    if ($sdt_lien_lac !== '' && !preg_match('/^\d{10}$/', $sdt_lien_lac)) {
        $_SESSION['error'] = 'SĐT Liên Lạc phải gồm đúng 10 số.';
        header("Location: class_details.php?id=$class_id");
        exit;
    }
    $sql = "UPDATE students SET ho_ten = '$ho_ten', ten_thanh = '$ten_thanh', ngay_sinh = '$ngay_sinh', gioi_tinh = '$gioi_tinh', ngay_rua_toi = '$ngay_rua_toi', ngay_them_suc = '$ngay_them_suc', ngay_gia_nhap = '$ngay_gia_nhap', ho_ten_bo = '$ho_ten_bo', ho_ten_me = '$ho_ten_me', sdt_lien_lac = '$sdt_lien_lac', email_phu_huynh = '$email_phu_huynh', dia_chi = '$dia_chi', ghi_chu = '$ghi_chu', updated_at = NOW() WHERE id = $student_id";
    $conn->query($sql) ? $_SESSION['success'] = "Sửa Đoàn Sinh thành công" : $_SESSION['error'] = "Lỗi: " . $conn->error;
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý xóa Đoàn Sinh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['delete_student'])) {
    $student_id = (int)$_POST['student_id'];
    $conn->query("DELETE FROM students WHERE id = $student_id");
    $_SESSION['success'] = "Xóa Đoàn Sinh thành công";
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Xử lý điểm danh hàng loạt
// Chỉ xử lý lưu điểm danh nếu không bấm nút gửi mail/xuất file
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $logged_in &&
    isset($_POST['submit_attendance']) &&
    !isset($_POST['send_email']) &&
    !isset($_POST['export_attendance']) &&
    !isset($_POST['export_attendance_range'])
) {
    $ngay_diem_danh = $conn->real_escape_string($_POST['ngay_diem_danh']);
    $success = true;
    foreach ($_POST['status'] as $student_id => $status) {
        $status = map_status_db($status);
        $status = $conn->real_escape_string($status);
        $sql_check = "SELECT id FROM attendance WHERE student_id = $student_id AND class_id = $class_id AND ngay_diem_danh = '$ngay_diem_danh'";
        $check = $conn->query($sql_check);
        if ($check->num_rows > 0) {
            if (!$conn->query("UPDATE attendance SET status = '$status' WHERE student_id = $student_id AND class_id = $class_id AND ngay_diem_danh = '$ngay_diem_danh'")) {
                $success = false;
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO attendance (student_id, class_id, ngay_diem_danh, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $student_id, $class_id, $ngay_diem_danh, $status);
            if (!$stmt->execute()) {
                $success = false;
            }
        }
    }
    if ($success) {
        $_SESSION['success'] = "Đã điểm danh ngày " . date('d/m/Y', strtotime($ngay_diem_danh)) . " thành công";
        // Redirect để nạp lại dữ liệu ngày vừa lưu và hiển thị dropdown đã chọn
        header("Location: class_details.php?id=$class_id&ngay_diem_danh=$ngay_diem_danh");
        exit;
    } else {
        $_SESSION['error'] = "Lỗi khi lưu điểm danh: " . $conn->error;
    }
}

// Xử lý gửi email hàng loạt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['send_email'])) {
    $ngay_diem_danh = $conn->real_escape_string($_POST['ngay_diem_danh']);
    $ngay_hien_thi = date('d/m/Y', strtotime($ngay_diem_danh));
    $gio_thong_bao = date('H:i');

    // Tìm tên Trưởng lớp (nếu có)
    $truong_lop = '';
    $sql_truong = "SELECT t.ho_ten FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE ct.class_id = $class_id AND (ct.vai_tro LIKE '%Trưởng%' OR ct.vai_tro LIKE '%Truong%') LIMIT 1";
    if ($res_tr = $conn->query($sql_truong)) {
        if ($row_tr = $res_tr->fetch_assoc()) { $truong_lop = $row_tr['ho_ten']; }
    }
    if ($truong_lop === '') { $truong_lop = 'Trưởng lớp'; }

    $sent = 0; $failed = 0; $lastError = '';
    foreach ($_POST['status'] as $student_id => $status) {
        if ($status == 'vắng có phép' || $status == 'vắng không phép') {
            $sql = "SELECT ho_ten, email_phu_huynh FROM students WHERE id = $student_id";
            if ($result = $conn->query($sql)) {
            $student = $result->fetch_assoc();
            if ($student && !empty($student['email_phu_huynh'])) {
                $to = $student['email_phu_huynh'];
                    $subject = "Thông báo vắng học: " . $student['ho_ten'];
                    $noidung = "Kính gửi Quý Phụ huynh,\n\n"
                             . "Con em: " . $student['ho_ten'] . " \n"
                             . "Trạng thái điểm danh: " . $status . "\n"
                             . "Ngày điểm danh: " . $ngay_hien_thi . " (lúc $gio_thong_bao)\n\n"
                             . "Kính mong Quý Phụ huynh phối hợp theo dõi và trao đổi thêm với giáo lý viên nếu cần.\n\n"
                             . "Trân trọng,\n"
                             . $truong_lop . " - Trưởng lớp " . $class['ten_lop'];

                    $ok = false;
                    if ($SMTP_ENABLED && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                        try {
                            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                            $mail->CharSet = 'UTF-8';
                            $mail->isSMTP();
                            $mail->Host = $SMTP_HOST;
                            $mail->SMTPAuth = true;
                            $mail->Username = $SMTP_USERNAME;
                            $mail->Password = $SMTP_PASSWORD;
                            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = $SMTP_PORT;
                            $mail->SMTPDebug = 0; // set 2 để debug chi tiết nếu cần
                            $mail->setFrom($SMTP_FROM_EMAIL ?: $SMTP_USERNAME, $SMTP_FROM_NAME ?: $truong_lop);
                            $mail->addAddress($to);
                            $mail->Subject = $subject;
                            $mail->Body = $noidung;
                            $ok = $mail->send();
                            if (!$ok) { $lastError = $mail->ErrorInfo; }
                        } catch (Exception $e) {
                            $ok = false; $lastError = $e->getMessage();
                        }
                    } else {
                        $headers  = "From: " . ($SMTP_FROM_EMAIL ?: 'no-reply@example.com') . "\r\n";
                        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                        $ok = @mail($to, $subject, $noidung, $headers);
                        if (!$ok) { $lastError = 'mail() không gửi được (cần bật SMTP)'; }
                    }
                    if ($ok) { $sent++; } else { $failed++; }
                } else { $failed++; }
            } else { $failed++; }
        }
    }
    if ($failed > 0 && $sent === 0) {
        if (!$SMTP_ENABLED || !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $_SESSION['error'] = "Không gửi được email. Vui lòng cài PHPMailer (composer require phpmailer/phpmailer) hoặc kiểm tra cấu hình SMTP.";
        } else {
            $_SESSION['error'] = "Không gửi được email: " . ($lastError ?: 'Lỗi không xác định');
        }
    } else {
        $_SESSION['success'] = "Gửi email: $sent thành công, $failed thất bại";
    }
    header("Location: class_details.php?id=$class_id&ngay_diem_danh=$ngay_diem_danh");
    exit;
}

// Xử lý sửa điểm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['submit_grades'])) {
    $ngay_cham_diem = date('Y-m-d');
    foreach ($_POST['diem'] as $student_id => $grades) {
        foreach ($grades as $loai_diem => $diem) {
            $diem_so = $diem === '' ? 0 : (float)$diem;
            $loai_diem = $conn->real_escape_string($loai_diem);
            $sql_check = "SELECT id FROM grades WHERE student_id = $student_id AND class_id = $class_id AND loai_diem = '$loai_diem'";
            $check = $conn->query($sql_check);
            if ($check->num_rows > 0) {
                $conn->query("UPDATE grades SET diem_so = $diem_so, ngay_cham_diem = '$ngay_cham_diem' WHERE student_id = $student_id AND class_id = $class_id AND loai_diem = '$loai_diem'");
            } else {
                $stmt = $conn->prepare("INSERT INTO grades (student_id, class_id, loai_diem, diem_so, ngay_cham_diem) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iisds", $student_id, $class_id, $loai_diem, $diem_so, $ngay_cham_diem);
                $stmt->execute();
            }
        }
    }
    $_SESSION['success'] = "Sửa điểm thành công";
    header("Location: class_details.php?id=$class_id");
    exit;
}

// Hàm xuất Excel
function exportExcel($conn, $class, $students, $attendance_data, $total_sessions, $type, $class_id) {
    // Nếu xuất điểm -> xuất HTML bảng (Excel mở được) để bôi đỏ điểm <5 và có tiêu đề
    if ($type === 'diem') {
        $safe_class = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $class['ten_lop']);
        $title = 'Bảng Điểm Lớp ' . htmlspecialchars($class['ten_lop']);
        $filename = 'Bang_diem_' . str_replace(' ', '_', $safe_class) . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "<html><head><meta charset='utf-8'><style>
                table{border-collapse:collapse;width:100%;table-layout:fixed;}
                th,td{border:1px solid #000000;padding:6px;text-align:center;height:30px}
                th{background:#f2f2f2;font-weight:600}
                .low{color:red}
              </style></head><body>";
        echo "<table>";
        // Cố định chiều rộng cột 100px bằng colgroup để Excel nhận đúng
        echo "<colgroup>"
            . str_repeat("<col style='width:100px'>", 11)
            . "</colgroup>";
        echo "<tr style='height:30px'><th colspan='11' style='font-size:18px'>" . $title . "</th></tr>";
        echo "<tr style='height:30px'>
                <th>Tên Thánh</th><th>Họ Tên</th><th>Ngày Sinh</th>
                <th>15 phút 1</th><th>15 phút 2</th><th>1 tiết 1</th><th>1 tiết 2</th>
                <th>Cuối kỳ 1</th><th>Cuối kỳ 2</th><th>Điểm TB</th><th>Xếp Hạng</th>
              </tr>";
        // Lấy điểm và tính TB/XH
        $sql_grades = "SELECT g.student_id, g.loai_diem, g.diem_so FROM grades g WHERE g.class_id = $class_id";
        $result_grades = $conn->query($sql_grades);
        $grades_data = [];
        if ($result_grades) {
            while ($row = $result_grades->fetch_assoc()) {
                $grades_data[$row['student_id']][$row['loai_diem']] = $row['diem_so'];
            }
        }
        $he_so_map = ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];
        $tb_array = [];
        foreach ($students as $student) {
            $grades = $grades_data[$student['id']] ?? [];
            $tb = 0; $total_he_so = 0;
            foreach ($grades as $loai => $diem) {
                $he_so = $he_so_map[$loai] ?? 1;
                $tb += ((float)$diem) * $he_so;
                $total_he_so += $he_so;
            }
            $tb_array[$student['id']] = $total_he_so ? round($tb / $total_he_so, 2) : 0;
        }
        arsort($tb_array);
        $rank = 1; $previous_tb = null; $ranks = []; $keys = array_keys($tb_array);
        foreach ($keys as $index => $id) {
            $tb = $tb_array[$id];
            if ($tb !== $previous_tb) { $ranks[$id] = $rank; $previous_tb = $tb; }
            else { $ranks[$id] = $ranks[$keys[$index - 1]] ?? $rank; }
            $rank++;
        }
        foreach ($students as $student) {
            $sid = $student['id'];
            $g = $grades_data[$sid] ?? [];
            $cols = ['15 phút 1','15 phút 2','1 tiết 1','1 tiết 2','Cuối kỳ 1','Cuối kỳ 2'];
            echo "<tr style='height:30px'>";
            echo "<td>" . htmlspecialchars($student['ten_thanh'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($student['ho_ten']) . "</td>";
            echo "<td>" . ($student['ngay_sinh'] ? date('d/m/Y', strtotime($student['ngay_sinh'])) : 'N/A') . "</td>";
            foreach ($cols as $c) {
                $val = $g[$c] ?? '';
                $cls = ($val !== '' && (float)$val < 5) ? ' class=\'low\'' : '';
                echo "<td$cls>" . ($val === '' ? '' : $val) . "</td>";
            }
            echo "<td>" . ($tb_array[$sid] ?? '') . "</td>";
            echo "<td>" . ($ranks[$sid] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table></body></html>";
        exit;
    }

    // Mặc định: CSV cho các loại khác
    header('Content-Type: text/csv; charset=utf-8');
    $filename = $type . '_' . $class['ten_lop'] . '_' . date('Ymd') . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($type === 'diem_danh') {
        fputcsv($output, ['Tên Thánh', 'Họ Tên', 'Ngày Sinh', 'Ngày', 'Trạng Thái', '% Nghỉ Học']);
        // Prefetch toàn bộ điểm danh của lớp một lần để xuất nhanh
        $attendance_by_student = [];
        $sql_all_att = "SELECT student_id, ngay_diem_danh, status FROM attendance WHERE class_id = $class_id ORDER BY ngay_diem_danh DESC";
        if ($res_all = $conn->query($sql_all_att)) {
            while ($row = $res_all->fetch_assoc()) {
                $attendance_by_student[(int)$row['student_id']][] = $row;
            }
        }
        foreach ($students as $student) {
            $sid = (int)$student['id'];
            $absent_percent = !empty($total_sessions[$sid]['total']) ? round(($total_sessions[$sid]['absent'] / $total_sessions[$sid]['total']) * 100, 2) : 0;
            $records = $attendance_by_student[$sid] ?? [];
            if (!empty($records)) {
                foreach ($records as $row) {
                    fputcsv($output, [
                        $student['ten_thanh'] ?? 'N/A',
                        $student['ho_ten'],
                        $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A',
                        date("d/m/Y", strtotime($row['ngay_diem_danh'])),
                        $row['status'],
                        $absent_percent . '%'
                    ]);
                }
            } else {
                fputcsv($output, [
                    $student['ten_thanh'] ?? 'N/A',
                    $student['ho_ten'],
                    $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A',
                    'N/A',
                    'N/A',
                    $absent_percent . '%'
                ]);
            }
        }
    } elseif ($type === 'diem') {
        // Lấy điểm
        $sql_grades = "SELECT g.student_id, g.loai_diem, g.diem_so FROM grades g WHERE g.class_id = $class_id";
        $result_grades = $conn->query($sql_grades);
        $grades_data = [];
        if ($result_grades) {
            while ($row = $result_grades->fetch_assoc()) {
                $grades_data[$row['student_id']][$row['loai_diem']] = $row['diem_so'];
            }
        }
        // Tính TB + XH
        $he_so_map = ['15 phút 1' => 1, '15 phút 2' => 1, '1 tiết 1' => 2, '1 tiết 2' => 2, 'Cuối kỳ 1' => 3, 'Cuối kỳ 2' => 3];
        $tb_array = [];
        foreach ($students as $student) {
            $grades = $grades_data[$student['id']] ?? [];
            $tb = 0; $total_he_so = 0;
            foreach ($grades as $loai => $diem) {
                $he_so = $he_so_map[$loai] ?? 1;
                $tb += ((float)$diem) * $he_so;
                $total_he_so += $he_so;
            }
            $tb_array[$student['id']] = $total_he_so ? round($tb / $total_he_so, 2) : 0;
        }
        arsort($tb_array);
        $rank = 1; $previous_tb = null; $ranks = [];
        $keys = array_keys($tb_array);
        foreach ($keys as $index => $id) {
            $tb = $tb_array[$id];
            if ($tb !== $previous_tb) { $ranks[$id] = $rank; $previous_tb = $tb; }
            else { $ranks[$id] = $ranks[$keys[$index - 1]] ?? $rank; }
            $rank++;
        }
        // Header giống giao diện web
        fputcsv($output, ['Tên Thánh', 'Họ Tên', 'Ngày Sinh', '15 phút 1', '15 phút 2', '1 tiết 1', '1 tiết 2', 'Cuối kỳ 1', 'Cuối kỳ 2', 'Điểm TB', 'Xếp Hạng']);
        foreach ($students as $student) {
            $sid = $student['id'];
            $g = $grades_data[$sid] ?? [];
            $row = [
                        $student['ten_thanh'] ?? 'N/A',
                        $student['ho_ten'],
                $student['ngay_sinh'] ? date('d/m/Y', strtotime($student['ngay_sinh'])) : 'N/A',
                $g['15 phút 1'] ?? '',
                $g['15 phút 2'] ?? '',
                $g['1 tiết 1'] ?? '',
                $g['1 tiết 2'] ?? '',
                $g['Cuối kỳ 1'] ?? '',
                $g['Cuối kỳ 2'] ?? '',
                $tb_array[$sid] ?? '',
                $ranks[$sid] ?? ''
            ];
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}

// Xuất Excel điểm danh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['export_attendance'])) {
    exportExcel($conn, $class, $students, $attendance_data, $total_sessions, 'diem_danh', $class_id);
}

// Xuất Excel điểm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['export_grades'])) {
    exportExcel($conn, $class, $students, $attendance_data, $total_sessions, 'diem', $class_id);
}

// Xuất điểm danh theo khoảng (6 tháng / 1 năm) dạng ma trận mỗi học sinh 1 hàng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in && isset($_POST['export_attendance_range'])) {
    $anchor = isset($_POST['ngay_diem_danh']) && $_POST['ngay_diem_danh'] ? $_POST['ngay_diem_danh'] : date('Y-m-d');
    $range = $_POST['export_attendance_range'];
    $start = $anchor;
    if ($range === '6m') {
        $from = date('Y-m-d', strtotime('-6 months', strtotime($anchor)));
    } else {
        $from = date('Y-m-d', strtotime('-12 months', strtotime($anchor)));
    }

    // Lấy danh sách ngày điểm danh trong khoảng
    $dates = [];
    $sql_dates = "SELECT DISTINCT ngay_diem_danh FROM attendance WHERE class_id = $class_id AND ngay_diem_danh BETWEEN '$from' AND '$anchor' ORDER BY ngay_diem_danh DESC";
    if ($resd = $conn->query($sql_dates)) {
        while ($r = $resd->fetch_assoc()) { $dates[] = $r['ngay_diem_danh']; }
    }

    // Xuất HTML bảng giống Excel, mỗi học sinh 1 hàng, cột là các ngày trong khoảng
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    $safe_class = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $class['ten_lop']);
    $filename = 'Diem_danh_' . str_replace(' ','_',$safe_class) . '_' . date('Ymd', strtotime($from)) . '_' . date('Ymd', strtotime($anchor)) . '.xls';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "<html><head><meta charset='utf-8'><style>
            table{border-collapse:collapse;width:100%;table-layout:fixed;}
            th,td{border:1px solid #bbb;padding:6px;text-align:center;height:28px}
            th{background:#f2f2f2;font-weight:600}
          </style></head><body>";
    echo "<table>";
    // colgroup: Tên Thánh, Họ Tên (tăng 30%), Ngày Sinh + N ngày + % Nghỉ Học (tăng 10%)
    $cols = 3 + count($dates) + 1;
    $base = 110; // cơ sở rộng cột
    echo "<colgroup>";
    echo "<col style='width:" . $base . "px'>"; // Tên Thánh
    echo "<col style='width:" . intval($base*1.3) . "px'>"; // Họ Tên +30%
    echo "<col style='width:" . $base . "px'>"; // Ngày Sinh
    foreach ($dates as $_) echo "<col style='width:" . intval($base*1.1) . "px'>"; // ngày +10%
    echo "<col style='width:" . intval($base*1.1) . "px'>"; // % Nghỉ Học +10%
    echo "</colgroup>";
    echo "<tr><th colspan='$cols' style='font-size:18px'>Bảng Điểm Danh Lớp " . htmlspecialchars($class['ten_lop']) . " (" . date('d/m/Y', strtotime($from)) . " - " . date('d/m/Y', strtotime($anchor)) . ")</th></tr>";
    echo "<tr><th>Tên Thánh</th><th>Họ Tên</th><th>Ngày Sinh</th>";
    foreach ($dates as $d) echo "<th>" . date('d/m', strtotime($d)) . "</th>";
    echo "<th>% Nghỉ Học</th></tr>";

    // Build map trạng thái trong khoảng để fill nhanh
    $student_ids = array_column($students, 'id');
    if (!empty($student_ids)) {
        $ids_list = implode(',', array_map('intval', $student_ids));
        $in_dates = implode(',', array_map(function($x){return "'".$x."'";}, $dates));
        $map = [];
        if (!empty($dates)) {
            $q = "SELECT student_id, ngay_diem_danh, status FROM attendance WHERE class_id = $class_id AND student_id IN ($ids_list) AND ngay_diem_danh IN ($in_dates)";
            if ($rs = $conn->query($q)) {
                while ($row = $rs->fetch_assoc()) {
                    $map[(int)$row['student_id']][$row['ngay_diem_danh']] = map_status_label($row['status']);
                }
            }
        }
        foreach ($students as $st) {
            $sid = (int)$st['id'];
            echo "<tr>";
            echo "<td>" . htmlspecialchars($st['ten_thanh'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($st['ho_ten']) . "</td>";
            echo "<td>" . ($st['ngay_sinh'] ? date('d/m/Y', strtotime($st['ngay_sinh'])) : 'N/A') . "</td>";
            $absent = 0; $total = 0;
            foreach ($dates as $d) {
                $val = $map[$sid][$d] ?? '';
                if ($val !== '') { $total++; if ($val !== 'có') $absent++; }
                echo "<td>" . ($val === '' ? '' : htmlspecialchars($val)) . "</td>";
            }
            $percent = $total>0 ? round(($absent/$total)*100, 2) : 0;
            $pcCls = ($percent>40) ? " style='color:red;font-weight:600'" : "";
            echo "<td$pcCls>".$percent."%</td>";
            echo "</tr>";
        }
    }
    echo "</table></body></html>";
    exit;
}

// Hàm tính thời gian sinh hoạt
function tinh_thoi_gian_sinh_hoat($ngay_gia_nhap) {
    if (!$ngay_gia_nhap) return 'N/A';
    $start = new DateTime($ngay_gia_nhap);
    $now = new DateTime();
    $interval = $start->diff($now);
    return $interval->y . ' năm, ' . $interval->m . ' tháng';
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi Tiết Lớp <?php echo htmlspecialchars($class['ten_lop']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* phần lớn style đã chuyển sang css/style.css; giữ lại vài kích thước cột cần thiết */
        .table td, .table th { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 120px; }
        .table th:first-child, .table td:first-child { width: 100px; }
        .table th:nth-child(2), .table td:nth-child(2) { width: 150px; }
        .table th:nth-child(3), .table td:nth-child(3) { width: 120px; }
        .table .attendance-col { width: 80px; }
        #ngay_diem_danh { max-width: 180px; }
        .table .grade-input { font-size: 0.9em; }
        .table .low-grade { color: red; }
        .accordion-button { font-weight: 600; }
        .btn-uniform { width: 200px; }
        .absent-high { color: red; font-weight: 600; }
    </style>
</head>
<body class="<?php echo $theme_class; ?>">
    <header class="text-white text-center">
        <div class="container">
            <img src="img/logo.png" alt="Logo Đoàn" style="max-width: 90px; margin-bottom: 15px;">
            <h1>Chi Tiết Lớp <?php echo htmlspecialchars($class['ten_lop']); ?></h1>
            <p class="lead mb-0">Ngành <?php echo htmlspecialchars($class['nganh']); ?></p>
        </div>
    </header>

    <main class="container my-5">
        <div class="card">
            <div class="card-body p-4">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>

                <h4 class="card-title"><i class="fa-solid fa-chalkboard-user me-2"></i>Huynh Trưởng Phụ Trách</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Tên Thánh</th>
                                <th>Họ Tên</th>
                                <th>Ngày Sinh</th>
                                <th>Thời Gian Sinh Hoạt</th>
                                <th>Số Điện Thoại</th>
                                <th>Email</th>
                                <th>Vai Trò</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result_teachers->num_rows > 0): while ($teacher = $result_teachers->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($teacher['ten_thanh'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['ho_ten']); ?></td>
                                    <td><?php echo $teacher['ngay_sinh'] ? date("d/m/Y", strtotime($teacher['ngay_sinh'])) : 'N/A'; ?></td>
                                    <td><?php echo tinh_thoi_gian_sinh_hoat($teacher['ngay_gia_nhap']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['so_dien_thoai'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['vai_tro']); ?></td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="7" class="text-center">Chưa có Huynh Trưởng.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="accordion" id="accordionPanelsStayOpenExample">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#panel1" aria-expanded="true">
                                <i class="fa-solid fa-children me-2"></i>Danh sách Đoàn Sinh (Sĩ số: <?php echo $result_students->num_rows; ?>)
                            </button>
                        </h2>
                        <div id="panel1" class="accordion-collapse collapse show">
                            <div class="accordion-body">
                                <?php if ($logged_in): ?>
                                    <div class="mb-3">
                                        <button class="btn btn-success btn-uniform" data-bs-toggle="modal" data-bs-target="#addStudentModal"><i class="fa-solid fa-plus me-2"></i>Thêm Đoàn Sinh</button>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <form method="GET" action="class_details.php">
                                        <input type="hidden" name="id" value="<?php echo $class_id; ?>">
                                        <div class="input-group">
                                            <input type="text" name="search" class="form-control" placeholder="Tìm kiếm theo tên..." value="<?php echo htmlspecialchars($search_query); ?>">
                                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i></button>
                                        </div>
                                    </form>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Tên Thánh</th>
                                                <th>Họ Tên</th>
                                                <th>Ngày Sinh</th>
                                                <th>Giới Tính</th>
                                                <?php if ($logged_in): ?>
                                                    <th>Ngày Rửa Tội</th>
                                                    <th>Ngày Thêm Sức</th>
                                                    <th>Họ Tên Bố</th>
                                                    <th>Họ Tên Mẹ</th>
                                                    <th>SĐT Liên Lạc</th>
                                                    <th>Email Phụ Huynh</th>
                                                    <th>Địa Chỉ</th>
                                                    <th>Ghi Chú</th>
                                                    <th>Thao tác</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($students as $student): ?>
                                                <tr class="<?php echo str_replace(' ', '-', $class['nganh']); ?>">
                                                    <td><?php echo htmlspecialchars($student['ten_thanh'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($student['ho_ten']); ?></td>
                                                    <td><?php echo $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A'; ?></td>
                                                    <td><?php echo htmlspecialchars($student['gioi_tinh']); ?></td>
                                                    <?php if ($logged_in): ?>
                                                        <td><?php echo $student['ngay_rua_toi'] ? date("d/m/Y", strtotime($student['ngay_rua_toi'])) : 'N/A'; ?></td>
                                                        <td><?php echo $student['ngay_them_suc'] ? date("d/m/Y", strtotime($student['ngay_them_suc'])) : 'N/A'; ?></td>
                                                        <td><?php echo htmlspecialchars($student['ho_ten_bo'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($student['ho_ten_me'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($student['sdt_lien_lac'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($student['email_phu_huynh'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($student['dia_chi'] ?? 'N/A'); ?></td>
                                                        <td><?php echo htmlspecialchars($student['ghi_chu'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editStudentModal" onclick="loadStudent(<?php echo $student['id']; ?>, '<?php echo addslashes(htmlspecialchars($student['ho_ten'])); ?>', '<?php echo addslashes(htmlspecialchars($student['ten_thanh'] ?? '')); ?>', '<?php echo $student['ngay_sinh'] ?? ''; ?>', '<?php echo htmlspecialchars($student['gioi_tinh']); ?>', '<?php echo $student['ngay_rua_toi'] ?? ''; ?>', '<?php echo $student['ngay_them_suc'] ?? ''; ?>', '<?php echo addslashes(htmlspecialchars($student['ho_ten_bo'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($student['ho_ten_me'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($student['sdt_lien_lac'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($student['email_phu_huynh'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($student['dia_chi'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($student['ghi_chu'] ?? '')); ?>')"><i class="fa-solid fa-edit"></i></button>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                                <button type="submit" name="delete_student" class="btn btn-sm btn-danger" onclick="return confirm('Xác nhận xóa?')"><i class="fa-solid fa-trash"></i></button>
                                                            </form>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($students)): ?>
                                                <tr><td colspan="<?php echo $logged_in ? 13 : 4; ?>" class="text-center">Chưa có Đoàn Sinh.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($logged_in): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#panel2" aria-expanded="false">
                                    <i class="fa-solid fa-calendar-check me-2"></i>Điểm Danh Đoàn Sinh
                                </button>
                            </h2>
                            <div id="panel2" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <form method="POST" id="attendanceForm">
                                        <input type="hidden" name="submit_attendance" value="1">
                                        <div class="mb-3">
                                            <input type="date" name="ngay_diem_danh" id="ngay_diem_danh" class="form-control" required value="<?php echo htmlspecialchars($selected_attendance_date); ?>" onchange="window.location='class_details.php?id=<?php echo $class_id; ?>&ngay_diem_danh='+this.value;">
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover text-center">
                                                <thead>
                                                    <tr>
                                                        <th>Tên Thánh</th>
                                                        <th>Họ Tên</th>
                                                        <th>Ngày Sinh</th>
                                                        <?php foreach ($attendance_dates as $date): ?>
                                                            <th class="attendance-col"><?php echo date('d/m/Y', strtotime($date)); ?></th>
                                                        <?php endforeach; ?>
                                                        <th class="attendance-col">Điểm Danh</th>
                                                        <th class="attendance-col">% Nghỉ Học</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($students as $student): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($student['ten_thanh'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($student['ho_ten']); ?></td>
                                                            <td><?php echo $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A'; ?></td>
                                                            <?php foreach ($attendance_dates as $date): ?>
                                                                <td class="attendance-col"><?php echo htmlspecialchars($attendance_data[$date][$student['id']] ?? 'N/A'); ?></td>
                                                            <?php endforeach; ?>
                                                            <td class="attendance-col">
                                                                <?php $cur = $attendance_selected[$student['id']] ?? 'có'; ?>
                                                                <select name="status[<?php echo $student['id']; ?>]" class="form-select">
                                                                    <option value="có" <?php echo $cur=='có'?'selected':''; ?>>Có</option>
                                                                    <option value="vắng có phép" <?php echo $cur=='vắng có phép'?'selected':''; ?>>Vắng CP</option>
                                                                    <option value="vắng không phép" <?php echo $cur=='vắng không phép'?'selected':''; ?>>Vắng KP</option>
                                                                </select>
                                                            </td>
                                                            <td class="attendance-col">
                                                                <?php 
                                                                $total = $total_sessions[$student['id']]['total'] ?? 0;
                                                                $absent = $total_sessions[$student['id']]['absent'] ?? 0;
                                                                $absent_percent = $total > 0 ? round(($absent / $total) * 100, 2) : 0;
                                                                $cls = $absent_percent > 40 ? 'absent-high' : '';
                                                                echo '<span class="' . $cls . '">' . $absent_percent . '%</span>';
                                                                ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($students)): ?>
                                                        <tr><td colspan="<?php echo 7 + count($attendance_dates); ?>" class="text-center">Chưa có Đoàn Sinh.</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <button type="submit" name="submit_attendance" class="btn btn-primary btn-uniform mt-3"><i class="fa-solid fa-save me-2"></i>Lưu</button>
                                        <button type="submit" name="send_email" class="btn btn-warning btn-uniform mt-3 ms-2"><i class="fa-solid fa-envelope me-2"></i>Gửi Mail</button>
                                        <button type="button" name="export_attendance_6m" class="btn btn-theme btn-uniform mt-3 ms-2"><i class="fa-solid fa-file-export me-2"></i>Xuất 6 tháng</button>
                                        <button type="button" name="export_attendance_1y" class="btn btn-theme btn-uniform mt-3 ms-2"><i class="fa-solid fa-file-export me-2"></i>Xuất 1 năm</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#panel3" aria-expanded="false">
                                    <i class="fa-solid fa-star me-2"></i>Điểm Đoàn Sinh
                                </button>
                            </h2>
                            <div id="panel3" class="accordion-collapse collapse">
                                <div class="accordion-body">
                                    <form method="POST">
                                        <input type="hidden" name="submit_grades" value="1">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover text-center">
                                                <thead>
                                                    <tr>
                                                        <th>Tên Thánh</th>
                                                        <th>Họ Tên</th>
                                                        <th>Ngày Sinh</th>
                                                        <th>15 phút 1</th>
                                                        <th>15 phút 2</th>
                                                        <th>1 tiết 1</th>
                                                        <th>1 tiết 2</th>
                                                        <th>Cuối kỳ 1</th>
                                                        <th>Cuối kỳ 2</th>
                                                        <th>Điểm TB</th>
                                                        <th>Xếp Hạng</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($students as $student): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($student['ten_thanh'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($student['ho_ten']); ?></td>
                                                            <td><?php echo $student['ngay_sinh'] ? date("d/m/Y", strtotime($student['ngay_sinh'])) : 'N/A'; ?></td>
                                                            <td><input type="number" step="0.5" min="0" max="10" name="diem[<?php echo $student['id']; ?>][15 phút 1]" value="<?php echo $grades_data[$student['id']]['15 phút 1'] ?? ''; ?>" class="form-control grade-input <?php echo ($grades_data[$student['id']]['15 phút 1'] ?? 0) < 5 ? 'low-grade' : ''; ?>" onfocus="this.value=''"></td>
                                                            <td><input type="number" step="0.5" min="0" max="10" name="diem[<?php echo $student['id']; ?>][15 phút 2]" value="<?php echo $grades_data[$student['id']]['15 phút 2'] ?? ''; ?>" class="form-control grade-input <?php echo ($grades_data[$student['id']]['15 phút 2'] ?? 0) < 5 ? 'low-grade' : ''; ?>" onfocus="this.value=''"></td>
                                                            <td><input type="number" step="0.5" min="0" max="10" name="diem[<?php echo $student['id']; ?>][1 tiết 1]" value="<?php echo $grades_data[$student['id']]['1 tiết 1'] ?? ''; ?>" class="form-control grade-input <?php echo ($grades_data[$student['id']]['1 tiết 1'] ?? 0) < 5 ? 'low-grade' : ''; ?>" onfocus="this.value=''"></td>
                                                            <td><input type="number" step="0.5" min="0" max="10" name="diem[<?php echo $student['id']; ?>][1 tiết 2]" value="<?php echo $grades_data[$student['id']]['1 tiết 2'] ?? ''; ?>" class="form-control grade-input <?php echo ($grades_data[$student['id']]['1 tiết 2'] ?? 0) < 5 ? 'low-grade' : ''; ?>" onfocus="this.value=''"></td>
                                                            <td><input type="number" step="0.5" min="0" max="10" name="diem[<?php echo $student['id']; ?>][Cuối kỳ 1]" value="<?php echo $grades_data[$student['id']]['Cuối kỳ 1'] ?? ''; ?>" class="form-control grade-input <?php echo ($grades_data[$student['id']]['Cuối kỳ 1'] ?? 0) < 5 ? 'low-grade' : ''; ?>" onfocus="this.value=''"></td>
                                                            <td><input type="number" step="0.5" min="0" max="10" name="diem[<?php echo $student['id']; ?>][Cuối kỳ 2]" value="<?php echo $grades_data[$student['id']]['Cuối kỳ 2'] ?? ''; ?>" class="form-control grade-input <?php echo ($grades_data[$student['id']]['Cuối kỳ 2'] ?? 0) < 5 ? 'low-grade' : ''; ?>" onfocus="this.value=''"></td>
                                                            <td><?php echo $tb_display[$student['id']] ?? 'N/A'; ?></td>
                                                            <td><?php echo $ranks_all[$student['id']] ?? 'N/A'; ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($students)): ?>
                                                        <tr><td colspan="11" class="text-center">Chưa có Đoàn Sinh.</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <button type="submit" name="submit_grades" class="btn btn-primary btn-uniform mt-3"><i class="fa-solid fa-save me-2"></i>Lưu Điểm</button>
                                        <button type="submit" name="export_grades" class="btn btn-theme btn-uniform mt-3 ms-2"><i class="fa-solid fa-file-export me-2"></i>Xuất Excel</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <a href="classes.php" class="btn btn-theme mt-3"><i class="fa-solid fa-arrow-left me-2"></i>Quay Lại Danh Sách Lớp</a>
            </div>
        </div>
    </main>

    <footer class="text-white text-center">
        <div class="container">
            <p class="mb-2">ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</p>
            <p>
                <a href="mailto:tntt.laptri@gmail.com" class="mx-2"><i class="fa-solid fa-envelope fa-lg"></i></a>
                <a href="https://facebook.com/tntt.laptri" class="mx-2"><i class="fa-brands fa-facebook fa-lg"></i></a>
            </p>
        </div>
    </footer>

    <?php if ($logged_in): ?>
    <!-- Modal Thêm Đoàn Sinh -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm Đoàn Sinh</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addStudentForm">
                        <input type="hidden" name="add_student" value="1">
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
                                <label class="form-label">Ngày Rửa Tội</label>
                                <input type="date" name="ngay_rua_toi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Thêm Sức</label>
                                <input type="date" name="ngay_them_suc" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Gia Nhập</label>
                                <input type="date" name="ngay_gia_nhap" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Bố</label>
                                <input type="text" name="ho_ten_bo" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Mẹ</label>
                                <input type="text" name="ho_ten_me" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SĐT Liên Lạc</label>
                                <input type="text" name="sdt_lien_lac" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Phụ Huynh</label>
                                <input type="email" name="email_phu_huynh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Địa Chỉ</label>
                                <input type="text" name="dia_chi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ghi Chú</label>
                                <input type="text" name="ghi_chu" class="form-control">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Thêm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Sửa Đoàn Sinh -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sửa Đoàn Sinh</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editStudentForm">
                        <input type="hidden" name="edit_student" value="1">
                        <input type="hidden" name="student_id" id="edit_student_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên</label>
                                <input type="text" name="ho_ten" id="edit_ho_ten" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tên Thánh</label>
                                <input type="text" name="ten_thanh" id="edit_ten_thanh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Sinh</label>
                                <input type="date" name="ngay_sinh" id="edit_ngay_sinh" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Giới Tính</label>
                                <select name="gioi_tinh" id="edit_gioi_tinh" class="form-control" required>
                                    <option value="Nam">Nam</option>
                                    <option value="Nữ">Nữ</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Rửa Tội</label>
                                <input type="date" name="ngay_rua_toi" id="edit_ngay_rua_toi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ngày Thêm Sức</label>
                                <input type="date" name="ngay_them_suc" id="edit_ngay_them_suc" class="form-control">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Bố</label>
                                <input type="text" name="ho_ten_bo" id="edit_ho_ten_bo" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Họ Tên Mẹ</label>
                                <input type="text" name="ho_ten_me" id="edit_ho_ten_me" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SĐT Liên Lạc</label>
                                <input type="text" name="sdt_lien_lac" id="edit_sdt_lien_lac" class="form-control" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" title="SĐT phải gồm 10 số">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Phụ Huynh</label>
                                <input type="email" name="email_phu_huynh" id="edit_email_phu_huynh" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Địa Chỉ</label>
                                <input type="text" name="dia_chi" id="edit_dia_chi" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ghi Chú</label>
                                <input type="text" name="ghi_chu" id="edit_ghi_chu" class="form-control">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Lưu</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
                    alert('SĐT Liên Lạc phải gồm đúng 10 số. Không thể lưu.');
                    return false;
                }
                return true;
            });
        }

        function loadStudent(id, ho_ten, ten_thanh, ngay_sinh, gioi_tinh, ngay_rua_toi, ngay_them_suc, ho_ten_bo, ho_ten_me, sdt_lien_lac, email_phu_huynh, dia_chi, ghi_chu) {
            document.getElementById('edit_student_id').value = id;
            document.getElementById('edit_ho_ten').value = ho_ten;
            document.getElementById('edit_ten_thanh').value = ten_thanh || '';
            document.getElementById('edit_ngay_sinh').value = ngay_sinh || '';
            document.getElementById('edit_gioi_tinh').value = gioi_tinh || '';
            document.getElementById('edit_ngay_rua_toi').value = ngay_rua_toi || '';
            document.getElementById('edit_ngay_them_suc').value = ngay_them_suc || '';
            document.getElementById('edit_ho_ten_bo').value = ho_ten_bo || '';
            document.getElementById('edit_ho_ten_me').value = ho_ten_me || '';
            document.getElementById('edit_sdt_lien_lac').value = sdt_lien_lac || '';
            document.getElementById('edit_email_phu_huynh').value = email_phu_huynh || '';
            document.getElementById('edit_dia_chi').value = dia_chi || '';
            document.getElementById('edit_ghi_chu').value = ghi_chu || '';
        }

        $(document).ready(function() {
            attachPhoneValidation('#addStudentForm', "input[name='sdt_lien_lac']");
            attachPhoneValidation('#editStudentForm', '#edit_sdt_lien_lac');
            // Nâng cấp: tải CSV qua fetch để hiện popup thành công/thất bại
            async function postAndDownloadCsv(postData, suggestedName) {
                try {
                    const url = 'class_details.php?id=<?php echo $class_id; ?>';
                    const resp = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams(postData).toString()
                    });
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    const contentType = (resp.headers.get('Content-Type') || '').toLowerCase();
                    const isCsv = contentType.includes('text/csv');
                    const isXls = contentType.includes('application/vnd.ms-excel') || contentType.includes('text/html');
                    if (!(isCsv || isXls)) {
                        const text = await resp.text();
                        throw new Error('Phản hồi không phải CSV/XLS. ' + text.slice(0, 200));
                    }
                    const cd = resp.headers.get('Content-Disposition') || '';
                    let filename = suggestedName || 'export.csv';
                    const match = cd.match(/filename="?([^";]+)"?/i);
                    if (match && match[1]) filename = match[1];
                    const blob = await resp.blob();
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(()=>URL.revokeObjectURL(link.href), 1000);
                    alert('Xuất file thành công: ' + filename);
                } catch (e) {
                    alert('Xuất file thất bại: ' + (e.message || e));
                }
            }

            $(document).on('click', "button[name='export_grades']", function(e) {
                e.preventDefault();
                postAndDownloadCsv({ export_grades: 1 }, 'diem.xls');
            });
            $(document).on('click', "button[name='export_attendance_6m']", function(e) {
                e.preventDefault();
                postAndDownloadCsv({ export_attendance_range: '6m', ngay_diem_danh: $('#ngay_diem_danh').val() }, 'diem_danh_6_thang.xls');
            });
            $(document).on('click', "button[name='export_attendance_1y']", function(e) {
                e.preventDefault();
                postAndDownloadCsv({ export_attendance_range: '1y', ngay_diem_danh: $('#ngay_diem_danh').val() }, 'diem_danh_1_nam.xls');
            });
            // Gửi điểm danh bằng submit chuẩn (tránh lỗi AJAX/redirect)
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>