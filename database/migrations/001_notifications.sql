-- Phase 1: notification foundation
ALTER TABLE notifications
    ADD COLUMN type VARCHAR(40) NOT NULL DEFAULT 'general' AFTER booking_id,
    ADD COLUMN link VARCHAR(255) NULL AFTER type,
    ADD INDEX idx_notifications_user_read_created (user_id, is_read, created_at);

ALTER TABLE email_notifications
    ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN body TEXT NULL AFTER subject,
    ADD COLUMN last_error VARCHAR(255) NULL AFTER attempts,
    ADD INDEX idx_email_notifications_status (status);

ALTER TABLE vehicle_bookings
    ADD UNIQUE KEY uq_vehicle_bookings_booking_no (booking_no);
