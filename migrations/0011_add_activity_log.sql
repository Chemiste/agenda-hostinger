-- Migration 0011 : table "activity_log", journal d'activite.
--
-- Enregistre qui s'est connecte (type_action = 'connexion') et qui a
-- ajoute/modifie/supprime un rendez-vous (type_action = 'ajout' /
-- 'modification' / 'suppression'), avec un resume du rendez-vous
-- concerne (garde meme apres suppression, puisque "resume" est une copie
-- de texte, pas une reference vivante). "appointment_id" n'est pas une
-- cle etrangere stricte (pas de ON DELETE CASCADE) : on veut justement
-- garder la trace apres suppression du rendez-vous.

CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type_action VARCHAR(20) NOT NULL,
  personne VARCHAR(100) NOT NULL,
  appointment_id INT NULL,
  resume VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_log_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
