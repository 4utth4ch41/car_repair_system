<?php

require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";

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

            if ($full_name === "" || $phone === "") {
                throw new Exception("กรุณากรอกชื่อ-สกุลและเบอร์โทรศัพท์");
            }

            $sql = "
                INSERT INTO customers
                (full_name, phone, address, note)
                VALUES
                (:full_name, :phone, :address, :note)
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                ":full_name" => $full_name,
                ":phone"     => $phone,
                ":address"   => $address,
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

                if ($customer_id <= 0) {
                    throw new Exception("ไม่พบข้อมูลลูกค้า");
                }

                if ($full_name === "" || $phone === "") {
                    throw new Exception("กรุณากรอกชื่อ-สกุลและเบอร์โทรศัพท์");
                }

                $sql = "
                    UPDATE customers
                    SET
                        full_name = :full_name,
                        phone = :phone,
                        address = :address,
                        note = :note
                    WHERE customer_id = :customer_id
                ";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    ":full_name"   => $full_name,
                    ":phone"       => $phone,
                    ":address"     => $address,
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

            $stmt = $pdo->prepare($sql);

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

            $stmt = $pdo->prepare($sql);

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
    } catch (Exception $e) {

        header(
            "Location: index.php?error=" .
            urlencode($e->getMessage())
        );

        exit;
    }
}
/* =========================
   DELETE CUSTOMER
   ========================= */

if ($action === "delete_customer") {

    $customer_id = intval($_POST["customer_id"] ?? 0);

    if ($customer_id <= 0) {
        throw new Exception("ไม่พบข้อมูลลูกค้า");
    }

    /*
     * ตรวจสอบก่อนว่าลูกค้ามีรถอยู่หรือไม่
     */

    $checkSql = "
        SELECT COUNT(*)
        FROM vehicles
        WHERE customer_id = :customer_id
    ";

    $checkStmt = $pdo->prepare($checkSql);

    $checkStmt->execute([
        ":customer_id" => $customer_id
    ]);

    $vehicleCount = (int) $checkStmt->fetchColumn();

    if ($vehicleCount > 0) {

        throw new Exception(
            "ไม่สามารถลบลูกค้าได้ เนื่องจากลูกค้ายังมีรถยนต์อยู่ในระบบ"
        );

    }
/* =========================
   DELETE VEHICLE
   ========================= */

if ($action === "delete_vehicle") {

    $vehicle_id = intval($_POST["vehicle_id"] ?? 0);

    if ($vehicle_id <= 0) {
        throw new Exception("ไม่พบข้อมูลรถยนต์");
    }


    /*
     * ลบข้อมูลรถยนต์
     */

    $sql = "
        DELETE FROM vehicles
        WHERE vehicle_id = :vehicle_id
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":vehicle_id" => $vehicle_id
    ]);


    header(
        "Location: index.php?tab=vehicles&success=vehicle_deleted"
    );

    exit;
}

    /*
     * ลบข้อมูลลูกค้า
     */

    $sql = "
        DELETE FROM customers
        WHERE customer_id = :customer_id
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":customer_id" => $customer_id
    ]);


    header(
        "Location: index.php?success=customer_deleted"
    );

    exit;
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

    $customerSql = "
        SELECT *
        FROM customers
        WHERE
            full_name LIKE :search
            OR phone LIKE :search
        ORDER BY customer_id DESC
    ";

    $stmt = $pdo->prepare($customerSql);

    $stmt->execute([
        ":search" => "%" . $search . "%"
    ]);

} else {

    $customerSql = "
        SELECT *
        FROM customers
        ORDER BY customer_id DESC
    ";

    $stmt = $pdo->query($customerSql);
}

$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GET VEHICLES
|--------------------------------------------------------------------------
*/

if ($search !== "") {

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

    $stmt = $pdo->prepare($vehicleSql);

    $stmt->execute([
        ":search" => "%" . $search . "%"
    ]);

} else {

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

    <title>ข้อมูลลูกค้าและรถยนต์ | P.Chalermchai Car Service</title>

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


</head>

<body>

<!-- =====================================================
     TOP NAVBAR
     ===================================================== -->

<header class="topbar">

    <!-- ชื่ออู่ -->
    <div class="brand">
        P.Chalermchai Car Service
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

    <section class="customer-page">

        <!-- PAGE HEADER -->

        <div class="customer-header">

            <div>

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
                    JSON_UNESCAPED_UNICODE
                ) ?>
            )'
        >
            🗑️
        </button>

    </div>

<!-- ADDRESS -->

    <?php if (!empty($customer["address"])): ?>
        <div class="customer-address">📍
            <?= nl2br(
                    htmlspecialchars($customer["address"])) ?>
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
                                            JSON_UNESCAPED_UNICODE
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

                <label>
                    ที่อยู่
                </label>

                <textarea
                    name="address"
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

                <label>
                    ที่อยู่
                </label>

                <textarea
                    name="address"
                    id="editCustomerAddress"
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

    document
        .getElementById("customerModal")
        .classList.add("show");

}


function closeCustomerModal() {

    document
        .getElementById("customerModal")
        .classList.remove("show");

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

    const confirmed = confirm(
        "คุณต้องการลบลูกค้า \"" +
        customerName +
        "\" หรือไม่?\n\n" +
        "ระบบจะลบข้อมูลลูกค้าออกจากระบบ"
    );

    if (!confirmed) {
        return;
    }


    document.getElementById("deleteCustomerId").value =
        customerId;

    document
        .getElementById("deleteCustomerForm")
        .submit();
}

/* =====================================================
   DELETE VEHICLE
===================================================== */

function deleteVehicle(vehicleId, licensePlate) {

    const confirmed = confirm(
        "คุณต้องการลบรถทะเบียน \"" +
        licensePlate +
        "\" หรือไม่?\n\n" +
        "ข้อมูลรถยนต์จะถูกลบออกจากระบบ"
    );

    if (!confirmed) {
        return;
    }


    document.getElementById("deleteVehicleId").value =
        vehicleId;

    document
        .getElementById("deleteVehicleForm")
        .submit();
}

</script>


</body>

</html>