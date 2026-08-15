<?php
/**
 * Plan de prise de médicaments — trois notions séparées (voir
 * migrations/0020_restructurer_medicaments.sql) :
 *
 *   medicament_moments : les moments de la journée, propres à chaque
 *                        personne ("Matin", "15h00", "Au coucher"...),
 *                        avec leur ordre d'affichage.
 *   medicaments        : un médicament, une ligne — son nom, son détail,
 *                        sa photo, et son éventuelle alternative
 *                        ("Dafalgan OU Paracetamol EG"), qui vaut pour
 *                        tout le médicament.
 *   medicament_prises  : le croisement médicament × moment, portant la
 *                        quantité — qui peut différer d'un moment à
 *                        l'autre pour un même médicament.
 *
 * Avant cette restructuration, une ligne valait pour UN médicament à UN
 * moment : le nom, le détail et la photo étaient recopiés à chaque moment,
 * et une alternative devait être re-saisie pour chacun d'eux.
 */

// Palette cyclique utilisee pour colorer chaque section (moment) sur la
// fiche de consultation et d'impression (medicaments.php) : un nouveau moment (ex.
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

require_once __DIR__ . '/persons.php';

// ------------------------------------------------------------------
// Les moments de la journée
// ------------------------------------------------------------------

function listerMoments($db, $personId) {
    $stmt = $db->prepare(
        'SELECT * FROM medicament_moments WHERE person_id = ? ORDER BY ordre ASC, id ASC'
    );
    $stmt->execute([(int) $personId]);
    return $stmt->fetchAll();
}

function obtenirMoment($db, $id) {
    $stmt = $db->prepare('SELECT * FROM medicament_moments WHERE id = ?');
    $stmt->execute([(int) $id]);
    $m = $stmt->fetch();
    return $m !== false ? $m : null;
}

function ajouterMoment($db, $personId, $libelle) {
    $personId = (int) $personId;
    $libelle = trim((string) $libelle);
    if ($libelle === '') {
        throw new Exception('Le nom du moment ne peut pas être vide.');
    }
    // Deux moments du meme nom pour une meme personne n'auraient aucun
    // sens et rendraient la fiche illisible.
    $stmt = $db->prepare('SELECT COUNT(*) FROM medicament_moments WHERE person_id = ? AND LOWER(libelle) = LOWER(?)');
    $stmt->execute([$personId, $libelle]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new Exception('Le moment « ' . $libelle . ' » existe déjà.');
    }

    $stmt = $db->prepare('SELECT COALESCE(MAX(ordre), -1) + 1 FROM medicament_moments WHERE person_id = ?');
    $stmt->execute([$personId]);
    $ordre = (int) $stmt->fetchColumn();

    // Le nom est encore ecrit a cote de l'identifiant : la colonne texte
    // existe jusqu'a la migration 0022, et les sauvegardes JSON s'en
    // servent. C'est person_id qui fait foi (voir lib/persons.php).
    $stmt = $db->prepare('INSERT INTO medicament_moments (person, person_id, libelle, ordre) VALUES (?, ?, ?, ?)');
    $stmt->execute([nomPerson($db, $personId), $personId, $libelle, $ordre]);
    return (int) $db->lastInsertId();
}

