<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * ข้อมูลแยกเป็น customers กับ vehicles โดย vehicles.customer_id บอกว่ารถเป็นของใคร
 * ค้นหาลูกค้าจากชื่อ/โทรศัพท์ ส่วนค้นหารถจากทะเบียน/ยี่ห้อ/รุ่น/ชื่อเจ้าของ เป็นคนละ query
 * หลังดึงรถมา PHP จัดกลุ่มตาม customer_id เพื่อแสดงรถใต้ลูกค้าแต่ละคน ไม่ได้ใช้ SQL GROUP BY
 * ก่อนลบลูกค้ามีการนับรถที่ผูกอยู่ ถ้ายังมีรถจะไม่ให้ลบลูกค้าในขั้นตอนนี้
 */

require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";

function validateCustomerAddress(string $province, string $district, string $subdistrict): array
{
    if ($province === "" && $district === "" && $subdistrict === "") {
        return [null, null, null];
    }

    if ($province === "" || $district === "" || $subdistrict === "") {
        throw new Exception("กรุณาเลือกจังหวัด อำเภอ/เขต และตำบล/แขวงให้ครบ");
    }

    $addressData = json_decode(
        file_get_contents(__DIR__ . "/../../assets/data/thailand-address.json"),
        true
    );

    foreach ($addressData as $provinceData) {
        if ($provinceData["name"] !== $province) {
            continue;
        }

        foreach ($provinceData["districts"] as $districtData) {
            if ($districtData["name"] !== $district) {
                continue;
            }

            if (in_array($subdistrict, $districtData["subdistricts"], true)) {
                return [$province, $district, $subdistrict];
            }
        }
    }

    throw new Exception("ข้อมูลจังหวัด อำเภอ/เขต หรือตำบล/แขวงไม่ถูกต้อง");
}

