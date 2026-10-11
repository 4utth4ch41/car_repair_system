<?php
/*
 * ภาพรวมการทำงานกับข้อมูลของไฟล์นี้
 * จุดกลางของการเปลี่ยนนัดหมาย เริ่ม transaction แล้วตรวจข้อมูลก่อนบันทึกทุกครั้ง
 * แก้ได้เฉพาะ scheduled ที่ยังไม่มีใบงาน ต้องตรวจว่ารถตรงกับลูกค้าที่เลือกด้วย
 * เลื่อนวัน/เวลาต้องมีเหตุผล ส่วน no_show ต้องถึงเวลานัดแล้ว โดยเทียบเวลา Asia/Bangkok
 * เปลี่ยนนัดและเพิ่ม workflow_events สำเร็จพร้อมกัน ถ้าเกิดข้อผิดพลาด rollback ทั้งชุด
 */
require_once __DIR__.'/repair_workflow.php';

function workflowAppointment(PDO $pdo, string $action, array $data, int $actor, array $serviceTypes): void
{
    /*
     * เริ่ม transaction: คำสั่งที่ตามมาต้องสำเร็จเป็นชุด หรือย้อนกลับเป็นชุดเมื่อผิดพลาด
     */
    $pdo->beginTransaction();
    try {
        $id = (int)($data['appointment_id'] ?? 0);
        $old = null;
        if ($action !== 'add_appointment') {
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
            $stmt->execute([$id]);
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            $old = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$old || $old['status'] !== 'scheduled') throw new InvalidArgumentException('จัดการได้เฉพาะนัดที่ยังมีสถานะนัดหมายแล้ว');
            /*
             * ตรงนี้อ่านข้อมูลจาก repair_jobs (ใบงานซ่อม)
             * เงื่อนไข WHERE ที่ใช้จริง: appointment_id=?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare('SELECT repair_order_id FROM repair_jobs WHERE appointment_id=?');
            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$id]);
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            if ($stmt->fetch()) throw new InvalidArgumentException('นัดนี้มีใบงานซ่อมแล้ว ไม่สามารถแก้ย้อนหลังได้');
        }
        if (in_array($action, ['add_appointment','edit_appointment'], true)) {
            $customer = (int)($data['customer_id'] ?? 0);
            $vehicle = (int)($data['vehicle_id'] ?? 0);
            $date = $data['appointment_date'] ?? '';
            $time = $data['appointment_time'] ?? '';
            $service = $data['service_type'] ?? '';
            $description = trim($data['description'] ?? '');
            if (!workflowDate($date) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time)) {
                throw new InvalidArgumentException('วันหรือเวลานัดหมายไม่ถูกต้อง');
            }
            if (!isset($serviceTypes[$service])) throw new InvalidArgumentException('ประเภทบริการไม่ถูกต้อง');
            /*
             * ตรงนี้อ่านข้อมูลจาก vehicles (รถยนต์)
             * เงื่อนไข WHERE ที่ใช้จริง: vehicle_id=? AND customer_id=?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $stmt = $pdo->prepare('SELECT vehicle_id FROM vehicles WHERE vehicle_id=? AND customer_id=?');
            /*
             * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
             */
            $stmt->execute([$vehicle,$customer]);
            /*
             * อ่านผลทีละแถว ถ้าไม่พบข้อมูลจะได้ false จึงต้องเช็กก่อนนำค่าไปใช้
             */
            if (!$stmt->fetch()) throw new InvalidArgumentException('รถยนต์ไม่ตรงกับลูกค้าที่เลือก');
            if ($old) {
                $moved = $old['appointment_date'] !== $date || substr($old['appointment_time'],0,5) !== substr($time,0,5);
                $reason = trim($data['reason'] ?? '');
                if ($moved && $reason === '') throw new InvalidArgumentException('กรุณาระบุเหตุผลที่เลื่อนนัด');
                /*
                 * แก้ข้อมูลเดิมใน appointments (นัดหมาย) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
                 * เงื่อนไข WHERE ที่ใช้จริง: appointment_id=?
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $pdo->prepare('UPDATE appointments SET customer_id=?,vehicle_id=?,appointment_date=?,appointment_time=?,service_type=?,description=? WHERE appointment_id=?')
                    /*
                     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                     */
                    ->execute([$customer,$vehicle,$date,$time,$service,$description,$id]);
                $detail = $moved ? 'เลื่อนนัดจาก '.$old['appointment_date'].' '.$old['appointment_time'].' เป็น '.$date.' '.$time.' เหตุผล: '.$reason : 'แก้ไขข้อมูลนัดหมาย';
            } else {
                /*
                 * เพิ่มแถวใหม่ลง appointments (นัดหมาย)
                 * ค่าหลัง VALUES ต้องตรงกับลำดับคอลัมน์ที่ระบุ ไม่ได้แก้แถวเก่าที่มีอยู่
                 * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
                 */
                $pdo->prepare("INSERT INTO appointments (customer_id,vehicle_id,appointment_date,appointment_time,service_type,description,status) VALUES (?,?,?,?,?,?,'scheduled')")
                    /*
                     * สั่งรัน statement ที่เตรียมไว้ ค่าที่ส่งต้องตรงกับชื่อหรือลำดับ placeholder ใน SQL
                     */
                    ->execute([$customer,$vehicle,$date,$time,$service,$description]);
                /*
                 * รับรหัสแถวที่เพิ่ง INSERT จาก connection นี้ เพื่อใช้ผูกข้อมูลตารางถัดไป
                 */
                $id = (int)$pdo->lastInsertId();
                $detail = 'สร้างนัดหมาย '.$date.' '.$time;
            }
        } elseif (in_array($action,['cancel_appointment','no_show'],true)) {
            $reason = trim($data['reason'] ?? '');
            if ($reason === '') throw new InvalidArgumentException('กรุณาระบุเหตุผล');
            if ($action === 'no_show') {
                $now = new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));
                $scheduled = new DateTimeImmutable($old['appointment_date'].' '.$old['appointment_time'],new DateTimeZone('Asia/Bangkok'));
                if ($scheduled > $now) throw new InvalidArgumentException('ยังไม่ถึงเวลานัดหมาย ไม่สามารถระบุว่าไม่มาตามนัดได้');
            }
            $status = $action === 'no_show' ? 'no_show' : 'cancelled';
            /*
             * แก้ข้อมูลเดิมใน appointments (นัดหมาย) โดยเปลี่ยนเฉพาะคอลัมน์หลัง SET
             * เงื่อนไข WHERE ที่ใช้จริง: appointment_id=?
             * ? / :ชื่อ คือช่องรับค่า ส่งค่าจริงผ่าน execute หรือ bind_param แยกจากข้อความ SQL
             */
            $pdo->prepare('UPDATE appointments SET status=? WHERE appointment_id=?')->execute([$status,$id]);
            $detail = ($status === 'no_show' ? 'ไม่มาตามนัด: ' : 'ยกเลิกนัด: ').$reason;
        } else {
            throw new InvalidArgumentException('กรุณาใช้ปุ่มเลื่อนนัด ยกเลิกนัด หรือไม่มาตามนัด');
        }
        workflowEvent($pdo,$id,null,$actor,$action,$detail);
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
