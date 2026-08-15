<?php
/**
 * ADMINISTRATION : gestion du plan de médicaments.
 *
 * Contrepartie de /medicaments.php, qui est la page de CONSULTATION (et
 * d'impression) : groupée par moment, grandes photos, à lire en
 * remplissant les bacs du pilulier. Ici c'est l'inverse — on vient
 * corriger une quantité ou ajouter une boîte, donc la liste est
 * organisée par MÉDICAMENT : une ligne chacun, ses moments de prise en
 * pastilles. Un médicament pris trois fois par jour apparaît une fois,
 * pas trois.
 *
 * La page rassemble trois choses, dans l'ordre où on s'en sert :
 *   1. la liste des médicaments (consultation et correction) ;
 *   2. le formulaire d'ajout / modification, avec la bibliothèque de
 *      photos ;
 *   3. les moments de la journée, qu'on ne touche que très rarement.
 *
 * Protégée par le mot de passe admin comme le reste de /admin : la saisie
 * n'est le travail que de Laurent, et elle n'a pas à encombrer l'écran
 * que consultent Michel et Christiane.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/medicaments.php';
require_once __DIR__ . '/../lib/persons.php';
require_once __DIR__ . '/../lib/entete_admin.php';

$db = getDb();

// Le patient dont on gere le plan, choisi par les onglets ("?person=").
// Aucun nom n'est ecrit en dur : la liste vient de la table persons, et un
// identifiant inconnu retombe sur le premier patient. Toutes les
// redirections de cette page reconduisent ce parametre, sinon on
// reviendrait sur un autre patient apres chaque enregistrement.
$patients = listerPatients($db);
// Les formulaires de cette page postent vers l'URL courante, "?person="
// comprise : $_GET reste renseigne pendant un POST, inutile d'ajouter un
// champ cache dans chacun des neuf formulaires.
$personCibleId = (int) (isset($_GET['person']) ? $_GET['person'] : 0);
if (!isset($patients[$personCibleId])) {
    $personCibleId = !empty($patients) ? (int) key($patients) : 0;
}
$personneCible = isset($patients[$personCibleId]) ? $patients[$personCibleId]['nom'] : 'Personne';

// Base des redirections : elle emporte toujours le patient en cours.
$RETOUR = '/admin/medicaments.php?person=' . $personCibleId;

$erreur = '';        // erreurs du formulaire médicament
$erreurMoment = '';  // erreurs du bloc « Moments de la journée »
$idEnEdition = null;

$DOSSIER_PHOTOS = __DIR__ . '/../medicaments_photos/';

function traiterUploadImageMedicament() {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return false;
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Erreur lors de l'envoi de l'image.");
    }
    if ($_FILES['image']['size'] > 4 * 1024 * 1024) {
        throw new Exception('Image trop lourde (4 Mo maximum).');
    }
    $infos = getimagesize($_FILES['image']['tmp_name']);
    if ($infos === false) {
        throw new Exception("Le fichier envoyé n'est pas une image valide.");
    }
    $extensionsAutorisees = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensionsAutorisees[$infos['mime']])) {
        throw new Exception("Format d'image non supporté (jpg, png ou webp uniquement).");
    }
    $nomFichier = bin2hex(random_bytes(8)) . '.' . $extensionsAutorisees[$infos['mime']];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../medicaments_photos/' . $nomFichier)) {
        throw new Exception("Impossible d'enregistrer l'image.");
    }
    return $nomFichier;
}

// Si une photo deja presente sur le site a ete choisie (plutot qu'un
// nouvel upload), on ne la retient que si son nom figure bien dans la
// liste reelle du dossier (protege d'un nom de fichier arbitraire envoye
// a la main dans le formulaire).
function imageExistanteChoisie($dossierPhotos) {
    if (empty($_POST['image_existante'])) {
        return false;
    }
    $candidat = basename((string) $_POST['image_existante']);
    return in_array($candidat, listerPhotosDuDossier($dossierPhotos), true) ? $candidat : false;
}

/**
 * Les cases « Quand ? » cochées, avec leur quantité : [id de moment =>
 * quantité]. Seuls les moments appartenant vraiment à la personne sont
 * retenus (un identifiant envoyé à la main est ignoré).
 */
