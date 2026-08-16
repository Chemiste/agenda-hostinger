-- Migration 0023 : choisir, rendez-vous par rendez-vous, s'il declenche un
-- rappel par email la veille.
--
-- POURQUOI. Le rappel etait tout ou rien : soit une personne recevait un
-- mail pour chacun de ses rendez-vous, soit pour aucun (voir
-- mes_rappels.php). Or tous les rendez-vous ne se valent pas. Une prise de
-- sang de routine a quelques rues de chez soi n'a pas besoin du meme
-- accompagnement qu'une consultation en hopital a l'autre bout de la
-- ville. Et un rappel qui arrive pour tout finit par n'etre plus lu du
-- tout - c'est ainsi qu'on rate celui qui comptait.
--
-- ACTIF PAR DEFAUT, et c'est un choix, pas une commodite. Sur un agenda
-- medical, l'oubli doit pencher du cote du rappel envoye en trop plutot
-- que du rappel manquant : on peut ignorer un mail inutile, on ne rattrape
-- pas un rendez-vous manque. La valeur par defaut vaut donc pour les
-- rendez-vous deja enregistres comme pour ceux a venir - personne n'a rien
-- a faire pour que le comportement actuel continue.

ALTER TABLE appointments ADD COLUMN rappel_actif TINYINT(1) NOT NULL DEFAULT 1 AFTER questions;
