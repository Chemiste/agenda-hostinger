-- Migration 0020 : restructuration du plan de medicaments.
--
-- Avant, une ligne = un medicament POUR UN moment. Le nom, le detail et la
-- photo etaient donc recopies a chaque moment de prise, une alternative se
-- rattachait a une ligne (donc a re-saisir pour chaque moment), et changer
-- une photo obligeait a la changer partout.
--
-- Trois notions separees desormais :
--   medicament_moments : les moments de la journee, propres a chaque
--                        personne (libelle + ordre d'affichage)
--   medicaments        : un medicament, une ligne (nom, detail, photo, et
--                        son eventuelle alternative qui vaut pour tout le
--                        medicament)
--   medicament_prises  : le croisement medicament x moment, portant la
--                        quantite (qui peut differer d'un moment a l'autre)
--
-- L'ancienne table est RENOMMEE, pas supprimee : les donnees restent
-- consultables dans medicaments_v1 le temps de verifier la reprise (voir
-- outils/reprendre_medicaments.php). A supprimer a la main plus tard.
--
-- ATTENTION - a n'appliquer qu'avec le nouveau code : entre la migration
-- et le deploiement des pages reecrites, la page Medicaments ne fonctionne
-- pas (l'ancienne page interroge des colonnes qui n'existent plus).
--
-- En cas d'echec au milieu : les deux CREATE TABLE sont relançables tels
-- quels. Si le RENAME est passe mais pas le dernier CREATE, la table
-- medicaments est absente - la recreer avec le CREATE ci-dessous suffit.

CREATE TABLE IF NOT EXISTS medicament_moments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(20) NOT NULL,
  libelle VARCHAR(100) NOT NULL,
  ordre INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_moments_person (person)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS medicament_prises (
  id INT AUTO_INCREMENT PRIMARY KEY,
  medicament_id INT NOT NULL,
  moment_id INT NOT NULL,
  quantite VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_medicament_moment (medicament_id, moment_id),
  INDEX idx_prises_moment (moment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

RENAME TABLE medicaments TO medicaments_v1;

CREATE TABLE IF NOT EXISTS medicaments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(20) NOT NULL,
  nom VARCHAR(150) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  image VARCHAR(255) NOT NULL DEFAULT '',
  alternative_de INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_medicaments_person (person)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
