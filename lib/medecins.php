<?php
/**
 * Carnet de médecins/spécialistes de référence (table "medecins", voir
 * migrations/0014_add_medecins.sql), indépendant des rendez-vous : permet
 * de garder le nom, la spécialité, l'adresse, le téléphone... d'un
 * médecin même sans rendez-vous prévu. Contrairement à la mémorisation
 * automatique basée sur l'historique des rendez-vous (voir
 * construireInfosParMedecin() dans assets/app.js), ce carnet est saisi à
 * la main et fusionné avec cet historique pour l'auto-remplissage du
 * formulaire (voir api.php, action "medecins").
 */

function listerMedecins($db) {
    return $db->query(
        'SELECT * FROM medecins ORDER BY person ASC, doctor ASC'
    )->fetchAll();
}

function obtenirMedecin($db, $id) {
    $stmt = $db->prepare('SELECT * FROM medecins WHERE id = ?');
    $stmt->execute([(int) $id]);
    $medecin = $stmt->fetch();
    return $medecin !== false ? $medecin : null;
}

function ajouterMedecin($db, $person, $doctor, $department, $location, $phone, $route, $notes) {
    $person = trim((string) $person);
    $doctor = trim((string) $doctor);
    if ($person === '') {
        throw new Exception('La personne est obligatoire.');
    }
    if ($doctor === '') {
        throw new Exception('Le nom du médecin ne peut pas être vide.');
    }
    $stmt = $db->prepare(
        'INSERT INTO medecins (person, doctor, department, location, phone, route, notes) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $person,
        $doctor,
        trim((string) $department),
        trim((string) $location),
        trim((string) $phone),
        trim((string) $route),
        trim((string) $notes),
    ]);
}

function modifierMedecin($db, $id, $person, $doctor, $department, $location, $phone, $route, $notes) {
    $person = trim((string) $person);
    $doctor = trim((string) $doctor);
    if ($person === '') {
        throw new Exception('La personne est obligatoire.');
    }
    if ($doctor === '') {
        throw new Exception('Le nom du médecin ne peut pas être vide.');
    }
    $stmt = $db->prepare(
        'UPDATE medecins SET person = ?, doctor = ?, department = ?, location = ?, phone = ?, route = ?, notes = ? ' .
        'WHERE id = ?'
    );
    $stmt->execute([
        $person,
        $doctor,
        trim((string) $department),
        trim((string) $location),
        trim((string) $phone),
        trim((string) $route),
        trim((string) $notes),
        (int) $id,
    ]);
}

function supprimerMedecin($db, $id) {
    $stmt = $db->prepare('DELETE FROM medecins WHERE id = ?');
    $stmt->execute([(int) $id]);
}
