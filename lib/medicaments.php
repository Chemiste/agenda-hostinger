<?php
/**
 * Plan de prise de médicaments (table "medicaments", voir
 * migrations/0015_add_medicaments.sql) : permet de générer soi-même la
 * fiche "Traitement de ... — Plan de prise quotidien" à afficher/imprimer
 * près des médicaments, sans avoir à repasser par une demande manuelle
 * à chaque changement de traitement.
 *
 * "moment" est du texte libre (ex. "Matin", "15h00", "Au coucher", "Si
 * besoin") : les nouveaux médicaments d'un moment déjà utilisé rejoignent
 * automatiquement la même section (même ordre_moment) ; un moment jamais
 * vu avant devient une nouvelle section, ajoutée à la fin.
 */

// Palette cyclique utilisee pour colorer chaque section (moment) sur la
// fiche imprimable (medicaments_plan.php) : un nouveau moment (ex.
// "15h00") recoit automatiquement la couleur suivante, sans que la
// personne qui saisit le plan ait besoin de choisir une couleur.
function paletteMoment($index) {
    $palette = [
        ['bordure' => '#e6a23c', 'fond' => '#fdf6e8'],
        ['bordure' => '#3b4a6b', 'fond' => '#edf0f7'],
        ['bordure' => '#7c3aed', 'fond' => '#f4f0fe'],
        ['bordure' => '#c0392b', 'fond' => '#fdecea'],
        ['bordure' => '#0f766e', 'fond' => '#e6f5f2'],
        ['bordure' => '#b45309', 'fond' => '#fdf0e2'],
    ];
    return $palette[$index % count($palette)];
}

function listerMedicaments($db, $person) {
    $stmt = $db->prepare(
        'SELECT * FROM medicaments WHERE person = ? ORDER BY ordre_moment ASC, ordre ASC, id ASC'
    );
    $stmt->execute([$person]);
    return $stmt->fetchAll();
}

function obtenirMedicament($db, $id) {
    $stmt = $db->prepare('SELECT * FROM medicaments WHERE id = ?');
    $stmt->execute([(int) $id]);
    $m = $stmt->fetch();
    return $m !== false ? $m : null;
}

// Liste les moments deja utilises pour cette personne, dans leur ordre
// d'affichage - sert de suggestions (datalist) dans le formulaire.
function listerMomentsExistants($db, $person) {
    $stmt = $db->prepare(
        'SELECT DISTINCT moment, MIN(ordre_moment) AS o FROM medicaments WHERE person = ? ' .
        'GROUP BY moment ORDER BY o ASC'
    );
    $stmt->execute([$person]);
    return array_column($stmt->fetchAll(), 'moment');
}

function ordreMomentPour($db, $person, $moment) {
    $stmt = $db->prepare('SELECT ordre_moment FROM medicaments WHERE person = ? AND moment = ? LIMIT 1');
    $stmt->execute([$person, $moment]);
    $existant = $stmt->fetchColumn();
    if ($existant !== false) {
        return (int) $existant;
    }
    $stmt = $db->prepare('SELECT COALESCE(MAX(ordre_moment), -1) + 1 FROM medicaments WHERE person = ?');
    $stmt->execute([$person]);
    return (int) $stmt->fetchColumn();
}

function prochainOrdre($db, $person, $moment) {
    $stmt = $db->prepare('SELECT COALESCE(MAX(ordre), -1) + 1 FROM medicaments WHERE person = ? AND moment = ?');
    $stmt->execute([$person, $moment]);
    return (int) $stmt->fetchColumn();
}