function renommerMoment($db, $id, $libelle) {
    $libelle = trim((string) $libelle);
    if ($libelle === '') {
        throw new Exception('Le nom du moment ne peut pas être vide.');
    }
    $moment = obtenirMoment($db, $id);
    if ($moment === null) {
        throw new Exception('Moment introuvable.');
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM medicament_moments WHERE person_id = ? AND LOWER(libelle) = LOWER(?) AND id != ?'
    );
    $stmt->execute([(int) $moment['person_id'], $libelle, (int) $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new Exception('Le moment « ' . $libelle . ' » existe déjà.');
    }
    $stmt = $db->prepare('UPDATE medicament_moments SET libelle = ? WHERE id = ?');
    $stmt->execute([$libelle, (int) $id]);
}

/**
 * Échange la position d'un moment avec son voisin du dessus ('haut') ou
 * du dessous ('bas'). Renommer ou réordonner ne touche plus aux
 * médicaments : c'est tout l'intérêt d'avoir sorti les moments de la
 * table des médicaments.
 */
function deplacerMoment($db, $id, $direction) {
    $moment = obtenirMoment($db, $id);
    if ($moment === null) {
        return;
    }
    $moments = listerMoments($db, $moment['person_id']);
    $index = null;
    foreach ($moments as $i => $m) {
        if ((int) $m['id'] === (int) $id) {
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

    // On reecrit les deux positions plutot que d'echanger les valeurs
    // existantes : deux moments peuvent partager le meme "ordre" (donnees
    // reprises de l'ancien format), un simple echange ne changerait alors
    // rien du tout.
    $stmt = $db->prepare('UPDATE medicament_moments SET ordre = ? WHERE id = ?');
    $stmt->execute([$cible, (int) $moments[$index]['id']]);
    $stmt->execute([$index, (int) $moments[$cible]['id']]);

    // Renumerote proprement tout le monde, pour que les prochains
    // deplacements partent d'une base saine.
    $position = 0;
    foreach (listerMoments($db, $moment['person_id']) as $m) {
        $stmt->execute([$position++, (int) $m['id']]);
    }
}

// Nombre de prises rattachees a un moment : sert a empecher (et a
// expliquer) la suppression d'un moment encore utilise.
function compterPrisesDuMoment($db, $momentId) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM medicament_prises WHERE moment_id = ?');
    $stmt->execute([(int) $momentId]);
    return (int) $stmt->fetchColumn();
}

function supprimerMoment($db, $id) {
    $nb = compterPrisesDuMoment($db, $id);
    if ($nb > 0) {
        throw new Exception(
            'Ce moment est encore utilisé par ' . $nb . ' médicament(s) : '
            . 'décoche-le d\'abord dans leur fiche.'
        );
    }
    $stmt = $db->prepare('DELETE FROM medicament_moments WHERE id = ?');
    $stmt->execute([(int) $id]);
}

// ------------------------------------------------------------------
// Les médicaments
// ------------------------------------------------------------------

function listerMedicaments($db, $personId) {
    $stmt = $db->prepare('SELECT * FROM medicaments WHERE person_id = ? ORDER BY nom ASC, id ASC');
    $stmt->execute([(int) $personId]);
    return $stmt->fetchAll();
}

/**
 * Médicaments pouvant servir de "principal" à une alternative : ceux qui
 * n'en sont pas eux-mêmes une (pas de chaîne), en excluant éventuellement
 * celui qu'on est en train de modifier.
 */
function listerMedicamentsPrincipaux($db, $personId, $idExclu = null) {
    $sql = 'SELECT * FROM medicaments WHERE person_id = ? AND alternative_de = 0';
    $params = [(int) $personId];
    if ($idExclu !== null) {
        $sql .= ' AND id != ?';
        $params[] = (int) $idExclu;
    }
    $sql .= ' ORDER BY nom ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function obtenirMedicament($db, $id) {
    $stmt = $db->prepare('SELECT * FROM medicaments WHERE id = ?');
    $stmt->execute([(int) $id]);
    $m = $stmt->fetch();
    return $m !== false ? $m : null;
}

/**
 * Vérifie qu'un médicament peut bien servir de principal à $idEnfant :
 * même personne, existant, et pas lui-même une alternative. Retourne
 * l'identifiant validé, ou 0.
 */
function validerAlternativeDe($db, $alternativeDe, $personId, $idEnfant = null) {
    $alternativeDe = (int) $alternativeDe;
    if ($alternativeDe <= 0) {
        return 0;
    }
    if ($idEnfant !== null && $alternativeDe === (int) $idEnfant) {
        return 0;
    }
    $parent = obtenirMedicament($db, $alternativeDe);
    if ($parent === null || (int) $parent['person_id'] !== (int) $personId || (int) $parent['alternative_de'] > 0) {
        return 0;
    }
    return $alternativeDe;
}

function ajouterMedicament($db, $personId, $nom, $detail, $image, $alternativeDe = 0) {
    $personId = (int) $personId;
    $nom = trim((string) $nom);
    if ($personId <= 0) {
        throw new Exception('La personne est obligatoire.');
    }
    if ($nom === '') {
        throw new Exception('Le nom du médicament ne peut pas être vide.');
    }
    $stmt = $db->prepare(
        'INSERT INTO medicaments (person, person_id, nom, detail, image, alternative_de) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        nomPerson($db, $personId),
        $personId,
        $nom,
        trim((string) $detail),
        (string) $image,
        validerAlternativeDe($db, $alternativeDe, $personId),
    ]);
    return (int) $db->lastInsertId();
}

/**
 * $nouvelleImage : false = ne pas toucher à l'image actuelle, '' = la
 * retirer, chaîne non vide = la remplacer. Le fichier lui-même n'est
 * jamais supprimé : le dossier medicaments_photos/ sert de bibliothèque
 * (voir listerPhotosDuDossier).
 */
function modifierMedicament($db, $id, $nom, $detail, $nouvelleImage, $alternativeDe = 0) {
    $nom = trim((string) $nom);
    if ($nom === '') {
        throw new Exception('Le nom du médicament ne peut pas être vide.');
    }
    $actuel = obtenirMedicament($db, $id);
    if ($actuel === null) {
        throw new Exception('Médicament introuvable.');
    }

    $image = $nouvelleImage === false ? $actuel['image'] : (string) $nouvelleImage;

    $stmt = $db->prepare(
        'UPDATE medicaments SET nom = ?, detail = ?, image = ?, alternative_de = ? WHERE id = ?'
    );
    $stmt->execute([
        $nom,
        trim((string) $detail),
        $image,
        validerAlternativeDe($db, $alternativeDe, $actuel['person_id'], $id),
        (int) $id,
    ]);
}

function supprimerMedicament($db, $id) {
    // Ses alternatives redeviennent des medicaments normaux plutot que
    // d'etre supprimees avec lui : ce sont de vrais medicaments, avec
    // leur photo et leurs prises.
    $stmt = $db->prepare('UPDATE medicaments SET alternative_de = 0 WHERE alternative_de = ?');
    $stmt->execute([(int) $id]);

    $stmt = $db->prepare('DELETE FROM medicament_prises WHERE medicament_id = ?');
    $stmt->execute([(int) $id]);

    $stmt = $db->prepare('DELETE FROM medicaments WHERE id = ?');
    $stmt->execute([(int) $id]);
}

// ------------------------------------------------------------------
// Les prises (médicament × moment)
// ------------------------------------------------------------------

// Quantites d'un medicament, indexees par identifiant de moment.
function listerPrises($db, $medicamentId) {
    $stmt = $db->prepare('SELECT moment_id, quantite FROM medicament_prises WHERE medicament_id = ?');
    $stmt->execute([(int) $medicamentId]);
    $parMoment = [];
    foreach ($stmt->fetchAll() as $p) {
        $parMoment[(int) $p['moment_id']] = $p['quantite'];
    }
    return $parMoment;
}

/**
 * Remplace toutes les prises d'un médicament par celles fournies.
 *
 * @param array $quantitesParMoment [id de moment => quantité]. Un moment
 *        absent du tableau signifie "ce médicament ne se prend pas à ce
 *        moment-là".
 */
function definirPrises($db, $medicamentId, $quantitesParMoment) {
    $stmt = $db->prepare('DELETE FROM medicament_prises WHERE medicament_id = ?');
    $stmt->execute([(int) $medicamentId]);

    if (empty($quantitesParMoment)) {
        return;
    }
    $stmt = $db->prepare(
        'INSERT INTO medicament_prises (medicament_id, moment_id, quantite) VALUES (?, ?, ?)'
    );
    foreach ($quantitesParMoment as $momentId => $quantite) {
        $stmt->execute([(int) $medicamentId, (int) $momentId, trim((string) $quantite)]);
    }
}

/**
 * Le plan complet d'une personne, prêt à afficher : la liste des moments
 * dans l'ordre, et pour chacun les médicaments qui s'y prennent (triés par
 * nom), chacun avec sa quantité à ce moment-là et ses éventuelles
 * alternatives — celles qui se prennent aussi à ce moment.
 *
 * Une seule requête par table plutôt qu'une par moment : le plan tient
 * dans quelques dizaines de lignes, autant tout charger et assembler ici.
 */
function construirePlan($db, $personId) {
    $personId = (int) $personId;
    $moments = listerMoments($db, $personId);
    if (empty($moments)) {
        return [];
    }

    $medicaments = [];
    foreach (listerMedicaments($db, $personId) as $m) {
        $medicaments[(int) $m['id']] = $m;
    }

    $stmt = $db->prepare(
        'SELECT p.medicament_id, p.moment_id, p.quantite FROM medicament_prises p ' .
        'JOIN medicaments m ON m.id = p.medicament_id WHERE m.person_id = ?'
    );
    $stmt->execute([$personId]);

    // [id de moment][id de medicament] => quantite
    $parMoment = [];
    foreach ($stmt->fetchAll() as $p) {
        $parMoment[(int) $p['moment_id']][(int) $p['medicament_id']] = $p['quantite'];
    }

    $plan = [];
    foreach ($moments as $moment) {
        $idMoment = (int) $moment['id'];
        $ici = isset($parMoment[$idMoment]) ? $parMoment[$idMoment] : [];

        $principaux = [];
        foreach ($ici as $idMed => $quantite) {
            if (!isset($medicaments[$idMed])) {
                continue;
            }
            if ((int) $medicaments[$idMed]['alternative_de'] > 0) {
                continue; // rattachee plus bas a son principal
            }
            $entree = $medicaments[$idMed];
            $entree['quantite'] = $quantite;
            $entree['alternatives'] = [];
            $principaux[$idMed] = $entree;
        }

        // Les alternatives rejoignent leur principal, mais seulement si
        // celui-ci se prend aussi a ce moment. Sinon (cas de donnees
        // incoherentes) l'alternative s'affiche seule plutot que de
        // disparaitre du plan.
        foreach ($ici as $idMed => $quantite) {
            if (!isset($medicaments[$idMed])) {
                continue;
            }
            $parent = (int) $medicaments[$idMed]['alternative_de'];
            if ($parent === 0) {
                continue;
            }
            $entree = $medicaments[$idMed];
            $entree['quantite'] = $quantite;
            if (isset($principaux[$parent])) {
                $principaux[$parent]['alternatives'][] = $entree;
            } else {
                $entree['alternatives'] = [];
                $principaux[$idMed] = $entree;
            }
        }

        // Tri alphabetique a l'interieur du moment, puis reordonne pour
        // remplir les lignes de la grille (voir ordonnerPourGrille).
        uasort($principaux, function ($a, $b) {
            return strcasecmp($a['nom'], $b['nom']);
        });

        $plan[] = [
            'moment' => $moment,
            'medicaments' => ordonnerPourGrille(array_values($principaux)),
        ];
    }

    return $plan;
}

/**
 * Réordonne les médicaments d'un moment pour qu'un maximum de lignes de la
 * fiche soient complètes.
 *
 * Un médicament qui a une alternative occupe deux colonnes sur trois (les
 * deux boîtes côte à côte, voir medicaments.php). En ordre purement
 * alphabétique, il suffit qu'il ne reste qu'une seule colonne libre en fin
 * de ligne pour qu'un médicament double parte à la ligne suivante et
 * laisse un trou. Sur le plan de Christiane, « Matin » occupait ainsi cinq
 * lignes dont deux à moitié vides, alors que ses 12 colonnes tiennent
 * exactement en quatre lignes pleines.
 *
 * Règle : à chaque emplacement on prend le médicament le PLUS LARGE qui
 * tient encore dans la ligne en cours ; à largeur égale, l'ordre
 * alphabétique départage. Prendre le plus large d'abord évite de se
 * retrouver en fin de ligne avec une seule colonne libre et uniquement des
 * médicaments doubles à placer.
 *
 * L'ordre alphabétique n'est donc plus garanti — c'est assumé : sur une
 * feuille posée près du pilulier, une ligne à moitié vide coûte plus cher
 * qu'un ordre parfait, et on repère un médicament à sa photo bien avant de
 * lire son nom.
 *
 * @param array $medicaments Déjà triés alphabétiquement.
 * @param int   $colonnes    Largeur de la grille (3 sur la fiche).
 */
function ordonnerPourGrille($medicaments, $colonnes = 3) {
    $restants = [];
    foreach ($medicaments as $m) {
        // Une carte occupe une colonne par boîte (le médicament + ses
        // alternatives), sans jamais dépasser la largeur de la grille.
        $largeur = 1 + (isset($m['alternatives']) ? count($m['alternatives']) : 0);
        $restants[] = ['medicament' => $m, 'largeur' => min($largeur, $colonnes)];
    }

    $ordonnes = [];
    $espace = $colonnes;

    while (!empty($restants)) {
        // Le plus large qui tient encore ; à largeur égale, le premier de
        // la liste, donc le premier dans l'ordre alphabétique.
        $choisi = null;
        foreach ($restants as $i => $r) {
            if ($r['largeur'] > $espace) {
                continue;
            }
            if ($choisi === null || $r['largeur'] > $restants[$choisi]['largeur']) {
                $choisi = $i;
            }
        }

        if ($choisi === null) {
            // Plus rien ne tient dans la place restante : on passe à la
            // ligne suivante. La ligne en cours garde son trou, il était
            // inévitable (ex. une seule colonne libre et plus que des
            // médicaments à alternative).
            $espace = $colonnes;
            continue;
        }

        $ordonnes[] = $restants[$choisi]['medicament'];
        $espace -= $restants[$choisi]['largeur'];
        array_splice($restants, $choisi, 1);

        if ($espace === 0) {
            $espace = $colonnes;
        }
    }

    return $ordonnes;
}

// ------------------------------------------------------------------
// Les photos (dossier medicaments_photos/)
// ------------------------------------------------------------------

/**
 * Toutes les photos présentes dans le dossier, qu'elles soient rattachées
 * à un médicament ou non : le dossier sert de bibliothèque, on peut y
 * déposer à l'avance la photo d'une boîte et la choisir plus tard.
 */
function listerPhotosDuDossier($dossier) {
    if (!is_dir($dossier)) {
        return [];
    }
    $extensions = ['jpg', 'jpeg', 'png', 'webp'];
    $photos = [];
    foreach (scandir($dossier) as $fichier) {
        if ($fichier === '.' || $fichier === '..' || !is_file($dossier . $fichier)) {
            continue;
        }
        if (in_array(strtolower(pathinfo($fichier, PATHINFO_EXTENSION)), $extensions, true)) {
            $photos[] = $fichier;
        }
    }
    sort($photos);
    return $photos;
}

/**
 * Noms de fichiers photo utilisés par au moins un médicament, toutes
 * personnes confondues : le dossier est partagé, une photo ne peut donc
 * être effacée que si plus aucune fiche ne s'en sert.
 */
function listerPhotosUtilisees($db) {
    $stmt = $db->query("SELECT DISTINCT image FROM medicaments WHERE image != ''");
    return array_column($stmt->fetchAll(), 'image');
}
