<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * ทุกงานที่โหลดต้องเป็นของ mechanic_id ซึ่งหาได้จากบัญชีใน session ไม่ได้ใช้รหัสช่างจากฟอร์มตรง ๆ
 * การบันทึกหมายเหตุกำหนดทั้งรหัสใบงาน รหัสช่าง และสถานะงานใน WHERE
 * การบันทึกอะไหล่ล็อกใบงาน ตรวจเจ้าของ/สถานะ แล้วลบชุดเดิมและเพิ่มชุดใหม่ใน transaction เดียว
 * ดึงอะไหล่ของหลายใบงานพร้อมกันด้วย IN แล้ว PHP จัดกลุ่มตาม repair_order_id
 * การเริ่มซ่อม/ส่งตรวจรับเรียก workflowJobAction เพื่อตรวจสิทธิ์และสถานะก่อน UPDATE
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_workflow.php';
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
    /*
     * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง)
     * เงื่อนไข WHERE ที่ใช้จริง: user_id = ? 
     * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([$userId]);
    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
    $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    /*
     * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง)
     * เงื่อนไข WHERE ที่ใช้จริง: user_id = ? 
     * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");
    /*
     * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
     */
    $stmt->bind_param("i", $userId);
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute();
    $result = $stmt->get_result();
    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
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
| START WORK
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['start','complete'], true)) {
    try {
        workflowJobAction($pdo,(int)($_POST['repair_order_id'] ?? 0),$_POST['action'],$_POST,(int)$_SESSION['user_id'],$mechanicId);
        $_SESSION['repair_job_success'] = $_POST['action'] === 'start' ? 'เริ่มงานซ่อมแล้ว' : 'ส่งงานให้แอดมินตรวจรับแล้ว';
    } catch (InvalidArgumentException $e) {
        $_SESSION['repair_job_error'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $_SESSION['repair_job_error'] = 'ไม่สามารถบันทึกได้ กรุณารีเฟรชแล้วลองใหม่';
    }
    header('Location: index.php');
    exit;
}
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_status'
) {

    $repairId =
        (int)($_POST['repair_order_id'] ?? 0);

    $status =
        $_POST['status'] ?? '';

    $mechanicNote =
        trim($_POST['mechanic_note'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | VALIDATE
    |--------------------------------------------------------------------------
    */

    if (!$repairId) {

        $_SESSION['repair_job_error'] =
            'ไม่พบใบงานซ่อม';

        header("Location: index.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE MECHANIC NOTE
    |--------------------------------------------------------------------------
    |
    | ช่างสามารถอัปเดตได้เฉพาะงานของตัวเอง
    | และต้องเป็นงานที่กำลังดำเนินการอยู่
    |
    */

    if ($status !== 'in_progress') {

        $_SESSION['repair_job_error'] =
            'ไม่สามารถอัปเดตสถานะของใบงานนี้ได้';

        header("Location: index.php");
        exit;
    }


    if ($db instanceof PDO) {

        /*
         * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id = ? AND mechanic_id = ? AND status = 'in_progress'
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                mechanic_note = ?,
                updated_at = NOW()
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
                AND status = 'in_progress'
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            $mechanicNote,
            $repairId,
            $mechanicId
        ]);

        $affectedRows =
            $stmt->rowCount();

    } else {

        /*
         * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id = ? AND mechanic_id = ? AND status = 'in_progress'
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $db->prepare("
            UPDATE repair_jobs
            SET
                mechanic_note = ?,
                updated_at = NOW()
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
                AND status = 'in_progress'
        ");

        /*
         * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
         */
        $stmt->bind_param(
            "sii",
            $mechanicNote,
            $repairId,
            $mechanicId
        );

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute();

        $affectedRows =
            $stmt->affected_rows;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK RESULT
    |--------------------------------------------------------------------------
    */

    if ($affectedRows <= 0) {

        $_SESSION['repair_job_error'] =
            'ไม่สามารถอัปเดตความคืบหน้าได้ ใบงานอาจไม่ได้อยู่ในสถานะกำลังดำเนินการ หรือไม่ได้เป็นงานของคุณ';

        header("Location: index.php");
        exit;
    }


    $_SESSION['repair_job_success'] =
        'อัปเดตความคืบหน้าเรียบร้อยแล้ว';

    header("Location: index.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| SAVE PARTS
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
    |--------------------------------------------------------------------------
    | VALIDATE REPAIR JOB
    |--------------------------------------------------------------------------
    */

    if (!$repairId) {

        $_SESSION['repair_job_error'] =
            'ไม่พบใบงานซ่อม';

        header("Location: index.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK JOB OWNERSHIP
    |--------------------------------------------------------------------------
    */

    if ($db instanceof PDO) {

        /*
         * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม)
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id = ? AND mechanic_id = ? 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $db->prepare("
            SELECT
                repair_order_id,
                status
            FROM repair_jobs
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
            LIMIT 1
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            $repairId,
            $mechanicId
        ]);

        $repairJob =
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            $stmt->fetch(PDO::FETCH_ASSOC);

    } else {

        /*
         * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม)
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id = ? AND mechanic_id = ? 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $db->prepare("
            SELECT
                repair_order_id,
                status
            FROM repair_jobs
            WHERE
                repair_order_id = ?
                AND mechanic_id = ?
            LIMIT 1
        ");

        /*
         * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
         */
        $stmt->bind_param(
            "ii",
            $repairId,
            $mechanicId
        );

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute();

        $result =
            $stmt->get_result();

        $repairJob =
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            $result->fetch_assoc();
    }


    if (!$repairJob) {

        $_SESSION['repair_job_error'] =
            'ไม่พบใบงานนี้ หรือใบงานนี้ไม่ได้เป็นงานของคุณ';

        header("Location: index.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK JOB STATUS
    |--------------------------------------------------------------------------
    |
    | อนุญาตให้แก้ไขอะไหล่เฉพาะงานที่ยังดำเนินการอยู่
    |
    */

    if (
        !in_array(
            $repairJob['status'],
            ['waiting', 'in_progress'],
            true
        )
    ) {

        $_SESSION['repair_job_error'] =
            'ไม่สามารถแก้ไขอะไหล่ของใบงานที่เสร็จแล้วได้';

        header("Location: index.php");
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | START TRANSACTION
    |--------------------------------------------------------------------------
    */

    if ($db instanceof PDO) {

        /*
         * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
         */
        $db->beginTransaction();

    } else {

        /*
         * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
         */
        $db->begin_transaction();
    }


    try {
        /*
         * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม)
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=? 
         * FOR UPDATE ล็อกแถวที่อ่านใน transaction เพื่อไม่ให้คำขออื่นแก้แถวเดียวกันแทรกระหว่างตรวจและบันทึก
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $lock = $pdo->prepare('SELECT status,mechanic_id FROM repair_jobs WHERE repair_order_id=? FOR UPDATE');
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $lock->execute([$repairId]);
        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        $lockedJob = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$lockedJob || (int)$lockedJob['mechanic_id'] !== $mechanicId || !in_array($lockedJob['status'], ['waiting','in_progress'], true)) {
            throw new InvalidArgumentException('ใบงานถูกส่งตรวจหรือไม่ได้เป็นงานของคุณแล้ว ไม่สามารถแก้ไขอะไหล่ได้');
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE OLD PARTS
        |--------------------------------------------------------------------------
        */

        if ($db instanceof PDO) {

            /*
             * ลบแถวออกจาก parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน) ตามเงื่อนไขด้านล่าง
             * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id = ?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $db->prepare("
                DELETE FROM parts_used
                WHERE repair_order_id = ?
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([
                $repairId
            ]);

        } else {

            /*
             * ลบแถวออกจาก parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน) ตามเงื่อนไขด้านล่าง
             * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id = ?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $db->prepare("
                DELETE FROM parts_used
                WHERE repair_order_id = ?
            ");

            /*
             * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
             */
            $stmt->bind_param(
                "i",
                $repairId
            );

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute();
        }


        /*
        |--------------------------------------------------------------------------
        | INSERT NEW PARTS
        |--------------------------------------------------------------------------
        */

        if ($db instanceof PDO) {

            /*
             * เพิ่มแถวใหม่ลง parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน)
             * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $db->prepare("
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

        } else {

            /*
             * เพิ่มแถวใหม่ลง parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน)
             * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $db->prepare("
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
        }


        $partCount =
            count($partNames);


        for (
            $i = 0;
            $i < $partCount;
            $i++
        ) {

            $partName =
                trim($partNames[$i] ?? '');

            $quantity =
                (int)($quantities[$i] ?? 0);

            $unitPrice =
                (float)($unitPrices[$i] ?? 0);


            /*
            |--------------------------------------------------------------------------
            | SKIP EMPTY ROW
            |--------------------------------------------------------------------------
            */

            if ($partName === '') {
                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | VALIDATE QUANTITY / PRICE
            |--------------------------------------------------------------------------
            */

            if (
                $quantity <= 0 ||
                $unitPrice < 0
            ) {

                throw new Exception(
                    'จำนวนอะไหล่หรือราคาต่อหน่วยไม่ถูกต้อง'
                );
            }


            if ($db instanceof PDO) {

                /*
                 * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                 */
                $stmt->execute([
                    $repairId,
                    $partName,
                    $quantity,
                    $unitPrice
                ]);

            } else {

                /*
                 * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
                 */
                $stmt->bind_param(
                    "isid",
                    $repairId,
                    $partName,
                    $quantity,
                    $unitPrice
                );

                /*
                 * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                 */
                $stmt->execute();
            }
        }


        /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */
        if ($db instanceof PDO) {
            /*
             * ยืนยันการเปลี่ยนแปลงทั้งหมดใน transaction นี้ลงฐานข้อมูล
             */
            $db->commit();
        } else {
            /*
             * ยืนยันการเปลี่ยนแปลงทั้งหมดใน transaction นี้ลงฐานข้อมูล
             */
            $db->commit();
        }
        $_SESSION['repair_job_success'] =
            'บันทึกอะไหล่เรียบร้อยแล้ว';
    } catch (Throwable $e) {
        /*
        |--------------------------------------------------------------------------
        | ROLLBACK
        |--------------------------------------------------------------------------
        */
        if ($db instanceof PDO) {
            if ($db->inTransaction()) {
                /*
                 * ถ้างานพลาด ให้ย้อนการเปลี่ยนแปลงใน transaction นี้ เพื่อไม่ให้ข้อมูลค้างครึ่งชุด
                 */
                $db->rollBack();
            }
        } else {
            /*
             * ถ้างานพลาด ให้ย้อนการเปลี่ยนแปลงใน transaction นี้ เพื่อไม่ให้ข้อมูลค้างครึ่งชุด
             */
            $db->rollback();
        }
        $_SESSION['repair_job_error'] =
            'ไม่สามารถบันทึกอะไหล่ได้: ' .
            $e->getMessage();
    }
    header("Location: index.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| LOAD MY JOBS
|--------------------------------------------------------------------------
*/
/*
 * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม), customers (ลูกค้า), vehicles (รถยนต์)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * เงื่อนไข WHERE ที่ใช้จริง: r.mechanic_id = ? 
 * เรียงตาม: CASE r.status WHEN 'in_progress' THEN 1 WHEN 'waiting' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END,
 * r.due_date ASC, r.created_at DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
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
        WHEN 'waiting' THEN 2
        WHEN 'completed' THEN 3
        ELSE 4
    END,
        r.due_date ASC,
        r.created_at DESC
";
$jobs = [];
if ($db instanceof PDO) {
    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $db->prepare($sql);
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([
        $mechanicId
    ]);
    $jobs =
        /*
         * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
         */
        $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    /*
     * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
     */
    $stmt = $db->prepare($sql);
    /*
     * MySQLi ผูกค่าตามลำดับ ? โดย i = จำนวนเต็ม, s = ข้อความ, d = ทศนิยม
     */
    $stmt->bind_param(
        "i",
        $mechanicId
    );
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute();
    $result =
        $stmt->get_result();
    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
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


        /*
         * ตรงนี้อ่านข้อมูลจาก parts_used (อะไหล่ที่ใช้ในแต่ละใบงาน)
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id IN ($placeholders) 
         * IN คือรับค่าที่อยู่ในชุดที่ระบุ ถ้าเป็นชุด ? ให้ส่งค่าตามจำนวนและลำดับ placeholder
         * เรียงตาม: part_id ASC
         * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
         */
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
    
    
    if ($db instanceof PDO) {
    
        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $stmt = $db->prepare(
            $sqlParts
        );

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute(
            $jobIds
        );


        while (
            $part =
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
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
            /*
             * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
             */
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
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute();
        $result =
            $stmt->get_result();
        while (
            $part =
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
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
$waitingCount = 0;
$inProgressCount = 0;
$completedCount = 0;
$reviewCount = count(array_filter($jobs, fn($job) => $job['status'] === 'awaiting_review'));

foreach ($jobs as $job) {
    if (
    $job['status'] === 'waiting'
    ) {
        $waitingCount++;
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

$jobHistory = workflowHistory($pdo,array_column($jobs,'repair_order_id'),'repair_order_id');
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
        งานของฉัน | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
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

<link rel="stylesheet" href="../../assets/css/workflow.css">
<link rel="stylesheet" href="../../assets/css/confirmation-modal.css">

    <!-- GARAGE THEME: shared navy, gold and warm cream styles -->
    <link rel="stylesheet" href="../../assets/css/garage-theme.css">


</head>

<body>
<!-- =====================================================
     TOPBAR
====================================================== -->
<header class="topbar">
    <div class="brand">
            <!-- BRAND LOGO: original garage identity -->
            <img class="brand-logo" src="../../assets/img/logo.jpg" alt="" width="48" height="48">
        ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
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
<?php if ($reviewCount > 0): ?><p class="workflow-notice">งานรอตรวจรับ: <?= (int)$reviewCount ?> งาน — ดูประวัติและผลตรวจรับได้ในหน้าใบงานซ่อม</p><?php endif; ?>
    <section class="mechanic-page">
        <!-- HEADER -->
        <div class="page-header garage-page-header">
            <div>
                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-screwdriver-wrench"></i>
                </span>

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
                    งานที่รอดำเนินการ
                </div>

                <div class="summary-number">
                    <?= $waitingCount ?>
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
                        in_array($job['status'], ['waiting','in_progress'], true)
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
                            <?php if ($job['status'] === 'waiting'): ?>
                                <div class="progress-box">
                                    <div class="box-title">
                                        <span>
                                            🔧 เริ่มดำเนินการ
                                        </span>
                                    </div>

                                    <form
                                        method="POST"
                                        data-confirm-title="ยืนยันเริ่มซ่อม"
                                        data-confirm-button="ยืนยันเริ่มซ่อม"
                                        data-confirm-details="<?= h(json_encode([
                                            'เลขใบงาน' => $job['repair_order_code'],
                                            'ทะเบียนรถ' => $job['license_plate']
                                        ], JSON_UNESCAPED_UNICODE)) ?>"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="start"
                                        >
                                        <input
                                            type="hidden"
                                            name="repair_order_id"
                                            value="<?= $repairId ?>"
                                        >
                                        <button
                                            type="submit"
                                            class="btn btn-status"
                                        >
                                            🔧 เริ่มงาน
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <?php if ($job['status'] === 'in_progress'): ?>
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
                                                        value="in_progress"
                                                        selected
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
                                    in_array($job['status'], ['waiting','in_progress'], true)
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

                                                    <div class="part-row">
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

                                                    <strong class="parts-grand-total">
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
                                <?php workflowRenderHistory($jobHistory[$job["repair_order_id"]] ?? []); ?>
                                <!-- =================================================
                                     COMPLETE FORM
                                ================================================== -->
                                <?php if (
                                    $job['status'] === 'in_progress'
                                ): ?>
                                    <form
                                        method="POST"
                                        class="complete-form"
                                        data-confirm-title="ยืนยันส่งงานตรวจรับ"
                                        data-confirm-button="ยืนยันส่งตรวจรับ"
                                        data-confirm-details="<?= h(json_encode([
                                            'เลขใบงาน' => $job['repair_order_code'],
                                            'ทะเบียนรถ' => $job['license_plate']
                                        ], JSON_UNESCAPED_UNICODE)) ?>"
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
                                                    name="repair_detail" required
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
                                                ✓ ส่งตรวจรับงาน
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
<?php require __DIR__ . "/../../includes/confirmation_modal.php"; ?>

<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>
</html>
