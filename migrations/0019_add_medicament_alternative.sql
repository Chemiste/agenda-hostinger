-- Migration 0019 : un medicament peut etre l'alternative d'un autre
-- ("Dafalgan Forte OU Paracetamol EG"). L'alternative reste une ligne
-- complete (son propre nom, sa quantite - qui peut differer de celle du
-- principal -, son detail et sa photo), simplement rattachee a un autre
-- medicament : elle ne s'affiche pas comme une entree separee du plan
-- mais a l'interieur de la carte de son medicament principal, precedee
-- d'un "OU".
--
-- 0 = medicament normal (cas par defaut). Pas de cle etrangere : a la
-- suppression d'un medicament principal, ses alternatives sont remises a
-- 0 (elles redeviennent des medicaments normaux) plutot que supprimees,
-- pour ne jamais perdre de donnee au passage - voir supprimerMedicament()
-- dans lib/medicaments.php.

ALTER TABLE medicaments ADD COLUMN alternative_de INT NOT NULL DEFAULT 0 AFTER image;
