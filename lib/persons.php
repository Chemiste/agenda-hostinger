<?php
/**
 * Les personnes — patients et membres de la famille (table "persons",
 * voir migrations/0021_ajouter_persons.sql).
 *
 * Avant, le nom d'une personne était recopié en clair dans six tables et
 * la liste de référence vivait dans config.php. Renommer quelqu'un dans
 * config.php ne touchait pas les lignes existantes : ses médicaments et
 * ses pathologies disparaissaient de l'écran sans message d'erreur.
 * Désormais le nom n'existe qu'à un seul endroit, et tout le reste
 * pointe dessus par son identifiant.
 *
 * Deux drapeaux plutôt que deux tables :
 *   est_patient       — on suit sa santé : elle a des onglets, un plan de
 *                       médicaments, des pathologies (Michel, Christiane).
 *   peut_se_connecter — elle apparaît dans "Qui est-ce ?" (Michel,
 *                       Christiane, Hélène, Laurent).
 * Michel et Christiane ont les deux : les séparer en deux tables les
 * dédoublerait, c'est-à-dire le problème de départ sous une autre forme.
 *
 * Toutes les lectures passent par un cache de requête : une page affiche
 * facilement quinze badges de personne, il serait absurde d'interroger la
 * base à chaque fois pour une table de quatre lignes.
 */

$GLOBALS['_cache_persons'] = null;

/** Vide le cache — à appeler après toute écriture. */
function oublierCachePersons() {
    $GLOBALS['_cache_persons'] = null;
}

/**
 * Toutes les personnes, actives comme inactives, indexées par identifiant.
 * Ordre d'affichage : colonne "ordre", puis nom.
 */
function listerPersons($db) {
    if ($GLOBALS['_cache_persons'] !== null) {
        return $GLOBALS['_cache_persons'];
    }
    $lignes = $db->query(
        'SELECT * FROM persons ORDER BY ordre ASC, nom ASC'
    )->fetchAll();

    $parId = [];
    foreach ($lignes as $l) {
        $l['id'] = (int) $l['id'];
        $l['est_patient'] = (int) $l['est_patient'] === 1;
        $l['peut_se_connecter'] = (int) $l['peut_se_connecter'] === 1;
        $l['actif'] = (int) $l['actif'] === 1;
        $parId[$l['id']] = $l;
    }
    $GLOBALS['_cache_persons'] = $parId;
    return $parId;
}

/**
 * Les patients : ceux dont on suit la santé. C'est cette liste qui décide
 * des onglets de l'agenda, du sélecteur de personne d'un rendez-vous, et
 * des pages Médicaments / Pathologies / Médecins.
 */
function listerPatients($db) {
    $patients = [];
    foreach (listerPersons($db) as $p) {
        if ($p['est_patient'] && $p['actif']) {
            $patients[$p['id']] = $p;
        }
    }
    return $patients;
}

/** Les personnes autorisees a se connecter au site (voir login.php). */
function listerMembresFamille($db) {
    $membres = [];
    foreach (listerPersons($db) as $p) {
        if ($p['peut_se_connecter'] && $p['actif']) {
            $membres[$p['id']] = $p;
        }
    }
    return $membres;
}

function obtenirPerson($db, $id) {
    $tous = listerPersons($db);
    $id = (int) $id;
    return isset($tous[$id]) ? $tous[$id] : null;
}

/**
 * Le nom d'une personne, ou une mention explicite si l'identifiant ne
 * correspond à personne. Ne renvoie jamais une chaîne vide : une carte
 * sans nom serait plus déroutante qu'une carte qui dit que le nom manque.
 */
function nomPerson($db, $id) {
    $p = obtenirPerson($db, $id);
    return $p !== null ? $p['nom'] : 'Personne inconnue';
}

/**
 * Retrouve une personne par son nom (comparaison insensible à la casse).
 * Sert aux passerelles avec l'ancien format : restauration d'une
 * sauvegarde JSON, import .ics, données déjà en base.
 */
