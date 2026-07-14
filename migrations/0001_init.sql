-- Migration 0001 : creation de la table des rendez-vous.

CREATE TABLE IF NOT EXISTS appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  appt_date DATE NOT NULL,
  appt_time TIME NOT NULL,
  person VARCHAR(20) NOT NULL,
  doctor VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT,
  calendar_event_id VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date_time (appt_date, appt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