function prisesSaisies($momentsAutorises) {
    $coches = isset($_POST['moments']) && is_array($_POST['moments']) ? $_POST['moments'] : [];
    $quantites = isset($_POST['quantite']) && is_array($_POST['quantite']) ? $_POST['quantite'] : [];

    $valides = [];
    foreach ($momentsAutorises as $m) {
        $valides[(int) $m['id']] = true;
    }

    $resultat = [];
    foreach ($coches as $idMoment) {
        $idMoment = (int) $idMoment;
        if (!isset($valides[$idMoment])) {
            continue;
        }
        $resultat[$idMoment] = isset($quantites[$idMoment]) ? (string) $quantites[$idMoment] : '';
    }
    return $resultat;
}

$moments = listerMoments($db, $personCibleId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- Les moments de la journée ---------------------------------
    if ($action === 'ajouter_moment') {
        try {
            ajouterMoment($db, $personCibleId, isset($_POST['libelle']) ? $_POST['libelle'] : '');
            header('Location: ' . $RETOUR . '#moments');
            exit;
        } catch (Exception $e) {
            $erreurMoment = $e->getMessage();
        }
    } elseif ($action === 'renommer_moment' && isset($_POST['moment_id'])) {
        try {
            $moment = obtenirMoment($db, $_POST['moment_id']);
            if ($moment === null || (int) $moment['person_id'] !== $personCibleId) {
                throw new Exception('Moment introuvable.');
            }
            renommerMoment($db, $_POST['moment_id'], isset($_POST['libelle']) ? $_POST['libelle'] : '');
            header('Location: ' . $RETOUR . '#moments');
            exit;
        } catch (Exception $e) {
            $erreurMoment = $e->getMessage();
        }
    } elseif ($action === 'deplacer_moment' && isset($_POST['moment_id'], $_POST['direction'])) {
        $moment = obtenirMoment($db, $_POST['moment_id']);
        if ($moment !== null && (int) $moment['person_id'] === $personCibleId) {
            deplacerMoment($db, $_POST['moment_id'], $_POST['direction']);
        }
        header('Location: ' . $RETOUR . '#moments');
        exit;
    } elseif ($action === 'supprimer_moment' && isset($_POST['moment_id'])) {
        try {
            $moment = obtenirMoment($db, $_POST['moment_id']);
            if ($moment === null || (int) $moment['person_id'] !== $personCibleId) {
                throw new Exception('Moment introuvable.');
            }
            supprimerMoment($db, $_POST['moment_id']);
            header('Location: ' . $RETOUR . '#moments');
            exit;
        } catch (Exception $e) {
            $erreurMoment = $e->getMessage();
        }

    // --- Les médicaments -------------------------------------------
    } elseif ($action === 'ajouter') {
        try {
            $image = traiterUploadImageMedicament();
            if ($image === false) {
                $existante = imageExistanteChoisie($DOSSIER_PHOTOS);
                if ($existante !== false) {
                    $image = $existante;
                }
            }

            $prises = prisesSaisies($moments);
            if (empty($prises)) {
                throw new Exception('Coche au moins un moment de prise.');
            }

            $id = ajouterMedicament(
                $db,
                $personCibleId,
                isset($_POST['nom']) ? $_POST['nom'] : '',
                isset($_POST['detail']) ? $_POST['detail'] : '',
                $image !== false ? $image : '',
                isset($_POST['alternative_de']) ? $_POST['alternative_de'] : 0
            );
            definirPrises($db, $id, $prises);

            // Redirige apres un ajout reussi (motif "Post/Redirect/Get") :
            // sans ca, la page se recharge avec le meme $_POST et le
            // formulaire restait rempli avec le medicament qu'on venait
            // d'ajouter au lieu de repartir vide pour le suivant.
            header('Location: ' . $RETOUR . '');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
        }
    } elseif ($action === 'modifier' && isset($_POST['id'])) {
        try {
            $nouvelleImage = !empty($_POST['supprimer_image']) ? '' : false;
            $uploadee = traiterUploadImageMedicament();
            if ($uploadee !== false) {
                $nouvelleImage = $uploadee;
            } elseif (empty($_POST['supprimer_image'])) {
                $existante = imageExistanteChoisie($DOSSIER_PHOTOS);
                if ($existante !== false) {
                    $nouvelleImage = $existante;
                }
            }

            $prises = prisesSaisies($moments);
            if (empty($prises)) {
                throw new Exception('Coche au moins un moment de prise.');
            }

            modifierMedicament(
                $db,
                $_POST['id'],
                isset($_POST['nom']) ? $_POST['nom'] : '',
                isset($_POST['detail']) ? $_POST['detail'] : '',
                $nouvelleImage,
                isset($_POST['alternative_de']) ? $_POST['alternative_de'] : 0
            );
            definirPrises($db, $_POST['id'], $prises);

            // Le fichier photo n'est PAS efface quand plus aucun medicament
            // ne s'en sert : le dossier medicaments_photos/ est une
            // bibliotheque ou l'on depose a l'avance des photos de boites,
            // et le selecteur les propose toutes.
            header('Location: ' . $RETOUR . '');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
            $idEnEdition = (int) $_POST['id'];
        }
    } elseif ($action === 'supprimer' && isset($_POST['id'])) {
        supprimerMedicament($db, $_POST['id']);
        header('Location: ' . $RETOUR . '');
        exit;
    } elseif ($action === 'supprimer_photo' && isset($_POST['photo'])) {
        // Suppression manuelle d'une photo de la bibliotheque. Deux
        // garde-fous : le fichier doit vraiment exister dans le dossier
        // (pas un chemin envoye a la main), et ne plus etre utilise par
        // aucun medicament - de personne, le dossier etant partage.
        $nomPhoto = basename((string) $_POST['photo']);
        if (!in_array($nomPhoto, listerPhotosDuDossier($DOSSIER_PHOTOS), true)) {
            $erreur = 'Photo introuvable.';
        } elseif (in_array($nomPhoto, listerPhotosUtilisees($db), true)) {
            $erreur = 'Cette photo est encore utilisée par un médicament : retire-la d\'abord de sa fiche.';
        } else {
            @unlink($DOSSIER_PHOTOS . $nomPhoto);
            header('Location: ' . $RETOUR . '#formulaireMedicament');
            exit;
        }
    }

    // Les moments ont pu changer avant qu'une erreur n'interrompe la
    // redirection : on les relit pour afficher la page dans son etat reel.
    $moments = listerMoments($db, $personCibleId);
}

