<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * รวมกติกาใบงาน: scheduled -> เปิดใบงาน waiting -> ช่างเริ่ม in_progress -> ส่ง awaiting_review
 * แอดมินตรวจผ่านจึงเป็น completed ถ้าส่งกลับแก้ไขจะกลับ in_progress และต้องมีเหตุผล
 * FOR UPDATE ใช้ล็อกแถวใน transaction แล้วตรวจสิทธิ์/สถานะจากข้อมูลล่าสุดก่อนแก้
 * การล็อกแถวช่วยจัดลำดับคำขอพร้อมกัน แต่ยังต้องมีเงื่อนไขตรวจข้อมูลและ constraint ประกอบ
 * ทุกการเปลี่ยนงานบันทึกผู้ทำและรายละเอียดใน workflow_events พร้อมปรับสถานะว่าง/ไม่ว่างของช่าง
 */

// Appointment dates and service dates follow the workshop's local time.
date_default_timezone_set('Asia/Bangkok');

// Shared by admin and mechanic entry points. Every transition checks the locked row.
function workflowDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function workflowEvent(PDO $pdo, ?int $appointmentId, ?int $repairId, int $actor, string $type, string $detail): void
{
    /*
     * เพิ่มแถวใหม่ลง workflow_events (ประวัติการเปลี่ยนแปลงงาน)
     * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $stmt = $pdo->prepare('INSERT INTO workflow_events (appointment_id,repair_order_id,actor_user_id,event_type,detail) VALUES (?,?,?,?,?)');
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([$appointmentId, $repairId, $actor, $type, $detail]);
}

function workflowAvailability(PDO $pdo, int $mechanicId): void
{
    // ถ้ายังมีงาน waiting / in_progress / awaiting_review คือ busy ไม่มีก็คือ available
    // WHERE ท้ายคำสั่งกัน inactive ไว้ ไม่เปลี่ยนคนที่พักงานให้กลับมาพร้อมรับงานเอง
    if (!$mechanicId) return;
    /*
     * แก้ข้อมูลเดิมใน mechanics (ช่าง), repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
     * เงื่อนไข WHERE ที่ใช้จริง: mechanic_id = ? AND status IN ('waiting','in_progress','awaiting_review')) THEN
     * 'busy' ELSE 'available' END WHERE mechanic_id = ? AND status <> 'inactive'
     * IN คือรับค่าที่อยู่ในชุดที่ระบุ ถ้าเป็นชุด ? ให้ส่งค่าตามจำนวนและลำดับ placeholder
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $stmt = $pdo->prepare("UPDATE mechanics SET status = CASE WHEN EXISTS
        (SELECT 1 FROM repair_jobs WHERE mechanic_id = ? AND status IN ('waiting','in_progress','awaiting_review'))
        THEN 'busy' ELSE 'available' END WHERE mechanic_id = ? AND status <> 'inactive'");
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([$mechanicId, $mechanicId]);
}

function workflowMechanic(PDO $pdo, int $id): void
{
    /*
     * ตรงนี้อ่านข้อมูลจาก mechanics (ช่าง), users (บัญชีผู้ใช้)
     * JOIN / INNER JOIN เอาเฉพาะแถวที่จับคู่ได้ตาม ON เช่น รหัสลูกค้าหรือรหัสรถตรงกัน
     * เงื่อนไข WHERE ที่ใช้จริง: m.mechanic_id=? AND m.status <> 'inactive' AND u.is_active=1 AND u.role='mechanic' 
     * FOR UPDATE ล็อกแถวที่อ่านใน transaction เพื่อไม่ให้คำขออื่นแก้แถวเดียวกันแทรกระหว่างตรวจและบันทึก
     * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
     */
    $stmt = $pdo->prepare("SELECT m.mechanic_id FROM mechanics m JOIN users u ON u.user_id=m.user_id
        WHERE m.mechanic_id=? AND m.status <> 'inactive' AND u.is_active=1 AND u.role='mechanic' FOR UPDATE");
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute([$id]);
    /*
     * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
     */
    if (!$stmt->fetch()) throw new InvalidArgumentException('กรุณาเลือกช่างที่พร้อมรับมอบหมายงาน');
}

function workflowCreateJob(PDO $pdo, array $data, int $actor): string
{
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $mechanicId = (int)($data['mechanic_id'] ?? 0);
    $date = $data['service_date'] ?? date('Y-m-d');
    $due = ($data['due_date'] ?? '') ?: null;
    if (!$appointmentId || !$mechanicId || !workflowDate($date) || ($due && (!workflowDate($due) || $due < $date))) {
        throw new InvalidArgumentException('กรุณาเลือกนัดหมาย ช่าง และวันที่รับรถ/กำหนดเสร็จให้ถูกต้อง');
    }
    /*
     * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
     */
    $pdo->beginTransaction();
    try {
        /*
         * ตรงนี้อ่านข้อมูลจาก appointments (นัดหมาย)
         * เงื่อนไข WHERE ที่ใช้จริง: appointment_id=? 
         * FOR UPDATE ล็อกแถวที่อ่านใน transaction เพื่อไม่ให้คำขออื่นแก้แถวเดียวกันแทรกระหว่างตรวจและบันทึก
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE appointment_id=? FOR UPDATE');
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([$appointmentId]);
        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$appointment || $appointment['status'] !== 'scheduled') {
            throw new InvalidArgumentException('เปิดใบงานได้เฉพาะนัดหมายที่มีสถานะนัดหมายแล้ว');
        }
        /*
         * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม)
         * เงื่อนไข WHERE ที่ใช้จริง: appointment_id=?
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare('SELECT repair_order_id FROM repair_jobs WHERE appointment_id=?');
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([$appointmentId]);
        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        if ($stmt->fetch()) throw new InvalidArgumentException('นัดหมายนี้มีใบงานซ่อมแล้ว');
        /*
         * ตรงนี้อ่านข้อมูลจาก vehicles (รถยนต์)
         * เงื่อนไข WHERE ที่ใช้จริง: vehicle_id=? AND customer_id=?
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare('SELECT vehicle_id FROM vehicles WHERE vehicle_id=? AND customer_id=?');
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([$appointment['vehicle_id'], $appointment['customer_id']]);
        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        if (!$stmt->fetch()) throw new InvalidArgumentException('ข้อมูลรถและเจ้าของไม่ตรงกับนัดหมาย');
        workflowMechanic($pdo, $mechanicId);
        // A temporary unique code avoids collisions between concurrent inserts.
        /*
         * เพิ่มแถวใหม่ลง repair_jobs (ใบงานซ่อม)
         * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare("INSERT INTO repair_jobs (repair_order_code,appointment_id,customer_id,vehicle_id,mechanic_id,
            assigned_at,due_date,service_date,initial_symptom,customer_request,status)
            VALUES (?,?,?,?,?,NOW(),?,?,?,?,'waiting')");
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute(['NEW-'.bin2hex(random_bytes(10)), $appointmentId, $appointment['customer_id'], $appointment['vehicle_id'],
            $mechanicId, $due, $date, trim($data['initial_symptom'] ?? $appointment['description'] ?? ''), trim($data['customer_request'] ?? '')]);
        /*
         * รับรหัสแถวที่เพิ่ง INSERT จาก connection นี้ เพื่อใช้ผูกข้อมูลตารางถัดไป
         */
        $id = (int)$pdo->lastInsertId();
        // ใช้ auto increment เป็นเลขท้ายใบงาน ไม่ใช้ MAX + 1 ที่อาจชนกันเมื่อบันทึกพร้อมกัน
        $code = 'RJ-'.str_pad((string)$id, 6, '0', STR_PAD_LEFT);
        /*
         * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=?
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $pdo->prepare('UPDATE repair_jobs SET repair_order_code=? WHERE repair_order_id=?')->execute([$code, $id]);
        /*
         * แก้ข้อมูลเดิมใน appointments (นัดหมาย) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
         * เงื่อนไข WHERE ที่ใช้จริง: appointment_id=?
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $pdo->prepare("UPDATE appointments SET status='attended' WHERE appointment_id=?")->execute([$appointmentId]);
        workflowEvent($pdo, $appointmentId, $id, $actor, 'created', 'เปิดใบงาน '.$code.' และเข้ารับบริการแล้ว');
        workflowAvailability($pdo, $mechanicId);
        /*
         * ยืนยันการเปลี่ยนแปลงทั้งหมดใน transaction นี้ลงฐานข้อมูล
         */
        $pdo->commit();
        return $code;
    } catch (Throwable $e) {
        /*
         * ถ้างานพลาด ให้ย้อนการเปลี่ยนแปลงใน transaction นี้ เพื่อไม่ให้ข้อมูลค้างครึ่งชุด
         */
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function workflowJobAction(PDO $pdo, int $id, string $action, array $data, int $actor, ?int $mechanicId = null): void
{
    /*
     * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
     */
    $pdo->beginTransaction();
    try {
        /*
         * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม)
         * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=? 
         * FOR UPDATE ล็อกแถวที่อ่านใน transaction เพื่อไม่ให้คำขออื่นแก้แถวเดียวกันแทรกระหว่างตรวจและบันทึก
         * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
         */
        $stmt = $pdo->prepare('SELECT * FROM repair_jobs WHERE repair_order_id=? FOR UPDATE');
        /*
         * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
         */
        $stmt->execute([$id]);
        /*
         * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
         */
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job || ($mechanicId !== null && (int)$job['mechanic_id'] !== $mechanicId)) {
            throw new InvalidArgumentException('ไม่พบใบงานหรือคุณไม่ได้รับมอบหมายงานนี้');
        }
        $detail = '';
        if ($mechanicId !== null) {
            if ($action === 'start' && $job['status'] === 'waiting') {
                /*
                 * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
                 * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=?
                 * COALESCE เลือกค่าตัวแรกที่ไม่เป็น NULL เช่น ยอดรวมไม่มีข้อมูลให้ใช้ 0 หรือเวลาเริ่มงานยังว่างให้ใช้ NOW()
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $pdo->prepare("UPDATE repair_jobs SET status='in_progress', start_time=COALESCE(start_time,NOW()) WHERE repair_order_id=?")->execute([$id]);
                $detail = 'ช่างเริ่มงานซ่อม';
            } elseif ($action === 'complete' && $job['status'] === 'in_progress') {
                $repair = trim($data['repair_detail'] ?? '');
                if ($repair === '') throw new InvalidArgumentException('กรุณาบันทึกผลการซ่อมหรือผลการแก้ไขก่อนส่งตรวจรับ');
                $inspection = trim($data['inspection_result'] ?? '');
                $problem = trim($data['additional_problem'] ?? '');
                $note = trim($data['mechanic_note'] ?? '');
                /*
                 * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
                 * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=?
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $pdo->prepare("UPDATE repair_jobs SET status='awaiting_review', end_time=NULL, inspection_result=?,
                    repair_detail=?,additional_problem=?,mechanic_note=? WHERE repair_order_id=?")
                    /*
                     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                     */
                    ->execute([$inspection, $repair, $problem, $note, $id]);
                $detail = "ส่งตรวจรับงาน\nผลตรวจ: $inspection\nผลการซ่อม: $repair\nปัญหาเพิ่มเติม: $problem\nหมายเหตุ: $note";
            } else {
                throw new InvalidArgumentException('สถานะงานเปลี่ยนแล้ว หรือไม่อนุญาตให้ดำเนินการขั้นตอนนี้');
            }
        } elseif ($action === 'approve' || $action === 'return_work') {
            if ($job['status'] !== 'awaiting_review') throw new InvalidArgumentException('ตรวจรับได้เฉพาะงานที่ช่างส่งตรวจแล้ว');
            if ($action === 'return_work') {
                $reason = trim($data['reason'] ?? '');
                if ($reason === '') throw new InvalidArgumentException('กรุณาระบุเหตุผลที่ส่งกลับแก้ไข');
                if (!$job['mechanic_id']) throw new InvalidArgumentException('ไม่พบช่างผู้รับผิดชอบงานนี้');
                /*
                 * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
                 * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=?
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $pdo->prepare("UPDATE repair_jobs SET status='in_progress',end_time=NULL WHERE repair_order_id=?")->execute([$id]);
                $detail = 'ส่งกลับแก้ไข: '.$reason;
            } else {
                /*
                 * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
                 * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=?
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $pdo->prepare("UPDATE repair_jobs SET status='completed',end_time=NOW() WHERE repair_order_id=?")->execute([$id]);
                $detail = 'แอดมินตรวจรับงานผ่าน';
            }
        } elseif ($action === 'update') {
            if (!in_array($job['status'], ['waiting','in_progress'], true)) throw new InvalidArgumentException('แก้ไขข้อมูลได้เฉพาะงานที่ยังไม่ส่งตรวจรับ');
            if (isset($data['status']) && $data['status'] !== $job['status']) throw new InvalidArgumentException('ไม่สามารถเปลี่ยนสถานะผ่านฟอร์มแก้ไขได้');
            $assigned = (int)($data['mechanic_id'] ?? 0);
            if ($assigned !== (int)$job['mechanic_id'] && $job['status'] !== 'waiting') {
                throw new InvalidArgumentException('เปลี่ยนช่างได้ก่อนเริ่มงานเท่านั้น');
            }
            workflowMechanic($pdo, $assigned);
            $due = ($data['due_date'] ?? '') ?: null;
            if ($due && (!workflowDate($due) || $due < $job['service_date'])) throw new InvalidArgumentException('กำหนดเสร็จไม่ถูกต้อง');
            /*
             * แก้ข้อมูลเดิมใน repair_jobs (ใบงานซ่อม) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
             * เงื่อนไข WHERE ที่ใช้จริง: repair_order_id=?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $pdo->prepare('UPDATE repair_jobs SET assigned_at=IF(mechanic_id <> ? OR mechanic_id IS NULL,NOW(),assigned_at),
                mechanic_id=?,due_date=?,initial_symptom=?,customer_request=? WHERE repair_order_id=?')->execute([
                $assigned,$assigned,$due,trim($data['initial_symptom'] ?? ''),trim($data['customer_request'] ?? ''),$id]);
            workflowAvailability($pdo, $assigned);
            $detail = 'แก้ไขข้อมูลใบงาน / การมอบหมายช่าง';
        } else {
            throw new InvalidArgumentException('ไม่อนุญาตให้เปลี่ยนสถานะด้วยวิธีนี้');
        }
        workflowEvent($pdo, $job['appointment_id'] ? (int)$job['appointment_id'] : null, $id, $actor, $action, $detail);
        workflowAvailability($pdo, (int)$job['mechanic_id']);
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

function workflowHistory(PDO $pdo, array $ids, string $column): array
{
    // ชื่อคอลัมน์ bind เป็นค่าไม่ได้ จึงให้เลือกได้แค่ appointment_id หรือ repair_order_id เท่านั้น
    // รหัสรายการทำเป็น ? ตามจำนวน ids แล้วส่งค่าทีเดียว ไม่ต่อรหัสจากผู้ใช้เป็น SQL ตรง ๆ
    // ประวัติเรียง event_id จากเก่าไปใหม่ แล้วจัด array แยกตามรหัสนัดหรือรหัสใบงาน
    if (!$ids) return [];
    if (!in_array($column, ['appointment_id','repair_order_id'], true)) throw new InvalidArgumentException('Invalid history type');
    /*
     * ตรงนี้อ่านข้อมูลจาก workflow_events (ประวัติการเปลี่ยนแปลงงาน), users (บัญชีผู้ใช้)
     * LEFT JOIN ยังเก็บแถวฝั่งซ้ายไว้แม้ฝั่งขวาไม่มีคู่ คอลัมน์ของฝั่งขวาจะเป็น NULL
     * WHERE ประกอบจากชื่อคอลัมน์ที่ตรวจไว้ แล้วใช้ IN กรองเฉพาะรหัสใน $ids
     */
    $stmt = $pdo->prepare('SELECT e.*,u.username FROM workflow_events e LEFT JOIN users u ON u.user_id=e.actor_user_id WHERE e.'.$column.
        ' IN ('.implode(',', array_fill(0,count($ids),'?')).') ORDER BY e.event_id');
    /*
     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
     */
    $stmt->execute($ids);
    $result = [];
    /*
     * รับผลลัพธ์หลายแถวมาเป็น array เพื่อใช้วนแสดงผลหรือจัดกลุ่มต่อใน PHP
     */
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) $result[$event[$column]][] = $event;
    return $result;
}

function workflowRenderHistory(array $events): void
{
    if (!$events) return;
    echo '<details class="workflow-history"><summary>ประวัติการดำเนินงาน ('.count($events).')</summary>';
    foreach ($events as $event) {
        echo '<div class="workflow-event"><small>'.htmlspecialchars($event['created_at'].' · '.($event['username'] ?? 'ผู้ใช้เดิม'),ENT_QUOTES,'UTF-8').
            '</small><p>'.nl2br(htmlspecialchars($event['detail'],ENT_QUOTES,'UTF-8')).'</p></div>';
    }
    echo '</details>';
}
