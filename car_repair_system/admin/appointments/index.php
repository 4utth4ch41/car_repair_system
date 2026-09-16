<?php

require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";

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
    "in_progress" => "กำลังดำเนินการ",
    "completed" => "เสร็จสิ้น",
    "cancelled" => "ยกเลิก"
];


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        /*
        |--------------------------------------------------------------------------
        | ADD APPOINTMENT
        |--------------------------------------------------------------------------
        */

        if ($action === "add_appointment") {

            $customer_id      = intval($_POST["customer_id"] ?? 0);
            $vehicle_id       = intval($_POST["vehicle_id"] ?? 0);
            $appointment_date = $_POST["appointment_date"] ?? "";
            $appointment_time = $_POST["appointment_time"] ?? "";
            $service_type     = $_POST["service_type"] ?? "";
            $status           = $_POST["status"] ?? "scheduled";
            $description      = trim($_POST["description"] ?? "");


            if ($customer_id <= 0) {
                throw new Exception("กรุณาเลือกลูกค้า");
            }

            if ($vehicle_id <= 0) {
                throw new Exception("กรุณาเลือกรถยนต์");
            }

            if ($appointment_date === "" || $appointment_time === "") {
                throw new Exception("กรุณาระบุวันและเวลานัดหมาย");
            }

            if (!array_key_exists($service_type, $serviceTypes)) {
                throw new Exception("ประเภทบริการไม่ถูกต้อง");
            }

            if (!array_key_exists($status, $statusTypes)) {
                throw new Exception("สถานะการนัดหมายไม่ถูกต้อง");
            }


            /*
            | ตรวจสอบว่ารถเป็นของลูกค้าคนนี้จริง
            */

            $checkVehicle = $pdo->prepare("
                SELECT vehicle_id
                FROM vehicles
                WHERE vehicle_id = :vehicle_id
                AND customer_id = :customer_id
            ");

            $checkVehicle->execute([
                ":vehicle_id" => $vehicle_id,
                ":customer_id" => $customer_id
            ]);

            if (!$checkVehicle->fetch()) {
                throw new Exception("รถยนต์คันนี้ไม่ได้เป็นของลูกค้าที่เลือก");
            }


            $sql = "
                INSERT INTO appointments
                (
                    customer_id,
                    vehicle_id,
                    appointment_date,
                    appointment_time,
                    service_type,
                    description,
                    status
                )
                VALUES
                (
                    :customer_id,
                    :vehicle_id,
                    :appointment_date,
                    :appointment_time,
                    :service_type,
                    :description,
                    :status
                )
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ":customer_id"      => $customer_id,
                ":vehicle_id"       => $vehicle_id,
                ":appointment_date" => $appointment_date,
                ":appointment_time" => $appointment_time,
                ":service_type"     => $service_type,
                ":description"      => $description,
                ":status"           => $status
            ]);

            header("Location: index.php?success=appointment_added");
            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | EDIT APPOINTMENT
        |--------------------------------------------------------------------------
        */

        if ($action === "edit_appointment") {

            $appointment_id    = intval($_POST["appointment_id"] ?? 0);
            $customer_id       = intval($_POST["customer_id"] ?? 0);
            $vehicle_id        = intval($_POST["vehicle_id"] ?? 0);
            $appointment_date  = $_POST["appointment_date"] ?? "";
            $appointment_time  = $_POST["appointment_time"] ?? "";
            $service_type      = $_POST["service_type"] ?? "";
            $status            = $_POST["status"] ?? "";
            $description       = trim($_POST["description"] ?? "");


            if ($appointment_id <= 0) {
                throw new Exception("ไม่พบข้อมูลการนัดหมาย");
            }

            if ($customer_id <= 0) {
                throw new Exception("กรุณาเลือกลูกค้า");
            }

            if ($vehicle_id <= 0) {
                throw new Exception("กรุณาเลือกรถยนต์");
            }

            if ($appointment_date === "" || $appointment_time === "") {
                throw new Exception("กรุณาระบุวันและเวลานัดหมาย");
            }

            if (!array_key_exists($service_type, $serviceTypes)) {
                throw new Exception("ประเภทบริการไม่ถูกต้อง");
            }

            if (!array_key_exists($status, $statusTypes)) {
                throw new Exception("สถานะการนัดหมายไม่ถูกต้อง");
            }


            /*
            | ตรวจสอบรถกับลูกค้า
            */

            $checkVehicle = $pdo->prepare("
                SELECT vehicle_id
                FROM vehicles
                WHERE vehicle_id = :vehicle_id
                AND customer_id = :customer_id
            ");

            $checkVehicle->execute([
                ":vehicle_id" => $vehicle_id,
                ":customer_id" => $customer_id
            ]);

            if (!$checkVehicle->fetch()) {
                throw new Exception("รถยนต์คันนี้ไม่ได้เป็นของลูกค้าที่เลือก");
            }


            $sql = "
                UPDATE appointments
                SET
                    customer_id = :customer_id,
                    vehicle_id = :vehicle_id,
                    appointment_date = :appointment_date,
                    appointment_time = :appointment_time,
                    service_type = :service_type,
                    description = :description,
                    status = :status
                WHERE appointment_id = :appointment_id
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ":customer_id"      => $customer_id,
                ":vehicle_id"       => $vehicle_id,
                ":appointment_date" => $appointment_date,
                ":appointment_time" => $appointment_time,
                ":service_type"     => $service_type,
                ":description"      => $description,
                ":status"           => $status,
                ":appointment_id"   => $appointment_id
            ]);

            header("Location: index.php?success=appointment_updated");
            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | DELETE APPOINTMENT
        |--------------------------------------------------------------------------
        */

        if ($action === "delete_appointment") {

            $appointment_id = intval($_POST["appointment_id"] ?? 0);

            if ($appointment_id <= 0) {
                throw new Exception("ไม่พบข้อมูลการนัดหมาย");
            }

            $stmt = $pdo->prepare("
                DELETE FROM appointments
                WHERE appointment_id = :appointment_id
            ");

            $stmt->execute([
                ":appointment_id" => $appointment_id
            ]);

            header("Location: index.php?success=appointment_deleted");
            exit;
        }

    } catch (Exception $e) {

        header(
            "Location: index.php?error=" .
            urlencode($e->getMessage())
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");


/*
|--------------------------------------------------------------------------
| GET CUSTOMERS
|--------------------------------------------------------------------------
*/

$customerStmt = $pdo->query("
    SELECT
        customer_id,
        full_name,
        phone
    FROM customers
    ORDER BY full_name ASC
");

$customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET VEHICLES
|--------------------------------------------------------------------------
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

$vehicles = $vehicleStmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET APPOINTMENTS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        a.*,

        c.full_name AS customer_name,
        c.phone AS customer_phone,

        v.license_plate,
        v.brand,
        v.model

    FROM appointments a

    INNER JOIN customers c
        ON a.customer_id = c.customer_id

    INNER JOIN vehicles v
        ON a.vehicle_id = v.vehicle_id
";


if ($search !== "") {

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


$sql .= "
    ORDER BY
        a.appointment_date ASC,
        a.appointment_time ASC,
        a.appointment_id DESC
";


if ($search !== "") {

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":search" => "%" . $search . "%"
    ]);

} else {

    $stmt = $pdo->query($sql);
}


$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);


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

    <title>การนัดหมาย | P.Chalermchai Car Service</title>


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

</head>


<body>


<!-- =====================================================
     TOP NAVBAR
     ===================================================== -->

<header class="topbar">

    <div class="brand">
        P.Chalermchai Car Service
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

    <section class="appointment-page">


        <!-- HEADER -->

        <div class="appointment-header">

            <div>

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


                            <button
                                type="button"
                                class="appointment-action edit"
                                onclick='openEditAppointmentModal(<?= $appointmentJson ?>)'
                            >
                                ✏️ แก้ไข
                            </button>


                            <button
                                type="button"
                                class="appointment-action delete"
                                onclick='openDeleteModal(
                                    <?= (int)$appointment["appointment_id"] ?>,
                                    <?= json_encode(
                                        $appointment["customer_name"],
                                        JSON_UNESCAPED_UNICODE
                                    ) ?>
                                )'
                            >
                                🗑️ ลบ
                            </button>

                        </div>

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


            <!-- STATUS -->

            <div class="form-group">

                <label>
                    สถานะ
                </label>


                <select
                    name="status"
                >

                    <?php foreach ($statusTypes as $key => $label): ?>

                        <option
                            value="<?= $key ?>"
                            <?= $key === "scheduled"
                                ? "selected"
                                : "" ?>
                        >

                            <?= htmlspecialchars($label) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- DESCRIPTION -->

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


            <!-- STATUS -->

            <div class="form-group">

                <label>
                    สถานะ
                </label>


                <select
                    name="status"
                    id="editStatus"
                >

                    <?php foreach ($statusTypes as $key => $label): ?>

                        <option value="<?= $key ?>">
                            <?= htmlspecialchars($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- DESCRIPTION -->

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


<!-- =====================================================
     DELETE MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="deleteAppointmentModal"
>

    <div class="delete-modal">

        <div class="delete-icon">
            ⚠️
        </div>


        <h2>
            ยืนยันการลบ
        </h2>


        <p>
            คุณต้องการลบการนัดหมายของ
        </p>


        <strong id="deleteCustomerName"></strong>


        <p class="delete-warning">
            ข้อมูลการนัดหมายนี้จะถูกลบออกจากระบบและไม่สามารถกู้คืนได้
        </p>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="delete_appointment"
            >


            <input
                type="hidden"
                name="appointment_id"
                id="deleteAppointmentId"
            >


            <div class="delete-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeDeleteModal()"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-delete-confirm"
                >
                    ลบการนัดหมาย
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


    document.getElementById("editStatus").value =
        appointment.status;


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

function openDeleteModal(appointmentId, customerName)
{
    document.getElementById("deleteAppointmentId").value =
        appointmentId;


    document.getElementById("deleteCustomerName").textContent =
        customerName;


    document
        .getElementById("deleteAppointmentModal")
        .classList.add("show");
}


function closeDeleteModal()
{
    document
        .getElementById("deleteAppointmentModal")
        .classList.remove("show");
}


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

        closeDeleteModal();

    }
});

</script>


</body>
</html>