<?php

require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
| รองรับทั้ง $conn และ $pdo
|--------------------------------------------------------------------------
*/

$db = $conn ?? $pdo ?? null;

if (!$db) {
    die("Database connection not found.");
}


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$statusLabels = [
    'waiting'      => 'รอดำเนินการ',
    'assigned'     => 'ได้รับมอบหมาย',
    'in_progress'  => 'กำลังดำเนินการ',
    'completed'    => 'เสร็จสิ้น',
    'invoiced'     => 'ออกใบแจ้งหนี้แล้ว'
];


/*
|--------------------------------------------------------------------------
| CREATE REPAIR JOB
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $vehicleId  = (int)($_POST['vehicle_id'] ?? 0);
    $mechanicId = !empty($_POST['mechanic_id'])
        ? (int)$_POST['mechanic_id']
        : null;

    $serviceDate = $_POST['service_date'] ?? date('Y-m-d');

    $dueDate = !empty($_POST['due_date'])
        ? $_POST['due_date']
        : null;

    $mileage = (int)($_POST['mileage'] ?? 0);

    $initialSymptom = trim($_POST['initial_symptom'] ?? '');
    $customerRequest = trim($_POST['customer_request'] ?? '');

    if (!$customerId || !$vehicleId || !$serviceDate) {
        $_SESSION['repair_job_error'] = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
        header("Location: index.php");
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    $status = $mechanicId ? 'assigned' : 'waiting';

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    $sql = "
        INSERT INTO repair_jobs
        (
            repair_order_code,
            customer_id,
            vehicle_id,
            mechanic_id,
            assigned_at,
            due_date,
            service_date,
            mileage,
            initial_symptom,
            customer_request,
            status
        )
        VALUES
        (
            '',
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ";

    if ($db instanceof PDO) {

        $stmt = $db->prepare($sql);

$assignedAt = $mechanicId ? date('Y-m-d H:i:s') : null;

        $stmt->execute([
            $customerId,
            $vehicleId,
            $mechanicId,
            $assignedAt,
            $dueDate,
            $serviceDate,
            $mileage,
            $initialSymptom,
            $customerRequest,
            $status
        ]);
        $repairId = $db->lastInsertId();

        $code = 'RJ-' . str_pad($repairId, 6, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET repair_order_code = ?
            WHERE repair_order_id = ?
        ");

        $stmt->execute([$code, $repairId]);

    } else {

        $stmt = $db->prepare($sql);

$assignedAt = $mechanicId ? date('Y-m-d H:i:s') : null;

        $stmt->bind_param(
            "iiisssisss",
            $customerId,
            $vehicleId,
            $mechanicId,
            $assignedAt,
            $dueDate,
            $serviceDate,
            $mileage,
            $initialSymptom,
            $customerRequest,
            $status
        );

        $stmt->execute();

        $repairId = $db->insert_id;

        $code = 'RJ-' . str_pad($repairId, 6, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET repair_order_code = ?
            WHERE repair_order_id = ?
        ");

        $stmt->bind_param("si", $code, $repairId);
        $stmt->execute();
    }

    /*
|--------------------------------------------------------------------------
| SET MECHANIC BUSY
|--------------------------------------------------------------------------
*/

