<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * เอา user_id จาก session ไปหา mechanic_id ก่อน แล้ว query ใบงานเฉพาะ mechanic_id นี้
 * จำนวนงานแต่ละสถานะนับด้วย PHP จาก $jobs ที่โหลดมาแล้ว ไม่ได้ COUNT SQL แยกทุกการ์ด
 * งานใกล้กำหนดคือ waiting / in_progress / awaiting_review ที่มี due_date เรียงใกล้สุดก่อนแล้วตัด 5 งาน
 * ประวัติในหน้าหลักกรอง completed ด้วย PHP แล้วเรียง end_time ล่าสุดก่อน
 */
require_once "../config/database.php";
session_start();
require_once '../includes/repair_workflow.php';
$db = $conn ?? $pdo ?? null;
if (!$db) {
    die("Database connection not found.");
}
/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'mechanic'
) {
    header("Location: ../login.php");
    exit;
}
function h($value)
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}
/*
|--------------------------------------------------------------------------
| GET CURRENT MECHANIC
|--------------------------------------------------------------------------
*/
$userId = (int)$_SESSION['user_id'];
if ($db instanceof PDO) {
    /*
     * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง)
     * เงื่อนไข WHERE ที่ใช้จริง: user_id = ? 
     * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([$userId]);
    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
    $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    /*
     * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง)
     * เงื่อนไข WHERE ที่ใช้จริง: user_id = ? 
     * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");

    /*
     * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
     */
    $stmt->bind_param("i", $userId);
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute();
    $result = $stmt->get_result();
    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
    $mechanic = $result->fetch_assoc();
}

if (!$mechanic) {
    die("ไม่พบข้อมูลช่างสำหรับบัญชีนี้");
}

$mechanicId = (int)$mechanic['mechanic_id'];
/*
|--------------------------------------------------------------------------
| STATUS LABEL
|--------------------------------------------------------------------------
*/
$statusLabels = [
    'waiting' => 'รอเริ่มงาน',
    'in_progress' => 'กำลังซ่อม',
    'awaiting_review' => 'รอตรวจรับงาน',
    'completed' => 'เสร็จสิ้น'
];
/*
|--------------------------------------------------------------------------
| START WORK
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['start','complete'], true)) {
    try {
        workflowJobAction($pdo,(int)($_POST['repair_order_id'] ?? 0),$_POST['action'],$_POST,(int)$_SESSION['user_id'],$mechanicId);
        $_SESSION['repair_job_success'] = $_POST['action'] === 'start' ? 'เริ่มงานซ่อมแล้ว' : 'ส่งงานให้แอดมินตรวจรับแล้ว';
    } catch (InvalidArgumentException $e) {
        $_SESSION['repair_job_error'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $_SESSION['repair_job_error'] = 'ไม่สามารถบันทึกได้ กรุณารีเฟรชแล้วลองใหม่';
    }
    header('Location: dashboard.php');
    exit;
}
/*
|--------------------------------------------------------------------------
| LOAD MY JOBS
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม), customers (ลูกค้า), vehicles (รถยนต์)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * เงื่อนไข WHERE ที่ใช้จริง: r.mechanic_id = ? 
 * เรียงตาม: CASE r.status WHEN 'in_progress' THEN 1 WHEN 'waiting' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END,
 * r.due_date ASC, r.created_at DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
 */
$sql = "

    SELECT

        r.*,

        c.full_name AS customer_name,

        c.phone AS customer_phone,

        v.license_plate,

        v.brand,

        v.model,

        v.color

    FROM repair_jobs r

    INNER JOIN customers c
        ON r.customer_id = c.customer_id

    INNER JOIN vehicles v
        ON r.vehicle_id = v.vehicle_id

    WHERE
        r.mechanic_id = ?

    ORDER BY

        CASE r.status

            WHEN 'in_progress' THEN 1

            WHEN 'waiting' THEN 2

            WHEN 'completed' THEN 3

            ELSE 4

        END,

        r.due_date ASC,

        r.created_at DESC
";


$jobs = [];