function personParNom($db, $nom) {
    $nom = trim((string) $nom);
    if ($nom === '') {
        return null;
    }
    foreach (listerPersons($db) as $p) {
        if (strcasecmp($p['nom'], $nom) === 0) {
            return $p;
        }
    }
    return null;
}

/**
 * Vérifie qu'un identifiant reçu d'un formulaire désigne bien un patient
 * existant. Retourne l'identifiant validé, ou 0.
 */
function validerPatient($db, $id) {
    $id = (int) $id;
    $patients = listerPatients($db);
    return isset($patients[$id]) ? $id : 0;
}

// ------------------------------------------------------------------
// Écriture (administration)
// ------------------------------------------------------------------

/**
 * @param string $googleEmail Adresse du compte Google autorisé à se
 *        connecter sous cette identité. C'est le seul moyen d'entrer sur
 *        le site : une personne sans adresse ne peut pas se connecter.
 * @param bool   $estAdmin    Droit de modifier les données de santé et
 *        d'atteindre l'administration.
 */
function ajouterPerson($db, $nom, $estPatient, $peutSeConnecter, $googleEmail = '', $estAdmin = false) {
    $nom = trim((string) $nom);
    if ($nom === '') {
        throw new Exception('Le nom ne peut pas être vide.');
    }
    if (personParNom($db, $nom) !== null) {
        throw new Exception('« ' . $nom . ' » existe déjà.');
    }
    $googleEmail = normaliserEmailGoogle($db, $googleEmail, null);
    $stmt = $db->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM persons');
    $stmt->execute();
    $ordre = (int) $stmt->fetchColumn();

    $stmt = $db->prepare(
        'INSERT INTO persons (nom, est_patient, peut_se_connecter, ordre, google_email, est_admin) ' .
        'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $nom, $estPatient ? 1 : 0, $peutSeConnecter ? 1 : 0, $ordre,
        $googleEmail !== '' ? $googleEmail : null, $estAdmin ? 1 : 0,
    ]);
    oublierCachePersons();
    return (int) $db->lastInsertId();
}

/**
 * Renommer est désormais sans danger : le nom n'est stocké qu'ici, tout
 * le reste pointe sur l'identifiant. C'est précisément ce que cette table
 * est venue régler.
 */
function modifierPerson($db, $id, $nom, $estPatient, $peutSeConnecter, $googleEmail = '', $estAdmin = false) {
    $nom = trim((string) $nom);
    if ($nom === '') {
        throw new Exception('Le nom ne peut pas être vide.');
    }
    $autre = personParNom($db, $nom);
    if ($autre !== null && $autre['id'] !== (int) $id) {
        throw new Exception('« ' . $nom . ' » existe déjà.');
    }
    $googleEmail = normaliserEmailGoogle($db, $googleEmail, (int) $id);

    // Changer l'adresse detache le compte Google deja lie (google_sub) :
    // le lien doit se refaire a la premiere connexion avec la NOUVELLE
    // adresse. Sans ce detachement, corriger une adresse saisie de travers
    // n'aurait aucun effet - l'ancien compte, deja rattache par son sub,
    // continuerait d'entrer.
    $stmt = $db->prepare('SELECT google_email FROM persons WHERE id = ?');
    $stmt->execute([(int) $id]);
    $ancienne = (string) $stmt->fetchColumn();
    $detacher = strtolower($ancienne) !== strtolower((string) $googleEmail);

    $sql = 'UPDATE persons SET nom = ?, est_patient = ?, peut_se_connecter = ?, '
         . 'google_email = ?, est_admin = ?' . ($detacher ? ', google_sub = NULL' : '')
         . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $nom, $estPatient ? 1 : 0, $peutSeConnecter ? 1 : 0,
        $googleEmail !== '' ? $googleEmail : null, $estAdmin ? 1 : 0, (int) $id,
    ]);
    oublierCachePersons();
}

