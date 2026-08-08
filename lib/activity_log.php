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

function enregistrerActivite($db, $typeAction, $personne, $resume = '', $appointmentId = null) {
    $stmt = $db->prepare('INSERT INTO activity_log (type_action, personne, appointment_id, resume) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $typeAction,
        ($personne !== null && $personne !== '') ? $personne : 'Inconnu',
        $appointmentId,
        $resume,
    ]);
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
