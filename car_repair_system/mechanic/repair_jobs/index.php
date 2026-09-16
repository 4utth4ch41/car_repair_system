<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';


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
| CURRENT MECHANIC
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
                start_time = COALESCE(start_time, NOW()),
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
                start_time = COALESCE(start_time, NOW()),
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
| UPDATE STATUS / PROGRESS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_status'
) {

    $repairId =
        (int)($_POST['repair_order_id'] ?? 0);

    $newStatus =
        $_POST['status'] ?? '';

    $mechanicNote =
        trim($_POST['mechanic_note'] ?? '');


    /*
    | อนุญาตเฉพาะสถานะที่ระบบมีอยู่
    */

    $allowedStatuses = [
        'assigned',
        'in_progress'
    ];


    if (!in_array($newStatus, $allowedStatuses, true)) {

        header("Location: index.php");
        exit;
    }


    if ($db instanceof PDO) {

        if ($newStatus === 'in_progress') {

            $stmt = $db->prepare("
                UPDATE repair_jobs

                SET
                    status = ?,
                    start_time = COALESCE(start_time, NOW()),
                    mechanic_note = ?,
                    updated_at = NOW()

                WHERE
                    repair_order_id = ?

                    AND mechanic_id = ?

                    AND status <> 'completed'
            ");

            $stmt->execute([
                $newStatus,
                $mechanicNote,
                $repairId,
                $mechanicId
            ]);

        } else {

            $stmt = $db->prepare("
                UPDATE repair_jobs

                SET
                    status = ?,
                    mechanic_note = ?,
                    updated_at = NOW()

                WHERE
                    repair_order_id = ?

                    AND mechanic_id = ?

                    AND status <> 'completed'
            ");

            $stmt->execute([
                $newStatus,
                $mechanicNote,
                $repairId,
                $mechanicId
            ]);
        }

    } else {

        if ($newStatus === 'in_progress') {

            $stmt = $db->prepare("
                UPDATE repair_jobs

                SET
                    status = ?,
                    start_time = COALESCE(start_time, NOW()),
                    mechanic_note = ?,
                    updated_at = NOW()

                WHERE
                    repair_order_id = ?

                    AND mechanic_id = ?

                    AND status <> 'completed'
            ");

        } else {

            $stmt = $db->prepare("
                UPDATE repair_jobs

                SET
                    status = ?,
                    mechanic_note = ?,
                    updated_at = NOW()

                WHERE
                    repair_order_id = ?

                    AND mechanic_id = ?

                    AND status <> 'completed'
            ");
        }


        $stmt->bind_param(
            "ssii",
            $newStatus,
            $mechanicNote,
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
| SAVE PARTS USED
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save_parts'
) {

    $repairId =
        (int)($_POST['repair_order_id'] ?? 0);


    $partNames =
        $_POST['part_name'] ?? [];

    $quantities =
        $_POST['quantity'] ?? [];

    $unitPrices =
        $_POST['unit_price'] ?? [];


    /*
    | ตรวจสอบว่าเป็นงานของช่างคนนี้จริง
    */

    if ($db instanceof PDO) {

        $check = $db->prepare("
            SELECT repair_order_id
            FROM repair_jobs
            WHERE
                repair_order_id = ?

                AND mechanic_id = ?

            LIMIT 1
        ");

        $check->execute([
            $repairId,
            $mechanicId
        ]);

        $jobExists = $check->fetch();

    } else {

        $check = $db->prepare("
            SELECT repair_order_id
            FROM repair_jobs
            WHERE
                repair_order_id = ?

                AND mechanic_id = ?

            LIMIT 1
        ");

        $check->bind_param(
            "ii",
            $repairId,
            $mechanicId
        );

        $check->execute();

        $result = $check->get_result();

        $jobExists = $result->fetch_assoc();
    }


    if (!$jobExists) {

        header("Location: index.php");
        exit;
    }


    /*
    | ลบรายการเดิมก่อน
    | แล้วบันทึกรายการล่าสุดใหม่
    */

    if ($db instanceof PDO) {

        $delete = $db->prepare("
            DELETE FROM parts_used
            WHERE repair_order_id = ?
        ");

        $delete->execute([
            $repairId
        ]);


        $insert = $db->prepare("
            INSERT INTO parts_used
            (
                repair_order_id,
                part_name,
                quantity,
                unit_price
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?
            )
        ");


        foreach ($partNames as $i => $partName) {

            $partName =
                trim($partName);

            $quantity =
                (int)($quantities[$i] ?? 0);

            $unitPrice =
                (float)($unitPrices[$i] ?? 0);


            if (
                $partName === '' ||
                $quantity <= 0
            ) {
                continue;
            }


            $insert->execute([
                $repairId,
                $partName,
                $quantity,
                $unitPrice
            ]);
        }

    } else {

        $delete = $db->prepare("
            DELETE FROM parts_used
            WHERE repair_order_id = ?
        ");

        $delete->bind_param(
            "i",
            $repairId
        );

        $delete->execute();


        $insert = $db->prepare("
            INSERT INTO parts_used
            (
                repair_order_id,
                part_name,
                quantity,
                unit_price
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?
            )
        ");


        foreach ($partNames as $i => $partName) {

            $partName =
                trim($partName);

            $quantity =
                (int)($quantities[$i] ?? 0);

            $unitPrice =
                (float)($unitPrices[$i] ?? 0);


            if (
                $partName === '' ||
                $quantity <= 0
            ) {
                continue;
            }


            $insert->bind_param(
                "isid",
                $repairId,
                $partName,
                $quantity,
                $unitPrice
            );

            $insert->execute();
        }
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
| LOAD PARTS USED
|--------------------------------------------------------------------------
*/

$partsByJob = [];


if (!empty($jobs)) {

    $jobIds = array_column(
        $jobs,
        'repair_order_id'
    );


    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($jobIds),
                '?'
            )
        );


    if ($db instanceof PDO) {

        $sqlParts = "

            SELECT
                part_id,
                repair_order_id,
                part_name,
                quantity,
                unit_price,
                total_price

            FROM parts_used

            WHERE repair_order_id IN ($placeholders)

            ORDER BY part_id ASC
        ";


        $stmt = $db->prepare(
            $sqlParts
        );

        $stmt->execute(
            $jobIds
        );


        while (
            $part =
            $stmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $partsByJob[
                $part['repair_order_id']
            ][] = $part;
        }

    } else {

        /*
        | MySQLi
        | ใช้ dynamic bind
        */

        $types =
            str_repeat(
                "i",
                count($jobIds)
            );


        $stmt =
            $db->prepare($sqlParts);


        $params = [];

        $params[] =
            &$types;


        foreach ($jobIds as $key => $value) {

            $params[] =
                &$jobIds[$key];
        }


        call_user_func_array(
            [$stmt, 'bind_param'],
            $params
        );


        $stmt->execute();

        $result =
            $stmt->get_result();


        while (
            $part =
            $result->fetch_assoc()
        ) {

            $partsByJob[
                $part['repair_order_id']
            ][] = $part;
        }
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

    if (
        $job['status'] === 'assigned'
    ) {
        $assignedCount++;
    }


    if (
        $job['status'] === 'in_progress'
    ) {
        $inProgressCount++;
    }


    if (
        $job['status'] === 'completed'
    ) {
        $completedCount++;
    }
}


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
        งานของฉัน | P.Chalermchai Car Service
    </title>


    <link
        rel="stylesheet"
        href="../../assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="../../assets/css/admin/sidebar.css"
    >

    <link rel="stylesheet" href="../../assets/css/user/dashboard.css">

</head>


<body>


<!-- =====================================================
     TOPBAR
====================================================== -->

<header class="topbar">

    <div class="brand">
        P.Chalermchai Car Service
    </div>

    <div class="topbar-center">
    </div>

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
            href="../../logout.php"
            class="logout-btn"
        >
            ออกจากระบบ
        </a>

    </div>

</header>


<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside class="sidebar">

    <nav class="sidebar-menu">


        <a
            href="/car_repair_system/mechanic/dashboard.php"
            class="sidebar-link"
        >

            <span class="menu-icon">
                🏠
            </span>

            <span>
                หน้าหลัก
            </span>

        </a>


        <a
            href="/car_repair_system/mechanic/repair_order/index.php"
            class="sidebar-link active"
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


<!-- =====================================================
     MAIN
====================================================== -->

<main class="main-content">

    <section class="mechanic-page">


        <!-- HEADER -->

        <div class="page-header">

            <div>

                <h1>
                    งานของฉัน
                </h1>

                <p>
                    งานซ่อมที่ได้รับมอบหมายและกำลังดำเนินการ
                </p>

            </div>

        </div>


        <!-- =================================================
             SUMMARY
        ================================================== -->

        <div class="summary-grid">


            <div class="summary-card">

                <div class="summary-label">
                    งานที่ได้รับมอบหมาย
                </div>

                <div class="summary-number">
                    <?= $assignedCount ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    กำลังดำเนินการ
                </div>

                <div class="summary-number">
                    <?= $inProgressCount ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-label">
                    งานที่เสร็จแล้ว
                </div>

                <div class="summary-number">
                    <?= $completedCount ?>
                </div>

            </div>


        </div>


        <!-- =================================================
             NOTIFICATION LIST
        ================================================== -->

        <section class="job-notification-section">


            <div class="section-title">

                <div>

                    <h2>
                        🔔 งานซ่อมของฉัน
                    </h2>

                    <p>
                        กดที่งานเพื่อดูรายละเอียด
                    </p>

                </div>


                <span class="job-count">

                    <?= count($jobs) ?> งาน

                </span>

            </div>


            <?php if (empty($jobs)): ?>

                <div class="empty">

                    <div class="empty-icon">
                        🔧
                    </div>

                    <h3>
                        ยังไม่มีงานซ่อม
                    </h3>

                    <p>
                        เมื่อมีการมอบหมายงาน
                        งานจะแสดงที่หน้านี้
                    </p>

                </div>

            <?php endif; ?>


            <div class="job-notification-list">


                <?php foreach ($jobs as $index => $job): ?>


                    <?php

                    $repairId =
                        (int)$job['repair_order_id'];

                    $jobParts =
                        $partsByJob[$repairId] ?? [];

                    $partsTotal = 0;


                    foreach ($jobParts as $part) {

                        $partsTotal +=
                            (float)$part['total_price'];
                    }


                    $isOverdue = false;


                    if (
                        !empty($job['due_date']) &&
                        $job['status'] !== 'completed'
                    ) {

                        $isOverdue =
                            $job['due_date'] < date('Y-m-d');
                    }

                    ?>


                    <article
                        class="
                            notification-job
                            <?= $index === 0
                                ? 'is-open'
                                : ''
                            ?>
                        "
                    >


                        <!-- =================================================
                             NOTIFICATION HEADER
                        ================================================== -->

                        <button
                            type="button"
                            class="notification-header"
                            onclick="toggleJob(this)"
                        >

                            <div class="notification-icon">

                                <?php if (
                                    $job['status'] === 'completed'
                                ): ?>

                                    ✓

                                <?php elseif (
                                    $job['status'] === 'in_progress'
                                ): ?>

                                    🔧

                                <?php else: ?>

                                    🔔

                                <?php endif; ?>

                            </div>


                            <div class="notification-main">

                                <div class="notification-top">

                                    <strong>

                                        <?= h(
                                            $job['repair_order_code']
                                        ) ?>

                                    </strong>


                                    <span
                                        class="
                                            status-badge
                                            status-<?= h(
                                                $job['status']
                                            ) ?>
                                        "
                                    >

                                        <?= h(
                                            $statusLabels[
                                                $job['status']
                                            ] ?? $job['status']
                                        ) ?>

                                    </span>

                                </div>


                                <div class="notification-customer">

                                    <?= h(
                                        $job['customer_name']
                                    ) ?>

                                </div>


                                <div class="notification-vehicle">

                                    🚗

                                    <?= h($job['brand']) ?>

                                    <?= h($job['model']) ?>

                                    ·

                                    <?= h(
                                        $job['license_plate']
                                    ) ?>

                                </div>

                            </div>


                            <div class="notification-arrow">

                                <span>
                                    ▼
                                </span>

                            </div>

                        </button>


                        <!-- =================================================
                             EXPANDED CARD
                        ================================================== -->

                        <div class="job-expanded">


                            <div class="job-expanded-inner">


                                <!-- JOB INFORMATION -->

                                <div class="job-information">

                                    <div>

                                        <span>
                                            อาการเบื้องต้น
                                        </span>

                                        <strong>

                                            <?= h(
                                                $job['initial_symptom']
                                                ?: 'ไม่ได้ระบุ'
                                            ) ?>

                                        </strong>

                                    </div>


                                    <?php if (
                                        !empty(
                                            $job['customer_request']
                                        )
                                    ): ?>

                                        <div>

                                            <span>
                                                ความต้องการลูกค้า
                                            </span>

                                            <strong>

                                                <?= h(
                                                    $job['customer_request']
                                                ) ?>

                                            </strong>

                                        </div>

                                    <?php endif; ?>


                                    <div>

                                        <span>
                                            วันรับรถ
                                        </span>

                                        <strong>

                                            <?= !empty(
                                                $job['service_date']
                                            )
                                                ? date(
                                                    'd/m/Y',
                                                    strtotime(
                                                        $job['service_date']
                                                    )
                                                )
                                                : '-'
                                            ?>

                                        </strong>

                                    </div>


                                    <?php if (
                                        !empty(
                                            $job['due_date']
                                        )
                                    ): ?>

                                        <div>

                                            <span>

                                                <?= $isOverdue
                                                    ? '⚠️ เกินกำหนด'
                                                    : '⏰ กำหนดเสร็จ'
                                                ?>

                                            </span>

                                            <strong>

                                                <?= date(
                                                    'd/m/Y',
                                                    strtotime(
                                                        $job['due_date']
                                                    )
                                                ) ?>

                                            </strong>

                                        </div>

                                    <?php endif; ?>

                                </div>


                                <!-- =================================================
                                     UPDATE STATUS
                                ================================================== -->

                                <?php if (
                                    $job['status'] !== 'completed'
                                ): ?>

                                    <div class="progress-box">

                                        <div class="box-title">

                                            <span>
                                                📢 อัปเดตสถานะงาน
                                            </span>

                                        </div>


                                        <form
                                            method="POST"
                                            class="status-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="update_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="repair_order_id"
                                                value="<?= $repairId ?>"
                                            >


                                            <div class="status-form-row">


                                                <div>

                                                    <label>
                                                        สถานะ
                                                    </label>

                                                    <select
                                                        name="status"
                                                        required
                                                    >

                                                        <option
                                                            value="assigned"
                                                            <?= $job['status'] === 'assigned'
                                                                ? 'selected'
                                                                : ''
                                                            ?>
                                                        >
                                                            ได้รับมอบหมาย
                                                        </option>

                                                        <option
                                                            value="in_progress"
                                                            <?= $job['status'] === 'in_progress'
                                                                ? 'selected'
                                                                : ''
                                                            ?>
                                                        >
                                                            กำลังดำเนินการ
                                                        </option>

                                                    </select>

                                                </div>


                                                <div class="status-note-field">

                                                    <label>
                                                        รายงานความคืบหน้า
                                                    </label>

                                                    <input
                                                        type="text"
                                                        name="mechanic_note"
                                                        value="<?= h(
                                                            $job['mechanic_note']
                                                            ?? ''
                                                        ) ?>"
                                                        placeholder="เช่น กำลังถอดชุดเบรก / รออะไหล่..."
                                                    >

                                                </div>


                                                <button
                                                    type="submit"
                                                    class="btn btn-status"
                                                >
                                                    📢 อัปเดตสถานะ
                                                </button>


                                            </div>

                                        </form>

                                    </div>

                                <?php endif; ?>


                                <!-- =================================================
                                     PARTS
                                ================================================== -->

                                <?php if (
                                    $job['status'] !== 'completed'
                                ): ?>

                                    <div class="parts-box">


                                        <div class="box-title">

                                            <div>

                                                <span>
                                                    🔩 อะไหล่ที่ใช้
                                                </span>

                                                <small>
                                                    บันทึกอะไหล่และคำนวณราคาอัตโนมัติ
                                                </small>

                                            </div>

                                        </div>


                                        <form
                                            method="POST"
                                            class="parts-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="save_parts"
                                            >

                                            <input
                                                type="hidden"
                                                name="repair_order_id"
                                                value="<?= $repairId ?>"
                                            >


                                            <div
                                                class="parts-table"
                                                data-parts-container
                                            >


                                                <?php if (
                                                    !empty($jobParts)
                                                ): ?>


                                                    <?php foreach (
                                                        $jobParts
                                                        as $part
                                                    ): ?>

                                                        <div
                                                            class="part-row"
                                                        >

                                                            <div>

                                                                <label>
                                                                    อะไหล่
                                                                </label>

                                                                <input
                                                                    type="text"
                                                                    name="part_name[]"
                                                                    value="<?= h(
                                                                        $part['part_name']
                                                                    ) ?>"
                                                                    placeholder="เช่น ผ้าเบรกหน้า"
                                                                    required
                                                                >

                                                            </div>


                                                            <div>

                                                                <label>
                                                                    จำนวน
                                                                </label>

                                                                <input
                                                                    type="number"
                                                                    name="quantity[]"
                                                                    min="1"
                                                                    value="<?= (int)$part['quantity'] ?>"
                                                                    class="part-quantity"
                                                                    required
                                                                >

                                                            </div>


                                                            <div>

                                                                <label>
                                                                    ราคาต่อหน่วย
                                                                </label>

                                                                <input
                                                                    type="number"
                                                                    name="unit_price[]"
                                                                    min="0"
                                                                    step="0.01"
                                                                    value="<?= h(
                                                                        $part['unit_price']
                                                                    ) ?>"
                                                                    class="part-price"
                                                                    required
                                                                >

                                                            </div>


                                                            <div>

                                                                <label>
                                                                    รวม
                                                                </label>

                                                                <div
                                                                    class="part-total"
                                                                >
                                                                    0.00
                                                                </div>

                                                            </div>


                                                            <button
                                                                type="button"
                                                                class="remove-part"
                                                                onclick="removePart(this)"
                                                            >
                                                                ×
                                                            </button>

                                                        </div>

                                                    <?php endforeach; ?>


                                                <?php else: ?>


                                                    <div
                                                        class="part-row"
                                                    >

                                                        <div>

                                                            <label>
                                                                อะไหล่
                                                            </label>

                                                            <input
                                                                type="text"
                                                                name="part_name[]"
                                                                placeholder="เช่น ผ้าเบรกหน้า"
                                                            >

                                                        </div>


                                                        <div>

                                                            <label>
                                                                จำนวน
                                                            </label>

                                                            <input
                                                                type="number"
                                                                name="quantity[]"
                                                                min="1"
                                                                value="1"
                                                                class="part-quantity"
                                                            >

                                                        </div>


                                                        <div>

                                                            <label>
                                                                ราคาต่อหน่วย
                                                            </label>

                                                            <input
                                                                type="number"
                                                                name="unit_price[]"
                                                                min="0"
                                                                step="0.01"
                                                                value="0"
                                                                class="part-price"
                                                            >

                                                        </div>


                                                        <div>

                                                            <label>
                                                                รวม
                                                            </label>

                                                            <div
                                                                class="part-total"
                                                            >
                                                                0.00
                                                            </div>

                                                        </div>


                                                        <button
                                                            type="button"
                                                            class="remove-part"
                                                            onclick="removePart(this)"
                                                        >
                                                            ×
                                                        </button>

                                                    </div>


                                                <?php endif; ?>


                                            </div>


                                            <button
                                                type="button"
                                                class="add-part-btn"
                                                onclick="addPart(this)"
                                            >
                                                ＋ เพิ่มอะไหล่
                                            </button>


                                            <div class="parts-footer">

                                                <div>

                                                    <span>
                                                        รวมค่าอะไหล่
                                                    </span>

                                                    <strong
                                                        class="parts-grand-total"
                                                    >
                                                        0.00 บาท
                                                    </strong>

                                                </div>


                                                <button
                                                    type="submit"
                                                    class="btn btn-save-parts"
                                                >
                                                    💾 บันทึกอะไหล่
                                                </button>

                                            </div>

                                        </form>

                                    </div>

                                <?php else: ?>


                                    <!-- COMPLETED PARTS -->

                                    <?php if (
                                        !empty($jobParts)
                                    ): ?>

                                        <div class="parts-box completed-parts">

                                            <div class="box-title">

                                                <span>
                                                    🔩 อะไหล่ที่ใช้
                                                </span>

                                            </div>


                                            <div class="parts-readonly">

                                                <?php foreach (
                                                    $jobParts
                                                    as $part
                                                ): ?>

                                                    <div class="readonly-part">

                                                        <span>
                                                            <?= h(
                                                                $part['part_name']
                                                            ) ?>
                                                        </span>

                                                        <span>
                                                            <?= (int)$part['quantity'] ?>
                                                            ×
                                                            <?= number_format(
                                                                (float)$part['unit_price'],
                                                                2
                                                            ) ?>
                                                        </span>

                                                        <strong>
                                                            <?= number_format(
                                                                (float)$part['total_price'],
                                                                2
                                                            ) ?>
                                                            บาท
                                                        </strong>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>


                                            <div class="parts-grand-total readonly-total">

                                                รวมค่าอะไหล่:

                                                <strong>
                                                    <?= number_format(
                                                        $partsTotal,
                                                        2
                                                    ) ?>
                                                    บาท
                                                </strong>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                <?php endif; ?>


                                <!-- =================================================
                                     COMPLETE FORM
                                ================================================== -->

                                <?php if (
                                    $job['status'] === 'in_progress'
                                ): ?>


                                    <form
                                        method="POST"
                                        class="complete-form"
                                        onsubmit="
                                            return confirm(
                                                'ยืนยันว่าซ่อมงานนี้เสร็จเรียบร้อยแล้วใช่หรือไม่?'
                                            );
                                        "
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="complete"
                                        >

                                        <input
                                            type="hidden"
                                            name="repair_order_id"
                                            value="<?= $repairId ?>"
                                        >


                                        <div class="complete-grid">


                                            <div>

                                                <label>
                                                    ผลการตรวจสอบ
                                                </label>

                                                <textarea
                                                    name="inspection_result"
                                                    rows="3"
                                                    placeholder="เช่น ตรวจพบผ้าเบรกหน้าสึก..."
                                                ></textarea>

                                            </div>


                                            <div>

                                                <label>
                                                    รายละเอียดการซ่อม
                                                </label>

                                                <textarea
                                                    name="repair_detail"
                                                    rows="3"
                                                    placeholder="เช่น เปลี่ยนผ้าเบรกหน้า..."
                                                ></textarea>

                                            </div>


                                            <div>

                                                <label>
                                                    ปัญหาเพิ่มเติม
                                                </label>

                                                <textarea
                                                    name="additional_problem"
                                                    rows="3"
                                                    placeholder="ถ้ามี..."
                                                ></textarea>

                                            </div>


                                            <div>

                                                <label>
                                                    หมายเหตุช่าง
                                                </label>

                                                <textarea
                                                    name="mechanic_note"
                                                    rows="3"
                                                    placeholder="หมายเหตุเพิ่มเติม..."
                                                ></textarea>

                                            </div>


                                        </div>


                                        <div class="complete-actions">

                                            <button
                                                type="submit"
                                                class="btn btn-complete"
                                            >
                                                ✓ ยืนยันซ่อมเสร็จ
                                            </button>

                                        </div>


                                    </form>


                                <?php endif; ?>


                            </div>

                        </div>

                    </article>


                <?php endforeach; ?>


            </div>

        </section>


    </section>

</main>
<!-- =====================================================
     JAVASCRIPT
====================================================== -->
<script>
/*
|--------------------------------------------------------------------------
| TOGGLE JOB
|--------------------------------------------------------------------------
*/

function toggleJob(button)
{
    const job =
        button.closest('.notification-job');

    job.classList.toggle('is-open');
}


/*
|--------------------------------------------------------------------------
| ADD PART
|--------------------------------------------------------------------------
*/

function addPart(button)
{
    const box =
        button.closest('.parts-box');

    const container =
        box.querySelector('[data-parts-container]');


    const row =
        document.createElement('div');

    row.className =
        'part-row';


    row.innerHTML = `

        <div>

            <label>
                อะไหล่
            </label>

            <input
                type="text"
                name="part_name[]"
                placeholder="เช่น ผ้าเบรกหน้า"
            >

        </div>


        <div>

            <label>
                จำนวน
            </label>

            <input
                type="number"
                name="quantity[]"
                min="1"
                value="1"
                class="part-quantity"
            >

        </div>


        <div>

            <label>
                ราคาต่อหน่วย
            </label>

            <input
                type="number"
                name="unit_price[]"
                min="0"
                step="0.01"
                value="0"
                class="part-price"
            >

        </div>


        <div>

            <label>
                รวม
            </label>

            <div class="part-total">
                0.00
            </div>

        </div>


        <button
            type="button"
            class="remove-part"
            onclick="removePart(this)"
        >
            ×
        </button>

    `;


    container.appendChild(row);

    attachPartEvents(row);

    calculateParts(box);
}


/*
|--------------------------------------------------------------------------
| REMOVE PART
|--------------------------------------------------------------------------
*/

function removePart(button)
{
    const box =
        button.closest('.parts-box');

    const row =
        button.closest('.part-row');


    row.remove();

    calculateParts(box);
}


/*
|--------------------------------------------------------------------------
| CALCULATE PARTS
|--------------------------------------------------------------------------
*/

function calculateParts(box)
{
    let grandTotal = 0;


    const rows =
        box.querySelectorAll('.part-row');


    rows.forEach(row => {

        const quantity =
            parseFloat(
                row.querySelector(
                    '.part-quantity'
                )?.value
            ) || 0;


        const price =
            parseFloat(
                row.querySelector(
                    '.part-price'
                )?.value
            ) || 0;


        const total =
            quantity * price;


        const totalElement =
            row.querySelector(
                '.part-total'
            );


        if (totalElement) {

            totalElement.textContent =
                total.toLocaleString(
                    'th-TH',
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );
        }


        grandTotal += total;

    });


    const grandTotalElement =
        box.querySelector(
            '.parts-grand-total'
        );


    if (grandTotalElement) {

        const strong =
            grandTotalElement.querySelector(
                'strong'
            );


        const text =
            grandTotal.toLocaleString(
                'th-TH',
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            )
            + ' บาท';


        if (strong) {

            strong.textContent =
                text;

        } else {

            grandTotalElement.textContent =
                text;
        }

    }

}


/*
|--------------------------------------------------------------------------
| ATTACH EVENTS
|--------------------------------------------------------------------------
*/

function attachPartEvents(row)
{
    const inputs =
        row.querySelectorAll(
            '.part-quantity, .part-price'
        );


    inputs.forEach(input => {

        input.addEventListener(
            'input',
            () => {

                const box =
                    row.closest('.parts-box');

                calculateParts(box);

            }
        );

    });
}


/*
|--------------------------------------------------------------------------
| INITIALIZE
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('.part-row')
    .forEach(row => {

        attachPartEvents(row);

        const box =
            row.closest('.parts-box');

        calculateParts(box);

    });


</script>


</body>

</html>