/**
 * Valide une adresse Google et verifie qu'aucune autre personne ne l'utilise
 * deja : deux personnes partageant une adresse rendraient la connexion
 * ambigue, et laquelle des deux entrerait dependrait de l'ordre des lignes.
 */
function normaliserEmailGoogle($db, $email, $idExclu) {
    $email = strtolower(trim((string) $email));
    if ($email === '') {
        return '';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('« ' . $email . ' » n\'est pas une adresse e-mail valide.');
    }
    $sql = 'SELECT nom FROM persons WHERE LOWER(google_email) = ?';
    $params = [$email];
    if ($idExclu !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $idExclu;
    }
    $stmt = $db->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    $dejaPrise = $stmt->fetchColumn();
    if ($dejaPrise !== false) {
        throw new Exception('Cette adresse est déjà associée à ' . $dejaPrise . '.');
    }
    return $email;
}

/** Compte ce qui est rattaché à une personne, table par table. */
function compterDonneesPerson($db, $id) {
    $tables = [
        'rendez-vous' => 'appointments',
        'médecins' => 'medecins',
        'médicaments' => 'medicaments',
        'pathologies' => 'pathologies',
        'tâches' => 'taches',
    ];
    $total = [];
    foreach ($tables as $libelle => $table) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE person_id = ?');
        $stmt->execute([(int) $id]);
        $n = (int) $stmt->fetchColumn();
        if ($n > 0) {
            $total[$libelle] = $n;
        }
    }
    return $total;
}

/**
 * On ne supprime pas une personne qui a des données : on la désactive.
 * Elle disparaît des listes et des onglets, mais son historique reste
 * lisible — supprimer la ligne rendrait orphelins des rendez-vous passés
 * et des entrées du journal, ce qui est exactement ce qu'on cherchait à
 * éviter en créant cette table.
 */
function desactiverPerson($db, $id) {
    $stmt = $db->prepare('UPDATE persons SET actif = 0 WHERE id = ?');
    $stmt->execute([(int) $id]);
    oublierCachePersons();
}

function reactiverPerson($db, $id) {
    $stmt = $db->prepare('UPDATE persons SET actif = 1 WHERE id = ?');
    $stmt->execute([(int) $id]);
    oublierCachePersons();
}

/** Supprime définitivement — refusé s'il reste des données rattachées. */
function supprimerPerson($db, $id) {
    $donnees = compterDonneesPerson($db, $id);
    if (!empty($donnees)) {
        $details = [];
        foreach ($donnees as $libelle => $n) {
            $details[] = $n . ' ' . $libelle;
        }
        throw new Exception(
            'Impossible de supprimer : il reste ' . implode(', ', $details)
            . '. Désactive plutôt cette personne, son historique restera lisible.'
        );
    }
    $stmt = $db->prepare('DELETE FROM persons WHERE id = ?');
    $stmt->execute([(int) $id]);
    oublierCachePersons();
}

/** Échange la position d'une personne avec sa voisine ('haut' ou 'bas'). */
function deplacerPerson($db, $id, $direction) {
    $liste = array_values(listerPersons($db));
    $index = null;
    foreach ($liste as $i => $p) {
        if ($p['id'] === (int) $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return;
    }
    $cible = $direction === 'haut' ? $index - 1 : $index + 1;
    if ($cible < 0 || $cible >= count($liste)) {
        return;
    }

    // On reecrit les deux positions puis on renumerote tout le monde :
    // deux personnes peuvent partager le meme "ordre" (donnees reprises),
    // un simple echange de valeurs ne changerait alors rien.
    $stmt = $db->prepare('UPDATE persons SET ordre = ? WHERE id = ?');
    $stmt->execute([$cible, $liste[$index]['id']]);
    $stmt->execute([$index, $liste[$cible]['id']]);

    oublierCachePersons();
    $position = 0;
    foreach (listerPersons($db) as $p) {
        $stmt->execute([$position++, $p['id']]);
    }
    oublierCachePersons();
}
