-- Migration 0006 : ajout du champ "route" (circuit / numero interne de
-- l'hopital, ex. "Route 555" chez certains hopitaux belges), separe du
-- reste du texte.

ALTER TABLE appointments ADD COLUMN route VARCHAR(50) NOT NULL DEFAULT '' AFTER phone;