<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost", "root", "", "tntt_lap_tri");
if ($conn->connect_error) {
    die(json_encode(['error' => 'Kết nối thất bại: ' . $conn->connect_error]));
}

$class_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sql = "SELECT t.id as teacher_id, ct.vai_tro 
        FROM class_teachers ct 
        JOIN teachers t ON ct.teacher_id = t.id 
        WHERE ct.class_id = $class_id";
$result = $conn->query($sql);

$teachers = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $teachers[] = $row;
    }
}
echo json_encode($teachers);
$conn->close();
?>