$medicamentEnEdition = null;
if ($idEnEdition === null && isset($_GET['modifier'])) {
    $idEnEdition = (int) $_GET['modifier'];
}
if ($idEnEdition !== null) {
    $medicamentEnEdition = obtenirMedicament($db, $idEnEdition);
    if ($medicamentEnEdition === null || (int) $medicamentEnEdition['person_id'] !== $personCibleId) {
        $medicamentEnEdition = null;
        $idEnEdition = null;
    } elseif ($erreur === '') {
        // Pré-remplissage du formulaire par $_POST : tous les champs
        // lisent $_POST, ce qui fait qu'un formulaire renvoyé en erreur
        // garde la saisie sans code supplémentaire.
        $_POST['nom'] = $medicamentEnEdition['nom'];
        $_POST['detail'] = $medicamentEnEdition['detail'];
        $_POST['alternative_de'] = $medicamentEnEdition['alternative_de'];
        $prisesActuelles = listerPrises($db, $idEnEdition);
        $_POST['moments'] = array_map('strval', array_keys($prisesActuelles));
        $_POST['quantite'] = $prisesActuelles;
    }
}

$photosExistantes = listerPhotosDuDossier($DOSSIER_PHOTOS);
$photosUtilisees = listerPhotosUtilisees($db);
$medicamentsPrincipaux = listerMedicamentsPrincipaux($db, $personCibleId, $idEnEdition);

// Les moments de chaque medicament principal : choisir "alternative a X"
// coche automatiquement les moments de X.
$momentsDesPrincipaux = [];
foreach ($medicamentsPrincipaux as $mp) {
    $momentsDesPrincipaux[(int) $mp['id']] = array_map('intval', array_keys(listerPrises($db, $mp['id'])));
}

// --- La liste, organisee par medicament (et non par moment) ---------
$libelleDuMoment = [];
foreach ($moments as $m) {
    $libelleDuMoment[(int) $m['id']] = $m['libelle'];
}
// Ordre d'affichage des pastilles : celui des moments de la journee, pas
// celui des identifiants.
$ordreDuMoment = array_flip(array_keys($libelleDuMoment));

$tousLesMedicaments = listerMedicaments($db, $personCibleId);
$prisesParMedicament = [];
foreach ($tousLesMedicaments as $m) {
    $prises = listerPrises($db, $m['id']);
    uksort($prises, function ($a, $b) use ($ordreDuMoment) {
        $pa = isset($ordreDuMoment[$a]) ? $ordreDuMoment[$a] : 999;
        $pb = isset($ordreDuMoment[$b]) ? $ordreDuMoment[$b] : 999;
        return $pa - $pb;
    });
    $prisesParMedicament[(int) $m['id']] = $prises;
}

