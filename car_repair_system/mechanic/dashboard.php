<?php

require_once "../config/database.php";

session_start();

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

    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);

} else {

    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();

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

    'assigned' => 'ได้รับมอบหมาย',

    'in_progress' => 'กำลังดำเนินการ',

    'completed' => 'เสร็จสิ้น'

];


/*
|--------------------------------------------------------------------------
| START WORK
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'start'
) {

    $repairId =
        (int)($_POST['repair_order_id'] ?? 0);


    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                status = 'in_progress',
                start_time = NOW(),
                updated_at = NOW()
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
                AND status = 'assigned'
        ");

        $stmt->execute([
            $repairId,
            $mechanicId
        ]);

    } else {

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                status = 'in_progress',
                start_time = NOW(),
                updated_at = NOW()
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
                AND status = 'assigned'
        ");

        $stmt->bind_param(
            "ii",
            $repairId,
            $mechanicId
        );

        $stmt->execute();
    }


    header("Location: index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| COMPLETE WORK
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'complete'
) {

    $repairId =
        (int)($_POST['repair_order_id'] ?? 0);

    $inspectionResult =
        trim($_POST['inspection_result'] ?? '');

    $repairDetail =
        trim($_POST['repair_detail'] ?? '');

    $additionalProblem =
        trim($_POST['additional_problem'] ?? '');

    $mechanicNote =
        trim($_POST['mechanic_note'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | COMPLETE REPAIR JOB
    |--------------------------------------------------------------------------
    */

    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                status = 'completed',
                end_time = NOW(),
                inspection_result = ?,
                repair_detail = ?,
                additional_problem = ?,
                mechanic_note = ?,
                updated_at = NOW()
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
                AND status = 'in_progress'
        ");

        $stmt->execute([
            $inspectionResult,
            $repairDetail,
            $additionalProblem,
            $mechanicNote,
            $repairId,
            $mechanicId
        ]);

        /*
        |--------------------------------------------------------------------------
        | CHECK WHETHER JOB WAS ACTUALLY COMPLETED
        |--------------------------------------------------------------------------
        */

        if ($stmt->rowCount() > 0) {

            /*
            |--------------------------------------------------------------------------
            | SET MECHANIC AVAILABLE
            |--------------------------------------------------------------------------
            */

            $stmt = $db->prepare("
                UPDATE mechanics
                SET
                    status = 'available',
                    updated_at = NOW()
                WHERE
                    mechanic_id = ?
            ");

            $stmt->execute([
                $mechanicId
            ]);
        }

    } else {

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                status = 'completed',
                end_time = NOW(),
                inspection_result = ?,
                repair_detail = ?,
                additional_problem = ?,
                mechanic_note = ?,
                updated_at = NOW()
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
                AND status = 'in_progress'
        ");

        $stmt->bind_param(
            "ssssii",
            $inspectionResult,
            $repairDetail,
            $additionalProblem,
            $mechanicNote,
            $repairId,
            $mechanicId
        );

        $stmt->execute();

        /*
        |--------------------------------------------------------------------------
        | CHECK WHETHER JOB WAS ACTUALLY COMPLETED
        |--------------------------------------------------------------------------
        */

        if ($stmt->affected_rows > 0) {

            /*
            |--------------------------------------------------------------------------
            | SET MECHANIC AVAILABLE
            |--------------------------------------------------------------------------
            */

            $stmt = $db->prepare("
                UPDATE mechanics
                SET
                    status = 'available',
                    updated_at = NOW()
                WHERE
                    mechanic_id = ?
            ");

            $stmt->bind_param(
                "i",
                $mechanicId
            );

            $stmt->execute();
        }
    }

    header("Location: index.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| LOAD MY JOBS
|--------------------------------------------------------------------------
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

            WHEN 'assigned' THEN 2

            WHEN 'completed' THEN 3

            ELSE 4

        END,

        r.due_date ASC,

        r.created_at DESC
";


$jobs = [];


if ($db instanceof PDO) {

    $stmt = $db->prepare($sql);

    $stmt->execute([
        $mechanicId
    ]);

    $jobs =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $stmt = $db->prepare($sql);

    $stmt->bind_param(
        "i",
        $mechanicId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $jobs[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$assignedCount = 0;
$inProgressCount = 0;
$completedCount = 0;


foreach ($jobs as $job) {

    if ($job['status'] === 'assigned') {
        $assignedCount++;
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
        in_array($job['status'], ['assigned', 'in_progress']) &&
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
         P.Chalermchai Car Service
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

</head>

<body>

<header class="topbar">

    <div class="brand">
        P.Chalermchai Car Service
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

    <section class="mechanic-dashboard">

        <!-- =====================================================
             WELCOME
        ====================================================== -->

        <div class="welcome-section">

            <div>

                <span class="welcome-label">
                    👋 ยินดีต้อนรับ
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
                        <?= $assignedCount ?>
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
                                            : 'status-assigned'
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
</body>
</html>