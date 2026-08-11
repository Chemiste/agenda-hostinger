<?php
/**
 * Carnet de médecins/spécialistes de référence, indépendant des
 * rendez-vous : garde le nom, la spécialité, l'adresse, le téléphone...
 * même sans rendez-vous prévu (voir lib/medecins.php). Chaque médecin est
 * obligatoirement rattaché à une personne (Papa ou Maman).
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/medecins.php';

$config = require __DIR__ . '/config.php';
$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';

$db = getDb();
$erreur = '';
$idEnEdition = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter') {
        try {
            ajouterMedecin(
                $db,
                isset($_POST['person']) ? $_POST['person'] : '',
                isset($_POST['doctor']) ? $_POST['doctor'] : '',
                isset($_POST['department']) ? $_POST['department'] : '',
                isset($_POST['location']) ? $_POST['location'] : '',
                isset($_POST['phone']) ? $_POST['phone'] : '',
                isset($_POST['route']) ? $_POST['route'] : '',
                isset($_POST['notes']) ? $_POST['notes'] : ''
            );
            // Post/Redirect/Get : repart sur un formulaire vide au lieu de
            // rester rempli avec le medecin qu'on vient d'ajouter.
            header('Location: /medecins.php#formulaireMedecin');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'modifier' && isset($_POST['id'])) {
        try {
            modifierMedecin(
                $db,
                $_POST['id'],
                isset($_POST['person']) ? $_POST['person'] : '',
                isset($_POST['doctor']) ? $_POST['doctor'] : '',
                isset($_POST['department']) ? $_POST['department'] : '',
                isset($_POST['location']) ? $_POST['location'] : '',
                isset($_POST['phone']) ? $_POST['phone'] : '',
                isset($_POST['route']) ? $_POST['route'] : '',
                isset($_POST['notes']) ? $_POST['notes'] : ''
            );
            header('Location: /medecins.php#formulaireMedecin');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
            $idEnEdition = (int) $_POST['id'];
        }
    } elseif ($_POST['action'] === 'supprimer' && isset($_POST['id'])) {
        supprimerMedecin($db, $_POST['id']);
    }
}

// Mode edition : declenche par le lien "Modifier" d'une rangee
// (?modifier=ID), ou reaffiche automatiquement si l'enregistrement d'une
// modification a echoue (voir plus haut) pour ne pas perdre le contexte.
$medecinEnEdition = null;
if ($idEnEdition === null && isset($_GET['modifier'])) {
    $idEnEdition = (int) $_GET['modifier'];
}
if ($idEnEdition !== null) {
    $medecinEnEdition = obtenirMedecin($db, $idEnEdition);
    if ($medecinEnEdition === null) {
        $idEnEdition = null;
    } elseif ($erreur === '') {
        $_POST['person'] = $medecinEnEdition['person'];
        $_POST['doctor'] = $medecinEnEdition['doctor'];
        $_POST['department'] = $medecinEnEdition['department'];
        $_POST['location'] = $medecinEnEdition['location'];
        $_POST['phone'] = $medecinEnEdition['phone'];
        $_POST['route'] = $medecinEnEdition['route'];
        $_POST['notes'] = $medecinEnEdition['notes'];
    }
}

$tousLesMedecins = listerMedecins($db);
$medecinsParPersonne = [$p1 => [], $p2 => []];
foreach ($tousLesMedecins as $m) {
    if (isset($medecinsParPersonne[$m['person']])) {
        $medecinsParPersonne[$m['person']][] = $m;
    }
}

function afficherRangeeMedecin($m) {
    ?>
    <div class="rangee-medecin">
      <div class="detail-medecin">
        <div class="nom-medecin"><?= htmlspecialchars($m['doctor']) ?></div>
        <?php if ($m['department'] !== ''): ?>
          <div class="specialite-medecin"><?= htmlspecialchars($m['department']) ?></div>
        <?php endif; ?>
        <?php if ($m['location'] !== '' || $m['phone'] !== ''): ?>
          <div class="coord-medecin">
            <?= $m['location'] !== '' ? htmlspecialchars($m['location']) : '' ?>
            <?= ($m['location'] !== '' && $m['phone'] !== '') ? ' · ' : '' ?>
            <?= $m['phone'] !== '' ? htmlspecialchars($m['phone']) : '' ?>
          </div>
        <?php endif; ?>
        <?php if ($m['route'] !== ''): ?>
          <div class="coord-medecin"><?= htmlspecialchars($m['route']) ?></div>
        <?php endif; ?>
        <?php if (!empty($m['notes'])): ?>
          <div class="notes-medecin"><?= htmlspecialchars($m['notes']) ?></div>
        <?php endif; ?>
      </div>
      <div class="actions-medecin">
        <a href="?modifier=<?= (int) $m['id'] ?>#formulaireMedecin" class="lien-modifier-tache">Modifier</a>
        <form method="post" data-confirm="Supprimer ce médecin du carnet ?">
          <input type="hidden" name="action" value="supprimer">
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <button type="submit" class="lien-danger">Supprimer</button>
        </form>
      </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Médecins — Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
  <div class="barre-admin">
    <h1>Médecins</h1>
    <div>
      <span class="qui-connecte"><?= htmlspecialchars(personneSessionActuelle()) ?></span>
      <a href="/index.php">Retour à l'agenda</a>
    </div>
  </div>
  <p class="sous-titre" style="margin-bottom:18px;">Un carnet de référence (médecin, spécialité, adresse, téléphone...) à garder même sans rendez-vous prévu. Utilisé aussi pour pré-remplir automatiquement le formulaire de rendez-vous.</p>

  <div class="outil" id="formulaireMedecin">
    <h2 class="panneau-titre" style="font-size:15px;"><?= $medecinEnEdition !== null ? 'Modifier le médecin' : 'Ajouter un médecin' ?></h2>

    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="<?= $medecinEnEdition !== null ? 'modifier' : 'ajouter' ?>">
      <?php if ($medecinEnEdition !== null): ?>
        <input type="hidden" name="id" value="<?= (int) $idEnEdition ?>">
      <?php endif; ?>
      <div class="champ-ligne">
        <div class="champ">
          <label>Personne</label>
          <select name="person" required>
            <option value="<?= htmlspecialchars($p1) ?>"<?= (isset($_POST['person']) && $_POST['person'] === $p1) ? ' selected' : '' ?>><?= htmlspecialchars($p1) ?></option>
            <option value="<?= htmlspecialchars($p2) ?>"<?= (isset($_POST['person']) && $_POST['person'] === $p2) ? ' selected' : '' ?>><?= htmlspecialchars($p2) ?></option>
          </select>
        </div>
        <div class="champ">
          <label>Médecin / spécialiste</label>
          <input type="text" name="doctor" placeholder="Ex. Dr Dupont" required value="<?= isset($_POST['doctor']) ? htmlspecialchars($_POST['doctor']) : '' ?>">
        </div>
      </div>
      <div class="champ">
        <label>Département / spécialité (facultatif)</label>
        <input type="text" name="department" value="<?= isset($_POST['department']) ? htmlspecialchars($_POST['department']) : '' ?>">
      </div>
      <div class="champ">
        <label>Adresse (facultatif)</label>
        <input type="text" name="location" value="<?= isset($_POST['location']) ? htmlspecialchars($_POST['location']) : '' ?>">
      </div>
      <div class="champ-ligne">
        <div class="champ">
          <label>Téléphone (facultatif)</label>
          <input type="text" name="phone" value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
        </div>
        <div class="champ">
          <label>Route (facultatif)</label>
          <input type="text" name="route" value="<?= isset($_POST['route']) ? htmlspecialchars($_POST['route']) : '' ?>">
        </div>
      </div>
      <div class="champ">
        <label>Notes (facultatif)</label>
        <textarea name="notes" rows="2"><?= isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : '' ?></textarea>
      </div>
      <div class="form-boutons">
        <button class="principal" type="submit"><?= $medecinEnEdition !== null ? 'Enregistrer les modifications' : 'Ajouter' ?></button>
        <?php if ($medecinEnEdition !== null): ?>
          <a class="secondaire" href="/medecins.php">Annuler</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="outil" style="margin-top:16px;">
    <h2 class="panneau-titre" style="font-size:15px;"><?= htmlspecialchars($p1) ?><?= count($medecinsParPersonne[$p1]) > 0 ? ' (' . count($medecinsParPersonne[$p1]) . ')' : '' ?></h2>
    <?php if (empty($medecinsParPersonne[$p1])): ?>
      <p class="vide">Aucun médecin enregistré.</p>
    <?php else: ?>
      <div class="grille-medecins">
        <?php foreach ($medecinsParPersonne[$p1] as $m) { afficherRangeeMedecin($m); } ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="outil" style="margin-top:16px;">
    <h2 class="panneau-titre" style="font-size:15px;"><?= htmlspecialchars($p2) ?><?= count($medecinsParPersonne[$p2]) > 0 ? ' (' . count($medecinsParPersonne[$p2]) . ')' : '' ?></h2>
    <?php if (empty($medecinsParPersonne[$p2])): ?>
      <p class="vide">Aucun médecin enregistré.</p>
    <?php else: ?>
      <div class="grille-medecins">
        <?php foreach ($medecinsParPersonne[$p2] as $m) { afficherRangeeMedecin($m); } ?>
      </div>
    <?php endif; ?>
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/assets/admin-ui.js') ?>"></script>
</body>
</html>
