-- Migration 0021 : une table "persons", et un identifiant numerique
-- partout ou un nom de personne etait recopie en clair.
--
-- POURQUOI. Le nom etait duplique dans six tables et la liste de
-- reference vivait dans config.php. Renommer quelqu'un dans config.php ne
-- touchait pas les lignes existantes : ses medicaments, ses pathologies et
-- ses medecins disparaissaient de l'ecran en silence, sans message
-- d'erreur. Et ajouter une troisieme personne demandait de modifier du
-- code, "personne_1" et "personne_2" etant lus en dur dans une quinzaine
-- de fichiers.
--
-- UNE SEULE TABLE, deux drapeaux. Michel et Christiane sont a la fois des
-- patients (on suit leurs rendez-vous, leurs medicaments) et des membres
-- qui se connectent. Helene et Laurent ne sont que des membres. Les mettre
-- dans deux tables reviendrait a les dedoubler, c'est-a-dire au probleme
-- de depart sous une autre forme.
--   est_patient       : on suit sa sante (onglets, medicaments, pathologies)
--   peut_se_connecter : il apparait dans "Qui est-ce ?"
--
-- MIGRATION SANS RUPTURE (motif "expand/contract"). Cette migration
-- N'AJOUTE que des colonnes, elle n'en supprime aucune : les colonnes
-- texte "person" et "personne" restent en place et le site continue de
-- fonctionner avec elles tant que le code n'a pas bascule. Elles seront
-- supprimees par une migration ulterieure, une fois la bascule verifiee en
-- production. En cas de probleme, il suffit de revenir a l'ancien code.
--
-- "accompagnant" reste du texte libre et n'est PAS rattache a cette table :
-- ce peut etre un voisin, un taxi, quelqu'un qui ne se connecte jamais.
--
-- EN CAS D'ECHEC AU MILIEU : les instructions se relancent une par une.
-- Le CREATE et l'INSERT sont sans effet s'ils ont deja tourne. Un
-- "ADD COLUMN" deja passe echouera en signalant que la colonne existe :
-- retirez cette ligne du fichier et relancez, ou appliquez les
-- instructions restantes a la main dans phpMyAdmin.

CREATE TABLE IF NOT EXISTS persons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  est_patient TINYINT(1) NOT NULL DEFAULT 0,
  peut_se_connecter TINYINT(1) NOT NULL DEFAULT 0,
  ordre INT NOT NULL DEFAULT 0,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_persons_nom (nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Chem" est le surnom de Laurent. Le site s'adressant a Michel et
-- Christiane, c'est "Laurent" qu'ils doivent lire partout - et sans ce
-- nettoyage, le peuplement ci-dessous creerait deux personnes distinctes
-- pour un seul individu. On normalise donc AVANT de peupler.
-- Ces deux instructions sont sans effet si elles ont deja tourne.
UPDATE activity_log SET personne = 'Laurent' WHERE personne = 'Chem';
UPDATE appointments SET accompagnant = 'Laurent' WHERE accompagnant = 'Chem';

-- Les personnes sont deduites des donnees reelles, pas de config.php :
-- un fichier .sql ne peut pas lire un fichier PHP. Un nom vu comme patient
-- ET comme membre recupere les deux drapeaux (MAX).
--
-- LIMITE CONNUE : activity_log dit qui S'EST connecte, pas qui PEUT se
-- connecter. Quelqu'un qui n'a jamais ouvert le site (ou seulement sur un
-- autre environnement) ne sera pas cree ici, et quelqu'un qui ne s'est
-- jamais connecte n'aura pas le drapeau "peut_se_connecter". C'est normal
-- et c'est rattrape par le bouton "Reprendre depuis config.php" de
-- admin/personnes.php, a cliquer une fois juste apres cette migration.
-- INSERT IGNORE plutot que INSERT : relancer la migration ne cree pas de
-- doublons, la contrainte d'unicite sur le nom absorbe les rejeux.
INSERT IGNORE INTO persons (nom, est_patient, peut_se_connecter)
SELECT nom, MAX(patient), MAX(membre) FROM (
  SELECT person AS nom, 1 AS patient, 0 AS membre FROM appointments WHERE person <> ''
  UNION ALL SELECT person, 1, 0 FROM medecins WHERE person <> ''
  UNION ALL SELECT person, 1, 0 FROM medicaments WHERE person <> ''
  UNION ALL SELECT person, 1, 0 FROM medicament_moments WHERE person <> ''
  UNION ALL SELECT person, 1, 0 FROM pathologies WHERE person <> ''
  UNION ALL SELECT personne, 1, 0 FROM taches WHERE personne <> ''
  UNION ALL SELECT personne, 0, 1 FROM activity_log WHERE personne <> ''
) AS noms_vus GROUP BY nom;

ALTER TABLE appointments ADD COLUMN person_id INT NOT NULL DEFAULT 0 AFTER person;
ALTER TABLE medecins ADD COLUMN person_id INT NOT NULL DEFAULT 0 AFTER person;
ALTER TABLE medicaments ADD COLUMN person_id INT NOT NULL DEFAULT 0 AFTER person;
ALTER TABLE medicament_moments ADD COLUMN person_id INT NOT NULL DEFAULT 0 AFTER person;
ALTER TABLE pathologies ADD COLUMN person_id INT NOT NULL DEFAULT 0 AFTER person;
ALTER TABLE taches ADD COLUMN person_id INT NOT NULL DEFAULT 0 AFTER personne;
ALTER TABLE activity_log ADD COLUMN person_id INT NOT NULL DEFAULT 0 AFTER personne;

ALTER TABLE appointments ADD INDEX idx_appointments_person_id (person_id);
ALTER TABLE medecins ADD INDEX idx_medecins_person_id (person_id);
ALTER TABLE medicaments ADD INDEX idx_medicaments_person_id (person_id);
ALTER TABLE medicament_moments ADD INDEX idx_moments_person_id (person_id);
ALTER TABLE pathologies ADD INDEX idx_pathologies_person_id (person_id);
ALTER TABLE taches ADD INDEX idx_taches_person_id (person_id);
ALTER TABLE activity_log ADD INDEX idx_activity_person_id (person_id);

-- Remplissage par correspondance de nom. Une ligne dont le nom ne
-- correspond a personne garde person_id = 0 : elle reste visible en base
-- et reperable, plutot que d'etre rattachee au hasard.
UPDATE appointments a JOIN persons p ON p.nom = a.person SET a.person_id = p.id;
UPDATE medecins m JOIN persons p ON p.nom = m.person SET m.person_id = p.id;
UPDATE medicaments m JOIN persons p ON p.nom = m.person SET m.person_id = p.id;
UPDATE medicament_moments m JOIN persons p ON p.nom = m.person SET m.person_id = p.id;
UPDATE pathologies pa JOIN persons p ON p.nom = pa.person SET pa.person_id = p.id;
UPDATE taches t JOIN persons p ON p.nom = t.personne SET t.person_id = p.id;
UPDATE activity_log l JOIN persons p ON p.nom = l.personne SET l.person_id = p.id;

-- L'ordre d'affichage part de l'identifiant, donc de l'ordre d'apparition
-- dans les donnees. Il se regle ensuite a la main dans l'administration.
UPDATE persons SET ordre = id;
