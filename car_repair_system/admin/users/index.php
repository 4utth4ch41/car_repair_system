<?php

require_once "../../includes/admin_auth.php";
require_once "../../config/database.php";

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
        $role = $_POST["role"] ?? "admin";


        // ตรวจสอบข้อมูล
        if ($username === "" || $password === "" || $role === "") {

            redirectUsers("กรุณากรอกข้อมูลให้ครบ");

        }

        // หน้า Users สามารถสร้างได้เฉพาะบัญชี Admin
        if ($role !== "admin") {

            redirectUsers("ไม่สามารถสร้างบัญชีช่างจากหน้านี้ได้ กรุณาเพิ่มช่างผ่านเมนูจัดการช่าง");

        }

        // ตรวจสอบ Username ซ้ำ
        $checkSql = "
            SELECT user_id
            FROM users
            WHERE username = :username
            LIMIT 1
        ";

        $checkStmt = $pdo->prepare($checkSql);

        $checkStmt->execute([
            ":username" => $username
        ]);

        if ($checkStmt->fetch()) {

            redirectUsers("Username นี้มีอยู่ในระบบแล้ว");

        }


        // Hash Password
        $hashedPassword = password_hash(
            $password,
            PASSWORD_DEFAULT
        );


        // Insert User
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

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ":username" => $username,
            ":password" => $hashedPassword,
            ":role" => $role
        ]);


        redirectUsers("เพิ่มผู้ใช้งานเรียบร้อยแล้ว");
    }


    /*
    |--------------------------------------------------------------------------
    | EDIT USER
    |--------------------------------------------------------------------------
    */

    if ($action === "edit") {

        $userId = (int)($_POST["user_id"] ?? 0);
        $username = trim($_POST["username"] ?? "");


        if ($userId <= 0 || $username === "") {

            redirectUsers("ข้อมูลไม่ถูกต้อง");

        }

        // ตรวจสอบ Username ซ้ำ
        $checkSql = "
            SELECT user_id
            FROM users
            WHERE username = :username
            AND user_id != :user_id
            LIMIT 1
        ";

        $checkStmt = $pdo->prepare($checkSql);

        $checkStmt->execute([
            ":username" => $username,
            ":user_id" => $userId
        ]);

        if ($checkStmt->fetch()) {

            redirectUsers("Username นี้มีอยู่ในระบบแล้ว");

        }


        // Update
        $sql = "
            UPDATE users
            SET
                username = :username
            WHERE user_id = :user_id
        ";

        $stmt = $pdo->prepare($sql);

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
        $sql = "
            UPDATE users
            SET password = :password
            WHERE user_id = :user_id
        ";

        $stmt = $pdo->prepare($sql);

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

        $checkMechanic = $pdo->prepare("
            SELECT mechanic_id
            FROM mechanics
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $checkMechanic->execute([
            ":user_id" => $userId
        ]);

        if ($checkMechanic->fetch()) {

            redirectUsers(
                "ไม่สามารถลบบัญชีช่างจากหน้านี้ได้ กรุณาจัดการช่างผ่านเมนูจัดการช่าง"
            );

        }


        $sql = "
            DELETE FROM users
            WHERE user_id = :user_id
        ";

        $stmt = $pdo->prepare($sql);

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

$sql = "
    SELECT
        user_id,
        username,
        role,
        is_active,
        created_at,
        updated_at
    FROM users
    WHERE role = 'admin'
    ORDER BY user_id DESC
";

$stmt = $pdo->query($sql);

$users = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Success Message
|--------------------------------------------------------------------------
*/

$success = $_GET["success"] ?? "";

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
        จัดการบัญชีผู้ใช้ | P.Chalermchai Car Service
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


        <a href="../technician/index.php">

            <span class="menu-icon">👨‍🔧</span>

            <span>จัดการช่าง</span>

        </a>


        <a href="../financial/index.php">

            <span class="menu-icon">💰</span>

            <span>การเงิน</span>

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

        <div class="users-header">

            <div>

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

                <div class="user-card">


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


                            <span
                                class="role-badge
                                <?= $user["role"] === "admin"
                                    ? "role-admin"
                                    : "role-mechanic"
                                ?>"
                            >

                                <?= $user["role"] === "admin"
                                    ? "แอดมิน"
                                    : "ช่าง"
                                ?>

                            </span>

                        </div>

                    </div>



                    <!-- STATUS -->

                    <div class="user-status">

                        <?php if ((int)$user["is_active"] === 1): ?>

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


                        <!-- EDIT -->

                        <button
                            type="button"
                            class="action-btn"
                            onclick='openEditModal(
                                <?= json_encode($user["user_id"]) ?>,
                                <?= json_encode($user["username"]) ?>,
                                <?= json_encode($user["role"]) ?>
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
                                <?= json_encode($user["username"]) ?>
                            )'
                        >

                            🔑 รีเซ็ตรหัสผ่าน

                        </button>



                        <!-- DELETE -->

                        <?php if (
                            (int)$user["user_id"]
                            !==
                            (int)$_SESSION["user_id"]
                        ): ?>

                            <button
                                type="button"
                                class="action-btn danger"
                                onclick='openDeleteModal(
                                    <?= json_encode($user["user_id"]) ?>,
                                    <?= json_encode($user["username"]) ?>
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

                <input
                    type="text"
                    value="แอดมิน"
                    readonly
                >

                <input
                    type="hidden"
                    name="role"
                    value="admin"
                >

                <small class="form-help">
                    บัญชีช่างต้องสร้างผ่านเมนู "จัดการช่าง"
                </small>

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
            แก้ไขชื่อผู้ใช้งานและตำแหน่ง
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

<script>

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
                : "ช่าง";

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


</body>

</html>