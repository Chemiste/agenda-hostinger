-- Migration 0015 : table "medicaments" - plan de prise quotidien
-- (medicament, dose, moment de la journee), pour generer soi-meme la
-- fiche "Traitement de ... - Plan de prise quotidien" a afficher/imprimer
-- (voir medicaments.php pour la gestion, medicaments_plan.php pour le
-- rendu imprimable). "moment" est du texte libre (ex. "Matin", "15h00",
-- "Au coucher", "Si besoin") pour rester aussi flexible qu'un vrai plan
-- de traitement, pas limite a des cases fixes.

CREATE TABLE IF NOT EXISTS medicaments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(20) NOT NULL,
  moment VARCHAR(100) NOT NULL,
  ordre_moment INT NOT NULL DEFAULT 0,
  ordre INT NOT NULL DEFAULT 0,
  nom VARCHAR(150) NOT NULL,
  quantite VARCHAR(100) NOT NULL DEFAULT '',
  detail VARCHAR(255) NOT NULL DEFAULT '',
  image VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_medicaments_person (person)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
