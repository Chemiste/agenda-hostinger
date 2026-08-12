-- Migration 0016 : ajout du champ "questions" (facultatif) - liste de
-- questions a poser au medecin/professionnel lors du rendez-vous, une par
-- ligne (texte libre), separee des notes. Affichee sur la carte et
-- imprimee avec le rendez-vous (impression detaillee, comme les notes -
-- pas dans le mode "compact" qui reste volontairement minimal).

ALTER TABLE appointments ADD COLUMN questions TEXT NOT NULL DEFAULT '' AFTER notes;