/*
|--------------------------------------------------------------------------
| ADD CUSTOMER
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    try {

        /* =========================
           ADD CUSTOMER
           ========================= */

        if ($action === "add_customer") {

            $full_name = trim($_POST["full_name"] ?? "");
            $phone     = trim($_POST["phone"] ?? "");
            $address   = trim($_POST["address"] ?? "");
            $note      = trim($_POST["note"] ?? "");
            [$province, $district, $subdistrict] = validateCustomerAddress(
                trim($_POST["province"] ?? ""),
                trim($_POST["district"] ?? ""),
                trim($_POST["subdistrict"] ?? "")
            );

            if ($full_name === "" || $phone === "") {
                throw new Exception("กรุณากรอกชื่อ-สกุลและเบอร์โทรศัพท์");
            }

            /*
             * เพิ่มแถวใหม่ลง customers (ลูกค้า)
             * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $sql = "
                INSERT INTO customers
                (full_name, phone, address, province, district, subdistrict, note)
                VALUES
                (:full_name, :phone, :address, :province, :district, :subdistrict, :note)
            ";

            /*
             * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
             */
            $stmt = $pdo->prepare($sql);

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([
                ":full_name" => $full_name,
                ":phone"     => $phone,
                ":address"   => $address,
                ":province"  => $province,
                ":district"  => $district,
                ":subdistrict" => $subdistrict,
                ":note"      => $note
            ]);

            header("Location: index.php?success=customer_added");
            exit;
        }

        /* =========================
            EDIT CUSTOMER
            ========================= */

            if ($action === "edit_customer") {

                $customer_id = intval($_POST["customer_id"] ?? 0);
                $full_name   = trim($_POST["full_name"] ?? "");
                $phone       = trim($_POST["phone"] ?? "");
                $address     = trim($_POST["address"] ?? "");
                $note        = trim($_POST["note"] ?? "");
                [$province, $district, $subdistrict] = validateCustomerAddress(
                    trim($_POST["province"] ?? ""),
                    trim($_POST["district"] ?? ""),
                    trim($_POST["subdistrict"] ?? "")
                );

                if ($customer_id <= 0) {
                    throw new Exception("ไม่พบข้อมูลลูกค้า");
                }

                if ($full_name === "" || $phone === "") {
                    throw new Exception("กรุณากรอกชื่อ-สกุลและเบอร์โทรศัพท์");
                }

                /*
                 * แก้ข้อมูลเดิมใน customers (ลูกค้า) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
                 * เงื่อนไข WHERE ที่ใช้จริง: customer_id = :customer_id
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $sql = "
                    UPDATE customers
                    SET
                        full_name = :full_name,
                        phone = :phone,
                        address = :address,
                        province = :province,
                        district = :district,
                        subdistrict = :subdistrict,
                        note = :note
                    WHERE customer_id = :customer_id
                ";

                /*
                 * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
                 */
                $stmt = $pdo->prepare($sql);

                /*
                 * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                 */
                $stmt->execute([
                    ":full_name"   => $full_name,
                    ":phone"       => $phone,
                    ":address"     => $address,
                    ":province"    => $province,
                    ":district"    => $district,
                    ":subdistrict" => $subdistrict,
                    ":note"        => $note,
                    ":customer_id" => $customer_id
                ]);

                header("Location: index.php?success=customer_updated");
                exit;
            }

        /* =========================
           ADD VEHICLE
           ========================= */

        if ($action === "add_vehicle") {

            $customer_id   = intval($_POST["customer_id"] ?? 0);
            $license_plate = trim($_POST["license_plate"] ?? "");
            $brand         = trim($_POST["brand"] ?? "");
            $model         = trim($_POST["model"] ?? "");
            $color         = trim($_POST["color"] ?? "");
            $mileage       = intval($_POST["mileage"] ?? 0);
            $note          = trim($_POST["note"] ?? "");

            if (
                $customer_id <= 0 ||
                $license_plate === "" ||
                $brand === "" ||
                $model === ""
            ) {
                throw new Exception("กรุณากรอกข้อมูลรถที่จำเป็นให้ครบ");
            }

            /*
             * เพิ่มแถวใหม่ลง vehicles (รถยนต์)
             * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $sql = "
                INSERT INTO vehicles
                (
                    customer_id,
                    license_plate,
                    brand,
                    model,
                    color,
                    mileage,
                    note
                )
                VALUES
                (
                    :customer_id,
                    :license_plate,
                    :brand,
                    :model,
                    :color,
                    :mileage,
                    :note
                )
            ";

            /*
             * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
             */
            $stmt = $pdo->prepare($sql);

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([
                ":customer_id"   => $customer_id,
                ":license_plate" => $license_plate,
                ":brand"         => $brand,
                ":model"         => $model,
                ":color"         => $color,
                ":mileage"       => $mileage,
                ":note"          => $note
            ]);

            header("Location: index.php?success=vehicle_added");
            exit;
        }
            
        /* =========================
        EDIT VEHICLE
        ========================= */

        if ($action === "edit_vehicle") {

            $vehicle_id    = intval($_POST["vehicle_id"] ?? 0);
            $license_plate = trim($_POST["license_plate"] ?? "");
            $brand         = trim($_POST["brand"] ?? "");
            $model         = trim($_POST["model"] ?? "");
            $color         = trim($_POST["color"] ?? "");
            $mileage       = intval($_POST["mileage"] ?? 0);
            $note          = trim($_POST["note"] ?? "");

            if ($vehicle_id <= 0) {
                throw new Exception("ไม่พบข้อมูลรถยนต์");
            }

            if (
                $license_plate === "" ||
                $brand === "" ||
                $model === ""
            ) {
                throw new Exception("กรุณากรอกข้อมูลรถที่จำเป็นให้ครบ");
            }

            if ($mileage < 0) {
                throw new Exception("เลขไมล์ไม่สามารถติดลบได้");
            }

            /*
             * แก้ข้อมูลเดิมใน vehicles (รถยนต์) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
             * เงื่อนไข WHERE ที่ใช้จริง: vehicle_id = :vehicle_id
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $sql = "
                UPDATE vehicles
                SET
                    license_plate = :license_plate,
                    brand = :brand,
                    model = :model,
                    color = :color,
                    mileage = :mileage,
                    note = :note
                WHERE vehicle_id = :vehicle_id
            ";

            /*
             * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
             */
            $stmt = $pdo->prepare($sql);

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([
                ":license_plate" => $license_plate,
                ":brand"         => $brand,
                ":model"         => $model,
                ":color"         => $color,
                ":mileage"       => $mileage,
                ":note"          => $note,
                ":vehicle_id"    => $vehicle_id
            ]);

            header("Location: index.php?tab=vehicles&success=vehicle_updated");
            exit;
        }
                /* =========================
           DELETE VEHICLE
           ========================= */
        if ($action === "delete_vehicle") {

            $vehicle_id = (int)($_POST["vehicle_id"] ?? 0);

            if ($vehicle_id <= 0) {
                throw new Exception("ไม่พบข้อมูลรถยนต์");
            }

            try {
                $stmt = $pdo->prepare("
                    DELETE FROM vehicles
                    WHERE vehicle_id = :vehicle_id
                ");

                $stmt->execute([
                    ":vehicle_id" => $vehicle_id
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception("ไม่พบรถยนต์ที่ต้องการลบ");
                }

            } catch (PDOException $e) {
                throw new Exception(
                    "ไม่สามารถลบรถยนต์ได้ เนื่องจากอาจมีประวัติงานซ่อมหรือการนัดหมายที่เชื่อมโยงอยู่"
                );
            }

            header(
                "Location: index.php?tab=vehicles&success=vehicle_deleted"
            );
            exit;
        }

        /* =========================
           DELETE CUSTOMER
           ========================= */
        if ($action === "delete_customer") {

            $customer_id = (int)($_POST["customer_id"] ?? 0);

            if ($customer_id <= 0) {
                throw new Exception("ไม่พบข้อมูลลูกค้า");
            }

            // ตรวจสอบว่าลูกค้ายังมีรถอยู่หรือไม่
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM vehicles
                WHERE customer_id = :customer_id
            ");

            $checkStmt->execute([
                ":customer_id" => $customer_id
            ]);

            $vehicleCount = (int)$checkStmt->fetchColumn();

            if ($vehicleCount > 0) {
                throw new Exception(
                    "ไม่สามารถลบลูกค้าได้ เนื่องจากลูกค้ายังมีรถยนต์อยู่ในระบบ กรุณาลบรถยนต์ก่อน"
                );
            }

            try {
                $stmt = $pdo->prepare("
                    DELETE FROM customers
                    WHERE customer_id = :customer_id
                ");

                $stmt->execute([
                    ":customer_id" => $customer_id
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception("ไม่พบลูกค้าที่ต้องการลบ");
                }

            } catch (PDOException $e) {
                throw new Exception(
                    "ไม่สามารถลบลูกค้าได้ เนื่องจากอาจมีประวัติงานซ่อมหรือข้อมูลอื่นเชื่อมโยงอยู่"
                );
            }

            header(
                "Location: index.php?success=customer_deleted"
            );
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
$tab    = $_GET["tab"] ?? "customers";


/*
|--------------------------------------------------------------------------
| GET CUSTOMERS
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    /*
     * ตรงนี้อ่านข้อมูลจาก customers (ลูกค้า)
     * เงื่อนไข WHERE ที่ใช้จริง: full_name LIKE :search OR phone LIKE :search 
     * LIKE ใช้ค้นหาข้อความ ส่วน % ที่ PHP เติมหน้าหลังหมายถึงมีคำนี้อยู่ตรงไหนก็เจอ
     * เรียงตาม: customer_id DESC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $customerSql = "
        SELECT *
        FROM customers
        WHERE
            full_name LIKE :search
            OR phone LIKE :search
        ORDER BY customer_id DESC
    ";

    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $pdo->prepare($customerSql);

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([
        ":search" => "%" . $search . "%"
    ]);

} else {

    /*
     * ตรงนี้อ่านข้อมูลจาก customers (ลูกค้า)
     * เรียงตาม: customer_id DESC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
    $customerSql = "
        SELECT *
        FROM customers
        ORDER BY customer_id DESC
    ";

    $stmt = $pdo->query($customerSql);
}

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET VEHICLES
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    /*
     * ตรงนี้อ่านข้อมูลจาก vehicles (รถยนต์), customers (ลูกค้า)
     * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
     * เงื่อนไข WHERE ที่ใช้จริง: v.license_plate LIKE :search OR v.brand LIKE :search OR v.model LIKE :search OR
     * c.full_name LIKE :search 
     * LIKE ใช้ค้นหาข้อความ ส่วน % ที่ PHP เติมหน้าหลังหมายถึงมีคำนี้อยู่ตรงไหนก็เจอ
     * เรียงตาม: v.vehicle_id DESC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $vehicleSql = "
        SELECT
            v.*,
            c.full_name AS customer_name
        FROM vehicles v
        INNER JOIN customers c
            ON v.customer_id = c.customer_id
        WHERE
            v.license_plate LIKE :search
            OR v.brand LIKE :search
            OR v.model LIKE :search
            OR c.full_name LIKE :search
        ORDER BY v.vehicle_id DESC
    ";

    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $pdo->prepare($vehicleSql);

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([
        ":search" => "%" . $search . "%"
    ]);

} else {

    /*
     * ตรงนี้อ่านข้อมูลจาก vehicles (รถยนต์), customers (ลูกค้า)
     * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
     * เรียงตาม: v.vehicle_id DESC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
    $vehicleSql = "
        SELECT
            v.*,
            c.full_name AS customer_name
        FROM vehicles v
        INNER JOIN customers c
            ON v.customer_id = c.customer_id
        ORDER BY v.vehicle_id DESC
    ";

    $stmt = $pdo->query($vehicleSql);
}

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GROUP VEHICLES BY CUSTOMER
|--------------------------------------------------------------------------
*/

$vehiclesByCustomer = [];

foreach ($vehicles as $vehicle) {

    $customerId = $vehicle["customer_id"];

    if (!isset($vehiclesByCustomer[$customerId])) {
        $vehiclesByCustomer[$customerId] = [];
    }

    $vehiclesByCustomer[$customerId][] = $vehicle;
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

    <title>ข้อมูลลูกค้าและรถยนต์ | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส</title>

<!-- CSS หลัก -->
<link
    rel="stylesheet"
    href="../../assets/css/style.css"
>

<!-- CSS Layout: Sidebar + Topbar -->
<link
    rel="stylesheet"
    href="../../assets/css/admin/sidebar.css"
>

<!-- CSS เฉพาะหน้าลูกค้า -->
<link
    rel="stylesheet"
    href="../../assets/css/admin/customer.css"
    >

    <link rel="stylesheet" href="../../assets/css/confirmation-modal.css">

    <!-- GARAGE THEME: shared navy, gold and warm cream styles -->
    <link rel="stylesheet" href="../../assets/css/garage-theme.css">


</head>

<body>

<!-- =====================================================
     TOP NAVBAR
     ===================================================== -->

<header class="topbar">

    <!-- ชื่ออู่ -->
    <div class="brand">
            <!-- BRAND LOGO: original garage identity -->
            <img class="brand-logo" src="../../assets/img/logo.jpg" alt="" width="48" height="48">
        ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
    </div>


    <!-- ด้านขวา -->
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


        <a
            href="index.php"
            class="active"
        >

            <span class="menu-icon">👥</span>

            <span>ข้อมูลลูกค้า</span>

        </a>

        <a href="../appointments/index.php">

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

    <section class="customer-page">

        <!-- PAGE HEADER -->

        <div class="customer-header garage-page-header">

            <div>

                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-users"></i>
                </span>

                <h1>
                    ข้อมูลลูกค้า / รถยนต์
                </h1>

                <p>
                    จัดการข้อมูลลูกค้าและข้อมูลรถยนต์
                </p>

            </div>


            <button
                class="btn-primary"
                onclick="openCustomerModal()"
            >
                <span class="plus">+</span>
                เพิ่มลูกค้า
            </button>

        </div>


        <?php if (isset($_GET["success"])): ?>

    <div class="alert alert-success">

        <?php if ($_GET["success"] === "customer_added"): ?>

            เพิ่มข้อมูลลูกค้าสำเร็จ

        <?php elseif ($_GET["success"] === "customer_updated"): ?>

            แก้ไขข้อมูลลูกค้าสำเร็จ

        <?php elseif ($_GET["success"] === "vehicle_added"): ?>

            เพิ่มข้อมูลรถยนต์สำเร็จ

        <?php elseif ($_GET["success"] === "vehicle_updated"): ?>

            แก้ไขข้อมูลรถยนต์สำเร็จ

        <?php elseif ($_GET["success"] === "customer_deleted"): ?>

            ลบข้อมูลลูกค้าสำเร็จ

        <?php elseif ($_GET["success"] === "vehicle_deleted"): ?>

            ลบข้อมูลรถยนต์สำเร็จ

        <?php endif; ?>

    </div>

<?php endif; ?>


<?php if (isset($_GET["error"])): ?>

    <div class="alert alert-error">

        <?= htmlspecialchars($_GET["error"]) ?>

    </div>

<?php endif; ?>



        <!-- TABS -->

        <div class="customer-tabs">

            <button
                class="customer-tab <?= $tab === "customers" ? "active" : "" ?>"
                onclick="changeTab('customers')"
            >
                ลูกค้า (<?= count($customers) ?>)
            </button>


            <button
                class="customer-tab <?= $tab === "vehicles" ? "active" : "" ?>"
                onclick="changeTab('vehicles')"
            >
                รถยนต์ (<?= count($vehicles) ?>)
            </button>

        </div>


        <!-- SEARCH -->

        <div class="customer-search-row">

            <form
                method="GET"
                class="customer-search"
            >

                <span class="search-icon">
                    🔍
                </span>

                <input
                    type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="<?= $tab === "vehicles"
                        ? "ค้นหาทะเบียนรถ ยี่ห้อ รุ่น หรือชื่อลูกค้า..."
                        : "ค้นหาชื่อลูกค้าหรือเบอร์โทรศัพท์..." ?>"
                >

                <input
                    type="hidden"
                    name="tab"
                    value="<?= htmlspecialchars($tab) ?>"
                >

            </form>


            <?php if ($search !== ""): ?>

                <a href="index.php?tab=<?= urlencode($tab) ?>" class="clear-search-btn">
                    ✕ ล้างการค้นหา   
                </a>
            <?php endif; ?>

        </div>


        <!-- =================================================
             CUSTOMER TAB
             ================================================= -->

        <?php if ($tab === "customers"): ?>

            <?php if (count($customers) > 0): ?>

                <div class="customer-grid">

                    <?php foreach ($customers as $customer): ?>

                        <?php

                        $customerId =
                            $customer["customer_id"];

                        $customerVehicles =
                            $vehiclesByCustomer[$customerId] ?? [];

                        $initial =
                            mb_substr(
                                $customer["full_name"],
                                0,
                                1
                            );

                        $addressParts = array_filter([
                            $customer["address"] ?? "",
                            $customer["subdistrict"] ?? "",
                            $customer["district"] ?? "",
                            $customer["province"] ?? ""
                        ]);

                        $customerAddress = implode(", ", $addressParts);

                        ?>

                        <div class="customer-card">

    <div class="customer-card-header">

        <div class="customer-top">

            <div class="customer-avatar">
                <?= htmlspecialchars($initial) ?>
            </div>

            <div>

                <div class="customer-name">
                    <?= htmlspecialchars($customer["full_name"]) ?>
                </div>

                <div class="customer-phone">
                    ☎
                    <?= htmlspecialchars($customer["phone"]) ?>
                </div>

            </div>

        </div>


        <button
            type="button"
            class="edit-customer-btn"
            title="แก้ไขข้อมูลลูกค้า"
            onclick='openEditCustomerModal(
                <?= json_encode(
                    $customer,
                    JSON_UNESCAPED_UNICODE
                ) ?>
            )'
        >
            ✏️
        </button>

        <button
            type="button"
            class="delete-customer-btn"
            title="ลบข้อมูลลูกค้า"
            onclick='deleteCustomer(
                <?= $customerId ?>,
                <?= json_encode(
                    $customer["full_name"],
                    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                ) ?>
            )'
        >
            🗑️
        </button>

    </div>

<!-- ADDRESS -->

    <?php if ($customerAddress !== ""): ?>
        <div class="customer-address">📍
            <?= nl2br(
                    htmlspecialchars($customerAddress)) ?>
        </div>                                    
        <?php endif; ?>                                                        
<!-- VEHICLES -->
    <div class="vehicle-section">                        
        <div class="vehicle-header">
            <strong>
                รถยนต์
                (<?= count($customerVehicles) ?>)
            </strong>                                                               
                <button class="add-vehicle-btn" onclick='openVehicleModal(
                        <?= $customerId ?>, <?= json_encode( $customer["full_name"],JSON_UNESCAPED_UNICODE ) ?>
                        )'> + เพิ่มรถ                 
                </button>                            
        </div>                                    
                                <?php if (count($customerVehicles) > 0): ?>

                                    <?php foreach ($customerVehicles as $vehicle): ?>

                                        <div class="vehicle-item">

                                            <span class="vehicle-icon">
                                                🚗
                                            </span>

                                            <span>

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $vehicle["license_plate"]
                                                    ) ?>
                                                </strong>

                                                •
                                                <?= htmlspecialchars(
                                                    $vehicle["brand"]
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    $vehicle["model"]
                                                ) ?>

                                            </span>

                                        </div>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <div class="no-vehicle">
                                        ยังไม่มีข้อมูลรถยนต์
                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="empty-state">
                    ไม่พบข้อมูลลูกค้า
                </div>

            <?php endif; ?>


        <?php else: ?>

    <!-- =================================================
         VEHICLE TAB
         ================================================= -->

    <?php if (count($vehicles) > 0): ?>

        <div class="vehicle-table-container">

            <table class="vehicle-table">

                <thead>

                    <tr>

                        <th>
                            ทะเบียนรถ
                        </th>

                        <th>
                            เจ้าของรถ
                        </th>

                        <th>
                            ยี่ห้อ
                        </th>

                        <th>
                            รุ่น
                        </th>

                        <th>
                            สี
                        </th>

                        <th>
                            เลขไมล์
                        </th>

                        <th>
                            จัดการข้อมูลรถยนต์
                        </th>

                    </tr>

                </thead>

                <tbody>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <tr>
                            <td>
                                <span class="license-plate">
                                    <?= htmlspecialchars(
                                        $vehicle["license_plate"]
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $vehicle["customer_name"]
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $vehicle["brand"]
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $vehicle["model"]
                                ) ?>
                            </td>
                            <td>
                                <?= !empty($vehicle["color"])
                                    ? htmlspecialchars($vehicle["color"])
                                    : "-" ?>
                            </td>
                            <td>
                                <?= number_format(
                                    (int)$vehicle["mileage"]
                                ) ?>
                                กม.
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="edit-vehicle-btn"
                                    title="แก้ไขข้อมูลรถยนต์"
                                    onclick='openEditVehicleModal(
                                        <?= json_encode(
                                            $vehicle,
                                            JSON_UNESCAPED_UNICODE
                                        ) ?> )'>                                   
                                    ✏️
                                </button>
                                <button
                                    type="button"
                                    class="delete-vehicle-btn"
                                    title="ลบข้อมูลรถยนต์"
                                    onclick='deleteVehicle(
                                        <?= $vehicle["vehicle_id"] ?>,
                                        <?= json_encode(
                                            $vehicle["license_plate"],
                                            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                        ) ?>
                                    )'
                                >
                                    🗑️
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <?php if ($search !== ""): ?>
                ไม่พบข้อมูลรถยนต์ที่ค้นหา
            <?php else: ?>
                ยังไม่มีข้อมูลรถยนต์
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

    </section>

