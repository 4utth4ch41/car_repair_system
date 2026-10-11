<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * รายการใบงานเชื่อมลูกค้า รถ และช่าง แล้วค่อยต่อเงื่อนไขคำค้นกับสถานะที่เลือก
 * WHERE 1=1 เป็นเงื่อนไขจริงเสมอ เอาไว้ต่อ AND ง่าย ๆ ไม่ได้กรองรายการด้วยตัวมันเอง
 * รายชื่อนัดสำหรับเปิดใบงานเลือก scheduled และต้องยังไม่มีใบงานอ้างถึงนัดนั้น
 * การสร้าง/ตรวจรับ/ส่งกลับ/แก้ใบงานทำผ่าน includes/repair_workflow.php ซึ่งตรวจสถานะซ้ำฝั่งเซิร์ฟเวอร์
 * มีทั้งทาง PDO กับ MySQLi ทำหน้าที่เดียวกัน ปกติ config/database.php ของระบบนี้สร้าง PDO
 */
require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";
require_once "../../includes/repair_workflow.php";

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
    'waiting' => 'รอเริ่มงาน',
    'in_progress' => 'กำลังซ่อม',
    'awaiting_review' => 'รอตรวจรับงาน',
    'completed' => 'เสร็จสิ้น',
    'waiting_payment' => 'รอชำระเงิน',
    'paid' => 'ชำระเงินแล้ว',
    'closed' => 'ปิดงาน',
    'cancelled' => 'ยกเลิก'
];
/*
|--------------------------------------------------------------------------
| CREATE REPAIR JOB
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $code = workflowCreateJob($pdo, $_POST, (int)$_SESSION['user_id']);
            $_SESSION['repair_job_success'] = "สร้างใบงาน $code และอัปเดตนัดเป็นเข้ารับบริการแล้ว";
        } else {
            workflowJobAction($pdo, (int)($_POST['repair_order_id'] ?? 0), $action, $_POST, (int)$_SESSION['user_id']);
            $_SESSION['repair_job_success'] = 'บันทึกการดำเนินการเรียบร้อยแล้ว';
        }
    } catch (InvalidArgumentException $e) {
        $_SESSION['repair_job_error'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $_SESSION['repair_job_error'] = 'ไม่สามารถบันทึกได้ กรุณารีเฟรชแล้วลองใหม่';
    }
    header('Location: index.php');
    exit;
}
$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';


/*
|--------------------------------------------------------------------------
| LOAD REPAIR JOBS
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม), customers (ลูกค้า), vehicles (รถยนต์), mechanics (ช่าง)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * LEFT JOIN ยังเก็บแถวฝั่งซ้ายไว้แม้ฝั่งขวาไม่มีคู่ คอลัมน์ของฝั่งขวาจะเป็น NULL
 * เงื่อนไข WHERE ที่ใช้จริง: 1=1
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

    /*
     * ต่อเงื่อนไขกรองเพิ่มให้ SQL เดิม เฉพาะเมื่อ PHP เข้ามาทำงานในบล็อกนี้
     * ต้องผ่านเงื่อนไขเดิมด้วย และเพิ่มเงื่อนไข: ( r.repair_order_code LIKE ? OR c.full_name LIKE ? OR
     * v.license_plate LIKE ? )
     * LIKE ใช้ค้นหาข้อความ ส่วน % ที่ PHP เติมหน้าหลังหมายถึงมีคำนี้อยู่ตรงไหนก็เจอ
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
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

    /*
     * ต่อเงื่อนไขกรองเพิ่มให้ SQL เดิม เฉพาะเมื่อ PHP เข้ามาทำงานในบล็อกนี้
     * ต้องผ่านเงื่อนไขเดิมด้วย และเพิ่มเงื่อนไข: r.status = ?
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $sql .= " AND r.status = ? ";

    $params[] = $filterStatus;
    $types .= "s";
}


/*
 * ต่อส่วนเรียงลำดับให้ SQL ที่ประกอบไว้ด้านบน
 * เรียงตาม: r.created_at DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
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

    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $db->prepare($sql);
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute($params);

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $db->prepare($sql);

    if (!empty($params)) {
        /*
         * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
         */
        $stmt->bind_param($types, ...$params);
    }

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute();

    $result = $stmt->get_result();

    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
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

    /*
     * ตรงนี้อ่านข้อมูลจาก customers (ลูกค้า)
     * เรียงตาม: full_name ASC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
    $stmt = $db->query("
        SELECT customer_id, full_name, phone
        FROM customers
        ORDER BY full_name ASC
    ");

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    /*
     * ตรงนี้อ่านข้อมูลจาก customers (ลูกค้า)
     * เรียงตาม: full_name ASC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
    $result = $db->query("
        SELECT customer_id, full_name, phone
        FROM customers
        ORDER BY full_name ASC
    ");

    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
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

    /*
     * ตรงนี้อ่านข้อมูลจาก vehicles (รถยนต์)
     * เรียงตาม: license_plate ASC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
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

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    /*
     * ตรงนี้อ่านข้อมูลจาก vehicles (รถยนต์)
     * เรียงตาม: license_plate ASC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
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

    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
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

/*
 * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง), mechanic_skills (ตารางเชื่อมช่างกับทักษะ), skills (ทักษะช่าง)
 * LEFT JOIN ยังเก็บแถวฝั่งซ้ายไว้แม้ฝั่งขวาไม่มีคู่ คอลัมน์ของฝั่งขวาจะเป็น NULL
 * เงื่อนไข WHERE ที่ใช้จริง: m.status != 'inactive' 
 * GROUP_CONCAT รวมหลายชื่อเป็นข้อความเดียวต่อกลุ่ม DISTINCT ตัดชื่อซ้ำ เช่น ทักษะของช่าง
 * จัดกลุ่มก่อนคำนวณยอด/จำนวนด้วย GROUP BY: m.mechanic_id, m.full_name, m.status 
 * เรียงตาม: s.skill_name SEPARATOR ', ' ) AS skills FROM mechanics m LEFT JOIN mechanic_skills ms ON
 * m.mechanic_id = ms.mechanic_id LEFT JOIN skills s ON ms.skill_id = s.skill_id WHERE m.status != 'inactive'
 * GROUP BY m.mechanic_id, m.full_name, m.status ORDER BY m.full_name ASC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
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

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $result = $db->query($sqlMechanics);

    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
    while ($row = $result->fetch_assoc()) {
        $mechanics[] = $row;
    }
}
/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก appointments (นัดหมาย), customers (ลูกค้า), vehicles (รถยนต์), repair_jobs (ใบงานซ่อม)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * LEFT JOIN ยังเก็บแถวฝั่งซ้ายไว้แม้ฝั่งขวาไม่มีคู่ คอลัมน์ของฝั่งขวาจะเป็น NULL
 * เงื่อนไข WHERE ที่ใช้จริง: a.status='scheduled' AND r.repair_order_id IS NULL 
 * เรียงตาม: a.appointment_date,a.appointment_time
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$availableAppointments = $pdo->query("SELECT a.*,c.full_name AS customer_name,v.license_plate,v.brand,v.model
    FROM appointments a JOIN customers c ON c.customer_id=a.customer_id JOIN vehicles v ON v.vehicle_id=a.vehicle_id
    LEFT JOIN repair_jobs r ON r.appointment_id=a.appointment_id
    WHERE a.status='scheduled' AND r.repair_order_id IS NULL ORDER BY a.appointment_date,a.appointment_time")->fetchAll(PDO::FETCH_ASSOC);
$jobHistory = workflowHistory($pdo,array_column($jobs,'repair_order_id'),'repair_order_id');
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

    <title>การจัดการใบงานซ่อม | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส</title>


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

<link rel="stylesheet" href="../../assets/css/workflow.css">
    <link rel="stylesheet" href="../../assets/css/confirmation-modal.css">

    <!-- GARAGE THEME: shared navy, gold and warm cream styles -->
    <link rel="stylesheet" href="../../assets/css/garage-theme.css">


</head>
<body>

<!-- =====================================================
     TOPBAR
===================================================== -->

<header class="topbar">

    <div class="brand">
            <!-- BRAND LOGO: original garage identity -->
            <img class="brand-logo" src="../../assets/img/logo.jpg" alt="" width="48" height="48">
        ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
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


        <a href="../financial/index.php">

            <span class="menu-icon">💰</span>

            <span>การเงิน</span>

        </a>


        <a href="../technician/index.php">

            <span class="menu-icon">👨‍🔧</span>

            <span>จัดการช่าง</span>

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

        <div class="repair-header garage-page-header">

            <div>

                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-screwdriver-wrench"></i>
                </span>

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
                        value="in_progress"
                        <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>
                    >
                        กำลังดำเนินการ
                    </option>

                    <option value="awaiting_review" <?= $filterStatus === "awaiting_review" ? "selected" : "" ?>>รอตรวจรับงาน</option>
<option
                        value="completed"
                        <?= $filterStatus === 'completed' ? 'selected' : '' ?>
                    >
                        เสร็จสิ้น
                    </option>

                    <option
                        value="cancelled"
                        <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>
                    >
                        ยกเลิก
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
                        <?php if (in_array($job['status'], ['waiting','in_progress'], true)): ?>
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
<?php endif; ?>

                        <?php workflowRenderHistory($jobHistory[$job['repair_order_id']] ?? []); ?>
<?php if ($job['status'] === 'awaiting_review'): ?>
<div class="workflow-review">
    <strong>รอแอดมินตรวจรับงาน</strong><p><?= nl2br(h($job['repair_detail'])) ?></p>
    <form
        method="POST"
        data-confirm-title="ยืนยันตรวจรับงาน"
        data-confirm-button="ยืนยันตรวจรับ"
        data-confirm-details="<?= h(json_encode([
            'เลขใบงาน' => $job['repair_order_code'],
            'ทะเบียนรถ' => $job['license_plate'],
            'รายละเอียดการซ่อม' => $job['repair_detail']
        ], JSON_UNESCAPED_UNICODE)) ?>"
    >
        <input type="hidden" name="action" value="approve"><input type="hidden" name="repair_order_id" value="<?= (int)$job['repair_order_id'] ?>">
        <button type="submit" class="repair-action complete">✓ ยืนยันตรวจรับ</button>
    </form>
    <form method="POST">
        <input type="hidden" name="action" value="return_work"><input type="hidden" name="repair_order_id" value="<?= (int)$job['repair_order_id'] ?>">
        <label>เหตุผลที่ส่งกลับแก้ไข<textarea name="reason" rows="2" required placeholder="ระบุสิ่งที่ต้องให้ช่างแก้ไขเพิ่มเติม"></textarea></label>
        <button type="submit" class="repair-action edit">ส่งกลับแก้ไข</button>
    </form>
</div>
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


<div class="form-group">
    <label for="createAppointment">นัดหมายที่เข้ารับบริการ *</label>
    <select name="appointment_id" id="createAppointment" required onchange="selectAppointment()">
        <option value="">เลือกนัดหมาย</option>
        <?php foreach ($availableAppointments as $appointment): ?>
        <option value="<?= (int)$appointment['appointment_id'] ?>"><?= h($appointment['appointment_date'].' '.substr($appointment['appointment_time'],0,5).' · '.$appointment['customer_name'].' · '.$appointment['license_plate']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if (!$availableAppointments): ?><p class="form-help">ไม่มีนัดหมายที่เปิดใบงานได้ กรุณาเพิ่มนัดหมายก่อน รวมถึงลูกค้า walk-in</p><?php endif; ?>
</div>
<div class="form-group"><label for="createCustomer">ลูกค้า</label><input id="createCustomer" readonly placeholder="แสดงจากนัดหมายที่เลือก"></div>
<div class="form-group"><label for="createVehicle">รถยนต์</label><input id="createVehicle" readonly placeholder="แสดงจากนัดหมายที่เลือก"></div>
            <!-- MECHANIC -->

            <div class="form-group">

                <label>
                    มอบหมายช่าง
                </label>

                <select
                    name="mechanic_id"
                    id="createMechanic" required
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

                <input id="editStatus" readonly>

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
                    id="editInspectionResult" readonly
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
                    id="editRepairDetail" readonly
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
                    id="editAdditionalProblem" readonly
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
                    id="editMechanicNote" readonly
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
const statusLabels = <?= json_encode($statusLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const appointments = <?= json_encode($availableAppointments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

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
        document.getElementById('createRepairForm').reset();
        selectAppointment();
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

        document.getElementById('editMechanic').querySelectorAll('option').forEach(option => { option.disabled = job.status !== 'waiting' && option.value !== String(job.mechanic_id); });
        document.getElementById('editDueDate').value =
            job.due_date || '';

        document.getElementById('editStatus').value =
            statusLabels[job.status] || job.status;

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

    function selectAppointment() {
        const appointment = appointments.find(a => String(a.appointment_id) === document.getElementById('createAppointment').value);
        document.getElementById('createCustomer').value = appointment ? appointment.customer_name : '';
        document.getElementById('createVehicle').value = appointment ? `${appointment.license_plate} · ${appointment.brand} ${appointment.model}` : '';
        document.querySelector('#createRepairForm [name="initial_symptom"]').value = appointment ? (appointment.description || '') : '';
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

<?php require __DIR__ . "/../../includes/confirmation_modal.php"; ?>

<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>
</html>
