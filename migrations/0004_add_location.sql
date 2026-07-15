-- Migration 0004 : ajout du champ "location" (adresse du rendez-vous),
-- separe du champ "doctor" (medecin / type de consultation).

ALTER TABLE appointments ADD COLUMN location VARCHAR(255) NOT NULL DEFAULT '' AFTER department;