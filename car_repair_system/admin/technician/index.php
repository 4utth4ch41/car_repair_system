<?php

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

    $userId = (int)($_POST["user_id"] ?? 0);
    $fullName = trim($_POST["full_name"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $status = $_POST["status"] ?? "available";
    $skills = $_POST["skills"] ?? [];


    /*
    |--------------------------------------------------------------------------
    | Validate
    |--------------------------------------------------------------------------
    */

    if ($userId <= 0 || $fullName === "") {

        redirectWithMessage(
            "error",
            "กรุณาเลือกผู้ใช้งานและกรอกชื่อช่าง"
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

        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | Check User
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                user_id,
                username
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $userId
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$user) {

            throw new Exception(
                "ไม่พบผู้ใช้งานที่เลือก"
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Check Already Mechanic
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT mechanic_id
            FROM mechanics
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $userId
        ]);


        if ($stmt->fetch()) {

            throw new Exception(
                "ผู้ใช้งานนี้ถูกเพิ่มเป็นช่างแล้ว"
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Change User Role
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE users
            SET role = 'mechanic'
            WHERE user_id = ?
        ");

        $stmt->execute([
            $userId
        ]);


        /*
        |--------------------------------------------------------------------------
        | Create Mechanic
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO mechanics
            (
                user_id,
                full_name,
                phone,
                status
            )
            VALUES
            (
                :user_id,
                :full_name,
                :phone,
                :status
            )
        ");

        $stmt->execute([
            ":user_id" => $userId,
            ":full_name" => $fullName,
            ":phone" => $phone !== "" ? $phone : null,
            ":status" => $status
        ]);


        $mechanicId = $pdo->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | Insert Skills
        |--------------------------------------------------------------------------
        */

        if (!empty($skills)) {

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

                $skillId = (int)$skillId;


                if ($skillId <= 0) {
                    continue;
                }


                $stmt->execute([
                    ":mechanic_id" => $mechanicId,
                    ":skill_id" => $skillId
                ]);
            }
        }


        $pdo->commit();


        redirectWithMessage(
            "success",
            "เพิ่มช่างเรียบร้อยแล้ว Username คือ {$user["username"]}"
        );


    } catch (Exception $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();
        }


        redirectWithMessage(
            "error",
            "ไม่สามารถเพิ่มช่างได้: " . $e->getMessage()
        );
    }
}


    /*
    |--------------------------------------------------------------------------
    | EDIT TECHNICIAN
    |--------------------------------------------------------------------------
    */

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

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Get Mechanic
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT user_id
                FROM mechanics
                WHERE mechanic_id = ?
                LIMIT 1
            ");

            $stmt->execute([$mechanicId]);

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

            $stmt = $pdo->prepare("
                UPDATE mechanics
                SET
                    full_name = :full_name,
                    phone = :phone,
                    status = :status
                WHERE mechanic_id = :mechanic_id
            ");

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

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE user_id = ?
                ");

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

            $stmt = $pdo->prepare("
                DELETE FROM mechanic_skills
                WHERE mechanic_id = ?
            ");

            $stmt->execute([$mechanicId]);


            if (!empty($skills)) {

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

                    $stmt->execute([
                        ":mechanic_id" => $mechanicId,
                        ":skill_id" => $skillId
                    ]);
                }
            }


            $pdo->commit();


            redirectWithMessage(
                "success",
                "อัปเดตข้อมูลช่างเรียบร้อยแล้ว"
            );


        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
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


        $stmt = $pdo->prepare("
            UPDATE mechanics
            SET status = ?
            WHERE mechanic_id = ?
        ");

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

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM repair_jobs
                WHERE mechanic_id = ?
            ");

            $stmt->execute([$mechanicId]);

            $repairCount = (int) $stmt->fetchColumn();


            if ($repairCount > 0) {

                redirectWithMessage(
                    "error",
                    "ไม่สามารถลบช่างคนนี้ได้ เนื่องจากมีประวัติใบงานซ่อมอยู่ในระบบ แนะนำให้ใช้การพักงานแทน"
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Get User ID
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT user_id
                FROM mechanics
                WHERE mechanic_id = ?
                LIMIT 1
            ");

            $stmt->execute([$mechanicId]);

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

            $stmt = $pdo->prepare("
                DELETE FROM mechanic_skills
                WHERE mechanic_id = ?
            ");

            $stmt->execute([$mechanicId]);


            /*
            |--------------------------------------------------------------------------
            | Delete Mechanic
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                DELETE FROM mechanics
                WHERE mechanic_id = ?
            ");

            $stmt->execute([$mechanicId]);


            /*
            |--------------------------------------------------------------------------
            | Delete User
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                DELETE FROM users
                WHERE user_id = ?
                AND role = 'mechanic'
            ");

            $stmt->execute([
                $mechanic["user_id"]
            ]);


            $pdo->commit();


            redirectWithMessage(
                "success",
                "ลบข้อมูลช่างเรียบร้อยแล้ว"
            );


        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
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

$stmt = $pdo->query("
    SELECT
        skill_id,
        skill_name,
        description
    FROM skills
    ORDER BY skill_name ASC
");

$skillsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Get Available Users
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        u.user_id,
        u.username
    FROM users u
    LEFT JOIN mechanics m
        ON u.user_id = m.user_id
    WHERE m.user_id IS NULL
    ORDER BY u.username ASC
");

$availableUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Get Mechanics
|--------------------------------------------------------------------------
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
                WHEN rj.status IN ('waiting', 'in_progress')
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

$mechanics = $stmt->fetchAll(PDO::FETCH_ASSOC); 


/*
|--------------------------------------------------------------------------
| Get Skills For Mechanics
|--------------------------------------------------------------------------
*/

$mechanicSkills = [];

if (!empty($mechanics)) {

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
        จัดการช่าง | P.Chalermchai Car Service
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

</head>


<body>


<!-- =====================================================
     TOPBAR
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


        <a href="../appointments/index.php">

            <span class="menu-icon">📅</span>

            <span>การนัดหมาย</span>

        </a>


        <a href="../repair_jobs/index.php">

            <span class="menu-icon">🔧</span>

            <span>ใบงานซ่อม</span>

        </a>


        <a
            href="../technician/index.php"
            class="active"
        >

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

    <section class="technician-page">


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="technician-header">

            <div>

                <h1>
                    การจัดการช่าง
                </h1>

                <p>
                    จัดการข้อมูล ความสามารถ และสถานะการรับงานของช่าง
                </p>

            </div>


            <button
                type="button"
                class="btn-primary"
                onclick="openAddModal()"
            >

                <span class="plus">+</span>

                เพิ่มช่าง

            </button>

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
                    กดปุ่ม "เพิ่มช่าง" เพื่อเพิ่มช่างเข้าสู่ระบบ
                </p>

            </div>

        <?php endif; ?>


    </section>

</main>


<!-- =====================================================
     ADD TECHNICIAN MODAL
===================================================== -->

<div
    id="addModal"
    class="modal-overlay"
    onclick="closeModalOutside(event, 'addModal')" >

    <div class="technician-modal">

        <div class="modal-header">

            <div>

                <h2>
                    เพิ่มช่างใหม่
                </h2>

                <p>
                    กรอกข้อมูลสำหรับสร้างบัญชีช่าง
                </p>

            </div>


            <button
                type="button"
                class="modal-close"
                onclick="closeModal('addModal')"
            >
                ×
            </button>

        </div>


        <form
            method="POST"
            id="addTechnicianForm"
        >

            <input
                type="hidden"
                name="action"
                value="add"
            >

            <!-- USER -->

            <div class="form-group">

                <label>
                    เลือกผู้ใช้งาน
                    <span>*</span>
                </label>

                <select
                    name="user_id"
                    id="addUserSelect"
                    required
                >

                    <option value="">
                        + เลือกผู้ใช้งาน
                    </option>

                    <?php foreach ($availableUsers as $user): ?>

                        <option
                            value="<?= (int)$user["user_id"] ?>"
                        >
                            <?= htmlspecialchars(
                                $user["username"]
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <small class="form-help">
                    เลือกบัญชีผู้ใช้งานที่ต้องการกำหนดให้เป็นช่าง
                </small>

            </div>

            <!-- NAME -->

            <div class="form-group">

                <label>
                    ชื่อ - นามสกุล
                    <span>*</span>
                </label>

                <input
                    type="text"
                    name="full_name"
                    placeholder="เช่น สมชาย ใจดี"
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
                    placeholder="เช่น 0812345678"
                >

            </div>


            <!-- SKILLS -->

            <div class="form-group">

                <label>
                    ความสามารถและความถนัด
                </label>


                <select
                    id="addSkillSelect"
                    class="skill-select"
                    onchange="addSkill('add')"
                >

                    <option value="">
                        + เลือกความสามารถ
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
                    id="addSkillList"
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
                    onclick="closeModal('addModal')"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-submit"
                >
                    เพิ่มช่าง
                </button>

            </div>

        </form>

    </div>

</div>


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
            ลบข้อมูลช่าง
        </h2>


        <p>
            คุณต้องการลบข้อมูลช่าง
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

let addSelectedSkills = [];

let editSelectedSkills = [];


/*
|--------------------------------------------------------------------------
| Modal
|--------------------------------------------------------------------------
*/

function openAddModal()
{
    addSelectedSkills = [];

    renderSkills(
        "add"
    );

    document.getElementById("addTechnicianForm").reset();

    document.getElementById("addModal")
        .classList.add("show");
}


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
        type === "add"
            ? "addSkillSelect"
            : "editSkillSelect"
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
        type === "add"
            ? addSelectedSkills
            : editSelectedSkills;


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
    if (type === "add") {

        addSelectedSkills =
            addSelectedSkills.filter(
                item =>
                    String(item.skill_id) !== String(skillId)
            );

    } else {

        editSelectedSkills =
            editSelectedSkills.filter(
                item =>
                    String(item.skill_id) !== String(skillId)
            );
    }


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
        type === "add"
            ? "addSkillList"
            : "editSkillList"
    );


    const selectedSkills =
        type === "add"
            ? addSelectedSkills
            : editSelectedSkills;


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


</body>
</html>