</main>

<!-- =====================================================
     ADD CUSTOMER MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="customerModal"
>

    <div class="modal">

        <div class="modal-header">

            <h2>
                เพิ่มลูกค้าใหม่
            </h2>

            <button
                class="modal-close"
                onclick="closeCustomerModal()"
            >
                ×
            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="add_customer"
            >


            <div class="form-group">

                <label>
                    ชื่อ - นามสกุล
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="full_name"
                    required
                    autofocus
                >

            </div>


            <div class="form-group">

                <label>
                    เบอร์โทรศัพท์
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="phone"
                    required
                >

            </div>


            <div class="form-group">

                <label for="addCustomerProvince">
                    จังหวัด
                </label>

                <div class="customer-address-combobox">
                    <input
                        type="text"
                        id="addCustomerProvince"
                        class="customer-address-input"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="addCustomerProvinceOptions"
                        placeholder="เลือกหรือพิมพ์ค้นหาจังหวัด"
                        autocomplete="off"
                        disabled
                    >
                    <input type="hidden" name="province" id="addCustomerProvinceValue">
                    <div
                        class="customer-address-options"
                        id="addCustomerProvinceOptions"
                        role="listbox"
                    ></div>
                </div>

            </div>


            <div class="form-group">

                <label id="addCustomerDistrictLabel" for="addCustomerDistrict">
                    อำเภอ / เขต
                </label>

                <div class="customer-address-combobox" id="addCustomerDistrictWrap">
                    <input
                        type="text"
                        id="addCustomerDistrict"
                        class="customer-address-input"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="addCustomerDistrictOptions"
                        placeholder="เลือกหรือพิมพ์ค้นหาอำเภอ / เขต"
                        autocomplete="off"
                        disabled
                    >
                    <input type="hidden" name="district" id="addCustomerDistrictValue">
                    <div
                        class="customer-address-options"
                        id="addCustomerDistrictOptions"
                        role="listbox"
                    ></div>
                </div>

            </div>


            <div class="form-group">

                <label id="addCustomerSubdistrictLabel" for="addCustomerSubdistrict">
                    ตำบล / แขวง
                </label>

                <div class="customer-address-combobox" id="addCustomerSubdistrictWrap">
                    <input
                        type="text"
                        id="addCustomerSubdistrict"
                        class="customer-address-input"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="addCustomerSubdistrictOptions"
                        placeholder="เลือกหรือพิมพ์ค้นหาตำบล / แขวง"
                        autocomplete="off"
                        disabled
                    >
                    <input type="hidden" name="subdistrict" id="addCustomerSubdistrictValue">
                    <div
                        class="customer-address-options"
                        id="addCustomerSubdistrictOptions"
                        role="listbox"
                    ></div>
                </div>

            </div>


            <div class="form-group">

                <label>
                    รายละเอียดที่อยู่ (บ้านเลขที่ / หมู่ / ซอย / ถนน)
                </label>

                <textarea
                    name="address"
                    placeholder="เช่น 123 หมู่ 4 ซอยสุขใจ ถนนประชาราษฎร์"
                ></textarea>

            </div>


            <div class="form-group">

                <label>
                    หมายเหตุ
                </label>

                <textarea
                    name="note"
                ></textarea>

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeCustomerModal()"
                >
                    ยกเลิก
                </button>

                <button
                    type="submit"
                    class="btn-submit"
                >
                    บันทึกลูกค้า
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     EDIT CUSTOMER MODAL
===================================================== -->

