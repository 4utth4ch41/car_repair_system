<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * หน้านี้โหลดรายชื่อลูกค้าและรถสำหรับฟอร์ม แล้ว JOIN ข้อมูลเพื่อแสดงรายการนัดหมาย
 * คำค้นมาจาก GET search: ถ้าเว้นว่างไม่เติม WHERE จึงเห็นนัดหมายทั้งหมด
 * การเพิ่ม/แก้/ยกเลิก/ไม่มาตามนัด ส่งไป workflowAppointment ใน includes/appointment_workflow.php
 * ประวัติของนัดหมายโหลดเป็นชุดผ่าน workflowHistory ไม่ได้ query ทีละการ์ด
 */

require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";
require_once "../../includes/appointment_workflow.php";

/*
|--------------------------------------------------------------------------
| SERVICE TYPES
|--------------------------------------------------------------------------
*/

$serviceTypes = [
    "oil_change" => "เปลี่ยนถ่ายน้ำมันเครื่อง",
    "brake_service" => "บริการระบบเบรก",
    "tire_rotation" => "สลับยาง",
    "engine_diagnostic" => "ตรวจวินิจฉัยเครื่องยนต์",
    "transmission_repair" => "ซ่อมระบบเกียร์",
    "ac_service" => "บริการระบบปรับอากาศ",
    "battery_replacement" => "เปลี่ยนแบตเตอรี่",
    "general_inspection" => "ตรวจเช็กสภาพรถทั่วไป",
    "other" => "อื่น ๆ"
];


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

$statusTypes = [
    "scheduled" => "นัดหมายแล้ว",
    "in_progress" => "กำลังดำเนินการ (ข้อมูลเดิม)",
    "attended" => "เข้ารับบริการแล้ว",
    "no_show" => "ไม่มาตามนัด",
    "completed" => "เสร็จสิ้น",
    "cancelled" => "ยกเลิก"
];


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowAppointment($pdo,$_POST['action'] ?? '',$_POST,(int)$_SESSION['user_id'],$serviceTypes);
        header('Location: index.php?success=appointment_updated');
    } catch (InvalidArgumentException $e) {
        header('Location: index.php?error='.urlencode($e->getMessage()));
    } catch (Throwable $e) {
        error_log($e->getMessage());
        header('Location: index.php?error='.urlencode('ไม่สามารถบันทึกนัดหมายได้ กรุณาลองใหม่'));
    }
    exit;
}
$search = trim($_GET["search"] ?? "");


/*
|--------------------------------------------------------------------------
| GET CUSTOMERS
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก customers (ลูกค้า)
 * เรียงตาม: full_name ASC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$customerStmt = $pdo->query("
    SELECT
        customer_id,
        full_name,
        phone
    FROM customers
    ORDER BY full_name ASC
");

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET VEHICLES
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก vehicles (รถยนต์)
 * เรียงตาม: license_plate ASC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$vehicleStmt = $pdo->query("
    SELECT
        vehicle_id,
        customer_id,
        license_plate,
        brand,
        model
    FROM vehicles
    ORDER BY license_plate ASC
");

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$vehicles = $vehicleStmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET APPOINTMENTS
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก appointments (นัดหมาย), repair_jobs (ใบงานซ่อม), customers (ลูกค้า), vehicles (รถยนต์)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * LEFT JOIN ยังเก็บแถวฝั่งซ้ายไว้แม้ฝั่งขวาไม่มีคู่ คอลัมน์ของฝั่งขวาจะเป็น NULL
 */
$sql = "
    SELECT
        a.*,
        r.repair_order_code,

        c.full_name AS customer_name,
        c.phone AS customer_phone,

        v.license_plate,
        v.brand,
        v.model

    FROM appointments a
    LEFT JOIN repair_jobs r ON r.appointment_id=a.appointment_id

    INNER JOIN customers c
        ON a.customer_id = c.customer_id

    INNER JOIN vehicles v
        ON a.vehicle_id = v.vehicle_id
";


