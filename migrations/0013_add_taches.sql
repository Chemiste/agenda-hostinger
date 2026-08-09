-- Migration 0013 : table "taches" - une petite liste de choses a faire,
-- independante des rendez-vous (ex: "prendre rdv chez le dentiste pour
-- Michel", "annuler le rendez-vous de mardi"). Pas d'heure precise comme
-- un rendez-vous, juste un texte a cocher, avec une personne et une date
-- cible facultatives.

CREATE TABLE IF NOT EXISTS taches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  texte VARCHAR(255) NOT NULL,
  personne VARCHAR(100) NOT NULL DEFAULT '',
  date_cible DATE NULL,
  fait TINYINT(1) NOT NULL DEFAULT 0,
  fait_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_taches_fait (fait)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
