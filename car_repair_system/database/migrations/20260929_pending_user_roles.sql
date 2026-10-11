-- Preserve existing roles; new accounts wait for role assignment.
ALTER TABLE users MODIFY COLUMN role ENUM('admin','mechanic') NULL DEFAULT NULL;
