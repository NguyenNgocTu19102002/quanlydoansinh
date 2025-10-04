<?php
session_start();
$logged_in = isset($_SESSION['user_id']);

// Lấy lời chúa hằng ngày (mô phỏng Selenium)
require_once 'loi_chua_fetcher_selenium.php';
$loi_chua = (new LoiChuaFetcherSelenium())->fetchTinMung();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #E3F2FD 0%, #BBDEFB 100%);
            animation: fadeIn 1s ease-in;
        }
        header {
            background: linear-gradient(90deg, #0277BD, #03A9F4);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            padding: 20px 0;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }
        .header-content {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            position: relative;
        }
        header img {
            max-width: 80px;
            margin-left: 20px;
            transition: transform 0.4s ease, filter 0.4s ease;
        }
        header img:hover {
            transform: scale(1.15) rotate(5deg);
            filter: brightness(1.2);
        }
        header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
            text-align: center;
        }
        .navbar-nav .nav-link {
            color: white !important;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 25px;
            margin: 0 5px;
            transition: all 0.3s ease;
        }
        .navbar-nav .nav-link:hover {
            background: #FFFFFF33;
            transform: translateY(-2px);
        }
        .navbar-nav .nav-link.active {
            background: white;
            color: #0277BD !important;
        }
        main {
            min-height: 70vh;
            padding: 40px 0;
        }
        .card {
            border: none;
            border-radius: 20px;
            background: #FFFFFF;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }
        .card-header {
            background: linear-gradient(90deg, #0277BD, #03A9F4);
            color: white;
            font-weight: 600;
            border-radius: 20px 20px 0 0;
            padding: 15px;
            text-align: center;
        }
        .card-body {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .btn-primary {
            background: #0277BD;
            border: none;
            border-radius: 25px;
            padding: 12px 24px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: #01579B;
            transform: scale(1.05);
        }
        .list-group-item {
            border: none;
            background: transparent;
            transition: all 0.3s ease;
        }
        .list-group-item:hover {
            background: #F5F5F5;
            border-radius: 10px;
        }
        footer {
            background: linear-gradient(90deg, #0277BD, #03A9F4);
            color: white;
            padding: 20px 0;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.2);
        }
        footer a {
            color: white;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        footer a:hover {
            color: #BBDEFB;
        }
        .equal-height-row {
            display: flex;
            flex-wrap: wrap;
            align-items: stretch;
        }
        .equal-height-row > div {
            display: flex;
            flex: 1 1 0;
        }
        .card {
            width: 100%;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            header img {
                margin: 0 auto 10px;
            }
            header h1 {
                position: static;
                transform: none;
                font-size: 1.5rem;
            }
            .card {
                margin-bottom: 20px;
            }
            .navbar-nav .nav-link {
                padding: 8px 15px;
            }
            .equal-height-row {
                flex-direction: column;
            }
            .equal-height-row > div {
                flex: 1 1 auto;
            }
        }
    </style>
</head>
<body>
    <header class="text-white text-center">
        <div class="container">
            <div class="header-content">
                <img src="img/logo.png" alt="Logo Đoàn">
                <h1>ĐOÀN TNTT ĐAMINH SAVIO LẬP TRÍ</h1>
            </div>
        </div>
        <nav class="navbar navbar-expand-lg navbar-dark mt-3">
            <div class="container-fluid">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse justify-content-center" id="navbarNav">
                    <ul class="navbar-nav">
                        <li class="nav-item"><a class="nav-link active" href="index.php">Trang chủ</a></li>
                        <li class="nav-item"><a class="nav-link" href="classes.php">Quản lý đoàn sinh</a></li>
                        <?php if ($logged_in): ?>
                            <li class="nav-item"><a class="nav-link" href="logout.php">Đăng xuất</a></li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link" href="login.php">Đăng nhập</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </nav>
    </header>
    <main class="container my-5">
        <div class="row g-4 justify-content-center equal-height-row">
            <div class="col-md-4 col-lg-3">
                <div class="card">
                    <div class="card-header">Lời Chúa Hàng Ngày</div>
                    <div class="card-body">
                        <div class="loi-chua-content" style="max-height: 300px; overflow-y: auto; font-size: 0.9rem; line-height: 1.4;">
                            <pre style="white-space: pre-wrap; font-family: inherit; margin: 0; background: none; border: none; padding: 0;"><?= htmlspecialchars($loi_chua) ?></pre>
                        </div>
                        <div class="mt-2 text-center">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i> Ngày: <?= date('d/m/Y') ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-lg-3">
                <div class="card">
                    <div class="card-header">Quản Lý Đoàn Sinh</div>
                    <div class="card-body text-center">
                        <a href="classes.php" class="btn btn-primary">Quản Lý Đoàn Sinh</a>
                    </div>
                </div>
            </div>
        <?php if ($logged_in && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'hdt_manager'): ?>
        <div class="col-md-4 col-lg-3">
            <div class="card">
                <div class="card-header">Quản Lý Huynh Dự Trưởng</div>
                <div class="card-body text-center">
                    <a href="hdt_management.php" class="btn btn-primary mb-2">Quản Lý Huynh Dự Trưởng</a>
                    <br>
                    <a href="hdt_attendance.php" class="btn btn-warning">Điểm Danh HT</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
            <div class="col-md-4 col-lg-3">
                <div class="card">
                    <div class="card-header">Thông Báo</div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item">Sinh hoạt tuần này: Chủ nhật, 8h sáng</li>
                            <li class="list-group-item">Trại hè 2025: Đăng ký trước 30/10</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="text-center">
        <div class="container">
            <p>Liên hệ: <a href="mailto:tntt.laptri@gmail.com">tntt.laptri@gmail.com</a> | 
            <a href="https://facebook.com/tntt.laptri">Facebook</a></p>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/script.js"></script>
</body>
</html>