<div
    class="modal-overlay"
    id="editCustomerModal"
>

    <div class="modal">

        <div class="modal-header">

            <h2>
                แก้ไขข้อมูลลูกค้า
            </h2>

            <button
                type="button"
                class="modal-close"
                onclick="closeEditCustomerModal()"
            >
                ×
            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="edit_customer"
            >

            <input
                type="hidden"
                name="customer_id"
                id="editCustomerId"
            >


            <div class="form-group">

                <label>
                    ชื่อ - นามสกุล
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="full_name"
                    id="editCustomerName"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    เบอร์โทรศัพท์
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="phone"
                    id="editCustomerPhone"
                    required
                >

            </div>


            <div class="form-group">

                <label for="editCustomerProvince">
                    จังหวัด
                </label>

                <div class="customer-address-combobox">
                    <input
                        type="text"
                        id="editCustomerProvince"
                        class="customer-address-input"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="editCustomerProvinceOptions"
                        placeholder="เลือกหรือพิมพ์ค้นหาจังหวัด"
                        autocomplete="off"
                        disabled
                    >
                    <input type="hidden" name="province" id="editCustomerProvinceValue">
                    <div
                        class="customer-address-options"
                        id="editCustomerProvinceOptions"
                        role="listbox"
                    ></div>
                </div>

            </div>


            <div class="form-group">

                <label id="editCustomerDistrictLabel" for="editCustomerDistrict">
                    อำเภอ / เขต
                </label>

                <div class="customer-address-combobox" id="editCustomerDistrictWrap">
                    <input
                        type="text"
                        id="editCustomerDistrict"
                        class="customer-address-input"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="editCustomerDistrictOptions"
                        placeholder="เลือกหรือพิมพ์ค้นหาอำเภอ / เขต"
                        autocomplete="off"
                        disabled
                    >
                    <input type="hidden" name="district" id="editCustomerDistrictValue">
                    <div
                        class="customer-address-options"
                        id="editCustomerDistrictOptions"
                        role="listbox"
                    ></div>
                </div>

            </div>


            <div class="form-group">

                <label id="editCustomerSubdistrictLabel" for="editCustomerSubdistrict">
                    ตำบล / แขวง
                </label>

                <div class="customer-address-combobox" id="editCustomerSubdistrictWrap">
                    <input
                        type="text"
                        id="editCustomerSubdistrict"
                        class="customer-address-input"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="editCustomerSubdistrictOptions"
                        placeholder="เลือกหรือพิมพ์ค้นหาตำบล / แขวง"
                        autocomplete="off"
                        disabled
                    >
                    <input type="hidden" name="subdistrict" id="editCustomerSubdistrictValue">
                    <div
                        class="customer-address-options"
                        id="editCustomerSubdistrictOptions"
                        role="listbox"
                    ></div>
                </div>

            </div>


            <div class="form-group">

                <label>
                    รายละเอียดที่อยู่ (บ้านเลขที่ / หมู่ / ซอย / ถนน)
                </label>

                <textarea
                    name="address"
                    id="editCustomerAddress"
                    placeholder="เช่น 123 หมู่ 4 ซอยสุขใจ ถนนประชาราษฎร์"
                ></textarea>

            </div>


            <div class="form-group">

                <label>
                    หมายเหตุ
                </label>

                <textarea
                    name="note"
                    id="editCustomerNote"
                ></textarea>

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeEditCustomerModal()"
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
     ADD VEHICLE MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="vehicleModal"
