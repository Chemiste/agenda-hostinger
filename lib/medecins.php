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

/**
 * Importe/met a jour une fiche du carnet a partir des donnees d'UN
 * rendez-vous (bouton "Importer dans le carnet", reserve a Laurent -
 * voir api.php action "importer_medecin"). Remplace l'ancien outil
 * ponctuel outils/importer_medecins_existants.php pour l'usage courant :
 * plutot qu'un import en masse une seule fois, on importe/rafraichit
 * medecin par medecin, rendez-vous par rendez-vous.
 *
 * Si le medecin n'existe pas encore dans le carnet (meme personne, meme
 * nom insensible a la casse), on le cree. S'il existe deja, seuls les
 * champs a la fois non vides ET differents de ce qui est deja enregistre
 * sont mis a jour - jamais un champ rempli n'est efface par une valeur
 * vide venue du rendez-vous, et un champ deja a jour n'est pas touche.
 *
 * Retourne 'cree', 'mis_a_jour' ou 'inchange'.
 */
function fusionnerMedecinDepuisRdv($db, $person, $doctor, $department, $location, $phone, $route) {
    $person = trim((string) $person);
    $doctor = trim((string) $doctor);
    if ($person === '') {
        throw new Exception('La personne est obligatoire.');
    }
    if ($doctor === '') {
        throw new Exception('Le nom du médecin ne peut pas être vide.');
    }

    $stmt = $db->prepare('SELECT * FROM medecins WHERE person = ? AND LOWER(doctor) = LOWER(?)');
    $stmt->execute([$person, $doctor]);
    $existant = $stmt->fetch();

    if ($existant === false) {
        ajouterMedecin($db, $person, $doctor, $department, $location, $phone, $route, '');
        return 'cree';
    }

    $department = trim((string) $department);
    $location = trim((string) $location);
    $phone = trim((string) $phone);
    $route = trim((string) $route);

    $nouveauDepartment = ($department !== '' && $department !== $existant['department']) ? $department : $existant['department'];
    $nouveauLocation = ($location !== '' && $location !== $existant['location']) ? $location : $existant['location'];
    $nouveauPhone = ($phone !== '' && $phone !== $existant['phone']) ? $phone : $existant['phone'];
    $nouveauRoute = ($route !== '' && $route !== $existant['route']) ? $route : $existant['route'];

    $modifie = $nouveauDepartment !== $existant['department']
        || $nouveauLocation !== $existant['location']
        || $nouveauPhone !== $existant['phone']
        || $nouveauRoute !== $existant['route'];

    if (!$modifie) {
        return 'inchange';
    }

    modifierMedecin($db, $existant['id'], $person, $doctor, $nouveauDepartment, $nouveauLocation, $nouveauPhone, $nouveauRoute, $existant['notes']);
    return 'mis_a_jour';
}
