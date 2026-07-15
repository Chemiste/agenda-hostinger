-- Migration 0008 : ajout du champ "reminder_sent_at" (rappels par email).
-- NULL tant qu'aucun rappel n'a ete envoye pour ce rendez-vous , rempli
-- avec la date/heure d'envoi une fois le rappel parti, pour ne jamais
-- envoyer deux fois le meme rappel. Remis a NULL si la date/heure du
-- rendez-vous est modifiee (voir api.php).

ALTER TABLE appointments ADD COLUMN reminder_sent_at DATETIME NULL DEFAULT NULL AFTER notes;