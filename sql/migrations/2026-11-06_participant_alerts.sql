
-- CHS Pet Pantry
-- Participant Alerts Migration
-- Creates the table used for categorized participant alerts.

CREATE TABLE IF NOT EXISTS dbparticipantalerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    alert_type ENUM(
        'Duplicate Participant',
        'Distribution Restriction',
        'Staff Review Required'
    ) NOT NULL,
    reason TEXT NOT NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_by VARCHAR(100) NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_participant_active (participant_id, resolved_at),
    INDEX idx_alert_type_active (alert_type, resolved_at)
);
