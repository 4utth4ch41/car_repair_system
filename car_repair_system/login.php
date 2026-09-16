<?php

session_start();

require_once "config/database.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = $_POST["password"];

    if ($username === "" || $password === "") {

        $error = "กรุณากรอก Username และ Password";

    } else {

        $sql = "SELECT * FROM users
                WHERE username = :username
                AND is_active = 1
                LIMIT 1";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ":username" => $username
        ]);

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user["password"])) {

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

    <title>เข้าสู่ระบบ | P.Chalermchai Car Service</title>

    <link rel="stylesheet"
          href="assets/css/style.css">

</head>

<body>

<div class="login-container">

    <div class="login-box">

        <h1>P.Chalermchai Car Service</h1>

        <p class="login-subtitle">
            ระบบจัดการอู่ซ่อมรถ
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

</body>

</html>