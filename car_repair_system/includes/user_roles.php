<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * กำหนดบทบาทได้ครั้งเดียว ตรวจ role ว่ายังเป็น NULL หลังล็อกแถว users แล้ว
 * ตรวจชื่อ/โทรศัพท์/สถานะ/ทักษะก่อน ถ้าเลือก mechanic จึงสร้างข้อมูลช่างและคู่ช่าง-ทักษะ
 * อัปเดต users และเพิ่มข้อมูลที่เกี่ยวข้องใน transaction เดียวกัน ไม่ปล่อยให้เหลือข้อมูลครึ่งชุด
 */
// Lock the account so concurrent requests cannot assign its role twice.
function assignUserRole(PDO $pdo, int $userId, string $role, array $data): void
{
    if ($userId <= 0 || !in_array($role, ['admin', 'mechanic'], true)) {
        throw new InvalidArgumentException('กรุณาเลือกบัญชีและบทบาทที่ถูกต้อง');
    }
    $name = trim($data['full_name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $status = $data['status'] ?? 'available';
    $skills = $data['skills'] ?? [];
    if ($role === 'mechanic') {
        if ($name === '' || mb_strlen($name) > 150 || mb_strlen($phone) > 20) {
            throw new InvalidArgumentException('กรุณากรอกชื่อช่างไม่เกิน 150 ตัวอักษร และเบอร์โทรไม่เกิน 20 ตัวอักษร');
        }
        if (!in_array($status, ['available', 'busy', 'inactive'], true) || !is_array($skills)) {
            throw new InvalidArgumentException('สถานะหรือทักษะช่างไม่ถูกต้อง');
        }
        foreach ($skills as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id <= 0) {
                throw new InvalidArgumentException('ทักษะช่างไม่ถูกต้อง');
            }
        }
        $skills = array_unique(array_map('intval', $skills));
    }
    /*
     * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
     */
    $pdo->beginTransaction();
    try {
        /*
         * ตรงนี้อ่านข้อมูลจาก users (บัญชีผู้ใช้)
         * เงื่อนไข WHERE ที่ใช้จริง: user_id = ? 
         * FOR UPDATE ล็อกแถวที่อ่านใน transaction เพื่อไม่ให้คำขออื่นแก้แถวเดียวกันแทรกระหว่างตรวจและบันทึก
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare('SELECT role FROM users WHERE user_id = ? FOR UPDATE');
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([$userId]);
        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['role'] !== null) {
            throw new InvalidArgumentException('บัญชีนี้ไม่มีอยู่หรือกำหนดบทบาทแล้ว ไม่สามารถเปลี่ยนบทบาทได้');
        }
        if ($role === 'mechanic') {
            /*
             * ตรงนี้อ่านข้อมูลจาก skills (ทักษะช่าง)
             * เงื่อนไข WHERE ที่ใช้จริง: skill_id = ?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $check = $pdo->prepare('SELECT skill_id FROM skills WHERE skill_id = ?');
            foreach ($skills as $id) {
                /*
                 * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                 */
                $check->execute([$id]);
                /*
                 * อ่านค่าเดียวจากคอลัมน์แรกของแถวถัดไป เหมาะกับ COUNT / SUM หรือการตรวจว่าพบรหัสหรือไม่
                 */
                if (!$check->fetchColumn()) throw new InvalidArgumentException('ไม่พบทักษะช่างที่เลือก');
            }
            /*
             * เพิ่มแถวใหม่ลง mechanics (ช่าง)
             * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare('INSERT INTO mechanics (user_id, full_name, phone, status) VALUES (?, ?, ?, ?)');
            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$userId, $name, $phone !== '' ? $phone : null, $status]);
            /*
             * รับรหัสแถวที่เพิ่ง INSERT จาก connection นี้ เพื่อใช้ผูกข้อมูลตารางถัดไป
             */
            $mechanicId = $pdo->lastInsertId();
            /*
             * เพิ่มแถวใหม่ลง mechanic_skills (ตารางเชื่อมช่างกับทักษะ)
             * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare('INSERT INTO mechanic_skills (mechanic_id, skill_id) VALUES (?, ?)');
            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            foreach ($skills as $id) $stmt->execute([$mechanicId, $id]);
        }
        /*
         * แก้ข้อมูลเดิมใน users (บัญชีผู้ใช้) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: user_id = ? AND role IS NULL
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE user_id = ? AND role IS NULL');
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([$role, $userId]);
        /*
         * ยืนยันการเปลี่ยนแปลงทั้งหมดใน transaction นี้ลงฐานข้อมูล
         */
        $pdo->commit();
    } catch (Throwable $e) {
        /*
         * ถ้างานพลาด ให้ย้อนการเปลี่ยนแปลงใน transaction นี้ เพื่อไม่ให้ข้อมูลค้างครึ่งชุด
         */
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