>

    <div class="modal">

        <div class="modal-header">

            <h2 id="vehicleModalTitle">
                เพิ่มรถยนต์
            </h2>

            <button
                class="modal-close"
                onclick="closeVehicleModal()"
            >
                ×
            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="add_vehicle"
            >

            <input
                type="hidden"
                name="customer_id"
                id="vehicleCustomerId"
            >


            <div class="form-group">

                <label>
                    เลขทะเบียน
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="license_plate"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    ยี่ห้อ
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="brand"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    รุ่น
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="model"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    สี
                </label>

                <input
                    type="text"
                    name="color"
                >

            </div>


            <div class="form-group">

                <label>
                    เลขไมล์
                </label>

                <input
                    type="number"
                    name="mileage"
                    value="0"
                    min="0"
                >

            </div>


            <div class="form-group">

                <label>
                    หมายเหตุ
                </label>

                <textarea
                    name="note"
                ></textarea>

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeVehicleModal()"
                >
                    ยกเลิก
                </button>

                <button
                    type="submit"
                    class="btn-submit"
                >
                    บันทึกรถยนต์
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     EDIT VEHICLE MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="editVehicleModal"
>

    <div class="modal">

        <div class="modal-header">

            <h2>
                แก้ไขข้อมูลรถยนต์
            </h2>

            <button
                type="button"
                class="modal-close"
                onclick="closeEditVehicleModal()"
            >
                ×
            </button>

        </div>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="edit_vehicle"
            >

            <input
                type="hidden"
                name="vehicle_id"
                id="editVehicleId"
            >


            <div class="form-group">

                <label>
                    เลขทะเบียน
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="license_plate"
                    id="editVehicleLicensePlate"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    ยี่ห้อ
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="brand"
                    id="editVehicleBrand"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    รุ่น
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="model"
                    id="editVehicleModel"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    สี
                </label>

                <input
                    type="text"
                    name="color"
                    id="editVehicleColor"
                >

            </div>


            <div class="form-group">

                <label>
                    เลขไมล์
                </label>

                <input
                    type="number"
                    name="mileage"
                    id="editVehicleMileage"
                    min="0"
                >
            </div>

            <div class="form-group">
                <label>
                    หมายเหตุ
                </label>

                <textarea
                    name="note"
                    id="editVehicleNote"
                ></textarea>

            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeEditVehicleModal()" >
                    ยกเลิก
                </button>

                <button type="submit" class="btn-submit" >
                    บันทึกการแก้ไข
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =====================================================
     DELETE CUSTOMER FORM
