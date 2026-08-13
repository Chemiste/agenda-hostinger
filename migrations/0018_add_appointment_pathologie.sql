-- Migration 0018 : lien facultatif entre un rendez-vous et une pathologie
-- (voir migrations/0017_add_pathologies.sql). Permet de repondre a "j'ai
-- des rendez-vous prevus pour mon bras ?" : la fiche d'une pathologie
-- liste les rendez-vous qui s'y rapportent (voir pathologies.php et
-- pathologies_plan.php).
--
-- 0 = aucune pathologie associee (cas par defaut, ex. un controle general).
-- Pas de cle etrangere : si une pathologie est supprimee, les rendez-vous
-- passes gardent la valeur mais ne l'affichent plus (la jointure ne
-- trouvera simplement rien) - plus simple et sans risque de blocage a la
-- suppression sur un hebergement mutualise.

ALTER TABLE appointments ADD COLUMN pathologie_id INT NOT NULL DEFAULT 0 AFTER questions;
