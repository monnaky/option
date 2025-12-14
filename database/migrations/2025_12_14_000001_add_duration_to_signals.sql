ALTER TABLE signals
    ADD COLUMN duration INT UNSIGNED NULL AFTER execution_time,
    ADD COLUMN duration_unit ENUM('t','s') NULL AFTER duration;
