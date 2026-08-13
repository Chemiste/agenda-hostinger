-- Migration 0017 : suivi des pathologies. Pour chaque personne, une liste
-- de pathologies (ex. "Dos", "Bras") avec leur cause/raison et ce qui est
-- fait pour les soigner (kine, medecin, medicaments...), en texte libre -
-- pense pour repondre rapidement a "qu'est-ce qu'on m'a dit pour X ?" lors
-- d'un rendez-vous, meme des mois plus tard. Voir lib/pathologies.php,
-- pathologies.php (gestion) et pathologies_plan.php (fiche imprimable).

CREATE TABLE IF NOT EXISTS pathologies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(20) NOT NULL,
  nom VARCHAR(150) NOT NULL,
  cause TEXT NOT NULL DEFAULT '',
  traitement TEXT NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pathologies_person (person)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
