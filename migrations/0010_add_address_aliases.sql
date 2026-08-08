-- Migration 0010 : table "address_aliases" pour l'affichage d'adresses
-- simplifiees (ex: "Avenue Hippocrate, 10, 1200 Bruxelles" -> "Hopital
-- St Luc"). Le remplacement n'est utilise que pour l'affichage a
-- l'ecran/impression (voir api.php) : le champ "location" en base et sur
-- Google Calendar reste toujours l'adresse reelle, pour que Waze/Maps
-- puissent naviguer correctement depuis le calendrier.

CREATE TABLE IF NOT EXISTS address_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  motif VARCHAR(255) NOT NULL,
  remplacement VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