if ($search !== "") {

    /*
     * ต่อเงื่อนไขกรองเพิ่มให้ SQL เดิม เฉพาะเมื่อ PHP เข้ามาทำงานในบล็อกนี้
     * เงื่อนไข WHERE ที่ใช้จริง: c.full_name LIKE :search OR c.phone LIKE :search OR v.license_plate LIKE :search OR
     * v.brand LIKE :search OR v.model LIKE :search OR a.service_type LIKE :search OR a.description LIKE :search
     * LIKE ใช้ค้นหาข้อความ ส่วน % ที่ PHP เติมหน้าหลังหมายถึงมีคำนี้อยู่ตรงไหนก็เจอ
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $sql .= "
        WHERE
            c.full_name LIKE :search
            OR c.phone LIKE :search
            OR v.license_plate LIKE :search
            OR v.brand LIKE :search
            OR v.model LIKE :search
            OR a.service_type LIKE :search
            OR a.description LIKE :search
    ";
}


/*
 * ต่อส่วนเรียงลำดับให้ SQL ที่ประกอบไว้ด้านบน
 * เรียงตาม: a.appointment_date ASC, a.appointment_time ASC, a.appointment_id DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$sql .= "
    ORDER BY
        a.appointment_date ASC,
        a.appointment_time ASC,
        a.appointment_id DESC
";


if ($search !== "") {

    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $pdo->prepare($sql);

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([
        ":search" => "%" . $search . "%"
    ]);

} else {

    $stmt = $pdo->query($sql);
}


/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$appointmentHistory = workflowHistory($pdo,array_column($appointments,"appointment_id"),"appointment_id");


/*
|--------------------------------------------------------------------------
| FORMAT FUNCTIONS
|--------------------------------------------------------------------------
*/

function thaiDate($date)
{
    if (!$date) {
        return "-";
    }

    $timestamp = strtotime($date);

    $day = date("d", $timestamp);
    $month = date("m", $timestamp);
    $year = date("Y", $timestamp) + 543;

    $months = [
        "01" => "ม.ค.",
        "02" => "ก.พ.",
        "03" => "มี.ค.",
        "04" => "เม.ย.",
        "05" => "พ.ค.",
        "06" => "มิ.ย.",
        "07" => "ก.ค.",
        "08" => "ส.ค.",
        "09" => "ก.ย.",
        "10" => "ต.ค.",
        "11" => "พ.ย.",
        "12" => "ธ.ค."
    ];

    return intval($day) . " " . $months[$month] . " " . $year;
}


function thaiTime($time)
{
    if (!$time) {
        return "-";
    }

    return date("H:i", strtotime($time)) . " น.";
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

    <title>การนัดหมาย | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส</title>


    <!-- CSS หลัก -->

    <link
        rel="stylesheet"
        href="../../assets/css/style.css"
    >


    <!-- CSS Sidebar -->

    <link
        rel="stylesheet"
        href="../../assets/css/admin/sidebar.css"
    >


    <!-- CSS Appointment -->

    <link
        rel="stylesheet"
        href="../../assets/css/admin/appointment.css"
    >

<link rel="stylesheet" href="../../assets/css/workflow.css">
    <link rel="stylesheet" href="../../assets/css/confirmation-modal.css">

    <!-- GARAGE THEME: shared navy, gold and warm cream styles -->
    <link rel="stylesheet" href="../../assets/css/garage-theme.css">


</head>


<body>


<!-- =====================================================
     TOP NAVBAR
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
                <?= htmlspecialchars($_SESSION["username"]) ?>
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


        <a
            href="../appointments/index.php"
            class="active"
        >

            <span class="menu-icon">📅</span>

            <span>การนัดหมาย</span>

        </a>


        <a href="../repair_jobs/index.php">

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

    <section class="appointment-page">


        <!-- HEADER -->

        <div class="appointment-header garage-page-header">

            <div>

                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-calendar-check"></i>
                </span>

                <h1>
                    การจัดการการนัดหมาย
                </h1>

                <p>
                    จัดตารางและจัดการการนัดหมายเข้ารับบริการของลูกค้า
                </p>

            </div>

            <button type="button" class="appointment-add-btn" onclick="openAddAppointmentModal()">
                <span>+</span>
                เพิ่มการนัดหมาย
            </button>
        </div>


        <!-- =================================================
             ALERT
             ================================================= -->

        <?php if (isset($_GET["success"])): ?>

            <div class="alert alert-success">

                <?php if ($_GET["success"] === "appointment_added"): ?>

                    เพิ่มการนัดหมายสำเร็จ

                <?php elseif ($_GET["success"] === "appointment_updated"): ?>

                    แก้ไขข้อมูลการนัดหมายสำเร็จ

                <?php elseif ($_GET["success"] === "appointment_deleted"): ?>

                    ลบข้อมูลการนัดหมายสำเร็จ

                <?php endif; ?>

            </div>

        <?php endif; ?>


        <?php if (isset($_GET["error"])): ?>

            <div class="alert alert-error">

                <?= htmlspecialchars($_GET["error"]) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             SEARCH
             ================================================= -->

        <div class="appointment-search-row">

            <form
                method="GET"
                class="appointment-search"
            >

                <span class="search-icon">
                    🔍
                </span>


                <input
                    type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="ค้นหาชื่อลูกค้า เบอร์โทร ทะเบียนรถ หรือประเภทบริการ..."
                >

            </form>


            <?php if ($search !== ""): ?>

                <a
                    href="../appointments/index.php"
                    class="clear-search-btn"
                >
                    ✕ ล้างการค้นหา
                </a>

            <?php endif; ?>

        </div>


        <!-- =================================================
             APPOINTMENT CARDS
             ================================================= -->

        <?php if (count($appointments) > 0): ?>

            <div class="appointment-grid">


                <?php foreach ($appointments as $appointment): ?>

                    <?php

                    $appointmentJson =
                        htmlspecialchars(
                            json_encode(
                                $appointment,
                                JSON_UNESCAPED_UNICODE
                            ),
                            ENT_QUOTES,
                            "UTF-8"
                        );

                    ?>


                    <div class="appointment-card">


                        <!-- CARD HEADER -->

                        <div class="appointment-card-header">

                            <div class="appointment-customer">

                                <div class="appointment-icon">
                                    📅
                                </div>


                                <div>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $appointment["customer_name"]
                                        ) ?>
                                    </strong>


                                    <span>
                                        <?= htmlspecialchars(
                                            $appointment["license_plate"]
                                        ) ?>
                                    </span>

                                </div>

                            </div>


                            <span
                                class="status-badge status-<?= htmlspecialchars(
                                    $appointment["status"]
                                ) ?>"
                            >
                                <?= htmlspecialchars(
                                    $statusTypes[
                                        $appointment["status"]
                                    ] ?? $appointment["status"]
                                ) ?>
                            </span>

                        </div>


                        <!-- SERVICE -->

                        <div class="appointment-service">

                            <?= htmlspecialchars(
                                $serviceTypes[
                                    $appointment["service_type"]
                                ] ?? "อื่น ๆ"
                            ) ?>

                        </div>


                        <!-- DATE -->

                        <div class="appointment-date">

                            📅

                            <?= thaiDate(
                                $appointment["appointment_date"]
                            ) ?>

                            เวลา

                            <?= thaiTime(
                                $appointment["appointment_time"]
                            ) ?>

                        </div>


                        <!-- VEHICLE -->

                        <div class="appointment-vehicle">

                            🚗

                            <?= htmlspecialchars(
                                $appointment["brand"]
                            ) ?>

                            <?= htmlspecialchars(
                                $appointment["model"]
                            ) ?>

                        </div>


                        <!-- DESCRIPTION -->

                        <?php if (!empty($appointment["description"])): ?>

                            <div class="appointment-description">

                                <?= nl2br(
                                    htmlspecialchars(
                                        $appointment["description"]
                                    )
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <!-- ACTIONS -->

                        <div class="appointment-actions">
                            <?php if ($appointment['status'] === 'scheduled' && !$appointment['repair_order_code']): ?>
                            <button type="button" class="appointment-action edit" onclick='openEditAppointmentModal(<?= $appointmentJson ?>)'>
                                แก้ไข / เลื่อนนัด
                            </button>
                            <form
                                method="POST"
                                data-confirm-title="ยกเลิกนัดหมาย"
                                data-confirm-button="ยืนยันยกเลิกนัด"
                                data-confirm-danger="true"
                                data-confirm-reason="เหตุผลที่ยกเลิกนัด (จำเป็น)"
                                data-confirm-details="<?= htmlspecialchars(json_encode([
                                    'ลูกค้า' => $appointment['customer_name'],
                                    'ทะเบียนรถ' => $appointment['license_plate'],
                                    'วันนัด' => thaiDate($appointment['appointment_date']),
                                    'เวลา' => thaiTime($appointment['appointment_time'])
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <input type="hidden" name="action" value="cancel_appointment">
                                <input type="hidden" name="appointment_id" value="<?= (int)$appointment['appointment_id'] ?>">
                                <input type="hidden" name="reason">
                                    <button class="appointment-action delete" type="submit">
                                        ยกเลิกนัด
                                    </button>
                            </form>

                            <form
                                method="POST"
                                data-confirm-title="บันทึกไม่มาตามนัด"
                                data-confirm-button="ยืนยันไม่มาตามนัด"
                                data-confirm-reason="หมายเหตุการไม่มาตามนัด (จำเป็น)"
                                data-confirm-details="<?= htmlspecialchars(json_encode([
                                    'ลูกค้า' => $appointment['customer_name'],
                                    'ทะเบียนรถ' => $appointment['license_plate'],
                                    'วันนัด' => thaiDate($appointment['appointment_date']),
                                    'เวลา' => thaiTime($appointment['appointment_time'])
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <input type="hidden" name="action" value="no_show">
                                <input type="hidden" name="appointment_id" value="<?= (int)$appointment['appointment_id'] ?>">
                                <input type="hidden" name="reason">
                                    <button class="appointment-action delete" type="submit">
                                        ไม่มาตามนัด
                                    </button>
                            </form>
                            <?php elseif ($appointment['repair_order_code']): ?>

                            <a class="appointment-action edit" href="../repair_jobs/index.php?search=<?= urlencode($appointment['repair_order_code']) ?>">
                                ดูใบงาน <?= htmlspecialchars($appointment['repair_order_code']) ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <?php workflowRenderHistory($appointmentHistory[$appointment['appointment_id']] ?? []); ?>

                    </div>

                <?php endforeach; ?>

            </div>


        <?php else: ?>

            <div class="appointment-empty">

                <div class="empty-icon">
                    📅
                </div>

                <?php if ($search !== ""): ?>

                    <h3>
                        ไม่พบข้อมูลการนัดหมาย
                    </h3>

                    <p>
                        ลองค้นหาด้วยชื่อ เบอร์โทร ทะเบียนรถ หรือประเภทบริการอื่น
                    </p>

                <?php else: ?>

                    <h3>
                        ยังไม่มีข้อมูลการนัดหมาย
                    </h3>

                    <p>
                        เริ่มต้นด้วยการเพิ่มการนัดหมายใหม่
                    </p>

                <?php endif; ?>

            </div>

        <?php endif; ?>


    </section>

</main>


<!-- =====================================================
     ADD APPOINTMENT MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="addAppointmentModal"
>

    <div class="appointment-modal">

        <div class="modal-header">

            <h2>
                เพิ่มการนัดหมาย
            </h2>


            <button
                type="button"
                class="modal-close"
                onclick="closeAddAppointmentModal()"
            >
                ×
            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="add_appointment"
            >


            <!-- CUSTOMER -->

            <div class="form-group">

                <label>
                    ลูกค้า
                    <span>*</span>
                </label>


                <select
                    name="customer_id"
                    id="addCustomerId"
                    required
                    onchange="loadVehicles(
                        this.value,
                        'addVehicleId'
                    )"
                >

                    <option value="">
                        เลือกลูกค้า
                    </option>


                    <?php foreach ($customers as $customer): ?>

                        <option
                            value="<?= (int)$customer["customer_id"] ?>"
                        >

                            <?= htmlspecialchars(
                                $customer["full_name"]
                            ) ?>

                            —

                            <?= htmlspecialchars(
                                $customer["phone"]
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- VEHICLE -->

            <div class="form-group">

                <label>
                    รถยนต์
                    <span>*</span>
                </label>


                <select
                    name="vehicle_id"
                    id="addVehicleId"
                    required
                    disabled
                >

                    <option value="">
                        กรุณาเลือกลูกค้าก่อน
                    </option>

                </select>

            </div>


            <!-- DATE -->

            <div class="form-group">

                <label>
                    วันนัดหมาย
                    <span>*</span>
                </label>


                <input
                    type="date"
                    name="appointment_date"
                    required
                >

            </div>


            <!-- TIME -->

            <div class="form-group">

                <label>
                    เวลานัดหมาย
                    <span>*</span>
                </label>


                <input
                    type="time"
                    name="appointment_time"
                    required
                >

            </div>


            <!-- SERVICE -->

            <div class="form-group">

                <label>
                    ประเภทการบริการ
                    <span>*</span>
                </label>


                <select
                    name="service_type"
                    required
                >

                    <option value="">
                        เลือกประเภทบริการ
                    </option>


                    <?php foreach ($serviceTypes as $key => $label): ?>

                        <option value="<?= $key ?>">
                            <?= htmlspecialchars($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


<p class="form-help">บันทึกแล้วจะมีสถานะนัดหมายแล้วโดยอัตโนมัติ</p>            <!-- DESCRIPTION -->

            <div class="form-group">

                <label>
                    หมายเหตุ
                </label>


                <textarea
                    name="description"
                    rows="3"
                    placeholder="รายละเอียดเพิ่มเติม..."
                ></textarea>

            </div>


            <!-- ACTIONS -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeAddAppointmentModal()"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-submit"
                >
                    บันทึกการนัดหมาย
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     EDIT APPOINTMENT MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="editAppointmentModal"
>

    <div class="appointment-modal">

        <div class="modal-header">

            <h2>
                แก้ไขข้อมูลการนัดหมาย
            </h2>


            <button
                type="button"
                class="modal-close"
                onclick="closeEditAppointmentModal()"
            >
                ×
            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="edit_appointment"
            >


            <input
                type="hidden"
                name="appointment_id"
                id="editAppointmentId"
            >


            <!-- CUSTOMER -->

            <div class="form-group">

                <label>
                    ลูกค้า
                    <span>*</span>
                </label>


                <select
                    name="customer_id"
                    id="editCustomerId"
                    required
                    onchange="loadVehicles(
                        this.value,
                        'editVehicleId'
                    )"
                >

                    <option value="">
                        เลือกลูกค้า
                    </option>


                    <?php foreach ($customers as $customer): ?>

                        <option
                            value="<?= (int)$customer["customer_id"] ?>"
                        >

                            <?= htmlspecialchars(
                                $customer["full_name"]
                            ) ?>

                            —

                            <?= htmlspecialchars(
                                $customer["phone"]
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- VEHICLE -->

            <div class="form-group">

                <label>
                    รถยนต์
                    <span>*</span>
                </label>


                <select
                    name="vehicle_id"
                    id="editVehicleId"
                    required
                >

                    <option value="">
                        เลือกรถยนต์
                    </option>

                </select>

            </div>


            <!-- DATE -->

            <div class="form-group">

                <label>
                    วันนัดหมาย
                    <span>*</span>
                </label>


                <input
                    type="date"
                    name="appointment_date"
                    id="editAppointmentDate"
                    required
                >

            </div>


            <!-- TIME -->

            <div class="form-group">

                <label>
                    เวลานัดหมาย
                    <span>*</span>
                </label>


                <input
                    type="time"
                    name="appointment_time"
                    id="editAppointmentTime"
                    required
                >

            </div>


            <!-- SERVICE -->

            <div class="form-group">

                <label>
                    ประเภทการบริการ
                    <span>*</span>
                </label>


                <select
                    name="service_type"
                    id="editServiceType"
                    required
                >

                    <?php foreach ($serviceTypes as $key => $label): ?>

                        <option value="<?= $key ?>">
                            <?= htmlspecialchars($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


<div class="form-group"><label>เหตุผลที่เลื่อนนัด (จำเป็นเมื่อเปลี่ยนวันหรือเวลา)</label><textarea name="reason" id="rescheduleReason" rows="2"></textarea></div>            <!-- DESCRIPTION -->

            <div class="form-group">

                <label>
                    หมายเหตุ
                </label>


                <textarea
                    name="description"
                    id="editDescription"
                    rows="3"
                ></textarea>

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeEditAppointmentModal()"
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


/* =====================================================
   VEHICLE DATA
   ===================================================== */

const vehicles = <?= json_encode(
    $vehicles,
    JSON_UNESCAPED_UNICODE
) ?>;


/* =====================================================
   LOAD VEHICLES BY CUSTOMER
   ===================================================== */

function loadVehicles(customerId, selectId, selectedVehicleId = null)
{
    const select =
        document.getElementById(selectId);

    select.innerHTML = "";


    if (!customerId) {

        select.disabled = true;

        const option =
            document.createElement("option");

        option.value = "";

        option.textContent =
            "กรุณาเลือกลูกค้าก่อน";

        select.appendChild(option);

        return;
    }


    const customerVehicles =
        vehicles.filter(
            vehicle =>
                String(vehicle.customer_id) ===
                String(customerId)
        );


    if (customerVehicles.length === 0) {

        select.disabled = true;

        const option =
            document.createElement("option");

        option.value = "";

        option.textContent =
            "ลูกค้ารายนี้ยังไม่มีรถยนต์";

        select.appendChild(option);

        return;
    }


    select.disabled = false;


    const firstOption =
        document.createElement("option");

    firstOption.value = "";

    firstOption.textContent =
        "เลือกรถยนต์";

    select.appendChild(firstOption);


    customerVehicles.forEach(vehicle => {

        const option =
            document.createElement("option");

        option.value =
            vehicle.vehicle_id;

        option.textContent =
            vehicle.license_plate +
            " — " +
            vehicle.brand +
            " " +
            vehicle.model;


        if (
            selectedVehicleId !== null &&
            String(vehicle.vehicle_id) ===
            String(selectedVehicleId)
        ) {

            option.selected = true;

        }


        select.appendChild(option);

    });
}


/* =====================================================
   ADD MODAL
   ===================================================== */

function openAddAppointmentModal()
{
    document
        .getElementById("addAppointmentModal")
        .classList.add("show");
}


function closeAddAppointmentModal()
{
    document
        .getElementById("addAppointmentModal")
        .classList.remove("show");
}


/* =====================================================
   EDIT MODAL
   ===================================================== */

function openEditAppointmentModal(appointment)
{
    document.getElementById("editAppointmentId").value =
        appointment.appointment_id;


    document.getElementById("editCustomerId").value =
        appointment.customer_id;


    loadVehicles(
        appointment.customer_id,
        "editVehicleId",
        appointment.vehicle_id
    );


    document.getElementById("editAppointmentDate").value =
        appointment.appointment_date;


    document.getElementById("editAppointmentTime").value =
        appointment.appointment_time.substring(0, 5);


    document.getElementById("editServiceType").value =
        appointment.service_type;


    document.getElementById("rescheduleReason").value = "";


    document.getElementById("editDescription").value =
        appointment.description || "";


    document
        .getElementById("editAppointmentModal")
        .classList.add("show");
}


function closeEditAppointmentModal()
{
    document
        .getElementById("editAppointmentModal")
        .classList.remove("show");
}


/* =====================================================
   DELETE MODAL
   ===================================================== */

/* =====================================================
   CLOSE MODAL WHEN CLICK OUTSIDE
   ===================================================== */

document.addEventListener("click", function(event)
{
    if (event.target.classList.contains("modal-overlay")) {

        event.target.classList.remove("show");

    }
});


/* =====================================================
   ESC TO CLOSE
   ===================================================== */

document.addEventListener("keydown", function(event)
{
    if (event.key === "Escape") {

        closeAddAppointmentModal();

        closeEditAppointmentModal();



    }
});

</script>


<?php require __DIR__ . "/../../includes/confirmation_modal.php"; ?>

<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>
</html>
