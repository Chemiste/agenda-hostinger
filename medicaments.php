<?php
/**
 * Gestion du plan de prise de médicaments (voir lib/medicaments.php) :
 * ajouter/modifier/supprimer un médicament, avec une photo facultative,
 * pour générer la fiche imprimable (medicaments_plan.php).
 *
 * Limité à Christiane pour l'instant (personne_2) - facile à étendre à
 * Michel plus tard (il suffirait d'ajouter un sélecteur de personne ici
 * et sur medicaments_plan.php, la table le supporte déjà).
 *
 * Modification (ajout/edition/suppression/réorganisation) réservée à
 * Laurent - les autres membres de la famille consultent et impriment la
 * fiche mais ne peuvent pas la changer, comme "Importer dans le carnet"
 * sur les rendez-vous. $peutModifier protège aussi bien l'affichage des
 * formulaires/boutons que le traitement des actions POST (une page
 * masquée côté HTML ne suffit pas à empêcher une requête envoyée à la
 * main).
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/medicaments.php';

$config = require __DIR__ . '/config.php';
$personneCible = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';
$peutModifier = personneSessionActuelle() === 'Laurent';

$db = getDb();
$erreur = '';
$idEnEdition = null;

$DOSSIER_PHOTOS = __DIR__ . '/medicaments_photos/';

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
    if (!move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/medicaments_photos/' . $nomFichier)) {
        throw new Exception("Impossible d'enregistrer l'image.");
    }
    return $nomFichier;
}

// Si une photo deja presente sur le site a ete choisie (plutot qu'un
// nouvel upload), on ne retient que si son nom figure bien dans la liste
// des photos existantes de cette personne (protege d'un nom de fichier
// arbitraire envoye a la main dans le formulaire).
function imageExistanteChoisie($db, $personneCible) {
    if (empty($_POST['image_existante'])) {
        return false;
    }
    $candidat = basename((string) $_POST['image_existante']);
    return in_array($candidat, listerPhotosExistantes($db, $personneCible), true) ? $candidat : false;
}

if ($peutModifier && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter') {
        try {
            $image = traiterUploadImageMedicament();
            if ($image === false) {
                $existante = imageExistanteChoisie($db, $personneCible);
                if ($existante !== false) {
                    $image = $existante;
                }
            }
            $imageAEnregistrer = $image !== false ? $image : '';

            // Un medicament pris a plusieurs moments (ex. matin, 15h00 et
            // au coucher) peut cocher plusieurs cases d'un coup plutot que
            // de re-saisir trois fois le meme nom/quantite/detail : une
            // ligne est creee par moment coche (+ un eventuel "nouveau
            // moment" tape a la main), toutes avec les memes valeurs. Si
            // la quantite differe pour l'un d'eux, il suffit ensuite de
            // modifier cette seule ligne.
            $moments = [];
            if (isset($_POST['moments']) && is_array($_POST['moments'])) {
                foreach ($_POST['moments'] as $m) {
                    $m = trim((string) $m);
                    if ($m !== '') {
                        $moments[] = $m;
                    }
                }
            }
            $nouveauMoment = isset($_POST['nouveau_moment']) ? trim((string) $_POST['nouveau_moment']) : '';
            if ($nouveauMoment !== '') {
                $moments[] = $nouveauMoment;
            }
            $moments = array_values(array_unique($moments));
            if (empty($moments)) {
                throw new Exception('Choisis ou indique au moins un moment.');
            }

            foreach ($moments as $moment) {
                ajouterMedicament(
                    $db,
                    $personneCible,
                    $moment,
                    isset($_POST['nom']) ? $_POST['nom'] : '',
                    isset($_POST['quantite']) ? $_POST['quantite'] : '',
                    isset($_POST['detail']) ? $_POST['detail'] : '',
                    $imageAEnregistrer
                );
            }
            // Redirige apres un ajout reussi (motif "Post/Redirect/Get") :
            // sans ca, la page se recharge avec le meme $_POST et le
            // formulaire restait rempli avec le medicament qu'on venait
            // d'ajouter au lieu de repartir vide pour le suivant.
            header('Location: /medicaments.php#formulaireMedicament');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'modifier' && isset($_POST['id'])) {
        try {
            $nouvelleImage = !empty($_POST['supprimer_image']) ? '' : false;
            $uploadee = traiterUploadImageMedicament();
            if ($uploadee !== false) {
                $nouvelleImage = $uploadee;
            } elseif (empty($_POST['supprimer_image'])) {
                $existante = imageExistanteChoisie($db, $personneCible);
                if ($existante !== false) {
                    $nouvelleImage = $existante;
                }
            }
            $ancienneImage = modifierMedicament(
                $db,
                $_POST['id'],
                isset($_POST['moment']) ? $_POST['moment'] : '',
                isset($_POST['nom']) ? $_POST['nom'] : '',
                isset($_POST['quantite']) ? $_POST['quantite'] : '',
                isset($_POST['detail']) ? $_POST['detail'] : '',
                $nouvelleImage
            );
            // Une photo peut etre partagee entre plusieurs medicaments (ex.
            // reutilisee via le selecteur) : ne jamais effacer le fichier
            // si une autre ligne y fait encore reference.
            if (!empty($ancienneImage) && !imageEncoreUtilisee($db, $personneCible, $ancienneImage, $_POST['id'])) {
                @unlink($DOSSIER_PHOTOS . $ancienneImage);
            }
            // Meme motif "Post/Redirect/Get" qu'a l'ajout : repart sur un
            // formulaire vide (mode "Ajouter") plutot que de rester rempli
            // avec le medicament qu'on vient de modifier.
            header('Location: /medicaments.php#formulaireMedicament');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
            $idEnEdition = (int) $_POST['id'];
        }
    } elseif ($_POST['action'] === 'supprimer' && isset($_POST['id'])) {
        $image = supprimerMedicament($db, $_POST['id']);
        if (!empty($image) && !imageEncoreUtilisee($db, $personneCible, $image)) {
            @unlink($DOSSIER_PHOTOS . $image);
        }
    } elseif ($_POST['action'] === 'deplacer_moment' && isset($_POST['moment'], $_POST['direction'])) {
        deplacerMoment($db, $personneCible, $_POST['moment'], $_POST['direction']);
    }
}

$medicamentEnEdition = null;
if ($peutModifier && $idEnEdition === null && isset($_GET['modifier'])) {
    $idEnEdition = (int) $_GET['modifier'];
}
if ($idEnEdition !== null) {
    $medicamentEnEdition = obtenirMedicament($db, $idEnEdition);
    if ($medicamentEnEdition === null) {
        $idEnEdition = null;
    } elseif ($erreur === '') {
        $_POST['moment'] = $medicamentEnEdition['moment'];
        $_POST['nom'] = $medicamentEnEdition['nom'];
        $_POST['quantite'] = $medicamentEnEdition['quantite'];
        $_POST['detail'] = $medicamentEnEdition['detail'];
    }
}

$tousLesMedicaments = listerMedicaments($db, $personneCible);
$momentsExistants = listerMomentsExistants($db, $personneCible);
$photosExistantes = listerPhotosExistantes($db, $personneCible);

// Regroupe par moment, dans l'ordre deja fourni par listerMedicaments().
$groupes = [];
foreach ($tousLesMedicaments as $m) {
    $groupes[$m['moment']][] = $m;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Médicaments — Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
  <div class="barre-admin">
    <h1>Médicaments</h1>
    <div>
      <span class="qui-connecte"><?= htmlspecialchars(personneSessionActuelle()) ?></span>
      <a href="/index.php">Retour à l'agenda</a>
    </div>
  </div>
  <p class="sous-titre" style="margin-bottom:18px;">
    Plan de prise de <?= htmlspecialchars($personneCible) ?>, à générer soi-même en fiche imprimable.
    <?php if (!$peutModifier): ?>
      Seul Laurent peut le modifier — tu peux le consulter et l'imprimer.
    <?php endif; ?>
  </p>

  <div class="outil">
    <a class="principal" href="/medicaments_plan.php" style="gap:8px;">
      <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M6 17v4h12v-4"/></svg>
      Voir / imprimer la fiche
    </a>
  </div>

  <?php if ($peutModifier): ?>
  <div class="outil" id="formulaireMedicament" style="margin-top:16px;">
    <h2 class="panneau-titre" style="font-size:15px;"><?= $medicamentEnEdition !== null ? 'Modifier le médicament' : 'Ajouter un médicament' ?></h2>

    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="<?= $medicamentEnEdition !== null ? 'modifier' : 'ajouter' ?>">
      <?php if ($medicamentEnEdition !== null): ?>
        <input type="hidden" name="id" value="<?= (int) $idEnEdition ?>">
      <?php endif; ?>
      <div class="champ-ligne">
        <div class="champ">
          <?php if ($medicamentEnEdition !== null): ?>
            <label>Moment</label>
            <input type="text" name="moment" list="listeMoments" placeholder="Ex. Matin, 15h00, Au coucher, Si besoin..." required value="<?= isset($_POST['moment']) ? htmlspecialchars($_POST['moment']) : '' ?>">
            <datalist id="listeMoments">
              <?php foreach ($momentsExistants as $mom): ?>
                <option value="<?= htmlspecialchars($mom) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          <?php else: ?>
            <label>Moment(s)</label>
            <?php if (!empty($momentsExistants)): ?>
              <div class="choix-moments">
                <?php $momentsCoches = isset($_POST['moments']) ? (array) $_POST['moments'] : []; ?>
                <?php foreach ($momentsExistants as $mom): ?>
                  <label class="case-moment">
                    <input type="checkbox" name="moments[]" value="<?= htmlspecialchars($mom) ?>"<?= in_array($mom, $momentsCoches, true) ? ' checked' : '' ?>>
                    <?= htmlspecialchars($mom) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <input type="text" name="nouveau_moment" placeholder="Nouveau moment (ex. 15h00)" value="<?= isset($_POST['nouveau_moment']) ? htmlspecialchars($_POST['nouveau_moment']) : '' ?>" style="<?= !empty($momentsExistants) ? 'margin-top:8px;' : '' ?>">
            <p class="aide" style="margin-top:6px;">Coche plusieurs moments si ce médicament se prend à plusieurs reprises dans la journée : une ligne est créée pour chacun, avec les mêmes nom/quantité/détail (modifiable ensuite au cas par cas si l'un d'eux diffère).</p>
          <?php endif; ?>
        </div>
        <div class="champ">
          <label>Médicament</label>
          <input type="text" name="nom" placeholder="Ex. ASA EG" required value="<?= isset($_POST['nom']) ? htmlspecialchars($_POST['nom']) : '' ?>">
        </div>
      </div>
      <div class="champ-ligne">
        <div class="champ">
          <label>Quantité (facultatif)</label>
          <input type="text" name="quantite" placeholder="Ex. 1 comprimé" value="<?= isset($_POST['quantite']) ? htmlspecialchars($_POST['quantite']) : '' ?>">
        </div>
        <div class="champ">
          <label>Détail (facultatif)</label>
          <input type="text" name="detail" placeholder="Ex. 100 mg — anti-coagulant" value="<?= isset($_POST['detail']) ? htmlspecialchars($_POST['detail']) : '' ?>">
        </div>
      </div>
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
            <p class="aide-selecteur-photos">Ou réutiliser une photo déjà présente sur le site :</p>
            <div class="grille-photos-existantes">
              <?php foreach ($photosExistantes as $nomPhoto): ?>
                <?php
                  // Photo deja utilisee par CE medicament (en edition) :
                  // presélectionnée visuellement, pas besoin de recliquer.
                  $dejaUtiliseeIci = $medicamentEnEdition !== null && $medicamentEnEdition['image'] === $nomPhoto;
                ?>
                <button type="button" class="vignette-photo-existante<?= $dejaUtiliseeIci ? ' selectionnee' : '' ?>" data-fichier="<?= htmlspecialchars($nomPhoto) ?>" title="Utiliser cette photo">
                  <img src="/medicaments_photos/<?= rawurlencode($nomPhoto) ?>" alt="">
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <input type="hidden" name="image_existante" id="champImageExistante" value="">
      </div>
      <div class="form-boutons">
        <button class="principal" type="submit"><?= $medicamentEnEdition !== null ? 'Enregistrer les modifications' : 'Ajouter' ?></button>
        <?php if ($medicamentEnEdition !== null): ?>
          <a class="secondaire" href="/medicaments.php">Annuler</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <?php if (empty($groupes)): ?>
    <div class="outil" style="margin-top:16px;">
      <p class="vide">Aucun médicament enregistré.</p>
    </div>
  <?php else: ?>
    <?php $nombreSections = count($groupes); $indexSection = 0; ?>
    <?php foreach ($groupes as $moment => $medicaments): ?>
      <?php $indexSection++; ?>
      <div class="outil" style="margin-top:16px;">
        <div class="entete-section-medicaments">
          <h2 class="panneau-titre" style="font-size:15px; margin:0;"><?= htmlspecialchars($moment) ?> (<?= count($medicaments) ?>)</h2>
          <?php if ($peutModifier && $nombreSections > 1): ?>
            <div class="boutons-deplacer-section">
              <form method="post">
                <input type="hidden" name="action" value="deplacer_moment">
                <input type="hidden" name="moment" value="<?= htmlspecialchars($moment) ?>">
                <input type="hidden" name="direction" value="haut">
                <button type="submit" class="bouton-deplacer" title="Monter cette section" <?= $indexSection === 1 ? 'disabled' : '' ?>>↑</button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="deplacer_moment">
                <input type="hidden" name="moment" value="<?= htmlspecialchars($moment) ?>">
                <input type="hidden" name="direction" value="bas">
                <button type="submit" class="bouton-deplacer" title="Descendre cette section" <?= $indexSection === $nombreSections ? 'disabled' : '' ?>>↓</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
        <div class="grille-medecins">
          <?php foreach ($medicaments as $m): ?>
            <div class="rangee-medecin">
              <?php if (!empty($m['image'])): ?>
                <img src="/medicaments_photos/<?= rawurlencode($m['image']) ?>" alt="" style="width:100%; max-height:90px; object-fit:contain; margin-bottom:6px;">
              <?php endif; ?>
              <div class="detail-medecin">
                <div class="nom-medecin"><?= htmlspecialchars($m['nom']) ?></div>
                <?php if ($m['quantite'] !== ''): ?>
                  <div class="specialite-medecin"><?= htmlspecialchars($m['quantite']) ?></div>
                <?php endif; ?>
                <?php if ($m['detail'] !== ''): ?>
                  <div class="coord-medecin"><?= htmlspecialchars($m['detail']) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($peutModifier): ?>
              <div class="actions-medecin">
                <a href="?modifier=<?= (int) $m['id'] ?>#formulaireMedicament" class="lien-modifier-tache">Modifier</a>
                <form method="post" data-confirm="Supprimer ce médicament du plan ?">
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button type="submit" class="lien-danger">Supprimer</button>
                </form>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/assets/admin-ui.js') ?>"></script>
  <script>
  (function () {
    var champFichier = document.getElementById('champFichierImage');
    var champExistante = document.getElementById('champImageExistante');
    var vignettes = document.querySelectorAll('.vignette-photo-existante');
    if (champExistante && vignettes.length) {
      vignettes.forEach(function (v) {
        v.addEventListener('click', function () {
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
  })();
  </script>
</body>
</html>
