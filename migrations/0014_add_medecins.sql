-- Migration 0014 : table "medecins" - un carnet de reference (nom,
-- specialite/departement, adresse, telephone, itineraire, notes) par
-- medecin, independant des rendez-vous. Contrairement a la memorisation
-- automatique (qui ne connait un medecin qu'apres un premier rendez-vous
-- pris), ce carnet permet de garder un medecin en reference meme sans
-- rendez-vous prevu. Chaque entree est liee a une personne precise
-- (Papa ou Maman), pas de fiche partagee entre les deux.

CREATE TABLE IF NOT EXISTS medecins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(20) NOT NULL,
  doctor VARCHAR(255) NOT NULL,
  department VARCHAR(255) NOT NULL DEFAULT '',
  location VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(50) NOT NULL DEFAULT '',
  route VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_medecins_person (person)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