if ($mechanicId) {

    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            UPDATE mechanics
            SET
                status = 'busy',
                updated_at = NOW()
            WHERE
                mechanic_id = ?
        ");

        $stmt->execute([
            $mechanicId
        ]);

    } else {

        $stmt = $db->prepare("
            UPDATE mechanics
            SET
                status = 'busy',
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

    $_SESSION['repair_job_success'] = "สร้างใบงาน {$code} สำเร็จ";

    header("Location: index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| UPDATE REPAIR JOB
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {

    $repairId = (int)($_POST['repair_order_id'] ?? 0);

    $mechanicId = !empty($_POST['mechanic_id'])
        ? (int)$_POST['mechanic_id']
        : null;

    $status = $_POST['status'] ?? 'waiting';

    $dueDate = !empty($_POST['due_date'])
        ? $_POST['due_date']
        : null;

    $initialSymptom = trim($_POST['initial_symptom'] ?? '');
    $customerRequest = trim($_POST['customer_request'] ?? '');
    $inspectionResult = trim($_POST['inspection_result'] ?? '');
    $repairDetail = trim($_POST['repair_detail'] ?? '');
    $additionalProblem = trim($_POST['additional_problem'] ?? '');
    $mechanicNote = trim($_POST['mechanic_note'] ?? '');

    if (!$repairId) {

        $_SESSION['repair_job_error'] = 'ไม่พบใบงานซ่อม';

        header("Location: index.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | GET OLD MECHANIC
    |--------------------------------------------------------------------------
    */

    $oldMechanicId = null;
    $oldAssignedAt = null;

    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            SELECT mechanic_id, assigned_at
            FROM repair_jobs
            WHERE repair_order_id = ?
        ");

        $stmt->execute([$repairId]);

        $oldJob = $stmt->fetch(PDO::FETCH_ASSOC);

    } else {

        $stmt = $db->prepare("
            SELECT mechanic_id, assigned_at
            FROM repair_jobs
            WHERE repair_order_id = ?
        ");

        $stmt->bind_param("i", $repairId);
        $stmt->execute();

        $result = $stmt->get_result();

        $oldJob = $result->fetch_assoc();
    }


    if ($oldJob) {

        $oldMechanicId = !empty($oldJob['mechanic_id'])
            ? (int)$oldJob['mechanic_id']
            : null;

        $oldAssignedAt = $oldJob['assigned_at'] ?? null;
    }


    /*
    |--------------------------------------------------------------------------
    | ASSIGNMENT TIME
    |--------------------------------------------------------------------------
    |
    | บันทึกเวลาเมื่อ:
    | 1. มีการมอบหมายช่างครั้งแรก
    | 2. เปลี่ยนช่างจากคนหนึ่งเป็นอีกคน
    |
    */

    $assignedAt = $oldAssignedAt;

    if ($mechanicId && $mechanicId !== $oldMechanicId) {

        $assignedAt = date('Y-m-d H:i:s');

        if ($status === 'waiting') {
            $status = 'assigned';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REMOVE MECHANIC
    |--------------------------------------------------------------------------
    */

    if (!$mechanicId) {

        $assignedAt = null;

        if ($status === 'assigned') {
            $status = 'waiting';
        }
    }

/*
|--------------------------------------------------------------------------
| UPDATE MECHANIC AVAILABILITY
|--------------------------------------------------------------------------
*/

/*
| ถอดช่างเก่าออก
*/

if (
    $oldMechanicId &&
    $oldMechanicId !== $mechanicId
) {

    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            UPDATE mechanics
            SET
                status = 'available',
                updated_at = NOW()
            WHERE
                mechanic_id = ?
        ");

        $stmt->execute([
            $oldMechanicId
        ]);

    } else {

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
            $oldMechanicId
        );

        $stmt->execute();
    }
}


/*
| มอบหมายช่างใหม่
*/

if (
    $mechanicId &&
    $mechanicId !== $oldMechanicId &&
    $status !== 'completed'
) {

    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            UPDATE mechanics
            SET
                status = 'busy',
                updated_at = NOW()
            WHERE
                mechanic_id = ?
        ");

        $stmt->execute([
            $mechanicId
        ]);

    } else {

        $stmt = $db->prepare("
            UPDATE mechanics
            SET
                status = 'busy',
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

if ($status === 'completed' && $mechanicId) {

    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            UPDATE mechanics
            SET
                status = 'available',
                updated_at = NOW()
            WHERE mechanic_id = ?
        ");

        $stmt->execute([
            $mechanicId
        ]);

    } else {

        $stmt = $db->prepare("
            UPDATE mechanics
            SET
                status = 'available',
                updated_at = NOW()
            WHERE mechanic_id = ?
        ");

        $stmt->bind_param(
            "i",
            $mechanicId
        );

        $stmt->execute();
    }
}

/*
|--------------------------------------------------------------------------
| START / END TIME
|--------------------------------------------------------------------------
*/

$startTimeSql = "";
$endTimeSql = "";

if ($status === 'in_progress') {
    $startTimeSql = ", start_time = COALESCE(start_time, NOW())";
}

if ($status === 'completed') {
    $endTimeSql = ", end_time = COALESCE(end_time, NOW())";
}
    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    if ($db instanceof PDO) {

        $sql = "
            UPDATE repair_jobs
            SET
                mechanic_id = ?,
                assigned_at = ?,
                due_date = ?,
                status = ?,
                initial_symptom = ?,
                customer_request = ?,
                inspection_result = ?,
                repair_detail = ?,
                additional_problem = ?,
                mechanic_note = ?,
                updated_at = NOW()
                $startTimeSql
                $endTimeSql
            WHERE repair_order_id = ?
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            $mechanicId,
            $assignedAt,
            $dueDate,
            $status,
            $initialSymptom,
            $customerRequest,
            $inspectionResult,
            $repairDetail,
            $additionalProblem,
            $mechanicNote,
            $repairId
        ]);

    } else {

        $sql = "
            UPDATE repair_jobs
            SET
                mechanic_id = ?,
                assigned_at = ?,
                due_date = ?,
                status = ?,
                initial_symptom = ?,
                customer_request = ?,
                inspection_result = ?,
                repair_detail = ?,
                additional_problem = ?,
                mechanic_note = ?,
                updated_at = NOW()
                $startTimeSql
                $endTimeSql
                WHERE repair_order_id = ?
        ";

        $stmt = $db->prepare($sql);

        $stmt->bind_param(
            "isssssssssi",
            $mechanicId,
            $assignedAt,
            $dueDate,
            $status,
            $initialSymptom,
            $customerRequest,
            $inspectionResult,
            $repairDetail,
            $additionalProblem,
            $mechanicNote,
            $repairId
        );

        $stmt->execute();
    }


    $_SESSION['repair_job_success'] =
        'อัปเดตใบงานซ่อมเรียบร้อยแล้ว';

    header("Location: index.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| MARK COMPLETE
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'complete'
) {

    $repairId = (int)($_POST['repair_order_id'] ?? 0);

    if (!$repairId) {

        $_SESSION['repair_job_error'] =
            'ไม่พบใบงานซ่อม';

        header("Location: index.php");
        exit;
    }
    /*
    |--------------------------------------------------------------------------
    | GET MECHANIC
    |--------------------------------------------------------------------------
    */
    $mechanicId = null;

    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            SELECT mechanic_id
            FROM repair_jobs
            WHERE repair_order_id = ?
        ");

        $stmt->execute([
            $repairId
        ]);

        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            $mechanicId = !empty($job['mechanic_id'])
                ? (int)$job['mechanic_id']
                : null;
        }

    } else {

        $stmt = $db->prepare("
            SELECT mechanic_id
            FROM repair_jobs
            WHERE repair_order_id = ?
        ");

        $stmt->bind_param(
            "i",
            $repairId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $job = $result->fetch_assoc();

        if ($job) {
            $mechanicId = !empty($job['mechanic_id'])
                ? (int)$job['mechanic_id']
                : null;
        }
    }
    /*
    |--------------------------------------------------------------------------
    | COMPLETE JOB
    |--------------------------------------------------------------------------
    */
    if ($db instanceof PDO) {

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                status = 'completed',
                end_time = NOW(),
                updated_at = NOW()
            WHERE repair_order_id = ?
        ");

        $stmt->execute([
            $repairId
        ]);

    } else {

        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                status = 'completed',
                end_time = NOW(),
                updated_at = NOW()
            WHERE repair_order_id = ?
        ");

        $stmt->bind_param(
            "i",
            $repairId
        );

        $stmt->execute();
    }
    /*
    |--------------------------------------------------------------------------
    | RELEASE MECHANIC
    |--------------------------------------------------------------------------
    */
    if ($mechanicId) {

        if ($db instanceof PDO) {

            $stmt = $db->prepare("
                UPDATE mechanics
                SET
                    status = 'available',
                    updated_at = NOW()
                WHERE mechanic_id = ?
            ");

            $stmt->execute([
                $mechanicId
            ]);

        } else {

            $stmt = $db->prepare("
                UPDATE mechanics
                SET
                    status = 'available',
                    updated_at = NOW()
                WHERE mechanic_id = ?
            ");

            $stmt->bind_param(
                "i",
                $mechanicId
            );

            $stmt->execute();
        }
    }


    $_SESSION['repair_job_success'] =
        'ปิดใบงานซ่อมเรียบร้อยแล้ว';

    header("Location: index.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| SEARCH / FILTER
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';


/*
|--------------------------------------------------------------------------
| LOAD REPAIR JOBS
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
        v.color,

        m.full_name AS mechanic_name

    FROM repair_jobs r

    INNER JOIN customers c
        ON r.customer_id = c.customer_id

    INNER JOIN vehicles v
        ON r.vehicle_id = v.vehicle_id

    LEFT JOIN mechanics m
        ON r.mechanic_id = m.mechanic_id

    WHERE 1=1
";

$params = [];
$types = "";


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            r.repair_order_code LIKE ?
            OR c.full_name LIKE ?
            OR v.license_plate LIKE ?
        )
    ";

    $searchValue = "%{$search}%";

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= "sss";
}


/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/

if ($filterStatus !== '' && isset($statusLabels[$filterStatus])) {

    $sql .= " AND r.status = ? ";

    $params[] = $filterStatus;
    $types .= "s";
}


$sql .= "
    ORDER BY
        r.created_at DESC
";


/*
|--------------------------------------------------------------------------
| EXECUTE
|--------------------------------------------------------------------------
*/

$jobs = [];

if ($db instanceof PDO) {

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $stmt = $db->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| CUSTOMERS
|--------------------------------------------------------------------------
*/

$customers = [];

if ($db instanceof PDO) {

    $stmt = $db->query("
        SELECT customer_id, full_name, phone
        FROM customers
        ORDER BY full_name ASC
    ");

    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $result = $db->query("
        SELECT customer_id, full_name, phone
        FROM customers
        ORDER BY full_name ASC
    ");

    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| VEHICLES
|--------------------------------------------------------------------------
*/

$vehicles = [];

if ($db instanceof PDO) {

    $stmt = $db->query("
        SELECT
            vehicle_id,
            customer_id,
            license_plate,
            brand,
            model,
            color,
            mileage
        FROM vehicles
        ORDER BY license_plate ASC
    ");

    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $result = $db->query("
        SELECT
            vehicle_id,
            customer_id,
            license_plate,
            brand,
            model,
            color,
            mileage
        FROM vehicles
        ORDER BY license_plate ASC
    ");

    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| MECHANICS + SKILLS
|--------------------------------------------------------------------------
*/

$mechanics = [];

$sqlMechanics = "
    SELECT
        m.mechanic_id,
        m.full_name,
        m.status,

        GROUP_CONCAT(
            DISTINCT s.skill_name
            ORDER BY s.skill_name
            SEPARATOR ', '
        ) AS skills

    FROM mechanics m

    LEFT JOIN mechanic_skills ms
        ON m.mechanic_id = ms.mechanic_id

    LEFT JOIN skills s
        ON ms.skill_id = s.skill_id

    WHERE m.status != 'inactive'

    GROUP BY
        m.mechanic_id,
        m.full_name,
        m.status

    ORDER BY m.full_name ASC
";


if ($db instanceof PDO) {

    $stmt = $db->query($sqlMechanics);

    $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $result = $db->query($sqlMechanics);

    while ($row = $result->fetch_assoc()) {
        $mechanics[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$successMessage = $_SESSION['repair_job_success'] ?? '';
$errorMessage = $_SESSION['repair_job_error'] ?? '';

unset($_SESSION['repair_job_success']);
unset($_SESSION['repair_job_error']);

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>การจัดการใบงานซ่อม | P.Chalermchai Car Service</title>


    <!-- Global -->

    <link
        rel="stylesheet"
        href="../../assets/css/style.css"
    >


    <!-- Sidebar -->

    <link
        rel="stylesheet"
        href="../../assets/css/admin/sidebar.css"
    >


    <!-- Repair Jobs -->

    <link
        rel="stylesheet"
        href="../../assets/css/admin/repair_jobs.css"
    >

</head>


<body>


<!-- =====================================================
     TOPBAR
===================================================== -->

<header class="topbar">

    <div class="brand">
        P.Chalermchai Car Service
    </div>


    <div class="user-area">

        <div class="user-info">

            <strong>
                <?= h($_SESSION["username"]) ?>
            </strong>

            <span>
                ผู้ดูแลระบบ
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
===================================================== -->

<aside class="sidebar">

    <nav class="sidebar-menu">


        <a href="../dashboard.php">

            <span class="menu-icon">🏠</span>

            <span>หน้าหลัก</span>

        </a>


        <a href="../customers/index.php">

            <span class="menu-icon">👥</span>

            <span>ข้อมูลลูกค้า</span>

        </a>


        <a href="../appointments/index.php">

            <span class="menu-icon">📅</span>

            <span>การนัดหมาย</span>

        </a>


        <a
            href="../repair_jobs/index.php"
            class="active"
        >

            <span class="menu-icon">🔧</span>

            <span>ใบงานซ่อม</span>

        </a>


        <a href="../technician/index.php">

            <span class="menu-icon">👨‍🔧</span>

            <span>จัดการช่าง</span>

        </a>


        <a href="../financial/index.php">

            <span class="menu-icon">💰</span>

            <span>การเงิน</span>

        </a>


        <a href="../users/index.php">

            <span class="menu-icon">👤</span>

            <span>ผู้ใช้งาน</span>

        </a>

    </nav>

</aside>



<!-- =====================================================
     MAIN
===================================================== -->

<main class="main-content">


    <section class="repair-page">


        <!-- HEADER -->

        <div class="repair-header">

            <div>

                <h1>
                    การจัดการใบงานซ่อม
                </h1>

                <p>
                    เปิดใบงาน ติดตามสถานะ และบันทึกผลการซ่อม
                </p>

            </div>


            <button
                type="button"
                class="btn-primary repair-add-btn"
                onclick="openCreateModal()"
            >

                <span class="plus">+</span>

                เพิ่มใบงานซ่อม

            </button>

        </div>



        <!-- =================================================
             ALERT
        ================================================== -->

        <?php if ($successMessage): ?>

            <div class="repair-alert success">
                <?= h($successMessage) ?>
            </div>

        <?php endif; ?>


        <?php if ($errorMessage): ?>

            <div class="repair-alert error">
                <?= h($errorMessage) ?>
            </div>

        <?php endif; ?>



        <!-- =================================================
             SEARCH / FILTER
        ================================================== -->

        <form
            method="GET"
            class="repair-toolbar"
        >


            <div class="repair-search">

                <span>🔍</span>

                <input
                    type="text"
                    name="search"
                    value="<?= h($search) ?>"
                    placeholder="ค้นหารหัสใบงาน ชื่อลูกค้า หรือทะเบียนรถ..."
                >

            </div>


            <select
                name="status"
                class="repair-status-filter"
                onchange="this.form.submit()"
            >

                <option value="">
                    ทุกสถานะ
                </option>

                <option
                    value="waiting"
                    <?= $filterStatus === 'waiting' ? 'selected' : '' ?>
                >
                    รอดำเนินการ
                </option>

                <option
                    value="assigned"
                    <?= $filterStatus === 'assigned' ? 'selected' : '' ?>
                >
                    ได้รับมอบหมาย
                </option>

                <option
                    value="in_progress"
                    <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>
                >
                    กำลังดำเนินการ
                </option>

                <option
                    value="completed"
                    <?= $filterStatus === 'completed' ? 'selected' : '' ?>
                >
                    เสร็จสิ้น
                </option>

                <option
                    value="invoiced"
                    <?= $filterStatus === 'invoiced' ? 'selected' : '' ?>
                >
                    ออกใบแจ้งหนี้แล้ว
                </option>

            </select>


            <?php if ($search !== '' || $filterStatus !== ''): ?>

                <a
                    href="index.php"
                    class="clear-search"
                >
                    ล้างตัวกรอง
                </a>

            <?php endif; ?>

        </form>



        <!-- =================================================
             REPAIR JOB GRID
        ================================================== -->

        <div class="repair-grid">


            <?php if (empty($jobs)): ?>

                <div class="repair-empty">

                    <div class="empty-icon">
                        🔧
                    </div>

                    <h3>
                        ไม่พบใบงานซ่อม
                    </h3>

                    <p>
                        ลองค้นหาด้วยคำอื่น หรือเพิ่มใบงานใหม่
                    </p>

                </div>

            <?php endif; ?>



            <?php foreach ($jobs as $job): ?>

                <article class="repair-card">


                    <!-- CARD HEADER -->

                    <div class="repair-card-header">


                        <div class="repair-title">


                            <div class="repair-icon">
                                🔧
                            </div>


                            <div>

                                <strong>
                                    <?= h($job['repair_order_code']) ?>
                                </strong>

                                <span>
                                    <?= h($job['license_plate']) ?>
                                    ·
                                    <?= h($job['customer_name']) ?>
                                </span>

                            </div>

                        </div>


                        <div class="repair-badges">


                            <span
                                class="
                                    priority-badge
                                    priority-medium
                                "
                            >
                                กลาง
                            </span>


                            <span
                                class="
                                    status-badge
                                    status-<?= h($job['status']) ?>
                                "
                            >
                                <?= h($statusLabels[$job['status']] ?? $job['status']) ?>
                            </span>

                        </div>

                    </div>



                    <!-- DESCRIPTION -->

                    <div class="repair-main-info">


                        <h3>
                            <?= h(
                                $job['initial_symptom']
                                ?: 'ยังไม่ได้ระบุอาการ'
                            ) ?>
                        </h3>


                        <?php if (!empty($job['customer_request'])): ?>

                            <p>
                                <strong>ความต้องการลูกค้า:</strong>
                                <?= h($job['customer_request']) ?>
                            </p>

                        <?php endif; ?>


                        <div class="repair-meta">

                            <span>
                                🚗
                                <?= h($job['brand']) ?>
                                <?= h($job['model']) ?>
                            </span>


                            <span>
                                📅
                                <?= date(
                                    'd/m/Y',
                                    strtotime($job['service_date'])
                                ) ?>
                            </span>


                            <span>
                                🛣️
                                <?= number_format($job['mileage']) ?>
                                กม.
                            </span>

                        </div>
                        
                        <?php if (!empty($job['assigned_at'])): ?>

                            <span>
                                🕐
                                มอบหมาย:
                                <?= date(
                                    'd/m/Y H:i',
                                    strtotime($job['assigned_at'])
                                ) ?>
                            </span>

                        <?php endif; ?>


                        <?php if (!empty($job['due_date'])): ?>

                            <span>
                                ⏰
                                กำหนดเสร็จ:
                                <?= date(
                                    'd/m/Y',
                                    strtotime($job['due_date'])
                                ) ?>
                            </span>

                        <?php endif; ?>

                    </div>



                    <!-- TECHNICIAN -->

                    <div class="repair-technician">


                        <span>
                            👨‍🔧
                            ช่าง:
                        </span>


                        <strong>

                            <?= h(
                                $job['mechanic_name']
                                ?: 'ยังไม่ได้มอบหมาย'
                            ) ?>

                        </strong>


                    </div>



                    <!-- INSPECTION -->

                    <?php if (!empty($job['inspection_result'])): ?>

                        <div class="inspection-preview">

                            <strong>
                                ผลตรวจ:
                            </strong>

                            <?= h($job['inspection_result']) ?>

                        </div>

                    <?php endif; ?>



                    <!-- ACTIONS -->

                    <div class="repair-actions">


                        <button
                            type="button"
                            class="repair-action edit"
                            onclick='openEditModal(<?= json_encode(
                                $job,
                                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                            ) ?>)'
                        >

                            ✏️ แก้ไข

                        </button>



                        <?php if ($job['status'] !== 'completed' && $job['status'] !== 'invoiced'): ?>

                            <form
                                method="POST"
                                onsubmit="return confirm('ต้องการปิดใบงานนี้ใช่หรือไม่?')"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="complete"
                                >

                                <input
                                    type="hidden"
                                    name="repair_order_id"
                                    value="<?= (int)$job['repair_order_id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="repair-action complete"
                                >
                                    ✓ Mark Complete
                                </button>

                            </form>

                        <?php endif; ?>


                    </div>


                </article>

            <?php endforeach; ?>


        </div>


    </section>

</main>



<!-- =====================================================
     CREATE MODAL
===================================================== -->

<div
    id="createModal"
    class="modal-overlay"
    onclick="closeModalOutside(event)"
>


    <div class="repair-modal">


        <div class="modal-header">

            <div>

                <h2>
                    เพิ่มใบงานซ่อม
                </h2>

                <p>
                    เปิดใบงานจากอาการหรือสิ่งที่ลูกค้าแจ้ง
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeCreateModal()"
            >
                ×
            </button>

        </div>



        <form
            method="POST"
            id="createRepairForm"
        >

            <input
                type="hidden"
                name="action"
                value="create"
            >


            <!-- CUSTOMER -->

            <div class="form-group">

                <label>
                    ลูกค้า <span>*</span>
                </label>

                <select
                    name="customer_id"
                    id="createCustomer"
                    required
                    onchange="loadVehicles('create')"
                >

                    <option value="">
                        เลือกลูกค้า
                    </option>


                    <?php foreach ($customers as $customer): ?>

                        <option
                            value="<?= (int)$customer['customer_id'] ?>"
                        >

                            <?= h($customer['full_name']) ?>

                            <?php if (!empty($customer['phone'])): ?>

                                · <?= h($customer['phone']) ?>

                            <?php endif; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>



            <!-- VEHICLE -->

            <div class="form-group">

                <label>
                    รถยนต์ <span>*</span>
                </label>

                <select
                    name="vehicle_id"
                    id="createVehicle"
                    required
                    disabled
                >

                    <option value="">
                        เลือกลูกค้าก่อน
                    </option>

                </select>

            </div>

            <!-- MECHANIC -->

            <div class="form-group">

                <label>
                    มอบหมายช่าง
                </label>

                <select
                    name="mechanic_id"
                    id="createMechanic"
                >

                    <option value="">
                        ยังไม่มอบหมาย
                    </option>

                    <?php foreach ($mechanics as $mechanic): ?>

                        <option
                            value="<?= (int)$mechanic['mechanic_id'] ?>"
                        >

                            <?= h($mechanic['full_name']) ?>

                            <?php if (!empty($mechanic['skills'])): ?>

                                · <?= h($mechanic['skills']) ?>

                            <?php endif; ?>

                            <?php if ($mechanic['status'] === 'busy'): ?>

                                (กำลังทำงาน)

                            <?php endif; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <small class="form-help">
                    แสดงทักษะของช่างเพื่อช่วยเลือกช่างให้เหมาะกับงาน
                </small>

            </div>
        <!-- SERVICE DATE + DUE DATE -->

        <div class="form-row">

            <div class="form-group">

                <label>
                    วันที่รับรถ <span>*</span>
                </label>

                <input
                    type="date"
                    name="service_date"
                    value="<?= date('Y-m-d') ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    กำหนดวันเสร็จ
                </label>

                <input
                    type="date"
                    name="due_date"
                >

                <small class="form-help">
                    กำหนดวันที่คาดว่าจะซ่อมเสร็จ
                </small>

            </div>

        </div>
            <!-- เลขไมล์ -->
             <div class="form-group">
                <label>
                    เลขไมล์
                </label>
                <input
                    type="number"
                    name="mileage"
                    min="0"
                    value="0"
                >
            </div>


            <!-- INITIAL SYMPTOM -->

            <div class="form-group">

                <label>
                    อาการ / สิ่งที่ลูกค้าแจ้ง
                </label>

                <textarea
                    name="initial_symptom"
                    rows="4"
                    placeholder="เช่น ลูกค้าแจ้งว่าเบรกมีเสียงดังเวลาหยุดรถ..."
                ></textarea>

            </div>



            <!-- CUSTOMER REQUEST -->

            <div class="form-group">

                <label>
                    ความต้องการของลูกค้า
                </label>

                <textarea
                    name="customer_request"
                    rows="3"
                    placeholder="เช่น ขอให้ตรวจระบบเบรกทั้งหมด และแจ้งราคาก่อนซ่อม"
                ></textarea>

            </div>



            <!-- INFO -->

            <div class="workflow-info">

                <strong>
                    💡 ขั้นตอนการทำงาน
                </strong>

                <p>
                    ตอนเปิดใบงานยังไม่ต้องระบุอะไหล่ ค่าแรง
                    หรือบริการที่ทำเสร็จแล้ว
                    สามารถบันทึกข้อมูลเหล่านี้ภายหลังเมื่อช่างตรวจและซ่อมจริง
                </p>

            </div>



            <!-- ACTIONS -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeCreateModal()"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-submit"
                >
                    สร้างใบงาน
                </button>

            </div>


        </form>

    </div>

</div>



<!-- =====================================================
     EDIT MODAL
===================================================== -->

<div
    id="editModal"
    class="modal-overlay"
    onclick="closeModalOutside(event)"
>


    <div class="repair-modal">


        <div class="modal-header">

            <div>

                <h2>
                    แก้ไขใบงาน
                </h2>

                <p id="editJobCode">
                    -
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeEditModal()"
            >
                ×
            </button>

        </div>



        <form
            method="POST"
            id="editRepairForm"
        >

            <input
                type="hidden"
                name="action"
                value="update"
            >

            <input
                type="hidden"
                name="repair_order_id"
                id="editRepairId"
            >


            <!-- MECHANIC -->

            <div class="form-group">

                <label>
                    ช่างผู้รับผิดชอบ
                </label>

                <select
                    name="mechanic_id"
                    id="editMechanic"
                >

                    <option value="">
                        ยังไม่มอบหมาย
                    </option>


                    <?php foreach ($mechanics as $mechanic): ?>

                        <option
                            value="<?= (int)$mechanic['mechanic_id'] ?>"
                        >

                            <?= h($mechanic['full_name']) ?>

                            <?php if (!empty($mechanic['skills'])): ?>

                                · <?= h($mechanic['skills']) ?>

                            <?php endif; ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- DUE DATE -->

            <div class="form-group">

                <label>
                    วันครบกำหนดซ่อม
                </label>

                <input
                    type="date"
                    name="due_date"
                    id="editDueDate"
                >

                <small class="form-help">
                    กำหนดวันที่คาดว่าจะซ่อมเสร็จ
                </small>

            </div>

            <!-- STATUS -->

            <div class="form-group">

                <label>
                    สถานะ
                </label>

                <select
                    name="status"
                    id="editStatus"
                >

                    <option value="waiting">
                        รอดำเนินการ
                    </option>

                    <option value="assigned">
                        ได้รับมอบหมาย
                    </option>

                    <option value="in_progress">
                        กำลังดำเนินการ
                    </option>

                    <option value="completed">
                        เสร็จสิ้น
                    </option>

                    <option value="invoiced">
                        ออกใบแจ้งหนี้แล้ว
                    </option>

                </select>

            </div>



            <!-- INITIAL SYMPTOM -->

            <div class="form-group">

                <label>
                    อาการ / สิ่งที่ลูกค้าแจ้ง
                </label>

                <textarea
                    name="initial_symptom"
                    id="editInitialSymptom"
                    rows="3"
                ></textarea>

            </div>



            <!-- CUSTOMER REQUEST -->

            <div class="form-group">

                <label>
                    ความต้องการของลูกค้า
                </label>

                <textarea
                    name="customer_request"
                    id="editCustomerRequest"
                    rows="3"
                ></textarea>

            </div>



            <!-- INSPECTION -->

            <div class="form-group">

                <label>
                    ผลการตรวจสอบ
                </label>

                <textarea
                    name="inspection_result"
                    id="editInspectionResult"
                    rows="4"
                    placeholder="เช่น ตรวจพบผ้าเบรกหน้าสึกประมาณ 80%"
                ></textarea>

            </div>



            <!-- REPAIR DETAIL -->

            <div class="form-group">

                <label>
                    รายละเอียดการซ่อม / บริการที่ทำ
                </label>

                <textarea
                    name="repair_detail"
                    id="editRepairDetail"
                    rows="4"
                    placeholder="เช่น เปลี่ยนผ้าเบรกหน้าและเจียรจานเบรก"
                ></textarea>

            </div>



            <!-- ADDITIONAL PROBLEM -->

            <div class="form-group">

                <label>
                    ปัญหาเพิ่มเติมที่พบ
                </label>

                <textarea
                    name="additional_problem"
                    id="editAdditionalProblem"
                    rows="3"
                ></textarea>

            </div>



            <!-- MECHANIC NOTE -->

            <div class="form-group">

                <label>
                    หมายเหตุช่าง
                </label>

                <textarea
                    name="mechanic_note"
                    id="editMechanicNote"
                    rows="3"
                ></textarea>

            </div>



            <div class="workflow-info">

                <strong>
                    🔧 อะไหล่และค่าแรง
                </strong>

                <p>
                    ส่วนอะไหล่และค่าแรงจะจัดการผ่านรายการซ่อมและรายการค่าแรง
                    ภายหลังจากทราบงานที่ทำจริง
                    ไม่ควรบังคับกรอกตั้งแต่เปิดใบงาน
                </p>

            </div>



            <!-- ACTION -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeEditModal()"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-submit"
                >
                    บันทึกการแก้ไข
                </button>

            </div>


        </form>

    </div>

</div>



<script>

    /*
    |--------------------------------------------------------------------------
    | VEHICLES DATA
    |--------------------------------------------------------------------------
    */

    const vehicles = <?= json_encode(
        $vehicles,
        JSON_UNESCAPED_UNICODE
    ) ?>;


    /*
    |--------------------------------------------------------------------------
    | CREATE MODAL
    |--------------------------------------------------------------------------
    */

    function openCreateModal()
    {
        document
            .getElementById('createModal')
            .classList.add('show');
    }


    function closeCreateModal()
    {
        document
            .getElementById('createModal')
            .classList.remove('show');
    }


    /*
    |--------------------------------------------------------------------------
    | EDIT MODAL
    |--------------------------------------------------------------------------
    */

    function openEditModal(job)
    {
        document.getElementById('editRepairId').value =
            job.repair_order_id;

        document.getElementById('editJobCode').textContent =
            job.repair_order_code;

        document.getElementById('editMechanic').value =
            job.mechanic_id || '';

        document.getElementById('editDueDate').value =
            job.due_date || '';

        document.getElementById('editStatus').value =
            job.status;

        document.getElementById('editInitialSymptom').value =
            job.initial_symptom || '';

        document.getElementById('editCustomerRequest').value =
            job.customer_request || '';

        document.getElementById('editInspectionResult').value =
            job.inspection_result || '';

        document.getElementById('editRepairDetail').value =
            job.repair_detail || '';

        document.getElementById('editAdditionalProblem').value =
            job.additional_problem || '';

        document.getElementById('editMechanicNote').value =
            job.mechanic_note || '';

        document
            .getElementById('editModal')
            .classList.add('show');
    }


    function closeEditModal()
    {
        document
            .getElementById('editModal')
            .classList.remove('show');
    }


    /*
    |--------------------------------------------------------------------------
    | VEHICLE SELECT
    |--------------------------------------------------------------------------
    */

    function loadVehicles(type)
    {
        const customerSelect =
            document.getElementById(
                type === 'create'
                    ? 'createCustomer'
                    : 'editCustomer'
            );

        const vehicleSelect =
            document.getElementById(
                type === 'create'
                    ? 'createVehicle'
                    : 'editVehicle'
            );

        const customerId =
            customerSelect.value;

        vehicleSelect.innerHTML = '';

        if (!customerId)
        {
            vehicleSelect.disabled = true;

            vehicleSelect.innerHTML =
                '<option value="">เลือกลูกค้าก่อน</option>';

            return;
        }

        const customerVehicles =
            vehicles.filter(
                vehicle =>
                    String(vehicle.customer_id) ===
                    String(customerId)
            );

        if (customerVehicles.length === 0)
        {
            vehicleSelect.disabled = true;

            vehicleSelect.innerHTML =
                '<option value="">ลูกค้ารายนี้ยังไม่มีรถ</option>';

            return;
        }

        vehicleSelect.disabled = false;

        vehicleSelect.innerHTML =
            '<option value="">เลือกรถ</option>';

        customerVehicles.forEach(vehicle =>
        {
            const option =
                document.createElement('option');

            option.value =
                vehicle.vehicle_id;

            option.textContent =
                `${vehicle.license_plate} · ${vehicle.brand} ${vehicle.model}`;

            vehicleSelect.appendChild(option);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | OUTSIDE CLICK
    |--------------------------------------------------------------------------
    */

    function closeModalOutside(event)
    {
        if (
            event.target.classList.contains(
                'modal-overlay'
            )
        )
        {
            event.target.classList.remove('show');
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ESC
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'keydown',
        function(event)
        {
            if (event.key === 'Escape')
            {
                closeCreateModal();
                closeEditModal();
            }
        }
    );

</script>


</body>
</html>