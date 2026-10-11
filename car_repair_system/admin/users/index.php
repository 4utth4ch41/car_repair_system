<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * เพิ่มบัญชีต้องเช็ก username ซ้ำก่อน ส่วนแก้บัญชีจะไม่เอา user_id ของตัวเองมานับว่าซ้ำ
 * รหัสผ่านถูก password_hash ก่อนเก็บ บัญชีใหม่ยังไม่กำหนด role จนกว่าแอดมินจะเลือกบทบาท
 * กำหนดบทบาทจริงอยู่ที่ includes/user_roles.php ถ้าเป็นช่างจะสร้าง mechanics และผูกทักษะด้วย
 * ก่อนลบบัญชีตรวจว่ามีข้อมูลช่างผูกอยู่หรือไม่ เพื่อไม่ให้ลบบัญชีที่ต้องจัดการจากหน้าช่าง
 */
require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";
require_once "../../includes/user_roles.php";

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function redirectUsers($message = "")
{
    if ($message !== "") {
        header("Location: index.php?success=" . urlencode($message));
    } else {
        header("Location: index.php");
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| Handle POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | ADD USER
    |--------------------------------------------------------------------------
    */

    if ($action === "add") {

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $role = null;


        // ตรวจสอบข้อมูล
        if ($username === "" || strlen($password) < 6 || mb_strlen($username) > 50) {

            redirectUsers("กรุณากรอกชื่อผู้ใช้ไม่เกิน 50 ตัวอักษร และรหัสผ่านอย่างน้อย 6 ตัวอักษร");

        }

        // ตรวจสอบ Username ซ้ำ
        /*
         * ตรงนี้อ่านข้อมูลจาก users (บัญชีผู้ใช้)
         * เงื่อนไข WHERE ที่ใช้จริง: username = :username 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $checkSql = "
            SELECT user_id
            FROM users
            WHERE username = :username
            LIMIT 1
        ";

        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $checkStmt = $pdo->prepare($checkSql);

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $checkStmt->execute([
            ":username" => $username
        ]);

        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        if ($checkStmt->fetch()) {

            redirectUsers("Username นี้มีอยู่ในระบบแล้ว");

        }


        // Hash Password
        $hashedPassword = password_hash(
            $password,
            PASSWORD_DEFAULT
        );


        // Insert User
        /*
         * เพิ่มแถวใหม่ลง users (บัญชีผู้ใช้)
         * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $sql = "
            INSERT INTO users
            (
                username,
                password,
                role,
                is_active
            )
            VALUES
            (
                :username,
                :password,
                :role,
                1
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
            ":username" => $username,
            ":password" => $hashedPassword,
            ":role" => $role
        ]);


        redirectUsers("เพิ่มบัญชีแล้ว กรุณากำหนดบทบาทก่อนเข้าใช้งาน");
    }


    /*
    |--------------------------------------------------------------------------
    | EDIT USER
    |--------------------------------------------------------------------------
    */

    if ($action === 'assign_role') {
        try {
            assignUserRole($pdo, (int)($_POST['user_id'] ?? 0), $_POST['role'] ?? '', $_POST);
            redirectUsers('กำหนดบทบาทเรียบร้อยแล้ว');
        } catch (InvalidArgumentException $e) {
            header('Location: index.php?error=' . urlencode($e->getMessage()));
            exit;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            header('Location: index.php?error=' . urlencode('ไม่สามารถกำหนดบทบาทได้ กรุณาลองอีกครั้ง'));
            exit;
        }
    }
    if ($action === "edit") {

        $userId = (int)($_POST["user_id"] ?? 0);
        $username = trim($_POST["username"] ?? "");


        if ($userId <= 0 || $username === "") {

            redirectUsers("ข้อมูลไม่ถูกต้อง");

        }

        // ตรวจสอบ Username ซ้ำ
        /*
         * ตรงนี้อ่านข้อมูลจาก users (บัญชีผู้ใช้)
         * เงื่อนไข WHERE ที่ใช้จริง: username = :username AND user_id != :user_id 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $checkSql = "
            SELECT user_id
            FROM users
            WHERE username = :username
            AND user_id != :user_id
            LIMIT 1
        ";

        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $checkStmt = $pdo->prepare($checkSql);

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $checkStmt->execute([
            ":username" => $username,
            ":user_id" => $userId
        ]);

        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        if ($checkStmt->fetch()) {

            redirectUsers("Username นี้มีอยู่ในระบบแล้ว");

        }


        // Update
        /*
         * แก้ข้อมูลเดิมใน users (บัญชีผู้ใช้) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: user_id = :user_id
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $sql = "
            UPDATE users
            SET
                username = :username
            WHERE user_id = :user_id
        ";

        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $stmt = $pdo->prepare($sql);

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            ":username" => $username,
            ":user_id" => $userId
        ]);


        redirectUsers("แก้ไขข้อมูลผู้ใช้งานเรียบร้อยแล้ว");
    }


    /*
    |--------------------------------------------------------------------------
    | RESET PASSWORD
    |--------------------------------------------------------------------------
    */

    if ($action === "reset_password") {

        $userId = (int)($_POST["user_id"] ?? 0);

        $password = $_POST["password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";


        if ($userId <= 0 || $password === "" || $confirmPassword === "") {

            redirectUsers("กรุณากรอกรหัสผ่านให้ครบ");

        }


        // ตรวจสอบ Password ตรงกัน
        if ($password !== $confirmPassword) {

            redirectUsers("รหัสผ่านไม่ตรงกัน");

        }


        // Hash Password
        $hashedPassword = password_hash(
            $password,
            PASSWORD_DEFAULT
        );


        // Update Password
        /*
         * แก้ข้อมูลเดิมใน users (บัญชีผู้ใช้) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: user_id = :user_id
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $sql = "
            UPDATE users
            SET password = :password
            WHERE user_id = :user_id
        ";

        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $stmt = $pdo->prepare($sql);

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            ":password" => $hashedPassword,
            ":user_id" => $userId
        ]);


        redirectUsers("รีเซ็ตรหัสผ่านเรียบร้อยแล้ว");
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE USER
    |--------------------------------------------------------------------------
    */

    if ($action === "delete") {

        $userId = (int)($_POST["user_id"] ?? 0);


        if ($userId <= 0) {

            redirectUsers("ไม่พบผู้ใช้งาน");

        }


        /*
        | ป้องกัน Admin ลบบัญชีตัวเอง
        */

        if ($userId === (int)$_SESSION["user_id"]) {

            redirectUsers("ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้");

        }
        /*
        | ป้องกันการลบบัญชีช่างจากหน้า Users
        */

        /*
         * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง)
         * เงื่อนไข WHERE ที่ใช้จริง: user_id = :user_id 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $checkMechanic = $pdo->prepare("
            SELECT mechanic_id
            FROM mechanics
            WHERE user_id = :user_id
            LIMIT 1
        ");

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $checkMechanic->execute([
            ":user_id" => $userId
        ]);

        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        if ($checkMechanic->fetch()) {

            redirectUsers(
                "ไม่สามารถลบบัญชีช่างจากหน้านี้ได้ กรุณาจัดการช่างผ่านเมนูจัดการช่าง"
            );

        }


        /*
         * ลบแถวออกจาก users (บัญชีผู้ใช้) ตามเงื่อนไขด้านล่าง
         * เงื่อนไข WHERE ที่ใช้จริง: user_id = :user_id
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $sql = "
            DELETE FROM users
            WHERE user_id = :user_id
        ";

        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $stmt = $pdo->prepare($sql);

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            ":user_id" => $userId
        ]);


        redirectUsers("ลบผู้ใช้งานเรียบร้อยแล้ว");
    }
}


/*
|--------------------------------------------------------------------------
| Get Users
|--------------------------------------------------------------------------
*/

/*
 * ตรงนี้อ่านข้อมูลจาก users (บัญชีผู้ใช้)
 * เรียงตาม: user_id DESC
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$sql = "
    SELECT
        user_id,
        username,
        role,
        is_active,
        created_at,
        updated_at
    FROM users
    ORDER BY user_id DESC
";

$stmt = $pdo->query($sql);

/*
 * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
 */
$users = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Success Message
|--------------------------------------------------------------------------
*/

$success = $_GET["success"] ?? "";
$error = $_GET["error"] ?? "";
/*
 * ตรงนี้อ่านข้อมูลจาก skills (ทักษะช่าง)
 * เรียงตาม: skill_name
 * ASC คือค่าน้อย/วันเก่าก่อน DESC คือค่ามาก/วันใหม่ก่อน ถ้าค่าแรกเท่ากันค่อยดูคอลัมน์ถัดไป
 */
$skillsList = $pdo->query("SELECT skill_id, skill_name FROM skills ORDER BY skill_name")->fetchAll();

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
        จัดการบัญชีผู้ใช้ | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส
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


    <!-- CSS หน้าจัดการผู้ใช้ -->

    <link
        rel="stylesheet"
        href="../../assets/css/admin/users.css"
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


        <a href="../technician/index.php">

            <span class="menu-icon">👨‍🔧</span>

            <span>จัดการช่าง</span>

        </a>


        <a
            href="../users/index.php"
            class="active"
        >

            <span class="menu-icon">👤</span>

            <span>ผู้ใช้งาน</span>

        </a>

    </nav>

</aside>



<!-- =====================================================
     MAIN
===================================================== -->

<main class="main-content">

    <section class="users-page">


        <!-- HEADER -->

        <div class="users-header garage-page-header">

            <div>

                <!-- PAGE ICON: decorative icon for this page -->
                <span class="garage-heading-icon" aria-hidden="true">
                    <i class="fas fa-user-shield"></i>
                </span>

                <h1>
                    การจัดการผู้ใช้งาน
                </h1>

                <p>
                    จัดการบัญชีผู้ใช้งานและสิทธิ์การเข้าใช้งานระบบ
                </p>

            </div>


            <button
                type="button"
                class="btn-primary"
                onclick="openAddModal()"
            >

                <span class="plus">+</span>

                เพิ่มข้อมูลผู้ใช้งาน

            </button>

        </div>



        <!-- SUCCESS MESSAGE -->

        <?php if ($success !== ""): ?>

            <div class="success-message">

                <?= htmlspecialchars($success) ?>

            </div>

        <?php endif; ?>



<?php if ($error !== ''): ?><div class="error-message" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="role-filter"><label for="roleFilter">บทบาท</label><select id="roleFilter" onchange="filterUsers(this.value)"><option value="all">ทั้งหมด</option><option value="pending">รอกำหนดบทบาท</option><option value="admin">แอดมิน</option><option value="mechanic">ช่าง</option></select></div>
<p id="noRoleResults" hidden>ไม่พบบัญชีในบทบาทที่เลือก</p>
        <!-- USER CARDS -->

        <div class="users-grid">

            <?php if (count($users) === 0): ?>

                <div class="empty-state">

                    <h3>
                        ยังไม่มีผู้ใช้งาน
                    </h3>

                    <p>
                        กดปุ่ม "เพิ่มข้อมูลผู้ใช้งาน"
                        เพื่อสร้างบัญชีใหม่
                    </p>

                </div>

            <?php endif; ?>



            <?php foreach ($users as $user): ?>

                <div class="user-card" data-role="<?= $user["role"] === null ? "pending" : htmlspecialchars($user["role"]) ?>">


                    <!-- USER INFO -->

                    <div class="user-card-top">

                        <div class="avatar">

                            <?= htmlspecialchars(
                                mb_substr(
                                    $user["username"],
                                    0,
                                    1
                                )
                            ) ?>

                        </div>


                        <div class="user-details">

                            <h3>

                                <?= htmlspecialchars(
                                    $user["username"]
                                ) ?>

                            </h3>


                            <span class="role-badge <?= $user["role"] === null ? "role-pending" : ($user["role"] === "admin" ? "role-admin" : "role-mechanic") ?>"><?= $user["role"] === null ? "รอกำหนดบทบาท" : ($user["role"] === "admin" ? "แอดมิน" : "ช่าง") ?></span>

                        </div>

                    </div>



                    <!-- STATUS -->

                    <div class="user-status">

                        <?php if ($user["role"] === null): ?><span class="status-inactive">● รอกำหนดบทบาท — ยังเข้าใช้งานไม่ได้</span><?php elseif ((int)$user["is_active"] === 1): ?>

                            <span class="status-active">
                                ● ใช้งานอยู่
                            </span>

                        <?php else: ?>

                            <span class="status-inactive">
                                ● ปิดใช้งาน
                            </span>

                        <?php endif; ?>

                    </div>



                    <!-- ACTIONS -->

                    <div class="user-actions">
<?php if ($user["role"] === null): ?><button type="button" class="action-btn" onclick="openRoleModal(<?= (int)$user["user_id"] ?>)">กำหนดบทบาท</button><?php endif; ?>


                        <!-- EDIT -->

                        <button
                            type="button"
                            class="action-btn"
                            onclick='openEditModal(
                                <?= json_encode($user["user_id"]) ?>,
                                <?= json_encode($user["username"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                                <?= json_encode($user["role"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                            )'
                        >

                            ✎ แก้ไข

                        </button>



                        <!-- RESET PASSWORD -->

                        <button
                            type="button"
                            class="action-btn"
                            onclick='openResetModal(
                                <?= json_encode($user["user_id"]) ?>,
                                <?= json_encode($user["username"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                            )'
                        >

                            🔑 รีเซ็ตรหัสผ่าน

                        </button>



                        <!-- DELETE -->

                        <?php if (
                            (int)$user["user_id"]
                            !==
                            (int)$_SESSION["user_id"] && $user["role"] !== "mechanic"
                        ): ?>

                            <button
                                type="button"
                                class="action-btn danger"
                                onclick='openDeleteModal(
                                    <?= json_encode($user["user_id"]) ?>,
                                    <?= json_encode($user["username"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                )'
                            >

                                🗑 ลบ

                            </button>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </section>

</main>



<!-- =====================================================
     ADD USER MODAL
===================================================== -->

<div
    id="addModal"
    class="modal"
>

    <div class="modal-content">

        <button
            type="button"
            class="modal-close"
            onclick="closeModal('addModal')"
        >
            ×
        </button>


        <h2>
            เพิ่มผู้ใช้งาน
        </h2>


        <p class="modal-description">
            สร้างบัญชีผู้ใช้งานใหม่
        </p>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="add"
            >


            <div class="form-group">

                <label>
                    ชื่อผู้ใช้งาน *
                </label>

                <input
                    type="text"
                    name="username"
                    placeholder="กรอกชื่อผู้ใช้งาน"
                    required
                >

            </div>



            <div class="form-group">

                <label>
                    รหัสผ่าน *
                </label>

                <input
                    type="password"
                    name="password"
                    placeholder="กรอกรหัสผ่าน"
                    minlength="6"
                    required
                >

            </div>



            <div class="form-group">

                <label>
                    ตำแหน่ง
                </label>

                <input type="text" value="รอกำหนดบทบาท" readonly><small class="form-help">บันทึกบัญชีก่อน แล้วกำหนดบทบาทภายหลัง บัญชียังเข้าใช้งานไม่ได้จนกว่าจะมีบทบาท</small>

            </div>



            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeModal('addModal')"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-primary"
                >
                    บันทึกข้อมูล
                </button>

            </div>

        </form>

    </div>

</div>



<!-- =====================================================
     EDIT USER MODAL
===================================================== -->

<div
    id="editModal"
    class="modal"
>

    <div class="modal-content">

        <button
            type="button"
            class="modal-close"
            onclick="closeModal('editModal')"
        >
            ×
        </button>


        <h2>
            แก้ไขข้อมูลผู้ใช้งาน
        </h2>


        <p class="modal-description">
            แก้ไขชื่อผู้ใช้งาน บทบาทที่กำหนดแล้วไม่สามารถเปลี่ยนได้
        </p>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="edit"
            >


            <input
                type="hidden"
                name="user_id"
                id="edit_user_id"
            >


            <div class="form-group">

                <label>
                    ชื่อผู้ใช้งาน *
                </label>

                <input
                    type="text"
                    name="username"
                    id="edit_username"
                    required
                >

            </div>



            <div class="form-group">

                <label>
                    ตำแหน่ง
                </label>

                <input
                    type="text"
                    id="edit_role_display"
                    readonly
                >

                <small class="form-help">
                    ตำแหน่งไม่สามารถเปลี่ยนจากหน้านี้ได้
                </small>

            </div>



            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeModal('editModal')"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-primary"
                >
                    บันทึกการแก้ไข
                </button>

            </div>

        </form>

    </div>

</div>



<!-- =====================================================
     RESET PASSWORD MODAL
===================================================== -->

<div
    id="resetModal"
    class="modal"
>

    <div class="modal-content">

        <button
            type="button"
            class="modal-close"
            onclick="closeModal('resetModal')"
        >
            ×
        </button>


        <h2>
            รีเซ็ตรหัสผ่าน
        </h2>


        <p class="modal-description">

            ผู้ใช้งาน:
            <strong id="reset_username"></strong>

        </p>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="reset_password"
            >


            <input
                type="hidden"
                name="user_id"
                id="reset_user_id"
            >


            <div class="form-group">

                <label>
                    รหัสผ่านใหม่ *
                </label>

                <input
                    type="password"
                    name="password"
                    minlength="6"
                    required
                >

            </div>



            <div class="form-group">

                <label>
                    ยืนยันรหัสผ่าน *
                </label>

                <input
                    type="password"
                    name="confirm_password"
                    minlength="6"
                    required
                >

            </div>



            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeModal('resetModal')"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-primary"
                >
                    รีเซ็ตรหัสผ่าน
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
    class="modal"
>

    <div class="modal-content delete-modal">

        <button
            type="button"
            class="modal-close"
            onclick="closeModal('deleteModal')"
        >
            ×
        </button>


        <h2>
            ยืนยันการลบผู้ใช้งาน
        </h2>


        <p>

            คุณต้องการลบบัญชี

            <strong id="delete_username"></strong>

            ออกจากระบบหรือไม่?

        </p>


        <p class="warning-text">

            การดำเนินการนี้ไม่สามารถย้อนกลับได้

        </p>


        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="delete"
            >


            <input
                type="hidden"
                name="user_id"
                id="delete_user_id"
            >


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn-secondary"
                    onclick="closeModal('deleteModal')"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn-danger"
                >
                    ลบผู้ใช้งาน
                </button>

            </div>

        </form>

    </div>

</div>



<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<div id="roleModal" class="modal">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeModal('roleModal')">×</button>
        <h2>กำหนดบทบาท</h2>
        <p class="modal-description">กำหนดได้ครั้งเดียว เมื่อบันทึกแล้วจะเปลี่ยนบทบาทไม่ได้</p>
        <form method="POST" id="roleForm">
            <input type="hidden" name="action" value="assign_role">
            <input type="hidden" name="user_id" id="role_user_id">
            <div class="form-group"><label for="roleSelect">บทบาท *</label>
                <select name="role" id="roleSelect" required onchange="toggleMechanicFields()">
                    <option value="">เลือกบทบาท</option><option value="admin">แอดมิน</option><option value="mechanic">ช่าง</option>
                </select>
            </div>
            <fieldset id="mechanicFields" hidden disabled>
                <div class="form-group"><label for="roleFullName">ชื่อ - นามสกุลช่าง *</label><input id="roleFullName" name="full_name" maxlength="150" required></div>
                <div class="form-group"><label for="rolePhone">เบอร์โทรศัพท์</label><input id="rolePhone" name="phone" maxlength="20"></div>
                <div class="form-group"><label>ความสามารถและความถนัด</label><div class="role-skills">
                    <?php foreach ($skillsList as $skill): ?>
                    <label class="role-skill"><input type="checkbox" name="skills[]" value="<?= (int)$skill['skill_id'] ?>"> <?= htmlspecialchars($skill['skill_name']) ?></label>
                    <?php endforeach; ?>
                </div></div>
                <div class="form-group"><label for="roleStatus">สถานะการรับงาน</label><select id="roleStatus" name="status"><option value="available">พร้อมรับงาน</option><option value="busy">ไม่ว่าง</option><option value="inactive">พักงาน</option></select></div>
            </fieldset>
            <div class="modal-actions"><button type="button" class="btn-secondary" onclick="closeModal('roleModal')">ยกเลิก</button><button type="submit" class="btn-primary">ยืนยันบทบาท</button></div>
        </form>
    </div>
</div>
<script>
function openRoleModal(userId) {
    document.getElementById('roleForm').reset();
    document.getElementById('role_user_id').value = userId;
    toggleMechanicFields();
    document.getElementById('roleModal').classList.add('show');
}
function toggleMechanicFields() {
    const fields = document.getElementById('mechanicFields');
    const mechanic = document.getElementById('roleSelect').value === 'mechanic';
    fields.hidden = !mechanic;
    fields.disabled = !mechanic;
}
function filterUsers(role) {
    let visible = 0;
    document.querySelectorAll('.user-card[data-role]').forEach(card => {
        card.hidden = role !== 'all' && card.dataset.role !== role;
        if (!card.hidden) visible++;
    });
    document.getElementById('noRoleResults').hidden = visible > 0;
}


function openAddModal()
{
    document.getElementById("addModal")
        .classList.add("show");
}


function openEditModal(
    userId,
    username,
    role
)
{
    document.getElementById("edit_user_id")
        .value = userId;

    document.getElementById("edit_username")
        .value = username;

    document.getElementById("edit_role_display")
        .value =
            role === "admin"
                ? "แอดมิน"
                : (role === "mechanic" ? "ช่าง" : "รอกำหนดบทบาท");

    document.getElementById("editModal")
        .classList.add("show");
}


function openResetModal(
    userId,
    username
)
{
    document.getElementById("reset_user_id")
        .value = userId;

    document.getElementById("reset_username")
        .textContent = username;

    document.getElementById("resetModal")
        .classList.add("show");
}


function openDeleteModal(
    userId,
    username
)
{
    document.getElementById("delete_user_id")
        .value = userId;

    document.getElementById("delete_username")
        .textContent = username;

    document.getElementById("deleteModal")
        .classList.add("show");
}


function closeModal(modalId)
{
    document.getElementById(modalId)
        .classList.remove("show");
}


/*
|--------------------------------------------------------------------------
| Close Modal when clicking outside
|--------------------------------------------------------------------------
*/

window.addEventListener(
    "click",
    function(event)
    {
        if (
            event.target.classList.contains("modal")
        ) {

            event.target.classList.remove("show");

        }
    }
);

</script>


<!-- LOGOUT MODAL: shared confirmation for admin and user pages -->
<?php require_once __DIR__ . "/../../includes/logout_modal.php"; ?>

</body>

</html>
