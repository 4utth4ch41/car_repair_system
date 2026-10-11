<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * สร้างใบแจ้งหนี้ได้จากใบงาน completed และตรวจว่าใบงานนั้นยังไม่มี invoice ก่อนบันทึก
 * รายได้ใช้ status = paid และ paid_at ตามเวลาไทย แยกวันนี้ / สัปดาห์ / เดือน / ปี
 * ยอดค้างรวม unpaid กับ overdue ส่วน paid ที่ไม่มี paid_at จะไม่เข้ารายได้ตามช่วงเวลา
 * weekly_revenue ยังถูกคำนวณอยู่ใน SQL แต่หน้าเว็บไม่ได้แสดงการ์ดสัปดาห์แล้ว
 * คำค้นและตัวกรองสถานะใช้กับตารางรายการเท่านั้น การ์ดสรุปด้านบนคิดจากใบแจ้งหนี้ทั้งหมด
 * รับชำระซ้ำไม่เขียน paid_at ใหม่ เพราะ UPDATE มีเงื่อนไข status <> paid
 */

require_once "../../config/database.php";
require_once "../../includes/admin_auth.php";

date_default_timezone_set("Asia/Bangkok");
/*
 * ตั้งเขตเวลาเฉพาะ connection นี้เป็น +07:00 ให้ NOW / CURDATE / CURRENT_TIMESTAMP ตรงกับเวลาไทย
 */