===================================================== -->

<form
    method="POST"
    id="deleteCustomerForm"
    style="display: none;"
>

    <input
        type="hidden"
        name="action"
        value="delete_customer"
    >

    <input
        type="hidden"
        name="customer_id"
        id="deleteCustomerId"
    >

</form>

<!-- =====================================================
     DELETE VEHICLE FORM
===================================================== -->

<form
    method="POST"
    id="deleteVehicleForm"
    style="display: none;"
>

    <input
        type="hidden"
        name="action"
        value="delete_vehicle"
    >

    <input
        type="hidden"
        name="vehicle_id"
        id="deleteVehicleId"
    >

</form>

<script>

/* =====================================================
   CUSTOMER MODAL
   ===================================================== */

function openCustomerModal() {

    setCustomerAddressLocation("add", "", "", "");

    document
        .getElementById("customerModal")
        .classList.add("show");

}


function closeCustomerModal() {

    document
        .getElementById("customerModal")
        .classList.remove("show");

}


let customerAddressData = [];
const customerAddressForms = {};

const customerAddressDataPromise = fetch("../../assets/data/thailand-address.json")
    .then(function (response) {

        if (!response.ok) {
            throw new Error("ไม่สามารถโหลดข้อมูลพื้นที่ได้");
        }

        return response.json();

    })
    .then(function (data) {

        customerAddressData = data;

        initializeCustomerAddressForm("add");
        initializeCustomerAddressForm("edit");

        document.addEventListener("click", function (event) {

            Object.values(customerAddressForms).forEach(function (form) {

                Object.values(form.combos).forEach(function (combo) {

                    if (!combo.wrapper.contains(event.target)) {
                        closeCustomerAddressCombo(combo);
                    }

                });

            });

        });

        return data;

    })
    .catch(function (error) {

        console.error(error);

        ["add", "edit"].forEach(function (prefix) {

            ["Province", "District", "Subdistrict"].forEach(function (level) {
                document.getElementById(prefix + "Customer" + level).disabled = true;
            });

        });

        return [];

    });


function customerAddressItemName(item) {

    return typeof item === "string" ? item : item.name;
}


function renderCustomerAddressOptions(combo, searchText = "") {

    const normalizedSearch = searchText.trim().toLocaleLowerCase();
    const filteredItems = combo.items.filter(function (item) {
        return customerAddressItemName(item)
            .toLocaleLowerCase()
            .includes(normalizedSearch);
    });

    combo.list.innerHTML = "";
    combo.activeIndex = -1;

    if (filteredItems.length === 0) {

        const emptyOption = document.createElement("div");
        emptyOption.className = "customer-address-no-results";
        emptyOption.textContent = "ไม่พบรายการที่ค้นหา";
        combo.list.appendChild(emptyOption);

        return;
    }

    filteredItems.forEach(function (item, index) {

        const value = customerAddressItemName(item);
        const option = document.createElement("button");
        option.type = "button";
        option.className = "customer-address-option";
        option.id = combo.list.id + "Option" + index;
        option.setAttribute("role", "option");
        option.setAttribute("aria-selected", value === combo.valueInput.value ? "true" : "false");
        option.textContent = value;

        if (value === combo.valueInput.value) {
            option.classList.add("is-selected");
        }

        option.addEventListener("mousedown", function (event) {
            event.preventDefault();
        });

        option.addEventListener("click", function () {
            selectCustomerAddressOption(combo, value);
        });

        combo.list.appendChild(option);

    });
}


function openCustomerAddressCombo(combo) {

    if (combo.input.disabled) {
        return;
    }

    const searchText = combo.valueInput.value !== ""
        && combo.input.value === combo.selectedText
        ? ""
        : combo.input.value;

    renderCustomerAddressOptions(combo, searchText);
    combo.list.classList.add("is-open");
    combo.input.setAttribute("aria-expanded", "true");
}


function closeCustomerAddressCombo(combo) {

    combo.list.classList.remove("is-open");
    combo.input.setAttribute("aria-expanded", "false");
    combo.input.removeAttribute("aria-activedescendant");
    combo.activeIndex = -1;

    if (combo.valueInput.value !== "") {
        combo.input.value = combo.selectedText;
    }
}


function moveCustomerAddressActiveOption(combo, direction) {

    if (!combo.list.classList.contains("is-open")) {
        openCustomerAddressCombo(combo);
    }

    const options = Array.from(
        combo.list.querySelectorAll(".customer-address-option")
    );

    if (options.length === 0) {
        return;
    }

    if (combo.activeIndex === -1) {
        combo.activeIndex = direction > 0 ? 0 : options.length - 1;
    } else {
        combo.activeIndex = (combo.activeIndex + direction + options.length) % options.length;
    }

    options.forEach(function (option, index) {
        option.classList.toggle("is-active", index === combo.activeIndex);
    });

    combo.input.setAttribute(
        "aria-activedescendant",
        options[combo.activeIndex].id
    );

    options[combo.activeIndex].scrollIntoView({ block: "nearest" });
}


function selectCustomerAddressOption(combo, value) {

    const previousValue = combo.valueInput.value;

    combo.valueInput.value = value;
    combo.input.value = value;
    combo.selectedText = value;
    combo.input.setCustomValidity("");

    closeCustomerAddressCombo(combo);

    if (previousValue !== value) {
        combo.onSelect(value);
    }

    syncCustomerAddressRequired(combo.prefix);
}