// Les alternatives s'affichent sous leur medicament principal plutot que
// comme des lignes independantes : c'est la meme relation que le « OU »
// de la fiche imprimee.
$alternativesDe = [];
$lignesPrincipales = [];
foreach ($tousLesMedicaments as $m) {
    if ((int) $m['alternative_de'] > 0) {
        $alternativesDe[(int) $m['alternative_de']][] = $m;
    } else {
        $lignesPrincipales[] = $m;
    }
}
// Une alternative dont le principal a disparu (cas de donnees anciennes)
// ne doit pas etre invisible : elle rejoint la liste principale.
foreach ($alternativesDe as $idParent => $enfants) {
    $parentExiste = false;
    foreach ($lignesPrincipales as $p) {
        if ((int) $p['id'] === $idParent) { $parentExiste = true; break; }
    }
    if (!$parentExiste) {
        foreach ($enfants as $e) { $lignesPrincipales[] = $e; }
        unset($alternativesDe[$idParent]);
    }
}
usort($lignesPrincipales, function ($a, $b) {
    return strcasecmp($a['nom'], $b['nom']);
});

$prisesParMoment = [];
foreach ($moments as $m) {
    $prisesParMoment[(int) $m['id']] = compterPrisesDuMoment($db, $m['id']);
}

$momentsCoches = isset($_POST['moments']) ? array_map('intval', (array) $_POST['moments']) : [];
$quantitesSaisies = isset($_POST['quantite']) && is_array($_POST['quantite']) ? $_POST['quantite'] : [];

