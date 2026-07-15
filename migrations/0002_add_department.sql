-- Migration 0002 : ajout du champ "departement", affiche avant les notes
-- (et place devant la note dans la description Google Calendar).

ALTER TABLE appointments ADD COLUMN department VARCHAR(255) NOT NULL DEFAULT '' AFTER doctor;