<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * ไฟล์นี้เป็นจุดเชื่อมฐานข้อมูลกลาง หน้าอื่น require มาแล้วใช้ตัวแปร $pdo ต่อได้เลย
 * DSN ระบุ MySQL / ชื่อฐานข้อมูล / utf8mb4 เพื่อเก็บภาษาไทยและอักขระพิเศษ
 * ERRMODE_EXCEPTION ทำให้ SQL ที่ผิดโยน exception ส่วน FETCH_ASSOC ให้ผลลัพธ์เป็นชื่อคอลัมน์ => ค่า
 */

$host = "localhost";
$dbname = "car_repair_system";
$username = "root";
$password = "";

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

} catch (PDOException $e) {

    die("Database connection failed: " . $e->getMessage());

}