if ($db instanceof PDO) {

    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $db->prepare($sql);

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([
        $mechanicId
    ]);

    $jobs =
        /*
         * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
         */
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $db->prepare($sql);

    /*
     * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
     */
    $stmt->bind_param(
        "i",
        $mechanicId
    );

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute();

    $result =
        $stmt->get_result();

    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
    while ($row = $result->fetch_assoc()) {

        $jobs[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$waitingCount = 0;
$inProgressCount = 0;
$completedCount = 0;
$reviewCount = count(array_filter($jobs, fn($job) => $job['status'] === 'awaiting_review'));


foreach ($jobs as $job) {

    if ($job['status'] === 'waiting') {
        $waitingCount++;
    }

    if ($job['status'] === 'in_progress') {
        $inProgressCount++;
    }

    if ($job['status'] === 'completed') {
        $completedCount++;
    }
}

/*
|--------------------------------------------------------------------------
| DASHBOARD DATA
|--------------------------------------------------------------------------
*/

// งานที่ยังไม่เสร็จและมี due date
$upcomingJobs = [];

foreach ($jobs as $job) {

    if (
        in_array($job['status'], ['waiting', 'in_progress', 'awaiting_review']) &&
        !empty($job['due_date'])
    ) {
        $upcomingJobs[] = $job;
    }
}


// เรียงงานตาม due date
usort($upcomingJobs, function ($a, $b) {

    return strtotime($a['due_date']) <=> strtotime($b['due_date']);

});


// แสดงเฉพาะ 5 งานล่าสุดใน Dashboard
$upcomingJobs = array_slice($upcomingJobs, 0, 5);


// ประวัติงานที่เสร็จแล้ว
$completedJobs = [];

foreach ($jobs as $job) {

    if ($job['status'] === 'completed') {
        $completedJobs[] = $job;
    }
}


// งานเสร็จล่าสุดขึ้นก่อน
usort($completedJobs, function ($a, $b) {

    $dateA = !empty($a['end_time'])
        ? strtotime($a['end_time'])
        : 0;

    $dateB = !empty($b['end_time'])
        ? strtotime($b['end_time'])
        : 0;

    return $dateB <=> $dateA;

});


// แสดง 4 งานล่าสุด
$completedJobs = array_slice($completedJobs, 0, 4);


// จำนวนงานที่กำลังจะถึงกำหนด
$upcomingCount = count($upcomingJobs);

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
         ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="../assets/css/admin/sidebar.css"
    >
    <link
        rel="stylesheet"
        href="../assets/css/user/dashboard.css">

<link rel="stylesheet" href="../assets/css/workflow.css">

    <!-- GARAGE THEME: shared navy, gold and warm cream styles -->
    <link rel="stylesheet" href="../assets/css/garage-theme.css">

</head>

<body>
<header class="topbar">
    <div class="brand">
            <!-- BRAND LOGO: original garage identity -->
            <img class="brand-logo" src="../assets/img/logo.jpg" alt="" width="48" height="48">
        ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
    </div>

        <div class="topbar-center"></div>            

    <div class="user-area">
        <div class="user-info">
            <strong>
                <?= h($mechanic['full_name']) ?>
            </strong>

            <span>
                ช่าง
            </span>
        </div>

        <a
            href="../logout.php"
            class="logout-btn"
        >
            ออกจากระบบ
        </a>
    </div>
</header>

<aside class="sidebar">
    <nav class="sidebar-menu">
        <a
            href="/car_repair_system/mechanic/dashboard.php"
            class="sidebar-link active"
        >
            <span class="menu-icon">
                🏠
            </span>

            <span>
                หน้าหลัก
            </span>
        </a>

        <a
            href="/car_repair_system/mechanic/repair_jobs/index.php"
            class="sidebar-link"
        >
            <span class="menu-icon">
                🔧
            </span>

            <span>
                งานของฉัน
            </span>
        </a>

        <a
            href="/car_repair_system/mechanic/history/index.php"
            class="sidebar-link"
        >
            <span class="menu-icon">
                📋
            </span>

            <span>
                ประวัติงานซ่อม
            </span>
        </a>
    </nav>
</aside>

<main class="main-content">
<?php if ($reviewCount > 0): ?><p class="workflow-notice">งานรอตรวจรับ: <?= (int)$reviewCount ?> งาน — ดูประวัติและผลตรวจรับได้ในหน้าใบงานซ่อม</p><?php endif; ?>
    <section class="mechanic-dashboard">
        <!-- =====================================================
             WELCOME
        ====================================================== -->
        <div class="welcome-section garage-page-header">
            <div>
                <span class="welcome-label">
                    👋 ยินดีต้อนรับ
                </span>
                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-car"></i>
                </span>

                <h1>
                    สวัสดีครับ คุณ<?= h($mechanic['full_name']) ?>
                </h1>

                <p>
                    ตรวจสอบงานซ่อมและติดตามงานที่ได้รับมอบหมายได้ที่นี่
                </p>
            </div>
        </div>
        <!-- =====================================================
             SUMMARY
        ====================================================== -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon">
                    🔧
                </div>

                <div>
                    <div class="summary-label">
                        งานที่ได้รับมอบหมาย
                    </div>

                    <div class="summary-number">
                        <?=$waitingCount?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon">
                    🛠️
                </div>

                <div>
                    <div class="summary-label">
                        กำลังดำเนินการ
                    </div>

                    <div class="summary-number">
                        <?= $inProgressCount ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon">
                    ✅
                </div>

                <div>
                    <div class="summary-label">
                        งานที่เสร็จแล้ว
                    </div>

                    <div class="summary-number">
                        <?= $completedCount ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- =====================================================
             UPCOMING JOBS
        ====================================================== -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <div class="section-title">
                        <span>
                            🔔
                        </span>
                        งานที่ใกล้ถึงกำหนด
                    </div>
                    <p>
                        งานที่ควรติดตามและดำเนินการ
                    </p>
                </div>
                <button
                    type="button"
                    class="section-more"
                    onclick="window.location.href='/car_repair_system/mechanic/repair_jobs/index.php'"
                >
                    ดูเพิ่มเติม →
                </button>
            </div>
            <?php if (empty($upcomingJobs)): ?>

                <div class="empty-card">
                    <div class="empty-icon">
                        🎉
                    </div>
                    <div>
                        <strong>
                            ไม่มีงานที่ใกล้ถึงกำหนด
                        </strong>
                        <p>
                            ตอนนี้ไม่มีงานที่ต้องเร่งติดตาม
                        </p>
                    </div>
                </div>
            <?php else: ?>

                <div class="notification-list">
                    <?php foreach ($upcomingJobs as $job): ?>
                        <?php
                        $dueTimestamp = strtotime($job['due_date']);
                        $today = strtotime(date('Y-m-d'));
                        $daysLeft = floor(
                            ($dueTimestamp - $today) / 86400
                        );
                        ?>
                        <a
                            href="/car_repair_system/mechanic/repair_jobs/index.php"
                            class="notification-card"
                        >
                            <div class="notification-icon">
                                🔧
                            </div>

                            <div class="notification-content">
                                <div class="notification-top">
                                    <strong>
                                        <?= h($job['repair_order_code']) ?>
                                    </strong>

                                    <span
                                        class="status-badge
                                        <?= $job['status'] === 'in_progress'
                                            ? 'status-progress'
                                            : ($job['status'] === 'awaiting_review' ? 'status-awaiting_review' : 'status-waiting')
                                        ?>"
                                    >
                                        <?= h(
                                            $statusLabels[$job['status']]
                                            ?? $job['status']
                                        ) ?>
                                    </span>
                                </div>

                                <div class="notification-title">
                                    <?= h($job['brand']) ?>
                                    <?= h($job['model']) ?>
                                    · ทะเบียน
                                    <?= h($job['license_plate']) ?>
                                </div>

                                <div class="notification-meta">
                                    <span>
                                        👤
                                        <?= h($job['customer_name']) ?>
                                    </span>

                                    <span>
                                        📅
                                        <?= date(
                                            'd/m/Y',
                                            $dueTimestamp
                                        ) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="notification-deadline">
                                <?php if ($daysLeft < 0): ?>
                                    <span class="deadline-overdue">
                                        เลยกำหนด
                                    </span>
                                <?php elseif ($daysLeft === 0): ?>
                                    <span class="deadline-today">
                                        ครบกำหนดวันนี้
                                    </span>
                                <?php elseif ($daysLeft === 1): ?>
                                    <span class="deadline-soon">
                                        เหลือ 1 วัน
                                    </span>
                                <?php else: ?>
                                    <span>
                                        เหลือ <?= $daysLeft ?> วัน
                                    </span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <!-- =====================================================
             COMPLETED HISTORY
        ====================================================== -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <div class="section-title">
                        <span>
                            📋
                        </span>
                        ประวัติงานที่เสร็จแล้ว
                    </div>
                    <p>
                        งานซ่อมล่าสุดที่ดำเนินการเสร็จสิ้น
                    </p>
                </div>

                <button
                    type="button"
                    class="section-more"
                    onclick="window.location.href='/car_repair_system/mechanic/history/index.php'"
                >
                    ดูเพิ่มเติม →
                </button>
            </div>
            <?php if (empty($completedJobs)): ?>

                <div class="empty-card">
                    <div class="empty-icon">
                        📋
                    </div>
                    <div>
                        <strong>
                            ยังไม่มีประวัติงานซ่อม
                        </strong>
                        <p>
                            งานที่ดำเนินการเสร็จแล้วจะแสดงที่นี่
                        </p>
                    </div>
                </div>
            <?php else: ?>

                <div class="history-grid">
                    <?php foreach ($completedJobs as $job): ?>
                        <a
                            href="/car_repair_system/mechanic/history/index.php"
                            class="history-card"
                        >
                            <div class="history-card-top">
                                <strong>
                                    <?= h($job['repair_order_code']) ?>
                                </strong>

                                <span class="completed-badge">
                                    ✓ เสร็จสิ้น
                                </span>
                            </div>

                            <h3>
                                <?= h($job['brand']) ?>
                                <?= h($job['model']) ?>
                            </h3>

                            <p class="history-plate">
                                🚗
                                <?= h($job['license_plate']) ?>
                            </p>

                            <div class="history-bottom">
                                <span>
                                    👤
                                    <?= h($job['customer_name']) ?>
                                </span>
                                <?php if (!empty($job['end_time'])): ?>
                                    <span>
                                        <?= date(
                                            'd/m/Y',
                                            strtotime($job['end_time'])
                                        ) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../includes/logout_modal.php"; ?>

</body>
</html>