$pdo->exec("SET time_zone = '+07:00'");


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
        
        // ระบบไม่มีการกรอกค่าแรงช่างแยก
        $labor_amount = 0;

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

        /*
         * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม), customers (ลูกค้า)
         * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
         * เงื่อนไข WHERE ที่ใช้จริง: r.repair_order_id = :repair_order_id AND r.status = 'completed' 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare("
            SELECT
                r.repair_order_id,
                r.repair_order_code,
                r.customer_id,
                c.full_name
            FROM repair_jobs r
            INNER JOIN customers c
                ON r.customer_id = c.customer_id
            WHERE r.repair_order_id = :repair_order_id AND r.status = 'completed'
            LIMIT 1
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            ":repair_order_id" => $repair_order_id
        ]);

        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        $repair_job = $stmt->fetch();


        if (!$repair_job) {
            throw new Exception("ไม่พบใบงานซ่อมที่เลือก");
        }


        // -------------------------------------------------
        // ตรวจสอบว่ามี Invoice แล้วหรือยัง
        // -------------------------------------------------

        /*
         * ตรงนี้อ่านข้อมูลจาก invoices (ใบแจ้งหนี้)
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id = :repair_order_id 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare("
            SELECT invoice_id
            FROM invoices
            WHERE repair_order_id = :repair_order_id
            LIMIT 1
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            ":repair_order_id" => $repair_order_id
        ]);

        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
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

        /*
         * ตรงนี้อ่านข้อมูลจาก invoices (ใบแจ้งหนี้)
         * เรียงตาม: invoice_id DESC 
         * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         */
        $stmt = $pdo->query("
            SELECT invoice_no
            FROM invoices
            ORDER BY invoice_id DESC
            LIMIT 1
        ");

        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
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

        /*
         * เพิ่มแถวใหม่ลง invoices (ใบแจ้งหนี้)
         * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
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

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
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


        /*
         * แก้ข้อมูลเดิมใน invoices (ใบแจ้งหนี้) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: invoice_id = :invoice_id AND status <> 'paid'
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare("
            UPDATE invoices
            SET
                status = 'paid',
                paid_at = CURRENT_TIMESTAMP
            WHERE invoice_id = :invoice_id
            AND status <> 'paid'
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
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


        /*
         * ลบแถวออกจาก invoices (ใบแจ้งหนี้) ตามเงื่อนไขด้านล่าง
         * เงื่อนไข WHERE ที่ใช้จริง: invoice_id = :invoice_id
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare("
            DELETE FROM invoices
            WHERE invoice_id = :invoice_id
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
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

/*
 * CASE เลือกยอด total_amount เฉพาะบิลที่เข้าเงื่อนไข นอกนั้นให้ 0 แล้ว SUM รวม
 * daily_revenue ใช้ DATE ตัดเวลาออกจาก paid_at แล้วเทียบกับวันนี้ของฐานข้อมูล
 * weekly_revenue ใช้ YEARWEEK(..., 3) เริ่มสัปดาห์วันจันทร์ และเทียบปีของสัปดาห์ด้วย
 * monthly_revenue ต้องตรงทั้งเดือนและปี ส่วน yearly_revenue ตรงเฉพาะปีปัจจุบัน
 * outstanding ไม่กรองวัน คือยอดทุกบิลที่ unpaid (ยังไม่จ่าย) หรือ overdue (เกินกำหนด)
 * COALESCE(..., 0) ทำให้ไม่มีรายการแล้วแสดง 0 แทน NULL
 */

/*
 * ตรงนี้อ่านข้อมูลจาก invoices (ใบแจ้งหนี้)
 * IN คือรับค่าที่อยู่ในชุดที่ระบุ ถ้าเป็นชุด ? ให้ส่งค่าตามจำนวนและลำดับ placeholder
 * SUM รวมค่าตัวเลขของแถวที่ผ่านเงื่อนไข ถ้ามี CASE จะรวมเฉพาะกิ่งที่เข้าเงื่อนไขนั้น
 * COALESCE เลือกค่าตัวแรกที่ไม่เป็น NULL เช่น ยอดรวมไม่มีข้อมูลให้ใช้ 0 หรือเวลาเริ่มงานยังว่างให้ใช้ NOW()
 */
$stmt = $pdo->query("
    SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'paid'
                    AND DATE(paid_at) = CURDATE()
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS daily_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'paid'
                    AND YEARWEEK(paid_at, 3)
                        = YEARWEEK(CURDATE(), 3)
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS weekly_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'paid'
                    AND YEAR(paid_at) = YEAR(CURDATE())
                    AND MONTH(paid_at) = MONTH(CURDATE())
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS monthly_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'paid'
                    AND YEAR(paid_at) = YEAR(CURDATE())
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS yearly_revenue,

        COALESCE(
            SUM(
                CASE
                    WHEN status IN ('unpaid', 'overdue')
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS outstanding

    FROM invoices
");

/*
 * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
 */
$summary = $stmt->fetch();

// =====================================================
// LOAD REPAIR JOBS
// =====================================================

/*
 * ตรงนี้อ่านข้อมูลจาก parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน), repair_jobs (ใบงานซ่อม), customers (ลูกค้า),
 * vehicles (รถยนต์), invoices (ใบแจ้งหนี้)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * เงื่อนไขหลัก: เอาเฉพาะใบงาน completed และยังไม่มี invoices ที่ใช้ repair_order_id เดียวกัน
 * query ย่อย parts_used ผูก p.repair_order_id กับ r.repair_order_id ของใบงานแถวปัจจุบัน
 * จึงได้ parts_total เป็นค่าอะไหล่ของใบงานนั้น ไม่ใช่ค่าอะไหล่รวมทั้งอู่
 * NOT EXISTS เลือกเฉพาะแถวที่ query ย่อยหาแถวที่เกี่ยวข้องไม่เจอ เช่น ใบงานที่ยังไม่ออก invoice
 * SUM รวมค่าตัวเลขของแถวที่ผ่านเงื่อนไข ถ้ามี CASE จะรวมเฉพาะกิ่งที่เข้าเงื่อนไขนั้น
 * COALESCE เลือกค่าตัวแรกที่ไม่เป็น NULL เช่น ยอดรวมไม่มีข้อมูลให้ใช้ 0 หรือเวลาเริ่มงานยังว่างให้ใช้ NOW()
 * เรียงตาม: r.repair_order_id DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$stmt = $pdo->query("
    SELECT
        r.repair_order_id,
        r.repair_order_code,
        r.customer_id,
        r.vehicle_id,
        r.status,

        c.full_name,
        c.phone,

        v.license_plate,
        v.brand,
        v.model,
        v.color,
        v.mileage,

        COALESCE(
            (
                SELECT SUM(p.total_price)
                FROM parts_used p
                WHERE p.repair_order_id = r.repair_order_id
            ),
            0
        ) AS parts_total

    FROM repair_jobs r

    INNER JOIN customers c
        ON r.customer_id = c.customer_id

    INNER JOIN vehicles v
        ON r.vehicle_id = v.vehicle_id

    WHERE r.status = 'completed'

    AND NOT EXISTS (
        SELECT 1
        FROM invoices i
        WHERE i.repair_order_id = r.repair_order_id
    )

    ORDER BY r.repair_order_id DESC
");

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$repair_jobs = $stmt->fetchAll();


// =====================================================
// LOAD PARTS USED
// =====================================================

$parts_by_job = [];

if (count($repair_jobs) > 0) {

    $job_ids = array_column(
        $repair_jobs,
        "repair_order_id"
    );

    $placeholders = implode(
        ",",
        array_fill(
            0,
            count($job_ids),
            "?"
        )
    );

    /*
     * ตรงนี้อ่านข้อมูลจาก parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน)
     * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id IN ($placeholders) 
     * IN คือรับค่าที่อยู่ในชุดที่ระบุ ถ้าเป็นชุด ? ให้ส่งค่าตามจำนวนและลำดับ placeholder
     * เรียงตาม: part_id ASC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
    $stmt = $pdo->prepare("
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
    ");

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute($job_ids);

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $parts = $stmt->fetchAll();

    foreach ($parts as $part) {

        $repair_order_id =
            (int)$part["repair_order_id"];

        if (!isset($parts_by_job[$repair_order_id])) {

            $parts_by_job[$repair_order_id] = [];

        }

        $parts_by_job[$repair_order_id][] = $part;
    }
}


// =====================================================
// ATTACH PARTS TO REPAIR JOBS
// =====================================================

foreach ($repair_jobs as &$job) {

    $repair_order_id =
        (int)$job["repair_order_id"];

    $job["parts"] =
        $parts_by_job[$repair_order_id] ?? [];

}

unset($job);

// =====================================================
// LOAD PARTS USED
// =====================================================

$parts_by_job = [];

if (count($repair_jobs) > 0) {

    $job_ids = array_column(
        $repair_jobs,
        "repair_order_id"
    );

    $placeholders = implode(
        ",",
        array_fill(
            0,
            count($job_ids),
            "?"
        )
    );

    /*
     * ตรงนี้อ่านข้อมูลจาก parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน)
     * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id IN ($placeholders) 
     * IN คือรับค่าที่อยู่ในชุดที่ระบุ ถ้าเป็นชุด ? ให้ส่งค่าตามจำนวนและลำดับ placeholder
     * เรียงตาม: part_id ASC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
    $stmt = $pdo->prepare("
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
    ");

    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute($job_ids);

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $parts = $stmt->fetchAll();

    foreach ($parts as $part) {

        $repair_order_id =
            (int)$part["repair_order_id"];

        if (!isset($parts_by_job[$repair_order_id])) {

            $parts_by_job[$repair_order_id] = [];

        }

        $parts_by_job[$repair_order_id][] = $part;
    }
}


// =====================================================
// ATTACH PARTS TO REPAIR JOBS
// =====================================================

foreach ($repair_jobs as &$job) {

    $repair_order_id =
        (int)$job["repair_order_id"];

    $job["parts"] =
        $parts_by_job[$repair_order_id] ?? [];

}

unset($job);

// =====================================================
// LOAD INVOICES
// =====================================================

/*
 * ตรงนี้อ่านข้อมูลจาก invoices (ใบแจ้งหนี้), repair_jobs (ใบงานซ่อม), customers (ลูกค้า)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * เงื่อนไข WHERE ที่ใช้จริง: 1 = 1
 */
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

    /*
     * ต่อเงื่อนไขกรองเพิ่มให้ SQL เดิม เฉพาะเมื่อ PHP เข้ามาทำงานในบล็อกนี้
     * ต้องผ่านเงื่อนไขเดิมด้วย และเพิ่มเงื่อนไข: c.full_name LIKE :search
     * LIKE ใช้ค้นหาข้อความ ส่วน % ที่ PHP เติมหน้าหลังหมายถึงมีคำนี้อยู่ตรงไหนก็เจอ
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
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

    /*
     * ต่อเงื่อนไขกรองเพิ่มให้ SQL เดิม เฉพาะเมื่อ PHP เข้ามาทำงานในบล็อกนี้
     * ต้องผ่านเงื่อนไขเดิมด้วย และเพิ่มเงื่อนไข: i.status = :status
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $sql .= "
        AND i.status = :status
    ";

    $params[":status"] =
        $status_filter;
}


/*
 * ต่อส่วนเรียงลำดับให้ SQL ที่ประกอบไว้ด้านบน
 * เรียงตาม: i.invoice_id DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$sql .= "
    ORDER BY i.invoice_id DESC
";


/*
 * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
 */
$stmt = $pdo->prepare($sql);

/*
 * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
 */
$stmt->execute($params);

$invoices =
    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
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

    <title>การเงิน | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส</title>


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


        <a
            href="../financial/index.php"
            class="active"
        >

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

    <section class="financial-page">


        <!-- =================================================
             HEADER
             ================================================= -->

        <div class="financial-header garage-page-header">

            <div>

                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-file-invoice-dollar"></i>
                </span>

                <h1>
                    การจัดการการเงิน
                </h1>

                <p>
                    สรุปรายได้ตามวันที่รับชำระ (เวลาไทย) และติดตามยอดค้างชำระ
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


            <!-- YEARLY -->

            <div class="summary-card">

                <div>

                    <span class="summary-label">
                        รายได้ปีนี้
                    </span>

                    <strong>
                        ฿<?= money($summary["yearly_revenue"]) ?>
                    </strong>

                </div>

                <div class="summary-icon blue">
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
                                            data-confirm-title="ยืนยันรับชำระเงิน"
                                            data-confirm-button="ยืนยันรับชำระ"
                                            data-confirm-message="ตรวจสอบยอดเงินและยืนยันว่าได้รับเงินจากลูกค้าแล้ว"
                                            data-confirm-details="<?= e(json_encode([
                                                'เลขใบแจ้งหนี้' => $invoice['invoice_no'],
                                                'ลูกค้า' => $invoice['full_name'],
                                                'ยอดรับชำระ' => '฿' . money($invoice['total_amount'])
                                            ], JSON_UNESCAPED_UNICODE)) ?>"
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
                                                <?= $invoice["invoice_id"] ?>,
                                                this
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
                    onchange="loadRepairJob(this)"
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

                            data-phone="<?= e(
                                $job["phone"]
                            ) ?>"

                            data-plate="<?= e(
                                $job["license_plate"]
                            ) ?>"

                            data-brand="<?= e(
                                $job["brand"]
                            ) ?>"

                            data-model="<?= e(
                                $job["model"]
                            ) ?>"

                            data-color="<?= e(
                                $job["color"]
                            ) ?>"

                            data-mileage="<?= e(
                                $job["mileage"]
                            ) ?>"

                            data-parts-total="<?= e(
                                $job["parts_total"]
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
        VEHICLE
        ============================================= -->

        <div class="form-group">

        <label>
            ข้อมูลรถ
        </label>

        <input
            type="text"
            id="vehicleInfo"
            readonly
            placeholder="ระบบจะแสดงข้อมูลรถอัตโนมัติ"
        >
        </div>
        <!-- =============================================
            AMOUNT
            ============================================= -->

        <div class="amount-grid">

                <!-- ค่าบริการ -->

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

                <!-- ค่าอะไหล่ -->
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
                        readonly
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
// LOAD REPAIR JOB
// =====================================================

