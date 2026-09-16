<?php

session_start();

if (isset($_SESSION['user_id'])) {

    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    }

    if ($_SESSION['role'] === 'mechanic') {
        header("Location: mechanic/dashboard.php");
        exit;
    }
}

header("Location: login.php");
exit;