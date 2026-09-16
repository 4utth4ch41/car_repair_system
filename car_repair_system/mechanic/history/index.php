<?php

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

    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $userId
    ]);

    $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);

} else {

    $stmt = $db->prepare("
        SELECT
            mechanic_id,
            full_name,
            status
        FROM mechanics
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result = $stmt->get_result();

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

    $stmt = $db->prepare($sql);

    $stmt->execute([
        $mechanicId
    ]);

    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $stmt = $db->prepare($sql);

    $stmt->bind_param(
        "i",
        $mechanicId
    );

    $stmt->execute();

    $result = $stmt->get_result();

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


        $stmt = $db->prepare(
            $sqlParts
        );

        $stmt->execute(
            $jobIds
        );


        while (
            $part = $stmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $partsByJob[
                $part['repair_order_id']
            ][] = $part;
        }

    } else {

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


        $stmt->execute();

        $result = $stmt->get_result();


        while (
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
        ประวัติการทำงาน | P.Chalermchai Car Service
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

</head>


<body>


<!-- =====================================================
     TOPBAR
====================================================== -->

<header class="topbar">

    <div class="brand">
        P.Chalermchai Car Service
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

        <div class="page-header">

            <div>

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


</body>

</html>