function ajouterMedicament($db, $person, $moment, $nom, $quantite, $detail, $image) {
    $person = trim((string) $person);
    $moment = trim((string) $moment);
    $nom = trim((string) $nom);
    if ($person === '') {
        throw new Exception('La personne est obligatoire.');
    }
    if ($moment === '') {
        throw new Exception('Le moment (ex. Matin, 15h00, Au coucher...) est obligatoire.');
    }
    if ($nom === '') {
        throw new Exception('Le nom du médicament ne peut pas être vide.');
    }
    $ordreMoment = ordreMomentPour($db, $person, $moment);
    $ordre = prochainOrdre($db, $person, $moment);
    $stmt = $db->prepare(
        'INSERT INTO medicaments (person, moment, ordre_moment, ordre, nom, quantite, detail, image) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $person,
        $moment,
        $ordreMoment,
        $ordre,
        $nom,
        trim((string) $quantite),
        trim((string) $detail),
        (string) $image,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * $nouvelleImage : false = ne pas toucher a l'image actuelle, '' = la
 * retirer, chaine non vide = remplacer par ce nom de fichier. Retourne
 * l'ancien nom de fichier image (celui remplace/retire), pour que
 * l'appelant supprime le fichier devenu inutile sur le disque - cette
 * fonction ne touche jamais au systeme de fichiers elle-meme.
 */
function modifierMedicament($db, $id, $moment, $nom, $quantite, $detail, $nouvelleImage) {
    $moment = trim((string) $moment);
    $nom = trim((string) $nom);
    if ($moment === '') {
        throw new Exception('Le moment (ex. Matin, 15h00, Au coucher...) est obligatoire.');
    }
    if ($nom === '') {
        throw new Exception('Le nom du médicament ne peut pas être vide.');
    }

    $actuel = obtenirMedicament($db, $id);
    if ($actuel === null) {
        throw new Exception('Médicament introuvable.');
    }
    $person = $actuel['person'];

    $ancienneImage = null;
    if ($nouvelleImage === false) {
        $image = $actuel['image'];
    } else {
        $image = (string) $nouvelleImage;
        if ($image !== $actuel['image']) {
            $ancienneImage = $actuel['image'];
        }
    }

    // Un changement de moment fait rejoindre (ou creer) la section
    // correspondante, comme a l'ajout.
    if ($moment !== $actuel['moment']) {
        $ordreMoment = ordreMomentPour($db, $person, $moment);
        $ordre = prochainOrdre($db, $person, $moment);
    } else {
        $ordreMoment = (int) $actuel['ordre_moment'];
        $ordre = (int) $actuel['ordre'];
    }

    $stmt = $db->prepare(
        'UPDATE medicaments SET moment = ?, ordre_moment = ?, ordre = ?, nom = ?, quantite = ?, detail = ?, image = ? ' .
        'WHERE id = ?'
    );
    $stmt->execute([
        $moment,
        $ordreMoment,
        $ordre,
        $nom,
        trim((string) $quantite),
        trim((string) $detail),
        $image,
        (int) $id,
    ]);

    return $ancienneImage;
}

// Retourne le nom de fichier image supprime (pour que l'appelant efface
// aussi le fichier sur le disque), ou '' s'il n'y en avait pas.
function supprimerMedicament($db, $id) {
    $m = obtenirMedicament($db, $id);
    $stmt = $db->prepare('DELETE FROM medicaments WHERE id = ?');
    $stmt->execute([(int) $id]);
    return $m !== null ? $m['image'] : '';
}

// Noms de fichiers photo deja utilises pour cette personne, pour proposer
// de reutiliser une photo existante (ex. le meme medicament pris matin et
// soir) plutot que d'avoir a la re-uploader.
function listerPhotosExistantes($db, $person) {
    $stmt = $db->prepare(
        "SELECT DISTINCT image FROM medicaments WHERE person = ? AND image != '' ORDER BY image"
    );
    $stmt->execute([$person]);
    return array_column($stmt->fetchAll(), 'image');
}

// Indique si ce nom de fichier image est encore utilise par au moins un
// medicament de cette personne (en excluant eventuellement $idExclu).
// Une meme photo peut etre partagee entre plusieurs lignes (ex. "Keppra"
// matin et soir) : on ne doit jamais supprimer le fichier sur le disque
// tant qu'une autre ligne y fait encore reference.
function imageEncoreUtilisee($db, $person, $image, $idExclu = null) {
    if ($image === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM medicaments WHERE person = ? AND image = ?';
    $params = [$person, $image];
    if ($idExclu !== null) {
        $sql .= ' AND id != ?';
        $params[] = (int) $idExclu;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

// Moments existants pour cette personne, dans leur ordre d'affichage
// actuel sur la fiche, avec leur ordre_moment (sert a la reorganisation
// manuelle des sections ci-dessous).
function listerMomentsAvecOrdre($db, $person) {
    $stmt = $db->prepare(
        'SELECT moment, MIN(ordre_moment) AS ordre_moment FROM medicaments WHERE person = ? ' .
        'GROUP BY moment ORDER BY ordre_moment ASC'
    );
    $stmt->execute([$person]);
    return $stmt->fetchAll();
}

// Echange la position d'une section (moment) avec celle du dessus
// ('haut') ou du dessous ('bas') sur la fiche - permet par exemple de
// remonter une section "15h00" ajoutee apres coup (et donc placee tout
// en bas) entre "Matin" et "Soir".
function deplacerMoment($db, $person, $moment, $direction) {
    $moments = listerMomentsAvecOrdre($db, $person);
    $index = null;
    foreach ($moments as $i => $m) {
        if ($m['moment'] === $moment) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return;
    }
    $cible = $direction === 'haut' ? $index - 1 : $index + 1;
    if ($cible < 0 || $cible >= count($moments)) {
        return;
    }
    $stmt = $db->prepare('UPDATE medicaments SET ordre_moment = ? WHERE person = ? AND moment = ?');
    $stmt->execute([(int) $moments[$cible]['ordre_moment'], $person, $moments[$index]['moment']]);
    $stmt->execute([(int) $moments[$index]['ordre_moment'], $person, $moments[$cible]['moment']]);
}
