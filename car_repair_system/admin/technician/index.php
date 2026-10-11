<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * ช่างผูกกับ users ผ่าน user_id ส่วนทักษะหลายรายการเก็บผ่าน mechanic_skills
 * แก้ข้อมูลช่าง/บัญชี/ทักษะใน transaction เดียวกัน เพื่อไม่ให้บันทึกสำเร็จแค่บางตาราง
 * ตารางช่าง LEFT JOIN ใบงานแล้ว COUNT CASE แยกงานค้างกับ completed ต่อช่างแต่ละคน
 * ก่อนลบช่างตรวจจำนวนใบงานที่อ้างถึงก่อน แล้วจึงลบทักษะ ช่าง และบัญชีตามลำดับในโค้ด
 */

require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function redirectWithMessage($type, $message)
{
    $_SESSION["technician_message"] = [
        "type" => $type,
        "message" => $message
    ];

    header("Location: index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| Flash Message
|--------------------------------------------------------------------------
*/

$flash = $_SESSION["technician_message"] ?? null;
unset($_SESSION["technician_message"]);


/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | ADD TECHNICIAN
    |--------------------------------------------------------------------------
    */

    if ($action === "add") {
        redirectWithMessage('error', 'กรุณาสร้างบัญชีและกำหนดบทบาทช่างจากหน้าบัญชีผู้ใช้');
    }

    if ($action === "edit") {

        $mechanicId = (int) ($_POST["mechanic_id"] ?? 0);

        $fullName = trim($_POST["full_name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $password = $_POST["password"] ?? "";
        $status = $_POST["status"] ?? "available";

        $skills = $_POST["skills"] ?? [];


        if ($mechanicId <= 0 || $fullName === "") {

            redirectWithMessage(
                "error",
                "ข้อมูลช่างไม่ถูกต้อง"
            );
        }


        $allowedStatuses = [
            "available",
            "busy",
            "inactive"
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = "available";
        }


        try {

            /*
             * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
             */
            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Get Mechanic
            |--------------------------------------------------------------------------
            */

            /*
             * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง)
             * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ? 
             * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                SELECT user_id
                FROM mechanics
                WHERE mechanic_id = ?
                LIMIT 1
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$mechanicId]);

            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$mechanic) {

                throw new Exception(
                    "ไม่พบข้อมูลช่าง"
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Update Mechanic
            |--------------------------------------------------------------------------
            */

            /*
             * แก้ข้อมูลเดิมใน mechanics (ช่าง) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
             * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = :mechanic_id
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                UPDATE mechanics
                SET
                    full_name = :full_name,
                    phone = :phone,
                    status = :status
                WHERE mechanic_id = :mechanic_id
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([
                ":full_name" => $fullName,
                ":phone" => $phone !== "" ? $phone : null,
                ":status" => $status,
                ":mechanic_id" => $mechanicId
            ]);


            /*
            |--------------------------------------------------------------------------
            | Update Password
            |--------------------------------------------------------------------------
            */

            if ($password !== "") {

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                /*
                 * แก้ข้อมูลเดิมใน users (บัญชีผู้ใช้) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
                 * เงื่อนไข WHERE ที่ใช้จริง: user_id = ?
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE user_id = ?
                ");

                /*
                 * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                 */
                $stmt->execute([
                    $hashedPassword,
                    $mechanic["user_id"]
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Replace Skills
            |--------------------------------------------------------------------------
            */

            /*
             * ลบแถวออกจาก mechanic_skills (ตารางเชื่อมช่างกับทักษะ) ตามเงื่อนไขด้านล่าง
             * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                DELETE FROM mechanic_skills
                WHERE mechanic_id = ?
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$mechanicId]);


            if (!empty($skills)) {

                /*
                 * เพิ่มแถวใหม่ลง mechanic_skills (ตารางเชื่อมช่างกับทักษะ)
                 * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $stmt = $pdo->prepare("
                    INSERT INTO mechanic_skills
                    (
                        mechanic_id,
                        skill_id
                    )
                    VALUES
                    (
                        :mechanic_id,
                        :skill_id
                    )
                ");

                foreach ($skills as $skillId) {

                    $skillId = (int) $skillId;

                    if ($skillId <= 0) {
                        continue;
                    }

                    /*
                     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                     */
                    $stmt->execute([
                        ":mechanic_id" => $mechanicId,
                        ":skill_id" => $skillId
                    ]);
                }
            }


            /*
             * ยืนยันการเปลี่ยนแปลงทั้งหมดใน transaction นี้ลงฐานข้อมูล
             */
            $pdo->commit();


            redirectWithMessage(
                "success",
                "อัปเดตข้อมูลช่างเรียบร้อยแล้ว"
            );


        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                /*
                 * ถ้างานพลาด ให้ย้อนการเปลี่ยนแปลงใน transaction นี้ เพื่อไม่ให้ข้อมูลค้างครึ่งชุด
                 */
                $pdo->rollBack();
            }

            redirectWithMessage(
                "error",
                "ไม่สามารถแก้ไขข้อมูลช่างได้: " . $e->getMessage()
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CHANGE STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === "change_status") {

        $mechanicId = (int) ($_POST["mechanic_id"] ?? 0);
        $status = $_POST["status"] ?? "";


        $allowedStatuses = [
            "available",
            "busy",
            "inactive"
        ];

        if (
            $mechanicId <= 0 ||
            !in_array($status, $allowedStatuses, true)
        ) {

            redirectWithMessage(
                "error",
                "ข้อมูลสถานะไม่ถูกต้อง"
            );
        }


        /*
         * แก้ข้อมูลเดิมใน mechanics (ช่าง) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ?
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare("
            UPDATE mechanics
            SET status = ?
            WHERE mechanic_id = ?
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            $status,
            $mechanicId
        ]);


        $message = match ($status) {

            "available" => "เปิดรับงานให้ช่างเรียบร้อยแล้ว",

            "busy" => "เปลี่ยนสถานะช่างเป็นไม่ว่างแล้ว",

            "inactive" => "พักงานช่างเรียบร้อยแล้ว",

            default => "อัปเดตสถานะเรียบร้อยแล้ว"
        };


        redirectWithMessage(
            "success",
            $message
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE TECHNICIAN
    |--------------------------------------------------------------------------
    */

    if ($action === "delete") {

        $mechanicId = (int) ($_POST["mechanic_id"] ?? 0);


        if ($mechanicId <= 0) {

            redirectWithMessage(
                "error",
                "ไม่พบข้อมูลช่าง"
            );
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | Check Existing Repair Jobs
            |--------------------------------------------------------------------------
            */

            /*
             * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม)
             * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ?
             * COUNT(*) นับแถว ส่วน COUNT(นิพจน์) นับเฉพาะค่าที่ไม่เป็น NULL จึงใช้ CASE แยกนับสถานะได้
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM repair_jobs
                WHERE mechanic_id = ?
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$mechanicId]);

            /*
             * อ่านค่าเดียวจากคอลัมน์แรกของแถวถัดไป เหมาะกับ COUNT / SUM หรือการตรวจว่าพบรหัสหรือไม่
             */
            $repairCount = (int) $stmt->fetchColumn();


            if ($repairCount > 0) {

                redirectWithMessage(
                    "error",
                    "ไม่สามารถลบช่างคนนี้ได้ เนื่องจากมีประวัติใบงานซ่อมอยู่ในระบบ แนะนำให้ใช้การพักงานแทน"
                );
            }


            /*
             * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
             */
            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Get User ID
            |--------------------------------------------------------------------------
            */

            /*
             * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง)
             * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ? 
             * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                SELECT user_id
                FROM mechanics
                WHERE mechanic_id = ?
                LIMIT 1
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$mechanicId]);

            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$mechanic) {

                throw new Exception(
                    "ไม่พบข้อมูลช่าง"
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Delete Skills
            |--------------------------------------------------------------------------
            */

            /*
             * ลบแถวออกจาก mechanic_skills (ตารางเชื่อมช่างกับทักษะ) ตามเงื่อนไขด้านล่าง
             * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                DELETE FROM mechanic_skills
                WHERE mechanic_id = ?
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$mechanicId]);


            /*
            |--------------------------------------------------------------------------
            | Delete Mechanic
            |--------------------------------------------------------------------------
            */

            /*
             * ลบแถวออกจาก mechanics (ช่าง) ตามเงื่อนไขด้านล่าง
             * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                DELETE FROM mechanics
                WHERE mechanic_id = ?
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$mechanicId]);


            /*
            |--------------------------------------------------------------------------
            | Delete User
            |--------------------------------------------------------------------------
            */

            /*
             * ลบแถวออกจาก users (บัญชีผู้ใช้) ตามเงื่อนไขด้านล่าง
             * เงื่อนไข WHERE ที่ใช้จริง: user_id = ? AND role = 'mechanic'
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare("
                DELETE FROM users
                WHERE user_id = ?
                AND role = 'mechanic'
            ");

            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([
                $mechanic["user_id"]
            ]);


            /*
             * ยืนยันการเปลี่ยนแปลงทั้งหมดใน transaction นี้ลงฐานข้อมูล
             */
            $pdo->commit();


            redirectWithMessage(
                "success",
                "ลบข้อมูลช่างเรียบร้อยแล้ว"
            );


        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                /*
                 * ถ้างานพลาด ให้ย้อนการเปลี่ยนแปลงใน transaction นี้ เพื่อไม่ให้ข้อมูลค้างครึ่งชุด
                 */
                $pdo->rollBack();
            }

            redirectWithMessage(
                "error",
                "ไม่สามารถลบช่างได้: " . $e->getMessage()
            );
        }
    }
}


/*
|--------------------------------------------------------------------------
| Get Skills
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก skills (ทักษะช่าง)
 * เรียงตาม: skill_name ASC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$stmt = $pdo->query("
    SELECT
        skill_id,
        skill_name,
        description
    FROM skills
    ORDER BY skill_name ASC
");

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$skillsList = $stmt->fetchAll(PDO::FETCH_ASSOC);




/*
|--------------------------------------------------------------------------
| Get Mechanics
|--------------------------------------------------------------------------
*/
/*
 * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง), users (บัญชีผู้ใช้), repair_jobs (ใบงานซ่อม)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * LEFT JOIN ยังเก็บแถวฝั่งซ้ายไว้แม้ฝั่งขวาไม่มีคู่ คอลัมน์ของฝั่งขวาจะเป็น NULL
 * IN คือรับค่าที่อยู่ในชุดที่ระบุ ถ้าเป็นชุด ? ให้ส่งค่าตามจำนวนและลำดับ placeholder
 * COUNT(*) นับแถว ส่วน COUNT(นิพจน์) นับเฉพาะค่าที่ไม่เป็น NULL จึงใช้ CASE แยกนับสถานะได้
 * จัดกลุ่มก่อนคำนวณยอด/จำนวนด้วย GROUP BY: m.mechanic_id, m.user_id, u.username, m.full_name, m.phone, m.status 
 * เรียงตาม: m.mechanic_id DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$stmt = $pdo->query("
    SELECT
        m.mechanic_id,
        m.user_id,
        u.username,
        m.full_name,
        m.phone,
        m.status,

        COUNT(
            CASE
                WHEN rj.status IN ('waiting', 'in_progress', 'awaiting_review')
                THEN 1
            END
        ) AS active_jobs,

        COUNT(
            CASE
                WHEN rj.status = 'completed'
                THEN 1
            END
        ) AS completed_jobs

    FROM mechanics m

    INNER JOIN users u
        ON m.user_id = u.user_id

    LEFT JOIN repair_jobs rj
        ON rj.mechanic_id = m.mechanic_id

    GROUP BY
        m.mechanic_id,
        m.user_id,
        u.username,
        m.full_name,
        m.phone,
        m.status

    ORDER BY m.mechanic_id DESC
");

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC); 


/*
|--------------------------------------------------------------------------
| Get Skills For Mechanics
|--------------------------------------------------------------------------
*/

$mechanicSkills = [];

if (!empty($mechanics)) {

    /*
     * ตรงนี้อ่านข้อมูลจาก mechanic_skills (ตารางเชื่อมช่างกับทักษะ), skills (ทักษะช่าง)
     * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
     * เรียงตาม: s.skill_name ASC
     * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
     */
    $stmt = $pdo->query("
        SELECT
            ms.mechanic_id,
            s.skill_id,
            s.skill_name
        FROM mechanic_skills ms

        INNER JOIN skills s
            ON ms.skill_id = s.skill_id

        ORDER BY s.skill_name ASC
    ");

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $skillRows = $stmt->fetchAll(PDO::FETCH_ASSOC);


    foreach ($skillRows as $row) {

        $mechanicSkills[$row["mechanic_id"]][] = [
            "skill_id" => $row["skill_id"],
            "skill_name" => $row["skill_name"]
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Status Labels
|--------------------------------------------------------------------------
*/

$statusLabels = [
    "available" => "พร้อมรับงาน",
    "busy" => "ไม่ว่าง",
    "inactive" => "พักงาน"
];

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
        จัดการช่าง | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
    </title>


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


    <!-- CSS หน้าจัดการช่าง -->

    <link
        rel="stylesheet"
        href="../../assets/css/admin/technician.css"
    >

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


        <a
            href="../technician/index.php"
            class="active"
        >

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

    <section class="technician-page">


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="technician-header garage-page-header">

            <div>

                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-user-gear"></i>
                </span>

                <h1>
                    การจัดการช่าง
                </h1>

                <p>
                    จัดการข้อมูล ความสามารถ และสถานะการรับงานของช่าง
                </p>

            </div>




        </div>


        <!-- =================================================
             FLASH MESSAGE
        ================================================== -->

        <?php if ($flash): ?>

            <div
                class="technician-alert
                <?= $flash["type"] === "success"
                    ? "alert-success"
                    : "alert-error"
                ?>"
            >

                <?= htmlspecialchars($flash["message"]) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             TECHNICIAN GRID
        ================================================== -->

        <?php if (!empty($mechanics)): ?>

            <div class="technician-grid">


                <?php foreach ($mechanics as $mechanic): ?>

                    <?php

                    $skills = $mechanicSkills[
                        $mechanic["mechanic_id"]
                    ] ?? [];

                    ?>


                    <article class="technician-card">


                        <!-- CARD HEADER -->

                        <div class="technician-card-header">

                            <div class="technician-profile">

                                <div class="technician-avatar">

                                    <?= htmlspecialchars(
                                        mb_substr(
                                            $mechanic["full_name"],
                                            0,
                                            1,
                                            "UTF-8"
                                        )
                                    ) ?>

                                </div>


                                <div>

                                    <h2>
                                        <?= htmlspecialchars(
                                            $mechanic["full_name"]
                                        ) ?>
                                    </h2>

                                    <span class="technician-username">
                                        <?= htmlspecialchars(
                                            $mechanic["username"]
                                        ) ?>
                                    </span>

                                </div>

                            </div>


                            <span
                                class="technician-status
                                status-<?= htmlspecialchars(
                                    $mechanic["status"]
                                ) ?>"
                            >

                                <?= htmlspecialchars(
                                    $statusLabels[
                                        $mechanic["status"]
                                    ] ?? $mechanic["status"]
                                ) ?>

                            </span>

                        </div>


                        <!-- PHONE -->

                        <div class="technician-phone">

                            📞

                            <?= $mechanic["phone"]
                                ? htmlspecialchars($mechanic["phone"])
                                : "ไม่ได้ระบุเบอร์โทรศัพท์"
                            ?>

                        </div>


                        <!-- SKILLS -->

                        <div class="technician-section">

                            <div class="section-label">
                                ความสามารถและความถนัด
                            </div>


                            <?php if (!empty($skills)): ?>

                                <div class="skill-list">

                                    <?php foreach ($skills as $skill): ?>

                                        <span class="skill-chip">

                                            <?= htmlspecialchars(
                                                $skill["skill_name"]
                                            ) ?>

                                        </span>

                                    <?php endforeach; ?>

                                </div>

                            <?php else: ?>

                                <span class="no-skill">
                                    ยังไม่ได้ระบุความสามารถ
                                </span>

                            <?php endif; ?>

                        </div>


                        <!-- JOB STATISTICS -->

                        <div class="technician-stats">

                            <div class="technician-stat">

                                <span>
                                    งานที่รับผิดชอบ
                                </span>

                                <strong>
                                    <?= number_format(
                                        $mechanic["active_jobs"]
                                    ) ?>
                                </strong>

                            </div>


                            <div class="technician-stat">

                                <span>
                                    งานที่เสร็จแล้ว
                                </span>

                                <strong>
                                    <?= number_format(
                                        $mechanic["completed_jobs"]
                                    ) ?>
                                </strong>

                            </div>

                        </div>


                        <!-- ACTIONS -->

                        <div class="technician-actions">


                            <button
                                type="button"
                                class="technician-edit-btn"
                                onclick='openEditModal(
                                    <?= json_encode(
                                        [
                                            "mechanic_id" => $mechanic["mechanic_id"],
                                            "full_name" => $mechanic["full_name"],
                                            "phone" => $mechanic["phone"],
                                            "status" => $mechanic["status"],
                                            "skills" => $skills
                                        ],
                                        JSON_UNESCAPED_UNICODE
                                        | JSON_HEX_TAG
                                        | JSON_HEX_APOS
                                        | JSON_HEX_QUOT
                                        | JSON_HEX_AMP
                                    ) ?>
                                )'
                            >

                                ✏️ แก้ไข

                            </button>


                            <?php if ($mechanic["status"] === "inactive"): ?>

                                <form
                                    method="POST"
                                    class="inline-form"
                                >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="change_status"
                                    >

                                    <input
                                        type="hidden"
                                        name="mechanic_id"
                                        value="<?= $mechanic["mechanic_id"] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="available"
                                    >

                                    <button
                                        type="submit"
                                        class="technician-status-btn activate"
                                    >
                                        ✓ เปิดรับงาน
                                    </button>

                                </form>

                            <?php else: ?>

                                <form
                                    method="POST"
                                    class="inline-form"
                                >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="change_status"
                                    >

                                    <input
                                        type="hidden"
                                        name="mechanic_id"
                                        value="<?= $mechanic["mechanic_id"] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="inactive"
                                    >

                                    <button
                                        type="submit"
                                        class="technician-status-btn pause"
                                    >
                                        ⏸ พักงาน
                                    </button>

                                </form>

                            <?php endif; ?>


                            <button
                                type="button"
                                class="technician-delete-btn"
                                onclick="openDeleteModal(
                                    <?= (int) $mechanic["mechanic_id"] ?>,
                                    '<?= htmlspecialchars(
                                        $mechanic["full_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>'
                                )"
                            >
                                🗑
                            </button>


                        </div>

                    </article>

                <?php endforeach; ?>

            </div>


        <?php else: ?>

            <div class="technician-empty">

                <div class="empty-icon">
                    👨‍🔧
                </div>

                <h3>
                    ยังไม่มีข้อมูลช่าง
                </h3>

                <p>
                    สร้างบัญชีและกำหนดบทบาทเป็นช่างในหน้าการจัดการผู้ใช้งาน
                </p>

            </div>

        <?php endif; ?>


    </section>

</main>



<!-- =====================================================
     EDIT TECHNICIAN MODAL
===================================================== -->

<div
    id="editModal"
    class="modal-overlay"
    onclick="closeModalOutside(event, 'editModal')"
>

    <div class="technician-modal">


        <div class="modal-header">

            <div>

                <h2>
                    แก้ไขข้อมูลช่าง
                </h2>

                <p>
                    แก้ไขข้อมูลและความสามารถของช่าง
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editModal')"
            >
                ×
            </button>

        </div>


        <form
            method="POST"
            id="editTechnicianForm"
        >

            <input
                type="hidden"
                name="action"
                value="edit"
            >


            <input
                type="hidden"
                name="mechanic_id"
                id="editMechanicId"
            >


            <!-- NAME -->

            <div class="form-group">

                <label>
                    ชื่อ - นามสกุล
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="full_name"
                    id="editFullName"
                    required
                >

            </div>


            <!-- PHONE -->

            <div class="form-group">

                <label>
                    เบอร์โทรศัพท์
                </label>

                <input
                    type="text"
                    name="phone"
                    id="editPhone"
                >

            </div>


            <!-- PASSWORD -->

            <div class="form-group">

                <label>
                    รีเซ็ตรหัสผ่าน
                </label>

                <input
                    type="password"
                    name="password"
                    id="editPassword"
                    placeholder="เว้นว่างไว้หากไม่ต้องการเปลี่ยน"
                    minlength="6"
                >

            </div>


            <!-- SKILLS -->

            <div class="form-group">

                <label>
                    ความสามารถและความถนัด
                </label>


                <select
                    id="editSkillSelect"
                    class="skill-select"
                    onchange="addSkill('edit')"
                >

                    <option value="">
                        + เพิ่มความสามารถ
                    </option>

                    <?php foreach ($skillsList as $skill): ?>

                        <option
                            value="<?= $skill["skill_id"] ?>"
                        >
                            <?= htmlspecialchars(
                                $skill["skill_name"]
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>


                <div
                    id="editSkillList"
                    class="selected-skills"
                ></div>

            </div>


            <!-- STATUS -->

            <div class="form-group">

                <label>
                    สถานะการรับงาน
                </label>


                <select
                    name="status"
                    id="editStatus"
                    required
                >

                    <option value="available">
                        พร้อมรับงาน
                    </option>

                    <option value="busy">
                        ไม่ว่าง
                    </option>

                    <option value="inactive">
                        พักงาน
                    </option>

                </select>

            </div>


            <!-- ACTION -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeModal('editModal')"
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
    id="deleteModal"
    class="modal-overlay"
    onclick="closeModalOutside(event, 'deleteModal')"
>

    <div class="delete-modal">


        <div class="delete-icon">
            🗑️
        </div>


        <h2>
            ลบช่างและบัญชีผู้ใช้
        </h2>


        <p>
            คุณต้องการลบข้อมูลช่างและบัญชีผู้ใช้ของ
        </p>


        <strong id="deleteMechanicName"></strong>


        <p class="delete-warning">
            หากช่างมีประวัติใบงานซ่อม ระบบจะไม่อนุญาตให้ลบ
        </p>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="delete"
            >


            <input
                type="hidden"
                name="mechanic_id"
                id="deleteMechanicId"
            >


            <div class="delete-actions">

                <button
                    type="button"
                    class="btn-cancel"
                    onclick="closeModal('deleteModal')"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-delete-confirm"
                >
                    ยืนยันการลบ
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

/*
|--------------------------------------------------------------------------
| Skill Data
|--------------------------------------------------------------------------
*/

const skillData = <?= json_encode(
    $skillsList,
    JSON_UNESCAPED_UNICODE
) ?>;


/*
|--------------------------------------------------------------------------
| Selected Skills
|--------------------------------------------------------------------------
*/

let editSelectedSkills = [];


/*
|--------------------------------------------------------------------------
| Modal
|--------------------------------------------------------------------------
*/

function openEditModal(data)
{
    editSelectedSkills = [];

    document.getElementById("editMechanicId").value =
        data.mechanic_id;

    document.getElementById("editFullName").value =
        data.full_name;

    document.getElementById("editPhone").value =
        data.phone || "";

    document.getElementById("editPassword").value =
        "";

    document.getElementById("editStatus").value =
        data.status;


    if (Array.isArray(data.skills)) {

        data.skills.forEach(function(skill) {

            editSelectedSkills.push({
                skill_id: String(skill.skill_id),
                skill_name: skill.skill_name
            });

        });
    }


    renderSkills("edit");


    document.getElementById("editModal")
        .classList.add("show");
}


function closeModal(id)
{
    document.getElementById(id)
        .classList.remove("show");
}


function closeModalOutside(event, id)
{
    if (event.target.id === id) {

        closeModal(id);
    }
}


/*
|--------------------------------------------------------------------------
| Add Skill
|--------------------------------------------------------------------------
*/

function addSkill(type)
{
    const select = document.getElementById(
        "editSkillSelect"
    );

    const skillId = select.value;

    if (!skillId) {
        return;
    }


    const skill = skillData.find(
        item => String(item.skill_id) === String(skillId)
    );


    if (!skill) {
        return;
    }


    let selectedSkills =
        editSelectedSkills;


    const alreadyExists = selectedSkills.some(
        item =>
            String(item.skill_id) === String(skillId)
    );


    if (!alreadyExists) {

        selectedSkills.push({
            skill_id: String(skill.skill_id),
            skill_name: skill.skill_name
        });
    }


    select.value = "";

    renderSkills(type);
}


/*
|--------------------------------------------------------------------------
| Remove Skill
|--------------------------------------------------------------------------
*/

function removeSkill(type, skillId)
{
    editSelectedSkills = editSelectedSkills.filter(item => String(item.skill_id) !== String(skillId));
    renderSkills(type);
}

/*
|--------------------------------------------------------------------------
| Render Skill Chips
|--------------------------------------------------------------------------
*/

function renderSkills(type)
{
    const container = document.getElementById(
        "editSkillList"
    );


    const selectedSkills =
        editSelectedSkills;


    container.innerHTML = "";


    selectedSkills.forEach(function(skill) {

        const chip = document.createElement("div");

        chip.className = "selected-skill";


        const text = document.createElement("span");

        text.textContent = skill.skill_name;


        const removeButton =
            document.createElement("button");

        removeButton.type = "button";

        removeButton.innerHTML = "×";

        removeButton.title =
            "ลบความสามารถนี้";


        removeButton.onclick = function() {

            removeSkill(
                type,
                skill.skill_id
            );
        };


        chip.appendChild(text);

        chip.appendChild(removeButton);

        container.appendChild(chip);


        /*
        |--------------------------------------------------------------------------
        | Hidden Input
        |--------------------------------------------------------------------------
        */

        const hidden =
            document.createElement("input");

        hidden.type = "hidden";

        hidden.name = "skills[]";

        hidden.value = skill.skill_id;

        container.appendChild(hidden);

    });
}


/*
|--------------------------------------------------------------------------
| Delete Modal
|--------------------------------------------------------------------------
*/

function openDeleteModal(
    mechanicId,
    mechanicName
)
{
    document.getElementById(
        "deleteMechanicId"
    ).value = mechanicId;


    document.getElementById(
        "deleteMechanicName"
    ).textContent = mechanicName;


    document.getElementById(
        "deleteModal"
    ).classList.add("show");
}


/*
|--------------------------------------------------------------------------
| ESC
|--------------------------------------------------------------------------
*/

document.addEventListener(
    "keydown",
    function(event) {

        if (event.key === "Escape") {

            document
                .querySelectorAll(".modal-overlay.show")
                .forEach(function(modal) {

                    modal.classList.remove("show");

                });
        }

    }
);

</script>


<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>
</html>
