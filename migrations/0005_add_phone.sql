-- Migration 0005 : ajout du champ "phone" (numero de telephone du service
-- ou du secretariat), separe du reste du texte (adresse, notes).

ALTER TABLE appointments ADD COLUMN phone VARCHAR(50) NOT NULL DEFAULT '' AFTER location;