<?php

require_once "../../config/database.php";
require_once "../../includes/admin_auth.php";


// =====================================================
// HELPER
// =====================================================

function e($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function money($value)
{
    return number_format((float)$value, 2);
}


// =====================================================
// ACTION
// =====================================================

$success = "";
$error = "";


// =====================================================
// CREATE INVOICE
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["action"])
    && $_POST["action"] === "create_invoice") {

    try {

        $repair_order_id = (int)($_POST["repair_order_id"] ?? 0);

        $service_amount = (float)($_POST["service_amount"] ?? 0);
        $parts_amount   = (float)($_POST["parts_amount"] ?? 0);
        $labor_amount   = (float)($_POST["labor_amount"] ?? 0);

        $issue_date = $_POST["issue_date"] ?? date("Y-m-d");

        $due_date = !empty($_POST["due_date"])
            ? $_POST["due_date"]
            : null;

        $status = $_POST["status"] ?? "unpaid";

        $note = trim($_POST["note"] ?? "");

        if ($repair_order_id <= 0) {
            throw new Exception("กรุณาเลือกใบงานซ่อม");
        }

        if ($service_amount < 0 ||
            $parts_amount < 0 ||
            $labor_amount < 0) {

            throw new Exception("จำนวนเงินไม่ถูกต้อง");
        }

        if (!in_array($status, ["unpaid", "paid"])) {
            throw new Exception("สถานะไม่ถูกต้อง");
        }


        // -------------------------------------------------
        // ตรวจสอบ Repair Job
        // -------------------------------------------------

        $stmt = $pdo->prepare("
            SELECT
                r.repair_order_id,
                r.repair_order_code,
                r.customer_id,
                c.full_name
            FROM repair_jobs r
            INNER JOIN customers c
                ON r.customer_id = c.customer_id
            WHERE r.repair_order_id = :repair_order_id
            LIMIT 1
        ");

        $stmt->execute([
            ":repair_order_id" => $repair_order_id
        ]);

        $repair_job = $stmt->fetch();


        if (!$repair_job) {
            throw new Exception("ไม่พบใบงานซ่อมที่เลือก");
        }


        // -------------------------------------------------
        // ตรวจสอบว่ามี Invoice แล้วหรือยัง
        // -------------------------------------------------

        $stmt = $pdo->prepare("
            SELECT invoice_id
            FROM invoices
            WHERE repair_order_id = :repair_order_id
            LIMIT 1
        ");

        $stmt->execute([
            ":repair_order_id" => $repair_order_id
        ]);

        if ($stmt->fetch()) {
            throw new Exception("ใบงานซ่อมนี้มีใบแจ้งหนี้แล้ว");
        }


        // -------------------------------------------------
        // คำนวณยอดรวม
        // -------------------------------------------------

        $total_amount =
            $service_amount
            + $parts_amount
            + $labor_amount;


        // -------------------------------------------------
        // สร้างเลข Invoice
        // -------------------------------------------------

        $stmt = $pdo->query("
            SELECT invoice_no
            FROM invoices
            ORDER BY invoice_id DESC
            LIMIT 1
        ");

        $last_invoice = $stmt->fetch();

        if ($last_invoice) {

            preg_match(
                '/(\d+)$/',
                $last_invoice["invoice_no"],
                $matches
            );

            $next_number =
                isset($matches[1])
                ? ((int)$matches[1] + 1)
                : 1;

        } else {

            $next_number = 1;
        }

        $invoice_no =
            "INV-" . str_pad(
                $next_number,
                6,
                "0",
                STR_PAD_LEFT
            );


        // -------------------------------------------------
        // ถ้าสร้างเป็น Paid
        // -------------------------------------------------

        $paid_at = null;

        if ($status === "paid") {
            $paid_at = date("Y-m-d H:i:s");
        }


        // -------------------------------------------------
        // INSERT
        // -------------------------------------------------

        $stmt = $pdo->prepare("
            INSERT INTO invoices (
                invoice_no,
                repair_order_id,
                customer_id,
                service_amount,
                parts_amount,
                labor_amount,
                total_amount,
                issue_date,
                due_date,
                status,
                paid_at,
                note
            )
            VALUES (
                :invoice_no,
                :repair_order_id,
                :customer_id,
                :service_amount,
                :parts_amount,
                :labor_amount,
                :total_amount,
                :issue_date,
                :due_date,
                :status,
                :paid_at,
                :note
            )
        ");

        $stmt->execute([

            ":invoice_no" =>
                $invoice_no,

            ":repair_order_id" =>
                $repair_order_id,

            ":customer_id" =>
                $repair_job["customer_id"],

            ":service_amount" =>
                $service_amount,

            ":parts_amount" =>
                $parts_amount,

            ":labor_amount" =>
                $labor_amount,

            ":total_amount" =>
                $total_amount,

            ":issue_date" =>
                $issue_date,

            ":due_date" =>
                $due_date,

            ":status" =>
                $status,

            ":paid_at" =>
                $paid_at,

            ":note" =>
                $note
        ]);


        header("Location: index.php?success=created");
        exit;


    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}


// =====================================================
// MARK PAID
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["action"])
    && $_POST["action"] === "mark_paid") {

    try {

        $invoice_id =
            (int)($_POST["invoice_id"] ?? 0);

        if ($invoice_id <= 0) {
            throw new Exception("ข้อมูลใบแจ้งหนี้ไม่ถูกต้อง");
        }


        $stmt = $pdo->prepare("
            UPDATE invoices
            SET
                status = 'paid',
                paid_at = CURRENT_TIMESTAMP
            WHERE invoice_id = :invoice_id
            AND status <> 'paid'
        ");

        $stmt->execute([
            ":invoice_id" => $invoice_id
        ]);


        header("Location: index.php?success=paid");
        exit;


    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}


// =====================================================
// DELETE INVOICE
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["action"])
    && $_POST["action"] === "delete_invoice") {

    try {

        $invoice_id =
            (int)($_POST["invoice_id"] ?? 0);

        if ($invoice_id <= 0) {
            throw new Exception("ข้อมูลใบแจ้งหนี้ไม่ถูกต้อง");
        }


        $stmt = $pdo->prepare("
            DELETE FROM invoices
            WHERE invoice_id = :invoice_id
        ");

        $stmt->execute([
            ":invoice_id" => $invoice_id
        ]);


        header("Location: index.php?success=deleted");
        exit;


    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}


// =====================================================
// SUCCESS MESSAGE
// =====================================================

if (isset($_GET["success"])) {

    switch ($_GET["success"]) {

        case "created":
            $success = "สร้างใบแจ้งหนี้เรียบร้อยแล้ว";
            break;

        case "paid":
            $success = "บันทึกการชำระเงินเรียบร้อยแล้ว";
            break;

        case "deleted":
            $success = "ลบใบแจ้งหนี้เรียบร้อยแล้ว";
            break;
    }
}


// =====================================================
// SEARCH / FILTER
// =====================================================

$search =
    trim($_GET["search"] ?? "");

$status_filter =
    $_GET["status"] ?? "";


// =====================================================
// DASHBOARD SUMMARY
// =====================================================

$stmt = $pdo->query("
    SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN issue_date = CURDATE()
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS daily_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN YEARWEEK(issue_date, 1)
                         = YEARWEEK(CURDATE(), 1)
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS weekly_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN YEAR(issue_date) = YEAR(CURDATE())
                    AND MONTH(issue_date) = MONTH(CURDATE())
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS monthly_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'unpaid'
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS outstanding

    FROM invoices
    WHERE status <> 'overdue'
");

$summary = $stmt->fetch();


// =====================================================
// LOAD REPAIR JOBS
// =====================================================

$stmt = $pdo->query("
    SELECT
        r.repair_order_id,
        r.repair_order_code,
        r.customer_id,
        c.full_name,
        r.status

    FROM repair_jobs r

    INNER JOIN customers c
        ON r.customer_id = c.customer_id

    WHERE r.status = 'completed'

    AND NOT EXISTS (
        SELECT 1
        FROM invoices i
        WHERE i.repair_order_id = r.repair_order_id
    )

    ORDER BY r.repair_order_id DESC
");

$repair_jobs =
    $stmt->fetchAll();


// =====================================================
// LOAD INVOICES
// =====================================================

$sql = "
    SELECT
        i.*,

        r.repair_order_code,

        c.full_name

    FROM invoices i

    INNER JOIN repair_jobs r
        ON i.repair_order_id = r.repair_order_id

    INNER JOIN customers c
        ON i.customer_id = c.customer_id

    WHERE 1 = 1
";

$params = [];


// Search customer

if ($search !== "") {

    $sql .= "
        AND c.full_name LIKE :search
    ";

    $params[":search"] =
        "%" . $search . "%";
}


// Filter status

if (
    in_array(
        $status_filter,
        ["unpaid", "paid", "overdue"]
    )
) {

    $sql .= "
        AND i.status = :status
    ";

    $params[":status"] =
        $status_filter;
}


$sql .= "
    ORDER BY i.invoice_id DESC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$invoices =
    $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>การเงิน | P.Chalermchai Car Service</title>


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


    <!-- CSS Financial -->

    <link
        rel="stylesheet"
        href="../../assets/css/admin/financial.css"
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
                <?= e($_SESSION["username"]) ?>
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


        <a href="../repair_jobs/index.php">

            <span class="menu-icon">🔧</span>

            <span>ใบงานซ่อม</span>

        </a>


        <a href="../technician/index.php">

            <span class="menu-icon">👨‍🔧</span>

            <span>จัดการช่าง</span>

        </a>


        <a
            href="../financial/index.php"
            class="active"
        >

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

    <section class="financial-page">


        <!-- =================================================
             HEADER
             ================================================= -->

        <div class="financial-header">

            <div>

                <h1>
                    การจัดการการเงิน
                </h1>

                <p>
                    สรุปค่าบริการและติดตามสถานะการชำระ
                </p>

            </div>


            <button
                type="button"
                class="financial-add-btn"
                onclick="openInvoiceModal()"
            >

                <span>+</span>

                เพิ่มใบแจ้งหนี้

            </button>

        </div>


        <!-- =================================================
             MESSAGE
             ================================================= -->

        <?php if ($success !== ""): ?>

            <div class="alert success">
                <?= e($success) ?>
            </div>

        <?php endif; ?>


        <?php if ($error !== ""): ?>

            <div class="alert error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>


        <!-- =================================================
             SUMMARY
             ================================================= -->

        <div class="financial-summary">


            <!-- DAILY -->

            <div class="summary-card">

                <div>

                    <span class="summary-label">
                        รายได้วันนี้
                    </span>

                    <strong>
                        ฿<?= money($summary["daily_revenue"]) ?>
                    </strong>

                </div>

                <div class="summary-icon green">
                    $
                </div>

            </div>


            <!-- WEEKLY -->

            <div class="summary-card">

                <div>

                    <span class="summary-label">
                        รายได้สัปดาห์นี้
                    </span>

                    <strong>
                        ฿<?= money($summary["weekly_revenue"]) ?>
                    </strong>

                </div>

                <div class="summary-icon blue">
                    ↗
                </div>

            </div>


            <!-- MONTHLY -->

            <div class="summary-card">

                <div>

                    <span class="summary-label">
                        รายได้เดือนนี้
                    </span>

                    <strong>
                        ฿<?= money($summary["monthly_revenue"]) ?>
                    </strong>

                </div>

                <div class="summary-icon purple">
                    ↗
                </div>

            </div>


            <!-- OUTSTANDING -->

            <div class="summary-card">

                <div>

                    <span class="summary-label">
                        ยอดค้างชำระ
                    </span>

                    <strong>
                        ฿<?= money($summary["outstanding"]) ?>
                    </strong>

                </div>

                <div class="summary-icon red">
                    $
                </div>

            </div>


        </div>


        <!-- =================================================
             SEARCH / FILTER
             ================================================= -->

        <form
            method="GET"
            class="invoice-filter"
        >


            <div class="search-box">

                <span>⌕</span>

                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="ค้นหาชื่อลูกค้า..."
                >

            </div>


            <select
                name="status"
                onchange="this.form.submit()"
            >

                <option
                    value=""
                    <?= $status_filter === ""
                        ? "selected"
                        : "" ?>
                >
                    สถานะทั้งหมด
                </option>

                <option
                    value="unpaid"
                    <?= $status_filter === "unpaid"
                        ? "selected"
                        : "" ?>
                >
                    ยังไม่ชำระ
                </option>

                <option
                    value="paid"
                    <?= $status_filter === "paid"
                        ? "selected"
                        : "" ?>
                >
                    ชำระแล้ว
                </option>

                <option
                    value="overdue"
                    <?= $status_filter === "overdue"
                        ? "selected"
                        : "" ?>
                >
                    เกินกำหนด
                </option>

            </select>


            <?php if ($search !== ""): ?>

                <a
                    href="index.php"
                    class="clear-filter"
                >
                    ล้าง
                </a>

            <?php endif; ?>

        </form>


        <!-- =================================================
             INVOICE TABLE
             ================================================= -->

        <div class="invoice-table-container">

            <table class="invoice-table">

                <thead>

                    <tr>

                        <th>
                            เลขที่ใบแจ้งหนี้
                        </th>

                        <th>
                            ลูกค้า
                        </th>

                        <th>
                            ใบงาน
                        </th>

                        <th>
                            วันที่ออก
                        </th>

                        <th>
                            ยอดรวม
                        </th>

                        <th>
                            สถานะ
                        </th>

                        <th>
                            จัดการ
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if (count($invoices) > 0): ?>

                    <?php foreach ($invoices as $invoice): ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= e($invoice["invoice_no"]) ?>
                                </strong>

                            </td>


                            <td>
                                <?= e($invoice["full_name"]) ?>
                            </td>


                            <td>
                                <?= e($invoice["repair_order_code"]) ?>
                            </td>


                            <td>

                                <?= date(
                                    "d/m/Y",
                                    strtotime($invoice["issue_date"])
                                ) ?>

                            </td>


                            <td>

                                <strong>
                                    ฿<?= money(
                                        $invoice["total_amount"]
                                    ) ?>
                                </strong>

                            </td>


                            <td>

                                <?php if ($invoice["status"] === "paid"): ?>

                                    <span class="status-badge paid">
                                        ชำระแล้ว
                                    </span>

                                <?php elseif ($invoice["status"] === "overdue"): ?>

                                    <span class="status-badge overdue">
                                        เกินกำหนด
                                    </span>

                                <?php else: ?>

                                    <span class="status-badge unpaid">
                                        ยังไม่ชำระ
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="invoice-actions">


                                    <?php if ($invoice["status"] !== "paid"): ?>

                                        <form
                                            method="POST"
                                            onsubmit="
                                                return confirm(
                                                    'ยืนยันว่าลูกค้าชำระเงินแล้วใช่หรือไม่?'
                                                );
                                            "
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="mark_paid"
                                            >

                                            <input
                                                type="hidden"
                                                name="invoice_id"
                                                value="<?= $invoice["invoice_id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="mark-paid-btn"
                                            >
                                                ✓ รับชำระ
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <button
                                        type="button"
                                        class="delete-btn"
                                        onclick="
                                            deleteInvoice(
                                                <?= $invoice["invoice_id"] ?>
                                            )
                                        "
                                    >
                                        🗑
                                    </button>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="7"
                            class="empty-table"
                        >

                            ยังไม่มีข้อมูลใบแจ้งหนี้

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


    </section>

</main>


<!-- =====================================================
     CREATE INVOICE MODAL
     ===================================================== -->

<div
    id="invoiceModal"
    class="modal-overlay"
    onclick="closeModalOutside(event)"
>


    <div
        class="invoice-modal"
        onclick="event.stopPropagation()"
    >


        <div class="modal-header">

            <h2>
                สร้างใบแจ้งหนี้ใหม่
            </h2>

            <button
                type="button"
                class="modal-close"
                onclick="closeInvoiceModal()"
            >
                ×
            </button>

        </div>


        <form
            method="POST"
            id="invoiceForm"
        >

            <input
                type="hidden"
                name="action"
                value="create_invoice"
            >


            <!-- =============================================
                 REPAIR JOB
                 ============================================= -->

            <div class="form-group">

                <label>
                    เชื่อมกับใบงานซ่อม
                    <span>*</span>
                </label>


                <select
                    name="repair_order_id"
                    id="repairOrderSelect"
                    required
                    onchange="loadCustomer(this)"
                >

                    <option value="">
                        เลือกใบงานที่ซ่อมเสร็จแล้ว
                    </option>


                    <?php foreach ($repair_jobs as $job): ?>

                        <option
                            value="<?= $job["repair_order_id"] ?>"
                            data-customer="<?= e(
                                $job["full_name"]
                            ) ?>"
                        >

                            <?= e(
                                $job["repair_order_code"]
                            ) ?>

                            —
                            <?= e(
                                $job["full_name"]
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- =============================================
                 CUSTOMER
                 ============================================= -->

            <div class="form-group">

                <label>
                    ชื่อลูกค้า
                </label>

                <input
                    type="text"
                    id="customerName"
                    readonly
                    placeholder="ระบบจะแสดงอัตโนมัติ"
                >

            </div>


            <!-- =============================================
                 AMOUNT
                 ============================================= -->

            <div class="amount-grid">


                <div class="form-group">

                    <label>
                        ค่าบริการ
                    </label>

                    <input
                        type="number"
                        name="service_amount"
                        id="serviceAmount"
                        value="0"
                        min="0"
                        step="0.01"
                        oninput="calculateTotal()"
                    >

                </div>


                <div class="form-group">

                    <label>
                        ค่าอะไหล่
                    </label>

                    <input
                        type="number"
                        name="parts_amount"
                        id="partsAmount"
                        value="0"
                        min="0"
                        step="0.01"
                        oninput="calculateTotal()"
                    >

                </div>


                <div class="form-group">

                    <label>
                        ค่าแรงช่าง
                    </label>

                    <input
                        type="number"
                        name="labor_amount"
                        id="laborAmount"
                        value="0"
                        min="0"
                        step="0.01"
                        oninput="calculateTotal()"
                    >

                </div>

            </div>


            <!-- =============================================
                 TOTAL
                 ============================================= -->

            <div class="invoice-total">

                <span>
                    ยอดรวม
                </span>

                <strong id="totalDisplay">
                    ฿0.00
                </strong>

            </div>


            <!-- =============================================
                 DATES
                 ============================================= -->

            <div class="date-grid">


                <div class="form-group">

                    <label>
                        วันที่ออกใบแจ้งหนี้
                        <span>*</span>
                    </label>

                    <input
                        type="date"
                        name="issue_date"
                        value="<?= date("Y-m-d") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        วันครบกำหนด
                    </label>

                    <input
                        type="date"
                        name="due_date"
                    >

                </div>


            </div>


            <!-- =============================================
                 STATUS
                 ============================================= -->

            <div class="form-group">

                <label>
                    สถานะ
                </label>

                <select
                    name="status"
                >

                    <option value="unpaid">
                        ยังไม่ชำระ
                    </option>

                    <option value="paid">
                        ชำระแล้ว
                    </option>

                </select>

            </div>


            <!-- =============================================
                 NOTE
                 ============================================= -->

            <div class="form-group">

                <label>
                    หมายเหตุ
                </label>

                <textarea
                    name="note"
                    rows="3"
                    placeholder="รายละเอียดเพิ่มเติม..."
                ></textarea>

            </div>


            <!-- =============================================
                 BUTTON
                 ============================================= -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="cancel-btn"
                    onclick="closeInvoiceModal()"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="submit-btn"
                >
                    สร้างใบแจ้งหนี้
                </button>

            </div>


        </form>

    </div>

</div>


<!-- =====================================================
     DELETE FORM
     ===================================================== -->

<form
    method="POST"
    id="deleteForm"
    style="display:none;"
>

    <input
        type="hidden"
        name="action"
        value="delete_invoice"
    >

    <input
        type="hidden"
        name="invoice_id"
        id="deleteInvoiceId"
    >

</form>


<!-- =====================================================
     JAVASCRIPT
     อยู่ท้ายไฟล์ตามที่เราคุยกัน
     ===================================================== -->

<script>


// =====================================================
// OPEN MODAL
// =====================================================

function openInvoiceModal()
{
    document
        .getElementById("invoiceModal")
        .classList.add("show");
}


// =====================================================
// CLOSE MODAL
// =====================================================

function closeInvoiceModal()
{
    document
        .getElementById("invoiceModal")
        .classList.remove("show");
}


// =====================================================
// CLOSE WHEN CLICK OUTSIDE
// =====================================================

function closeModalOutside(event)
{
    if (
        event.target.id === "invoiceModal"
    ) {
        closeInvoiceModal();
    }
}


// =====================================================
// LOAD CUSTOMER
// =====================================================

function loadCustomer(select)
{
    const option =
        select.options[
            select.selectedIndex
        ];

    const customer =
        option.dataset.customer || "";

    document
        .getElementById("customerName")
        .value = customer;
}


// =====================================================
// CALCULATE TOTAL
// =====================================================

function calculateTotal()
{
    const service =
        parseFloat(
            document.getElementById(
                "serviceAmount"
            ).value
        ) || 0;


    const parts =
        parseFloat(
            document.getElementById(
                "partsAmount"
            ).value
        ) || 0;


    const labor =
        parseFloat(
            document.getElementById(
                "laborAmount"
            ).value
        ) || 0;


    const total =
        service + parts + labor;


    document
        .getElementById("totalDisplay")
        .textContent =
            "฿" +
            total.toLocaleString(
                "th-TH",
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );
}


// =====================================================
// DELETE INVOICE
// =====================================================

function deleteInvoice(invoiceId)
{
    const confirmed =
        confirm(
            "คุณต้องการลบใบแจ้งหนี้รายการนี้ใช่หรือไม่?\n\nการลบข้อมูลไม่สามารถย้อนกลับได้"
        );


    if (!confirmed) {
        return;
    }


    document
        .getElementById("deleteInvoiceId")
        .value = invoiceId;


    document
        .getElementById("deleteForm")
        .submit();
}


// =====================================================
// ESC TO CLOSE
// =====================================================

document.addEventListener(
    "keydown",
    function(event)
    {

        if (event.key === "Escape") {

            closeInvoiceModal();

        }

    }
);


</script>


</body>
</html>