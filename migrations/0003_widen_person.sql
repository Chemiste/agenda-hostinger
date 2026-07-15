-- Migration 0003 : elargit la colonne "person" pour supporter des noms
-- personnalises plus longs que "Papa"/"Maman" (ex : prenoms composes).

ALTER TABLE appointments MODIFY person VARCHAR(50) NOT NULL;