function loadRepairJob(select)
{
    const option =
        select.options[
            select.selectedIndex
        ];


    // -----------------------------
    // ข้อมูลลูกค้า
    // -----------------------------

    const customer =
        option.dataset.customer || "";


    document
        .getElementById("customerName")
        .value = customer;


    // -----------------------------
    // ข้อมูลรถ
    // -----------------------------

    const plate =
        option.dataset.plate || "";

    const brand =
        option.dataset.brand || "";

    const model =
        option.dataset.model || "";

    const color =
        option.dataset.color || "";

    const mileage =
        option.dataset.mileage || "";


    let vehicleInfo = "";


    if (plate !== "") {

        vehicleInfo +=
            "ทะเบียน " + plate;

    }


    if (brand !== "" || model !== "") {

        vehicleInfo +=
            " | " +
            brand +
            " " +
            model;

    }


    if (color !== "") {

        vehicleInfo +=
            " | สี " +
            color;

    }


    if (mileage !== "") {

        vehicleInfo +=
            " | เลขไมล์ " +
            Number(mileage).toLocaleString("th-TH") +
            " กม.";

    }


    document
        .getElementById("vehicleInfo")
        .value = vehicleInfo;


    // -----------------------------
    // ค่าอะไหล่
    // -----------------------------

    const partsTotal =
        parseFloat(
            option.dataset.partsTotal
        ) || 0;


    document
        .getElementById("partsAmount")
        .value = partsTotal.toFixed(2);


    // -----------------------------
    // คำนวณยอดรวม
    // -----------------------------

    calculateTotal();
}

//=====================================================
// CALAULATE TOTAL
//=====================================================
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


    const total =
        service + parts;


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

function deleteInvoice(invoiceId, button)
{
    const cells = button.closest("tr").cells;

    openConfirmation({
        title: "ลบใบแจ้งหนี้",
        message: "การลบข้อมูลไม่สามารถย้อนกลับได้ และจะมีผลต่อยอดสรุปการเงิน",
        button: "ลบใบแจ้งหนี้",
        danger: true,
        details: {
            "เลขใบแจ้งหนี้": cells[0].textContent.trim(),
            "ลูกค้า": cells[1].textContent.trim(),
            "ยอดเงิน": cells[4].textContent.trim()
        }
    }, function () {

        document
            .getElementById("deleteInvoiceId")
            .value = invoiceId;

        document
            .getElementById("deleteForm")
            .submit();
    });
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


<?php require __DIR__ . "/../../includes/confirmation_modal.php"; ?>

<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>
</html>
