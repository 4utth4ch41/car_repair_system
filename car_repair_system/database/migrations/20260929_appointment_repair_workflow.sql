-- Existing appointments/jobs retain their original statuses and data.
ALTER TABLE appointments MODIFY status ENUM('scheduled','in_progress','completed','cancelled','attended','no_show') NOT NULL DEFAULT 'scheduled';
ALTER TABLE repair_jobs MODIFY status ENUM('waiting','in_progress','awaiting_review','completed','waiting_payment','paid','closed','cancelled') NOT NULL DEFAULT 'waiting';
ALTER TABLE repair_jobs ADD COLUMN IF NOT EXISTS appointment_id INT UNSIGNED NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_repair_appointment ON repair_jobs (appointment_id);
CREATE TABLE IF NOT EXISTS workflow_events (
    event_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT UNSIGNED NULL,
    repair_order_id INT UNSIGNED NULL,
    actor_user_id INT UNSIGNED NULL,
    event_type VARCHAR(40) NOT NULL,
    detail TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_workflow_appointment (appointment_id),
    KEY idx_workflow_repair (repair_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
