<?php
/**
 * Suivi des pathologies (table "pathologies", voir
 * migrations/0017_add_pathologies.sql) : pour chaque personne, une liste
 * de pathologies avec leur cause/raison et ce qui est fait pour les
 * soigner (kiné, médecin, médicaments...), en texte libre - pensé pour
 * répondre rapidement à "qu'est-ce qu'on m'a dit pour X ?" lors d'un
 * rendez-vous, même des mois plus tard. Voir pathologies.php (gestion,
 * réservée à Laurent) et pathologies_plan.php (fiche imprimable).
 */

function listerPathologies($db, $person) {
    $stmt = $db->prepare('SELECT * FROM pathologies WHERE person = ? ORDER BY nom ASC');
    $stmt->execute([$person]);
    return $stmt->fetchAll();
}

function obtenirPathologie($db, $id) {
    $stmt = $db->prepare('SELECT * FROM pathologies WHERE id = ?');
    $stmt->execute([(int) $id]);
    $p = $stmt->fetch();
    return $p !== false ? $p : null;
}

function ajouterPathologie($db, $person, $nom, $cause, $traitement) {
    $person = trim((string) $person);
    $nom = trim((string) $nom);
    if ($person === '') {
        throw new Exception('La personne est obligatoire.');
    }
    if ($nom === '') {
        throw new Exception('Le nom de la pathologie ne peut pas être vide.');
    }
    $stmt = $db->prepare('INSERT INTO pathologies (person, nom, cause, traitement) VALUES (?, ?, ?, ?)');
    $stmt->execute([$person, $nom, trim((string) $cause), trim((string) $traitement)]);
    return (int) $db->lastInsertId();
}

function modifierPathologie($db, $id, $nom, $cause, $traitement) {
    $nom = trim((string) $nom);
    if ($nom === '') {
        throw new Exception('Le nom de la pathologie ne peut pas être vide.');
    }
    $stmt = $db->prepare('UPDATE pathologies SET nom = ?, cause = ?, traitement = ? WHERE id = ?');
    $stmt->execute([$nom, trim((string) $cause), trim((string) $traitement), (int) $id]);
}

function supprimerPathologie($db, $id) {
    $stmt = $db->prepare('DELETE FROM pathologies WHERE id = ?');
    $stmt->execute([(int) $id]);
}

/**
 * Rendez-vous rattachés à une pathologie (voir la colonne pathologie_id
 * de la table appointments, migration 0018), les plus proches d'abord.
 *
 * @param bool $seulementAVenir true = uniquement les rendez-vous à venir
 *        (le cas utile au quotidien : "j'ai un rendez-vous prévu pour ça
 *        début octobre"), false = tout l'historique.
 */
function listerRendezVousPathologie($db, $pathologieId, $seulementAVenir = true) {
    $sql = 'SELECT id, appt_date, appt_time, doctor, department FROM appointments WHERE pathologie_id = ?';
    if ($seulementAVenir) {
        $sql .= ' AND appt_date >= CURDATE()';
    }
    $sql .= ' ORDER BY appt_date ASC, appt_time ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute([(int) $pathologieId]);
    return $stmt->fetchAll();
}

/**
 * Libellé court d'un rendez-vous pour l'affichage sur une fiche de
 * pathologie : "3 octobre 2026 à 10:30 — Dr Dupont (Cardiologie)".
 */
function libelleRendezVousPathologie($rdv) {
    $moisFr = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime($rdv['appt_date'] . ' ' . $rdv['appt_time']);
    $texte = (int) date('j', $ts) . ' ' . $moisFr[(int) date('n', $ts)] . ' ' . date('Y', $ts) . ' à ' . date('H:i', $ts);
    $qui = [];
    if (!empty($rdv['doctor'])) $qui[] = $rdv['doctor'];
    if (!empty($rdv['department'])) $qui[] = $rdv['department'];
    return empty($qui) ? $texte : $texte . ' — ' . implode(', ', $qui);
}
