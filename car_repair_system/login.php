<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * รับ username จากฟอร์มแล้วหาเฉพาะบัญชีที่ is_active = 1 ยังไม่ใช่การยืนยันรหัสผ่าน
 * จากนั้น PHP ใช้ password_verify เทียบรหัสที่กรอกกับ hash ที่เก็บไว้ ไม่เทียบรหัสผ่านด้วย SQL
 * role เป็น NULL คือยังรอกำหนดบทบาท ส่วน admin / mechanic ใช้เลือกหน้าหลังเข้าสู่ระบบ
 */

session_start();

require_once "config/database.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = $_POST["password"];

    if ($username === "" || $password === "") {

        $error = "กรุณากรอก Username และ Password";

    } else {

        /*
         * ตรงนี้อ่านข้อมูลจาก users (บัญชีผู้ใช้)
         * เงื่อนไข WHERE ที่ใช้จริง: username = :username AND is_active = 1 
         * เอาผลลัพธ์ไม่เกิน 1 แถว ตามลำดับที่ query กำหนด
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $sql = "SELECT * FROM users
                WHERE username = :username
                AND is_active = 1
                LIMIT 1";

        /*
         * เตรียม statement ก่อนส่งค่าจริง แยกข้อมูลออกจากโครง SQL ช่วยป้องกัน SQL injection ในค่าที่ bind
         */
        $stmt = $pdo->prepare($sql);

        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([
            ":username" => $username
        ]);

        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user["password"])) {

            if ($user["role"] === null) {
                unset($_SESSION["user_id"], $_SESSION["username"], $_SESSION["role"]);
                $error = "บัญชีนี้รอผู้ดูแลระบบกำหนดบทบาท";
            } elseif (in_array($user["role"], ["admin", "mechanic"], true)) {
                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["role"] = $user["role"];

                if ($user["role"] === "admin") {

                    header("Location: admin/dashboard.php");
                    exit;

                } elseif ($user["role"] === "mechanic") {

                    header("Location: mechanic/dashboard.php");
                    exit;
                }

            } else {
                $error = "บัญชีนี้ไม่มีสิทธิ์เข้าใช้งานระบบ";
            }
        } else {

            $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>เข้าสู่ระบบ | ป.เฉลิมชัย คาร์แคร์ เซอร์วิส</title>

    <link rel="stylesheet"
          href="assets/css/style.css">

    <link rel="stylesheet"
          href="assets/css/garage-theme.css">

    <link rel="stylesheet" href="assets/css/public-tracking.css">

    <!-- GARAGE THEME: สีกรมท่า เหลืองเข้ม และครีมอมเทา -->

</head>

<body>

<div class="login-container public-entry">

    <nav class="public-entry-nav" aria-label="บริการลูกค้า">
        <a class="public-entry-link" href="track.php">ติดตามสถานะรถยนต์ของคุณ</a>
    </nav>

    <!-- LOGIN ART: ภาพรถและโลโก้ของอู่ -->

    <div class="login-shell">

        <div class="login-art">

            <img
                class="login-art-logo"
                src="assets/img/logo.jpg"
                alt="โลโก้ ป.เฉลิมชัย คาร์แคร์ เซอร์วิส"
                width="110"
                height="110"
            >

        </div>

        <div class="login-box">

            <!-- LOGIN BANNER: ป้ายอู่ด้านบนของฟอร์มเข้าสู่ระบบ -->

            <img
                class="login-art-banner"
                src="assets/img/banner.jpg"
                alt="ป้ายอู่ ป.เฉลิมชัย คาร์แคร์ พร้อมรายละเอียดบริการและเบอร์ติดต่อ"
            >

            <h1>ป.เฉลิมชัย คาร์แคร์ เซอร์วิส</h1>

            <p class="login-subtitle">
                ระบบจัดการการเข้ารับบริการอู่ซ่อมรถ
            </p>

            <?php if ($error !== ""): ?>

                <div class="error-message">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>

            <form method="POST">

                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="กรอก Username"
                        autocomplete="username"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="กรอก Password"
                        autocomplete="current-password"
                        required
                    >

                </div>

                <button
                    type="submit"
                    class="login-button"
                >
                    เข้าสู่ระบบ
                </button>

            </form>

        </div>

    </div>

</div>

</body>

</html>