/** Les pastilles « Matin · 1 comprimé » d'un médicament. */
function pastillesDesPrises($prises, $libelleDuMoment) {
    $html = '';
    foreach ($prises as $idMoment => $quantite) {
        if (!isset($libelleDuMoment[$idMoment])) {
            continue;
        }
        $texte = $libelleDuMoment[$idMoment];
        if (trim((string) $quantite) !== '') {
            $texte .= ' · ' . $quantite;
        }
        $html .= '<span class="pastille-prise">' . htmlspecialchars($texte) . '</span>';
    }
    if ($html === '') {
        $html = '<span class="pastille-prise pastille-vide">Aucun moment</span>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Médicaments — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
  <?php afficherEnteteAdmin(
      'Médicaments',
      'Plan de prise de ' . htmlspecialchars($personneCible) . ' — ' . count($tousLesMedicaments)
      . ' médicament' . (count($tousLesMedicaments) > 1 ? 's' : '') . ', ' . count($moments)
      . ' moment' . (count($moments) > 1 ? 's' : '') . ' de la journée. '
      . '<a href="/medicaments.php?person=' . $personCibleId . '">Voir la fiche telle que la famille la lit</a>.'
  ); ?>

  <?php /* Onglets masques quand il n'y a qu'un patient : un onglet unique
           n'offre aucun choix. */ ?>
  <?php if (count($patients) > 1): ?>
    <div class="tabs onglets-patients" role="tablist">
      <?php $rangOnglet = 0; foreach ($patients as $unPatient): ?>
        <?php
          $classeOnglet = $rangOnglet === 0 ? 'papa' : ($rangOnglet === 1 ? 'maman' : 'tous');
          $rangOnglet++;
          $estActif = (int) $unPatient['id'] === $personCibleId;
        ?>
        <a class="tab <?= $classeOnglet ?><?= $estActif ? ' active' : '' ?>" href="?person=<?= (int) $unPatient['id'] ?>" role="tab" aria-selected="<?= $estActif ? 'true' : 'false' ?>"><?= htmlspecialchars($unPatient['nom']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="outil">
    <div class="entete-liste-medicaments">
      <h2 class="panneau-titre" style="margin:0;">Les médicaments</h2>
      <a class="principal bouton-ajouter-medicament" href="#formulaireMedicament">+ Ajouter un médicament</a>
    </div>

    <?php if (empty($lignesPrincipales)): ?>
      <p class="vide">Aucun médicament enregistré.</p>
    <?php else: ?>
      <div class="liste-medicaments">
        <?php foreach ($lignesPrincipales as $m): ?>
          <?php $idM = (int) $m['id']; ?>
          <div class="bloc-medicament">
            <div class="rangee-medicament">
              <div class="vignette-medicament">
                <?php if (!empty($m['image'])): ?>
                  <img src="/medicaments_photos/<?= rawurlencode($m['image']) ?>" alt="">
                <?php else: ?>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="7" rx="3.5"/><path d="M8 11v7"/><circle cx="17" cy="6" r="3"/></svg>
                <?php endif; ?>
              </div>
              <div class="infos-medicament">
                <div class="nom-medicament-liste"><?= htmlspecialchars($m['nom']) ?></div>
                <?php if ($m['detail'] !== ''): ?>
                  <div class="detail-medicament-liste"><?= htmlspecialchars($m['detail']) ?></div>
                <?php endif; ?>
              </div>
              <div class="pastilles-prises"><?= pastillesDesPrises($prisesParMedicament[$idM], $libelleDuMoment) ?></div>
              <div class="actions-medicament-liste">
                <a href="?person=<?= $personCibleId ?>&amp;modifier=<?= $idM ?>#formulaireMedicament" class="lien-modifier-tache">Modifier</a>
                <form method="post" data-confirm="Supprimer « <?= htmlspecialchars($m['nom']) ?> » de tous les moments du plan ?<?= !empty($alternativesDe[$idM]) ? ' Son alternative restera dans le plan, comme médicament à part entière.' : '' ?>">
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= $idM ?>">
                  <button type="submit" class="lien-danger">Supprimer</button>
                </form>
              </div>
            </div>

            <?php if (!empty($alternativesDe[$idM])): ?>
              <?php foreach ($alternativesDe[$idM] as $alt): ?>
                <?php $idA = (int) $alt['id']; ?>
                <!-- L'alternative sous son principal : meme relation que le
                     « OU » de la fiche imprimee, mais sans repeter toute la
                     ligne comme une entree independante. -->
                <div class="rangee-medicament rangee-alternative">
                  <div class="marque-ou">ou</div>
                  <div class="vignette-medicament">
                    <?php if (!empty($alt['image'])): ?>
                      <img src="/medicaments_photos/<?= rawurlencode($alt['image']) ?>" alt="">
                    <?php else: ?>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="7" rx="3.5"/><path d="M8 11v7"/><circle cx="17" cy="6" r="3"/></svg>
                    <?php endif; ?>
                  </div>
                  <div class="infos-medicament">
                    <div class="nom-medicament-liste"><?= htmlspecialchars($alt['nom']) ?></div>
                    <?php if ($alt['detail'] !== ''): ?>
                      <div class="detail-medicament-liste"><?= htmlspecialchars($alt['detail']) ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="pastilles-prises"><?= pastillesDesPrises($prisesParMedicament[$idA], $libelleDuMoment) ?></div>
                  <div class="actions-medicament-liste">
                    <a href="?person=<?= $personCibleId ?>&amp;modifier=<?= $idA ?>#formulaireMedicament" class="lien-modifier-tache">Modifier</a>
                    <form method="post" data-confirm="Supprimer l'alternative « <?= htmlspecialchars($alt['nom']) ?> » ?">
                      <input type="hidden" name="action" value="supprimer">
                      <input type="hidden" name="id" value="<?= $idA ?>">
                      <button type="submit" class="lien-danger">Supprimer</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="outil" id="formulaireMedicament" style="margin-top:16px;">
    <h2 class="panneau-titre"><?= $medicamentEnEdition !== null ? 'Modifier le médicament' : 'Ajouter un médicament' ?></h2>

    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <?php if (empty($moments)): ?>
      <p class="vide">
        Commence par créer au moins un moment de la journée (Matin, Soir…)
        dans <a href="#moments">Moments de la journée</a>, plus bas : c'est
        là-dedans que se rangent les médicaments.
      </p>
    <?php else: ?>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="<?= $medicamentEnEdition !== null ? 'modifier' : 'ajouter' ?>">
      <?php if ($medicamentEnEdition !== null): ?>
        <input type="hidden" name="id" value="<?= (int) $idEnEdition ?>">
      <?php endif; ?>

      <div class="champ-ligne">
        <div class="champ">
          <label for="champNomMedicament">Médicament</label>
          <input type="text" id="champNomMedicament" name="nom" placeholder="Ex. ASA EG" required value="<?= isset($_POST['nom']) ? htmlspecialchars($_POST['nom']) : '' ?>">
        </div>
        <div class="champ">
          <label for="champDetailMedicament">Détail (facultatif)</label>
          <input type="text" id="champDetailMedicament" name="detail" placeholder="Ex. 100 mg — anti-coagulant" value="<?= isset($_POST['detail']) ? htmlspecialchars($_POST['detail']) : '' ?>">
        </div>
      </div>

      <!-- Le coeur du formulaire : un medicament pris matin, 15h et au
           coucher se saisit UNE fois, en cochant trois cases. La quantite
           appartient a la prise (elle peut differer d'un moment a
           l'autre), pas au medicament. -->
      <div class="champ">
        <label>Quand ?</label>
        <div class="grille-prises" id="grillePrises">
          <?php foreach ($moments as $m): ?>
            <?php
              $idMoment = (int) $m['id'];
              $coche = in_array($idMoment, $momentsCoches, true);
              $quantite = isset($quantitesSaisies[$idMoment]) ? (string) $quantitesSaisies[$idMoment] : '';
            ?>
            <div class="ligne-prise<?= $coche ? ' active' : '' ?>">
              <label class="case-moment">
                <input type="checkbox" name="moments[]" value="<?= $idMoment ?>"<?= $coche ? ' checked' : '' ?>>
                <span><?= htmlspecialchars($m['libelle']) ?></span>
              </label>
              <input type="text" class="quantite-prise" name="quantite[<?= $idMoment ?>]" placeholder="Quantité (ex. 1 comprimé)" value="<?= htmlspecialchars($quantite) ?>"<?= $coche ? '' : ' disabled' ?>>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="aide" style="margin-top:6px;">Coche chaque moment où ce médicament se prend. La première quantité tapée est recopiée sur les autres moments cochés — corrige-la si elle diffère.</p>
      </div>

      <?php if (!empty($medicamentsPrincipaux)): ?>
        <?php $altSelectionnee = isset($_POST['alternative_de']) ? (int) $_POST['alternative_de'] : 0; ?>
        <!-- "Dafalgan Forte OU Paracetamol EG" : l'alternative est un
             medicament a part entiere (son nom, sa photo, ses quantites),
             simplement rattache a un autre. Elle vaut pour tout le
             medicament, on ne la saisit donc qu'une fois. -->
        <div class="champ" id="champAlternative">
          <label for="selAlternative">Est-ce une alternative à un autre médicament ?</label>
          <select name="alternative_de" id="selAlternative" data-moments='<?= htmlspecialchars(json_encode((object) $momentsDesPrincipaux), ENT_QUOTES) ?>'>
            <option value="0">Non, c'est un médicament à part entière</option>
            <?php foreach ($medicamentsPrincipaux as $mp): ?>
              <option value="<?= (int) $mp['id'] ?>"<?= $altSelectionnee === (int) $mp['id'] ? ' selected' : '' ?>>
                Alternative à <?= htmlspecialchars($mp['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="aide" style="margin-top:6px;">Une alternative s'affiche dans la fiche du médicament choisi, précédée d'un « OU ». Choisir un médicament coche automatiquement ses moments de prise.</p>
        </div>
      <?php endif; ?>

      <div class="champ">
        <label>Photo de la boîte (facultatif)</label>
        <input type="file" name="image" id="champFichierImage" accept="image/png, image/jpeg, image/webp">
        <?php if ($medicamentEnEdition !== null && !empty($medicamentEnEdition['image'])): ?>
          <div class="champ-case" style="margin-top:8px;">
            <input type="checkbox" name="supprimer_image" id="supprimerImage" value="1">
            <label for="supprimerImage">Supprimer la photo actuelle</label>
          </div>
        <?php endif; ?>
        <?php if (!empty($photosExistantes)): ?>
          <div class="selecteur-photos-existantes">
            <p class="aide-selecteur-photos">Ou réutiliser une photo déjà présente sur le site — survole une vignette pour la voir en grand :</p>
            <div class="grille-photos-existantes">
              <?php foreach ($photosExistantes as $nomPhoto): ?>
                <?php
                  $dejaUtiliseeIci = $medicamentEnEdition !== null && $medicamentEnEdition['image'] === $nomPhoto;
                  $estUtilisee = in_array($nomPhoto, $photosUtilisees, true);
                ?>
                <!-- <div> et non <button> : la vignette contient deux
                     boutons (choisir / supprimer), et un bouton ne peut pas
                     en contenir un autre. -->
                <div class="vignette-photo-existante<?= $dejaUtiliseeIci ? ' selectionnee' : '' ?>" data-fichier="<?= htmlspecialchars($nomPhoto) ?>">
                  <button type="button" class="choisir-photo" title="Utiliser cette photo — <?= htmlspecialchars($nomPhoto) ?>">
                    <img src="/medicaments_photos/<?= rawurlencode($nomPhoto) ?>" alt="">
                  </button>
                  <?php if (!$estUtilisee): ?>
                    <!-- Croix visible seulement sur les photos qu'aucun
                         medicament n'utilise. Le bouton appartient au
                         formulaire de suppression place plus bas (attribut
                         "form"), car on ne peut pas imbriquer un formulaire
                         dans celui du medicament. -->
                    <button type="submit" form="formSupprimerPhoto" name="photo" value="<?= htmlspecialchars($nomPhoto) ?>" class="supprimer-photo" title="Supprimer définitivement cette photo" aria-label="Supprimer la photo <?= htmlspecialchars($nomPhoto) ?>">×</button>
                  <?php endif; ?>
                  <span class="apercu-photo">
                    <img src="/medicaments_photos/<?= rawurlencode($nomPhoto) ?>" alt="">
                    <span class="nom-apercu"><?= htmlspecialchars($nomPhoto) ?><?= $estUtilisee ? ' · utilisée' : '' ?></span>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <input type="hidden" name="image_existante" id="champImageExistante" value="">
      </div>

      <div class="form-boutons">
        <button class="principal" type="submit"><?= $medicamentEnEdition !== null ? 'Enregistrer les modifications' : 'Ajouter' ?></button>
        <?php if ($medicamentEnEdition !== null): ?>
          <a class="secondaire" href="<?= $RETOUR ?>">Annuler</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Formulaire cible des croix de suppression du selecteur de photos.
         Il vit hors du formulaire du medicament (on ne peut pas imbriquer
         deux formulaires) ; les boutons s'y rattachent par leur attribut
         "form". data-confirm est pris en charge par admin-ui.js. -->
    <form id="formSupprimerPhoto" method="post" data-confirm="Supprimer définitivement cette photo du site ?" style="display:none;">
      <input type="hidden" name="action" value="supprimer_photo">
    </form>

    <?php endif; ?>
  </div>

  <!-- Les moments en bas de page : on les cree une fois pour toutes, alors
       qu'on ajoute des medicaments regulierement. -->
  <div class="outil" id="moments" style="margin-top:16px;">
    <h2 class="panneau-titre">Moments de la journée</h2>
    <p class="aide" style="margin-top:-4px;">Les sections de la fiche, dans l'ordre des bacs du pilulier. Renommer ou déplacer un moment ne touche pas aux médicaments.</p>

    <?php if ($erreurMoment): ?>
      <p class="erreur"><?= htmlspecialchars($erreurMoment) ?></p>
    <?php endif; ?>

    <?php if (!empty($moments)): ?>
      <?php $nombreMoments = count($moments); ?>
      <ul class="liste-moments">
        <?php foreach ($moments as $index => $m): ?>
          <?php $nbPrises = $prisesParMoment[(int) $m['id']]; ?>
          <li class="rangee-moment">
            <form method="post" class="form-renommer-moment">
              <input type="hidden" name="action" value="renommer_moment">
              <input type="hidden" name="moment_id" value="<?= (int) $m['id'] ?>">
              <input type="text" name="libelle" value="<?= htmlspecialchars($m['libelle']) ?>" aria-label="Nom du moment" required>
              <button type="submit" class="secondaire">Renommer</button>
            </form>
            <span class="compteur-moment"><?= $nbPrises ?> médicament<?= $nbPrises > 1 ? 's' : '' ?></span>
            <div class="boutons-deplacer-section">
              <form method="post">
                <input type="hidden" name="action" value="deplacer_moment">
                <input type="hidden" name="moment_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="direction" value="haut">
                <button type="submit" class="bouton-deplacer" title="Monter ce moment" <?= $index === 0 ? 'disabled' : '' ?>>↑</button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="deplacer_moment">
                <input type="hidden" name="moment_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="direction" value="bas">
                <button type="submit" class="bouton-deplacer" title="Descendre ce moment" <?= $index === $nombreMoments - 1 ? 'disabled' : '' ?>>↓</button>
              </form>
              <form method="post" data-confirm="Supprimer le moment « <?= htmlspecialchars($m['libelle']) ?> » ?">
                <input type="hidden" name="action" value="supprimer_moment">
                <input type="hidden" name="moment_id" value="<?= (int) $m['id'] ?>">
                <button type="submit" class="bouton-deplacer bouton-supprimer-moment" title="<?= $nbPrises > 0 ? 'Encore utilisé par ' . $nbPrises . ' médicament(s)' : 'Supprimer ce moment' ?>" <?= $nbPrises > 0 ? 'disabled' : '' ?>>✕</button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" class="form-ajouter-moment">
      <input type="hidden" name="action" value="ajouter_moment">
      <input type="text" name="libelle" placeholder="Nouveau moment (ex. 15h00)" aria-label="Nouveau moment" required>
      <button type="submit" class="principal">Ajouter le moment</button>
    </form>
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
  <script>
  (function () {
    // "+ Ajouter un medicament" est une ancre vers le formulaire : on y
    // pose aussi le curseur, sinon on arrive devant un champ vide sans
    // savoir qu'on peut deja taper.
    var lienAjouter = document.querySelector('.bouton-ajouter-medicament');
    var champNom = document.getElementById('champNomMedicament');
    if (lienAjouter && champNom) {
      lienAjouter.addEventListener('click', function () {
        setTimeout(function () { champNom.focus(); }, 120);
      });
    }

    var champFichier = document.getElementById('champFichierImage');
    var champExistante = document.getElementById('champImageExistante');
    var vignettes = document.querySelectorAll('.vignette-photo-existante');
    if (champExistante && vignettes.length) {
      vignettes.forEach(function (v) {
        // Le clic est ecoute sur le bouton "choisir" a l'interieur, et non
        // sur toute la vignette : celle-ci contient aussi la croix de
        // suppression, qui ne doit pas selectionner la photo au passage.
        var boutonChoisir = v.querySelector('.choisir-photo');
        if (!boutonChoisir) return;
        boutonChoisir.addEventListener('click', function () {
          var etaitSelectionnee = v.classList.contains('selectionnee');
          vignettes.forEach(function (autre) { autre.classList.remove('selectionnee'); });
          if (etaitSelectionnee) {
            champExistante.value = '';
          } else {
            v.classList.add('selectionnee');
            champExistante.value = v.getAttribute('data-fichier');
            if (champFichier) champFichier.value = '';
          }
        });
      });
    }
    if (champFichier && champExistante) {
      champFichier.addEventListener('change', function () {
        if (champFichier.value) {
          champExistante.value = '';
          vignettes.forEach(function (v) { v.classList.remove('selectionnee'); });
        }
      });
    }

    // --- Les cases "Quand ?" -----------------------------------------
    var grille = document.getElementById('grillePrises');
    if (!grille) return;
    var lignes = Array.prototype.slice.call(grille.querySelectorAll('.ligne-prise'));

    // Un champ quantite n'est actif que si son moment est coche : evite de
    // taper une quantite dans un moment qu'on ne prend pas, et de se
    // demander ensuite pourquoi elle n'apparait pas sur la fiche.
    function majLigne(ligne) {
      var case_ = ligne.querySelector('input[type=checkbox]');
      var quantite = ligne.querySelector('.quantite-prise');
      ligne.classList.toggle('active', case_.checked);
      quantite.disabled = !case_.checked;
    }

    // La quantite est presque toujours la meme a tous les moments : la
    // premiere saisie est recopiee sur les moments coches encore vides,
    // et reste modifiable au cas par cas.
    function premiereQuantite() {
      for (var i = 0; i < lignes.length; i++) {
        var q = lignes[i].querySelector('.quantite-prise');
        if (!q.disabled && q.value.trim() !== '') return q.value;
      }
      return '';
    }

    function recopierQuantite(valeur) {
      if (valeur.trim() === '') return;
      lignes.forEach(function (l) {
        var q = l.querySelector('.quantite-prise');
        if (!q.disabled && q.value.trim() === '') q.value = valeur;
      });
    }

    lignes.forEach(function (ligne) {
      var case_ = ligne.querySelector('input[type=checkbox]');
      var quantite = ligne.querySelector('.quantite-prise');
      case_.addEventListener('change', function () {
        majLigne(ligne);
        if (case_.checked) {
          if (quantite.value.trim() === '') quantite.value = premiereQuantite();
          quantite.focus();
        }
      });
      quantite.addEventListener('blur', function () {
        recopierQuantite(quantite.value);
      });
      majLigne(ligne);
    });

    // Choisir "alternative a X" coche les moments de X : une alternative se
    // prend aux memes moments que le medicament qu'elle remplace.
    var selAlternative = document.getElementById('selAlternative');
    if (selAlternative) {
      var momentsParPrincipal = {};
      try {
        momentsParPrincipal = JSON.parse(selAlternative.getAttribute('data-moments') || '{}');
      } catch (e) {
        momentsParPrincipal = {};
      }
      // Uniquement sur "change" (et pas au chargement) : en modification,
      // les cases refletent deja les prises reelles de l'alternative, qui
      // peuvent differer volontairement.
      selAlternative.addEventListener('change', function () {
        var voulus = momentsParPrincipal[selAlternative.value];
        if (!voulus) return;
        lignes.forEach(function (ligne) {
          var case_ = ligne.querySelector('input[type=checkbox]');
          if (voulus.indexOf(parseInt(case_.value, 10)) !== -1) {
            case_.checked = true;
          }
          majLigne(ligne);
        });
        recopierQuantite(premiereQuantite());
      });
    }
  })();
  </script>
</body>
</html>