function clearCustomerAddressCombo(combo) {

    combo.valueInput.value = "";
    combo.input.value = "";
    combo.selectedText = "";
    combo.input.setCustomValidity("");
    closeCustomerAddressCombo(combo);
}


function syncCustomerAddressRequired(prefix) {

    const form = customerAddressForms[prefix];

    if (!form) {
        return;
    }

    const combos = Object.values(form.combos);
    const hasSelection = combos.some(function (combo) {
        return combo.valueInput.value !== "" || combo.input.value.trim() !== "";
    });

    combos.forEach(function (combo) {

        combo.input.required = hasSelection;

        if (hasSelection && combo.valueInput.value === "") {
            combo.input.setCustomValidity("กรุณาเลือกข้อมูลจากรายการ");
        } else {
            combo.input.setCustomValidity("");
        }

    });
}


function createCustomerAddressCombo(prefix, level, placeholder) {

    const input = document.getElementById(prefix + "Customer" + level);
    const valueInput = document.getElementById(prefix + "Customer" + level + "Value");
    const list = document.getElementById(prefix + "Customer" + level + "Options");

    const combo = {
        prefix: prefix,
        level: level,
        input: input,
        valueInput: valueInput,
        list: list,
        wrapper: input.closest(".customer-address-combobox"),
        placeholder: placeholder,
        items: [],
        selectedText: "",
        activeIndex: -1,
        onSelect: function () {},
        onClear: function () {}
    };

    input.addEventListener("focus", function () {

        if (combo.valueInput.value !== "") {
            input.select();
        }

        openCustomerAddressCombo(combo);
    });

    input.addEventListener("click", function () {

        if (!combo.list.classList.contains("is-open")) {
            openCustomerAddressCombo(combo);
        }

    });

    input.addEventListener("input", function () {

        if (combo.valueInput.value !== "" && input.value !== combo.selectedText) {
            combo.valueInput.value = "";
            combo.selectedText = "";
            combo.onClear();
        }

        renderCustomerAddressOptions(combo, input.value);
        combo.list.classList.add("is-open");
        input.setAttribute("aria-expanded", "true");
        syncCustomerAddressRequired(prefix);

    });

    input.addEventListener("keydown", function (event) {

        if (event.key === "ArrowDown") {
            event.preventDefault();

            if (!combo.list.classList.contains("is-open")) {
                openCustomerAddressCombo(combo);
            }

            moveCustomerAddressActiveOption(combo, 1);
        } else if (event.key === "ArrowUp") {
            event.preventDefault();

            if (!combo.list.classList.contains("is-open")) {
                openCustomerAddressCombo(combo);
            }

            moveCustomerAddressActiveOption(combo, -1);
        } else if (event.key === "Enter" && combo.list.classList.contains("is-open")) {

            event.preventDefault();

            const options = Array.from(
                combo.list.querySelectorAll(".customer-address-option")
            );

            if (combo.activeIndex >= 0 && options[combo.activeIndex]) {
                options[combo.activeIndex].click();
            } else if (options.length === 1) {
                options[0].click();
            }

        } else if (event.key === "Escape") {
            closeCustomerAddressCombo(combo);
        }

    });

    input.addEventListener("blur", function () {

        window.setTimeout(function () {
            closeCustomerAddressCombo(combo);
        }, 120);

    });

    return combo;
}


function initializeCustomerAddressForm(prefix) {

    const province = createCustomerAddressCombo(
        prefix,
        "Province",
        "เลือกหรือพิมพ์ค้นหาจังหวัด"
    );
    const district = createCustomerAddressCombo(
        prefix,
        "District",
        "เลือกหรือพิมพ์ค้นหาอำเภอ / เขต"
    );
    const subdistrict = createCustomerAddressCombo(
        prefix,
        "Subdistrict",
        "เลือกหรือพิมพ์ค้นหาตำบล / แขวง"
    );

    customerAddressForms[prefix] = {
        combos: {
            province: province,
            district: district,
            subdistrict: subdistrict
        }
    };

    province.items = customerAddressData;
    province.input.disabled = false;

    province.onClear = function () {
        updateCustomerAddressDistricts(prefix, "");
    };

    province.onSelect = function (value) {
        updateCustomerAddressDistricts(prefix, value);
    };

    district.onClear = function () {
        updateCustomerAddressSubdistricts(prefix, "");
    };

    district.onSelect = function (value) {
        updateCustomerAddressSubdistricts(prefix, value);
    };

    subdistrict.onClear = function () {
        syncCustomerAddressRequired(prefix);
    };

    subdistrict.onSelect = function () {
        syncCustomerAddressRequired(prefix);
    };

    syncCustomerAddressRequired(prefix);
}


function updateCustomerAddressDistricts(prefix, provinceName) {

    const form = customerAddressForms[prefix];
    const district = form.combos.district;
    const subdistrict = form.combos.subdistrict;
    const selectedProvince = customerAddressData.find(function (item) {
        return item.name === provinceName;
    });
    const isBangkok = provinceName === "กรุงเทพมหานคร";

    document.getElementById(prefix + "CustomerDistrictLabel").textContent =
        isBangkok ? "เขต" : "อำเภอ";

    document.getElementById(prefix + "CustomerSubdistrictLabel").textContent =
        isBangkok ? "แขวง" : "ตำบล";

    district.input.placeholder = isBangkok
        ? "เลือกหรือพิมพ์ค้นหาเขต"
        : "เลือกหรือพิมพ์ค้นหาอำเภอ";

    subdistrict.input.placeholder = isBangkok
        ? "เลือกหรือพิมพ์ค้นหาแขวง"
        : "เลือกหรือพิมพ์ค้นหาตำบล";

    district.items = selectedProvince ? selectedProvince.districts : [];
    district.input.disabled = !selectedProvince;

    clearCustomerAddressCombo(district);
    updateCustomerAddressSubdistricts(prefix, "");
    syncCustomerAddressRequired(prefix);
}


function updateCustomerAddressSubdistricts(prefix, districtName) {

    const form = customerAddressForms[prefix];
    const provinceName = form.combos.province.valueInput.value;
    const district = form.combos.district;
    const subdistrict = form.combos.subdistrict;
    const selectedProvince = customerAddressData.find(function (item) {
        return item.name === provinceName;
    });
    const selectedDistrict = selectedProvince
        ? selectedProvince.districts.find(function (item) {
            return item.name === districtName;
        })
        : null;

    subdistrict.items = selectedDistrict ? selectedDistrict.subdistricts : [];
    subdistrict.input.disabled = !selectedDistrict;

    clearCustomerAddressCombo(subdistrict);
    syncCustomerAddressRequired(prefix);
}


