<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * ประวัติเลือกเฉพาะใบงานของช่างที่เข้าสู่ระบบและ status = completed
 * เรียงงานจบล่าสุดก่อนด้วย end_time แล้วใช้ updated_at / created_at เป็นลำดับรอง
 * อะไหล่ดึงตามรหัสใบงานที่ได้จากชุดประวัติเท่านั้น แล้วจัดกลุ่มใน PHP เพื่อนำไปแสดง
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

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
    header("Location: ../../index.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

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
    $stmt->execute([
        $userId
    ]);

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
    $stmt->bind_param(
        "i",
        $userId
    );

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
| LOAD COMPLETED JOBS
|--------------------------------------------------------------------------
|
| ดึงเฉพาะงานที่ช่างคนนี้ทำเสร็จแล้ว
|
*/

/*
 * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม), customers (ลูกค้า), vehicles (รถยนต์)
 * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
 * เงื่อนไข WHERE ที่ใช้จริง: r.mechanic_id = ? AND r.status = 'completed' 
 * เรียงตาม: r.end_time DESC, r.updated_at DESC, r.created_at DESC
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

        v.color,

        v.mileage

    FROM repair_jobs r

    INNER JOIN customers c
        ON r.customer_id = c.customer_id

    INNER JOIN vehicles v
        ON r.vehicle_id = v.vehicle_id

    WHERE
        r.mechanic_id = ?

        AND r.status = 'completed'

    ORDER BY

        r.end_time DESC,

        r.updated_at DESC,

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

    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
| LOAD PARTS USED
|--------------------------------------------------------------------------
*/

$partsByJob = [];


if (!empty($jobs)) {

    $jobIds = array_map(
        'intval',
        array_column(
            $jobs,
            'repair_order_id'
        )
    );


    $placeholders = implode(
        ',',
        array_fill(
            0,
            count($jobIds),
            '?'
        )
    );


    if ($db instanceof PDO) {

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

            WHERE
                repair_order_id IN ($placeholders)

            ORDER BY
                part_id ASC
        ";


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
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            $part = $stmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $partsByJob[
                $part['repair_order_id']
            ][] = $part;
        }

    } else {

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

            WHERE
                repair_order_id IN ($placeholders)

            ORDER BY
                part_id ASC
        ";


        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $stmt = $db->prepare(
            $sqlParts
        );


        $types = str_repeat(
            "i",
            count($jobIds)
        );


        $params = [];

        $params[] = &$types;


        foreach ($jobIds as $key => $value) {

            $params[] = &$jobIds[$key];
        }


        call_user_func_array(
            [$stmt, 'bind_param'],
            $params
        );


        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute();

        $result = $stmt->get_result();


        while (
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            $part = $result->fetch_assoc()
        ) {

            $partsByJob[
                $part['repair_order_id']
            ][] = $part;
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$totalJobs = count($jobs);

$totalPartsCost = 0;


foreach ($jobs as $job) {

    $repairId = (int)$job['repair_order_id'];

    $jobParts =
        $partsByJob[$repairId] ?? [];


    foreach ($jobParts as $part) {

        $totalPartsCost +=
            (float)$part['total_price'];
    }
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

    <title>
        ประวัติการทำงาน | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
    </title>


    <!-- =====================================================
         GLOBAL CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="/car_repair_system/assets/css/style.css"
    >


    <!-- =====================================================
         SIDEBAR CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="/car_repair_system/assets/css/admin/sidebar.css"
    >


    <!-- =====================================================
         DASHBOARD / COMMON CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="/car_repair_system/assets/css/user/dashboard.css"
    >


    <!-- =====================================================
         HISTORY CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="/car_repair_system/assets/css/user/history.css"
    >

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


    <div class="topbar-center"></div>


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
            href="/car_repair_system/mechanic/repair_jobs/index.php"
            class="sidebar-link"
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
            class="sidebar-link active"
        >

            <span class="menu-icon">
                📋
            </span>

            <span>
                ประวัติการทำงาน
            </span>

        </a>


    </nav>

</aside>


<!-- =====================================================
     MAIN
====================================================== -->

<main class="main-content">

    <section class="history-page">


        <!-- =================================================
             PAGE HEADER
        ================================================== -->

        <div class="page-header garage-page-header">

            <div>

                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-clock-rotate-left"></i>
                </span>

                <h1>
                    ประวัติการทำงาน
                </h1>

                <p>
                    งานซ่อมที่คุณดำเนินการเสร็จเรียบร้อยแล้ว
                </p>

            </div>

        </div>


        <!-- =================================================
             SUMMARY
        ================================================== -->

        <div class="history-summary">


            <div class="history-summary-card">

                <div class="history-summary-icon">
                    📋
                </div>

                <div>

                    <span>
                        งานที่เสร็จแล้ว
                    </span>

                    <strong>
                        <?= $totalJobs ?>
                    </strong>

                </div>

            </div>


            <div class="history-summary-card">

                <div class="history-summary-icon">
                    🔩
                </div>

                <div>

                    <span>
                        ค่าอะไหล่รวม
                    </span>

                    <strong>
                        <?= number_format(
                            $totalPartsCost,
                            2
                        ) ?>
                        บาท
                    </strong>

                </div>

            </div>


        </div>


        <!-- =================================================
             SEARCH / FILTER
        ================================================== -->

        <div class="history-filter">

            <input
                type="text"
                id="historySearch"
                placeholder="ค้นหาด้วยชื่อลูกค้า รหัสงาน หรือทะเบียนรถ..."
                autocomplete="off"
            >


            <span>
                ทั้งหมด <?= $totalJobs ?> รายการ
            </span>

        </div>


        <!-- =================================================
             HISTORY LIST
        ================================================== -->

        <section class="history-list">


            <?php if (empty($jobs)): ?>


                <div class="history-empty">

                    <div class="history-empty-icon">
                        📋
                    </div>

                    <h2>
                        ยังไม่มีประวัติการทำงาน
                    </h2>

                    <p>
                        เมื่องานซ่อมถูกยืนยันว่าเสร็จแล้ว
                        ประวัติงานจะแสดงที่หน้านี้
                    </p>

                </div>


            <?php else: ?>


                <?php foreach (
                    $jobs as $index => $job
                ): ?>


                    <?php

                    $repairId =
                        (int)$job['repair_order_id'];


                    $jobParts =
                        $partsByJob[$repairId] ?? [];


                    $partsTotal = 0;


                    foreach (
                        $jobParts as $part
                    ) {

                        $partsTotal +=
                            (float)$part['total_price'];
                    }


                    ?>


                    <article
                        class="history-job"
                        data-search="
                            <?= h(
                                $job['repair_order_code']
                                . ' '
                                . $job['customer_name']
                                . ' '
                                . $job['license_plate']
                                . ' '
                                . $job['brand']
                                . ' '
                                . $job['model']
                            ) ?>
                        "
                    >


                        <!-- =================================================
                             HEADER
                        ================================================== -->

                        <button
                            type="button"
                            class="history-job-header"
                            onclick="toggleHistoryJob(this)"
                        >


                            <div class="history-job-check">
                                ✓
                            </div>


                            <div class="history-job-main">


                                <div class="history-job-top">

                                    <strong>
                                        <?= h(
                                            $job['repair_order_code']
                                        ) ?>
                                    </strong>


                                    <span class="history-status">
                                        เสร็จสิ้น
                                    </span>

                                </div>


                                <div class="history-customer">

                                    <?= h(
                                        $job['customer_name']
                                    ) ?>

                                </div>


                                <div class="history-vehicle">

                                    🚗

                                    <?= h(
                                        $job['brand']
                                    ) ?>

                                    <?= h(
                                        $job['model']
                                    ) ?>

                                    ·

                                    <?= h(
                                        $job['license_plate']
                                    ) ?>

                                </div>


                            </div>


                            <div class="history-job-date">

                                <?php if (
                                    !empty($job['end_time'])
                                ): ?>

                                    <?= date(
                                        'd/m/Y',
                                        strtotime(
                                            $job['end_time']
                                        )
                                    ) ?>

                                <?php else: ?>

                                    -

                                <?php endif; ?>

                            </div>


                            <div class="history-arrow">
                                ▼
                            </div>


                        </button>


                        <!-- =================================================
                             EXPANDED DETAIL
                        ================================================== -->

                        <div class="history-expanded">

                            <div class="history-expanded-inner">


                                <!-- =========================================
                                     BASIC INFORMATION
                                ========================================== -->

                                <div class="history-info-grid">


                                    <div class="history-info-box">

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


                                    <div class="history-info-box">

                                        <span>
                                            ความต้องการลูกค้า
                                        </span>

                                        <strong>

                                            <?= h(
                                                $job['customer_request']
                                                ?: 'ไม่ได้ระบุ'
                                            ) ?>

                                        </strong>

                                    </div>


                                    <div class="history-info-box">

                                        <span>
                                            วันรับรถ
                                        </span>

                                        <strong>

                                            <?php if (
                                                !empty(
                                                    $job['service_date']
                                                )
                                            ): ?>

                                                <?= date(
                                                    'd/m/Y',
                                                    strtotime(
                                                        $job['service_date']
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </strong>

                                    </div>


                                    <div class="history-info-box">

                                        <span>
                                            เสร็จงาน
                                        </span>

                                        <strong>

                                            <?php if (
                                                !empty(
                                                    $job['end_time']
                                                )
                                            ): ?>

                                                <?= date(
                                                    'd/m/Y H:i',
                                                    strtotime(
                                                        $job['end_time']
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </strong>

                                    </div>


                                </div>


                                <!-- =========================================
                                     REPAIR RESULT
                                ========================================== -->

                                <div class="history-detail-box">

                                    <div class="history-box-title">
                                        🔧 รายละเอียดการทำงาน
                                    </div>


                                    <div class="history-detail-grid">


                                        <div>

                                            <span>
                                                ผลการตรวจสอบ
                                            </span>

                                            <p>

                                                <?= nl2br(
                                                    h(
                                                        $job['inspection_result']
                                                        ?: 'ไม่ได้ระบุ'
                                                    )
                                                ) ?>

                                            </p>

                                        </div>


                                        <div>

                                            <span>
                                                รายละเอียดการซ่อม
                                            </span>

                                            <p>

                                                <?= nl2br(
                                                    h(
                                                        $job['repair_detail']
                                                        ?: 'ไม่ได้ระบุ'
                                                    )
                                                ) ?>

                                            </p>

                                        </div>


                                        <div>

                                            <span>
                                                ปัญหาเพิ่มเติม
                                            </span>

                                            <p>

                                                <?= nl2br(
                                                    h(
                                                        $job['additional_problem']
                                                        ?: 'ไม่มี'
                                                    )
                                                ) ?>

                                            </p>

                                        </div>


                                        <div>

                                            <span>
                                                หมายเหตุช่าง
                                            </span>

                                            <p>

                                                <?= nl2br(
                                                    h(
                                                        $job['mechanic_note']
                                                        ?: 'ไม่มี'
                                                    )
                                                ) ?>

                                            </p>

                                        </div>


                                    </div>

                                </div>


                                <!-- =========================================
                                     PARTS
                                ========================================== -->

                                <div class="history-parts-box">


                                    <div class="history-box-title">

                                        <span>
                                            🔩 อะไหล่ที่ใช้
                                        </span>

                                    </div>


                                    <?php if (
                                        !empty($jobParts)
                                    ): ?>


                                        <div class="history-parts-list">


                                            <?php foreach (
                                                $jobParts as $part
                                            ): ?>


                                                <div class="history-part-row">


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


                                        <div class="history-parts-total">

                                            <span>
                                                รวมค่าอะไหล่
                                            </span>

                                            <strong>
                                                <?= number_format(
                                                    $partsTotal,
                                                    2
                                                ) ?>
                                                บาท
                                            </strong>

                                        </div>


                                    <?php else: ?>


                                        <div class="history-no-parts">
                                            งานนี้ไม่มีการบันทึกอะไหล่
                                        </div>


                                    <?php endif; ?>


                                </div>


                            </div>

                        </div>


                    </article>


                <?php endforeach; ?>


            <?php endif; ?>


        </section>


    </section>

</main>


<!-- =====================================================
     JAVASCRIPT
====================================================== -->

<script>

/*
|--------------------------------------------------------------------------
| TOGGLE HISTORY JOB
|--------------------------------------------------------------------------
*/

function toggleHistoryJob(button)
{
    const job =
        button.closest('.history-job');

    if (!job) {
        return;
    }

    job.classList.toggle('is-open');
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

const searchInput =
    document.getElementById('historySearch');


if (searchInput) {

    searchInput.addEventListener(
        'input',
        function ()
        {
            const keyword =
                this.value
                    .trim()
                    .toLowerCase();


            document
                .querySelectorAll('.history-job')
                .forEach(function (job)
                {

                    const searchText =
                        (
                            job.dataset.search
                            || ''
                        ).toLowerCase();


                    if (
                        keyword === ''
                        ||
                        searchText.includes(keyword)
                    ) {

                        job.style.display = '';

                    } else {

                        job.style.display = 'none';

                    }

                });
        }
    );

}


/*
|--------------------------------------------------------------------------
| OPEN FIRST JOB
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'DOMContentLoaded',
    function ()
    {

        const firstJob =
            document.querySelector(
                '.history-job'
            );


        if (firstJob) {

            firstJob.classList.add(
                'is-open'
            );

        }

    }
);

</script>


<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>

</html>
