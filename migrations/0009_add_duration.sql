-- Migration 0009 : ajout de la duree du rendez-vous (en minutes), utilisee
-- pour calculer l'heure de fin de l'evenement synchronise vers Google
-- Calendar (remplace la duree fixe de 30 minutes utilisee jusqu'ici).

ALTER TABLE appointments ADD COLUMN duration_minutes INT NOT NULL DEFAULT 30 AFTER appt_time;
