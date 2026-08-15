<?php
/**
 * Journal d'activite (table "activity_log", voir
 * migrations/0011_add_activity_log.sql) : qui s'est connecte et qui a
 * ajoute/modifie/supprime un rendez-vous, et quand.
 *
 * "resume" est une copie de texte du rendez-vous au moment de l'action
 * (date, medecin, pour qui) - pas une reference vivante - pour que le
 * journal reste lisible meme apres suppression du rendez-vous concerne.
 */

/**
 * L'identifiant de la personne est retrouve ici a partir de son nom,
 * plutot que d'etre exige de chaque appelant : la fonction est appelee
 * depuis une dizaine d'endroits, et le nom est ce qu'ils ont sous la main.
 * Le nom reste ecrit tel quel a cote — c'est une copie figee, comme
 * "resume" : le journal doit rester lisible meme si la personne est
 * renommee ou retiree plus tard.
 */
function enregistrerActivite($db, $typeAction, $personne, $resume = '', $appointmentId = null) {
    $nom = ($personne !== null && $personne !== '') ? $personne : 'Inconnu';

    $personId = 0;
    require_once __DIR__ . '/persons.php';
    try {
        $p = personParNom($db, $nom);
        if ($p !== null) {
            $personId = $p['id'];
        }
    } catch (Exception $e) {
        // Table persons pas encore creee (migration 0021 non appliquee) :
        // on ecrit le nom seul, comme avant.
        $personId = 0;
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO activity_log (type_action, personne, person_id, appointment_id, resume) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$typeAction, $nom, $personId, $appointmentId, $resume]);
    } catch (Exception $e) {
        // Colonne person_id absente : on retombe sur l'ancien format
        // plutot que de perdre l'entree du journal.
        $stmt = $db->prepare(
            'INSERT INTO activity_log (type_action, personne, appointment_id, resume) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$typeAction, $nom, $appointmentId, $resume]);
    }
}

/**
 * Resume texte d'un rendez-vous (date, medecin, pour qui) a partir d'un
 * tableau associatif contenant au moins 'date' et 'person' (accepte aussi
 * bien les cles utilisees par l'API - date/time/doctor/person - que
 * appt_date/appt_time si jamais appele directement sur une ligne SQL).
 */
function resumeActivite($appt) {
    $date = isset($appt['date']) ? $appt['date'] : (isset($appt['appt_date']) ? $appt['appt_date'] : '');
    $heure = isset($appt['time']) ? $appt['time'] : (isset($appt['appt_time']) ? $appt['appt_time'] : '');
    $morceaux = [];
    if ($date !== '') {
        $texteDate = date('d/m/Y', strtotime($date));
        $morceaux[] = $heure !== '' ? $texteDate . ' ' . substr($heure, 0, 5) : $texteDate;
    }
    if (!empty($appt['doctor'])) $morceaux[] = $appt['doctor'];
    if (!empty($appt['person'])) $morceaux[] = 'pour ' . $appt['person'];
    return implode(' — ', $morceaux);
}

/**
 * @param array $typesAction Filtre optionnel (ex: ['ajout','modification','suppression']
 *                            pour exclure les connexions sur la page familiale).
 */
function listerActivite($db, $limite = 200, $typesAction = null) {
    $limite = (int) $limite;
    if ($typesAction) {
        $marqueurs = implode(',', array_fill(0, count($typesAction), '?'));
        $stmt = $db->prepare("SELECT * FROM activity_log WHERE type_action IN ($marqueurs) ORDER BY created_at DESC LIMIT $limite");
        $stmt->execute($typesAction);
    } else {
        $stmt = $db->prepare("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT $limite");
        $stmt->execute();
    }
    return $stmt->fetchAll();
}
