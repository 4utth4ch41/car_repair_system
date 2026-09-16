<?php

require_once "../config/database.php";
require_once "../includes/auth.php";
require_once "../includes/admin_auth.php";

/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/
// จำนวนการนัดหมายวันนี้
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM appointments
    WHERE appointment_date = CURDATE()
    AND status = 'scheduled'
");
$todayAppointments = $stmt->fetchColumn();

// จำนวนรถทั้งหมด
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM vehicles
");
$totalVehicles = $stmt->fetchColumn();

// จำนวนลูกค้าทั้งหมด
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM customers
");
$totalCustomers = $stmt->fetchColumn();
// รายรับเดือนนี้
$stmt = $pdo->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM invoices
    WHERE YEAR(issue_date) = YEAR(CURDATE())
    AND MONTH(issue_date) = MONTH(CURDATE())
    AND status <> 'overdue'
");
$monthlyIncome = $stmt->fetchColumn();
// งานซ่อมที่กำลังดำเนินการ
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM repair_jobs
    WHERE status = 'in_progress'
");
$activeRepairJobs = $stmt->fetchColumn();
// ช่างที่กำลังทำงาน
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM mechanics
    WHERE status = 'busy'
");
$busyMechanics = $stmt->fetchColumn();
/*
|--------------------------------------------------------------------------
| Upcoming Appointments
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        c.full_name,
        v.license_plate
    FROM appointments a
    INNER JOIN customers c
        ON a.customer_id = c.customer_id
    INNER JOIN vehicles v
        ON a.vehicle_id = v.vehicle_id
    WHERE a.appointment_date >= CURDATE()
    AND a.status = 'scheduled'
    ORDER BY a.appointment_date ASC,
             a.appointment_time ASC
    LIMIT 5
");

$upcomingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Active Repair Jobs
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        r.repair_order_code,
        r.service_date,
        c.full_name,
        v.license_plate,
        m.full_name AS mechanic_name
    FROM repair_jobs r

    INNER JOIN customers c
        ON r.customer_id = c.customer_id

    INNER JOIN vehicles v
        ON r.vehicle_id = v.vehicle_id

    LEFT JOIN mechanics m
        ON r.mechanic_id = m.mechanic_id

    WHERE r.status = 'in_progress'

    ORDER BY r.service_date ASC

    LIMIT 5
");

$activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | P.Chalermchai Car Service</title>

    <link rel="stylesheet"
      href="../assets/css/admin/dashboard.css">

          
</head>


<body>

<div class="app">


    <!-- =========================================================
         TOP NAVBAR
    ========================================================== -->

    <header class="topbar">

        <div class="brand">

            P.Chalermchai Car Service

        </div>

        <div class="topbar-center">
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
                href="../logout.php"
                class="logout-btn"
            >
                ออกจากระบบ
            </a>

        </div>

    </header>


    <!-- =========================================================
         SIDEBAR
    ========================================================== -->

    <aside class="sidebar">

        <nav>

            <a
                href="dashboard.php"
                class="sidebar-link active"
            >
                <span>🏠</span>
                <span>หน้าหลัก</span>
            </a>


            <a
                href="customers/index.php"
                class="sidebar-link"
            >
                <span>👥</span>
                <span>ข้อมูลลูกค้า</span>
            </a>

            <a
                href="appointments/index.php"
                class="sidebar-link"
            >
                <span>📅</span>
                <span>การนัดหมาย</span>
            </a>


            <a
                href="repair_jobs/index.php"
                class="sidebar-link"
            >
                <span>🔧</span>
                <span>ใบงานซ่อม</span>
            </a>


            <a
                href="../admin/technician/index.php"
                class="sidebar-link"
            >
                <span>👨‍🔧</span>
                <span>จัดการช่าง</span>
            </a>


            <a
                href="financial/index.php"
                class="sidebar-link"
            >
                <span>💰</span>
                <span>การเงิน</span>
            </a>

            <a
                href="users/index.php"
                class="sidebar-link"
            >
                <span>👤</span>
                <span>ผู้ใช้งาน</span>
            </a>

        </nav>

    </aside>


    <!-- =========================================================
         MAIN CONTENT
    ========================================================== -->

    <main class="main-content">


        <div class="page-header">

            <div>

                <h1>รายงานบริการของเรา</h1>

                <p>
                    ภาพรวมการให้บริการของอู่
                </p>

            </div>

        </div>


        <!-- =====================================================
             STATISTICS CARDS
        ====================================================== -->

        <section class="stats-grid">


            <!-- นัดหมาย -->

            <div class="stat-card">

                <div class="stat-icon appointment">
                    📅
                </div>

                <div class="stat-info">

                    <span>
                        นัดหมายวันนี้
                    </span>

                    <strong>
                        <?= number_format($todayAppointments) ?>
                    </strong>

                </div>

            </div>


            <!-- รถยนต์ -->

            <div class="stat-card">

                <div class="stat-icon vehicle">
                    🚗
                </div>

                <div class="stat-info">

                    <span>
                        รถยนต์ทั้งหมด
                    </span>

                    <strong>
                        <?= number_format($totalVehicles) ?>
                    </strong>

                </div>

            </div>


            <!-- ลูกค้า -->

            <div class="stat-card">

                <div class="stat-icon customer">
                    👥
                </div>

                <div class="stat-info">

                    <span>
                        ลูกค้าทั้งหมด
                    </span>

                    <strong>
                        <?= number_format($totalCustomers) ?>
                    </strong>

                </div>

            </div>


            <!-- รายรับ -->

            <div class="stat-card">

                <div class="stat-icon income">
                    💰
                </div>

                <div class="stat-info">

                    <span>
                        รายรับเดือนนี้
                    </span>

                    <strong>
                        <?= number_format($monthlyIncome, 2) ?>
                        <small>บาท</small>
                    </strong>

                </div>

            </div>


            <!-- งานซ่อม -->

            <div class="stat-card">

                <div class="stat-icon repair">
                    🔧
                </div>

                <div class="stat-info">

                    <span>
                        งานซ่อมที่กำลังดำเนินการ
                    </span>

                    <strong>
                        <?= number_format($activeRepairJobs) ?>
                    </strong>

                </div>

            </div>


            <!-- ช่าง -->

            <div class="stat-card">

                <div class="stat-icon mechanic">
                    👨‍🔧
                </div>

                <div class="stat-info">

                    <span>
                        ช่างที่กำลังทำงาน
                    </span>

                    <strong>
                        <?= number_format($busyMechanics) ?>
                    </strong>

                </div>

            </div>

        </section>


        <!-- =====================================================
             DASHBOARD TABLES
        ====================================================== -->

        <section class="dashboard-grid">


            <!-- =================================================
                 UPCOMING APPOINTMENTS
            ================================================== -->

            <div class="dashboard-card">

                <div class="card-header">

                    <div>

                        <h2>📅 นัดหมายที่กำลังจะถึง</h2>

                        <p>
                            รายการนัดหมายล่าสุด
                        </p>

                    </div>

                    <a href="appointments/index.php">
                        ดูทั้งหมด
                    </a>

                </div>


                <?php if (count($upcomingAppointments) > 0): ?>

                    <div class="appointment-list">

                        <?php foreach ($upcomingAppointments as $appointment): ?>

                            <div class="appointment-item">

                                <div class="appointment-date">

                                    <strong>
                                        <?= date(
                                            "d/m/Y",
                                            strtotime($appointment["appointment_date"])
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= date(
                                            "H:i",
                                            strtotime($appointment["appointment_time"])
                                        ) ?>
                                        น.
                                    </span>

                                </div>


                                <div class="appointment-detail">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $appointment["full_name"]
                                        ) ?>
                                    </strong>

                                    <span>
                                        🚗
                                        <?= htmlspecialchars(
                                            $appointment["license_plate"]
                                        ) ?>
                                    </span>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <span>📅</span>

                        <p>
                            ยังไม่มีรายการนัดหมาย
                        </p>

                    </div>

                <?php endif; ?>

            </div>


            <!-- =================================================
                 ACTIVE REPAIR JOBS
            ================================================== -->

            <div class="dashboard-card">

                <div class="card-header">

                    <div>

                        <h2>🔧 งานซ่อมที่กำลังดำเนินการ</h2>

                        <p>
                            งานที่ช่างกำลังดำเนินการ
                        </p>

                    </div>

                    <a href="repair_jobs/index.php">
                        ดูทั้งหมด
                    </a>

                </div>


                <?php if (count($activeJobs) > 0): ?>

                    <div class="repair-list">

                        <?php foreach ($activeJobs as $job): ?>

                            <div class="repair-item">

                                <div class="repair-code">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $job["repair_order_code"]
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $job["license_plate"]
                                        ) ?>
                                    </span>

                                </div>


                                <div class="repair-detail">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $job["full_name"]
                                        ) ?>
                                    </strong>

                                    <span>

                                        👨‍🔧

                                        <?= $job["mechanic_name"]
                                            ? htmlspecialchars(
                                                $job["mechanic_name"]
                                            )
                                            : "ยังไม่ได้มอบหมาย"
                                        ?>

                                    </span>

                                </div>


                                <div class="status-badge">

                                    กำลังซ่อม

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <span>🔧</span>

                        <p>
                            ไม่มีงานซ่อมที่กำลังดำเนินการ
                        </p>

                    </div>

                <?php endif; ?>

            </div>


        </section>


    </main>

</div>

</body>

</html>