function setCustomerAddressLocation(prefix, provinceName, districtName, subdistrictName) {

    customerAddressDataPromise.then(function () {

        const form = customerAddressForms[prefix];

        if (!form) {
            return;
        }

        const province = form.combos.province;
        const district = form.combos.district;
        const subdistrict = form.combos.subdistrict;

        clearCustomerAddressCombo(province);
        clearCustomerAddressCombo(district);
        clearCustomerAddressCombo(subdistrict);

        if (provinceName) {
            province.valueInput.value = provinceName;
            province.input.value = provinceName;
            province.selectedText = provinceName;
        }

        updateCustomerAddressDistricts(prefix, provinceName || "");

        if (districtName) {
            district.valueInput.value = districtName;
            district.input.value = districtName;
            district.selectedText = districtName;
            updateCustomerAddressSubdistricts(prefix, districtName);
        }

        if (subdistrictName) {
            subdistrict.valueInput.value = subdistrictName;
            subdistrict.input.value = subdistrictName;
            subdistrict.selectedText = subdistrictName;
        }

        syncCustomerAddressRequired(prefix);

    });
}


/* =====================================================
   VEHICLE MODAL
   ===================================================== */

function openVehicleModal(customerId, customerName) {

    document
        .getElementById("vehicleCustomerId")
        .value = customerId;

    document
        .getElementById("vehicleModalTitle")
        .textContent =
        "เพิ่มรถยนต์ให้กับ " + customerName;

    document
        .getElementById("vehicleModal")
        .classList.add("show");

}


function closeVehicleModal() {

    document
        .getElementById("vehicleModal")
        .classList.remove("show");

}

/* =====================================================
   EDIT CUSTOMER MODAL
===================================================== */

function openEditCustomerModal(customer) {

    document.getElementById("editCustomerId").value =
        customer.customer_id;

    document.getElementById("editCustomerName").value =
        customer.full_name || "";

    document.getElementById("editCustomerPhone").value =
        customer.phone || "";

    document.getElementById("editCustomerAddress").value =
        customer.address || "";

    setCustomerAddressLocation(
        "edit",
        customer.province || "",
        customer.district || "",
        customer.subdistrict || ""
    );

    document.getElementById("editCustomerNote").value =
        customer.note || "";

    document
        .getElementById("editCustomerModal")
        .classList.add("show");
}


function closeEditCustomerModal() {

    document
        .getElementById("editCustomerModal")
        .classList.remove("show");
}   

/* =====================================================
   EDIT VEHICLE MODAL
===================================================== */

function openEditVehicleModal(vehicle) {

    document.getElementById("editVehicleId").value =
        vehicle.vehicle_id;

    document.getElementById("editVehicleLicensePlate").value =
        vehicle.license_plate || "";

    document.getElementById("editVehicleBrand").value =
        vehicle.brand || "";

    document.getElementById("editVehicleModel").value =
        vehicle.model || "";

    document.getElementById("editVehicleColor").value =
        vehicle.color || "";

    document.getElementById("editVehicleMileage").value =
        vehicle.mileage || 0;

    document.getElementById("editVehicleNote").value =
        vehicle.note || "";

    document
        .getElementById("editVehicleModal")
        .classList.add("show");
}


function closeEditVehicleModal() {

    document
        .getElementById("editVehicleModal")
        .classList.remove("show");

}

/* =====================================================
   CLOSE MODAL WHEN CLICK OUTSIDE
   ===================================================== */

document
    .getElementById("customerModal")
    .addEventListener("click", function(event) {

        if (event.target === this) {
            closeCustomerModal();
        }

    });

    document
        .getElementById("vehicleModal")
        .addEventListener("click", function(event) {

            if (event.target === this) {
                closeVehicleModal();
            }

        });

    document
        .getElementById("editCustomerModal")
        .addEventListener("click", function(event) {

            if (event.target === this) {
                closeEditCustomerModal();
            }
        });

    document
        .getElementById("editVehicleModal")
        .addEventListener("click", function(event) {

            if (event.target === this) {
                closeEditVehicleModal();
            }

        });


/* =====================================================
   ESC TO CLOSE MODAL
   ===================================================== */

document.addEventListener("keydown", function(event) {

    if (event.key === "Escape") {

        closeCustomerModal();

        closeVehicleModal();

        closeEditVehicleModal();

    }

});

document.addEventListener("keydown", function(event) {

    if (event.key === "Escape") {

        closeCustomerModal();

        closeEditCustomerModal();

        closeVehicleModal();

        closeEditVehicleModal();

    }

});

/* =====================================================
   CHANGE TAB
   ===================================================== */

function changeTab(tab) {

    const search =
        new URLSearchParams(
            window.location.search
        ).get("search") || "";

    let url =
        "index.php?tab=" +
        encodeURIComponent(tab);

    if (search !== "") {

        url +=
            "&search=" +
            encodeURIComponent(search);

    }

    window.location.href = url;

}

/* =====================================================
   DELETE CUSTOMER
===================================================== */

function deleteCustomer(customerId, customerName) {

    openConfirmation({
        title: "ลบข้อมูลลูกค้า",
        message: "ระบบจะลบข้อมูลลูกค้าออกจากระบบ กรุณาตรวจสอบก่อนยืนยัน",
        button: "ลบลูกค้า",
        danger: true,
        details: {
            "ชื่อลูกค้า": customerName
        }
    }, function () {

        document.getElementById("deleteCustomerId").value =
            customerId;

        document
            .getElementById("deleteCustomerForm")
            .submit();
    });
}

/* =====================================================
   DELETE VEHICLE
===================================================== */

function deleteVehicle(vehicleId, licensePlate) {

    openConfirmation({
        title: "ลบข้อมูลรถยนต์",
        message: "ข้อมูลรถยนต์จะถูกลบออกจากระบบ กรุณาตรวจสอบก่อนยืนยัน",
        button: "ลบรถยนต์",
        danger: true,
        details: {
            "ทะเบียนรถ": licensePlate
        }
    }, function () {

        document.getElementById("deleteVehicleId").value =
            vehicleId;

        document
            .getElementById("deleteVehicleForm")
            .submit();
    });
}

</script>


<?php require __DIR__ . "/../../includes/confirmation_modal.php"; ?>

<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>

</html>
