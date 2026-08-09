-- Migration 0012 : ajout du champ "accompagnant" (facultatif) - qui
-- accompagne la personne a ce rendez-vous (ex. "Chem", "Laurent",
-- "Helene"), simple texte libre, separe des notes.

ALTER TABLE appointments ADD COLUMN accompagnant VARCHAR(100) NOT NULL DEFAULT '' AFTER route;
