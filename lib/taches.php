<?php
/**
 * Petite liste de taches libres (table "taches", voir
 * migrations/0013_add_taches.sql), independante des rendez-vous : des
 * choses a faire comme "prendre rdv chez le dentiste pour Michel" ou
 * "annuler le rendez-vous de mardi" - un rappel d'action, pas un
 * rendez-vous planifie avec une heure precise. Personne et date cible
 * sont toutes les deux facultatives.
 */

function listerTachesOuvertes($db) {
    return $db->query(
        'SELECT * FROM taches WHERE fait = 0 ' .
        'ORDER BY (date_cible IS NULL) ASC, date_cible ASC, created_at ASC'
    )->fetchAll();
}

function listerTachesTerminees($db, $limite = 50) {
    $limite = (int) $limite;
    return $db->query(
        "SELECT * FROM taches WHERE fait = 1 ORDER BY fait_at DESC LIMIT $limite"
    )->fetchAll();
}

function ajouterTache($db, $texte, $personId, $dateCible) {
    $texte = trim((string) $texte);
    if ($texte === '') {
        throw new Exception('Le texte de la tâche ne peut pas être vide.');
    }
    $dateCible = trim((string) $dateCible);
    require_once __DIR__ . '/persons.php';
    $personId = (int) $personId;
    // Le nom est encore ecrit a cote de l'identifiant : la colonne texte
    // existe jusqu'a la migration 0022. C'est person_id qui fait foi.
    $stmt = $db->prepare('INSERT INTO taches (texte, personne, person_id, date_cible) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $texte,
        $personId > 0 ? nomPerson($db, $personId) : '',
        $personId,
        $dateCible !== '' ? $dateCible : null,
    ]);
}

function obtenirTache($db, $id) {
    $stmt = $db->prepare('SELECT * FROM taches WHERE id = ?');
    $stmt->execute([(int) $id]);
    $tache = $stmt->fetch();
    return $tache !== false ? $tache : null;
}

function modifierTache($db, $id, $texte, $personId, $dateCible) {
    $texte = trim((string) $texte);
    if ($texte === '') {
        throw new Exception('Le texte de la tâche ne peut pas être vide.');
    }
    $dateCible = trim((string) $dateCible);
    require_once __DIR__ . '/persons.php';
    $personId = (int) $personId;
    $stmt = $db->prepare('UPDATE taches SET texte = ?, personne = ?, person_id = ?, date_cible = ? WHERE id = ?');
    $stmt->execute([
        $texte,
        $personId > 0 ? nomPerson($db, $personId) : '',
        $personId,
        $dateCible !== '' ? $dateCible : null,
        (int) $id,
    ]);
}

function definirTacheFaite($db, $id, $fait) {
    // NOW() plutot que date('Y-m-d H:i:s') : l'horodatage vient ainsi de
    // la meme horloge que created_at (defaut de la colonne) et que
    // reminder_sent_at. Avec la date calculee par PHP, la table melangeait
    // l'heure de PHP et celle de MySQL - deux valeurs qui ne coincident
    // que si les deux serveurs sont regles sur le meme fuseau, ce que
    // rien ne garantissait.
    // Deux requetes plutot qu'un CASE avec un parametre lie : MySQL
    // recevrait la condition sous forme de chaine ('1'/'0') et devrait la
    // reinterpreter en booleen. Deux lignes lisibles valent mieux qu'une
    // ligne qui repose sur une conversion implicite.
    if ($fait) {
        $stmt = $db->prepare('UPDATE taches SET fait = 1, fait_at = NOW() WHERE id = ?');
    } else {
        $stmt = $db->prepare('UPDATE taches SET fait = 0, fait_at = NULL WHERE id = ?');
    }
    $stmt->execute([(int) $id]);
}

function supprimerTache($db, $id) {
    $stmt = $db->prepare('DELETE FROM taches WHERE id = ?');
    $stmt->execute([(int) $id]);
}
