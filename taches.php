<?php
/**
 * Petite liste de tâches libres, indépendante des rendez-vous : pour des
 * choses comme "prendre rdv chez le dentiste pour Michel" ou "annuler le
 * rendez-vous de mardi" - un rappel d'action à cocher, pas un rendez-vous
 * planifié avec une heure précise (voir index.php pour ça).
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/entete.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/taches.php';
require_once __DIR__ . '/lib/persons.php';

$config = require __DIR__ . '/config.php';
// Les patients viennent de la table persons : ajouter une personne ne
// demande plus de toucher au code (voir admin/personnes.php).

$db = getDb();

// Les patients de la table persons : une troisieme personne apparait ici
// sans toucher au code, et renommer quelqu'un ne detache pas ses donnees.
$patients = listerPatients($db);
$erreur = '';
$idEnEdition = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter') {
        try {
            ajouterTache(
                $db,
                isset($_POST['texte']) ? $_POST['texte'] : '',
                validerPatient($db, isset($_POST['person_id']) ? $_POST['person_id'] : 0),
                isset($_POST['date_cible']) ? $_POST['date_cible'] : ''
            );
            // Post/Redirect/Get : repart sur un formulaire vide au lieu de
            // rester rempli avec la tache qu'on vient d'ajouter.
            header('Location: /taches.php#formulaireTache');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'modifier' && isset($_POST['id'])) {
        try {
            modifierTache(
                $db,
                $_POST['id'],
                isset($_POST['texte']) ? $_POST['texte'] : '',
                validerPatient($db, isset($_POST['person_id']) ? $_POST['person_id'] : 0),
                isset($_POST['date_cible']) ? $_POST['date_cible'] : ''
            );
            header('Location: /taches.php#formulaireTache');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
            $idEnEdition = (int) $_POST['id'];
        }
    } elseif ($_POST['action'] === 'toggle' && isset($_POST['id'])) {
        definirTacheFaite($db, $_POST['id'], !empty($_POST['fait']));
    } elseif ($_POST['action'] === 'supprimer' && isset($_POST['id'])) {
        supprimerTache($db, $_POST['id']);
    }
}

// Mode edition : declenche par le lien "Modifier" d'une rangee
// (?modifier=ID), ou reaffiche automatiquement si l'enregistrement d'une
// modification a echoue (voir plus haut) pour ne pas perdre le contexte.
$tacheEnEdition = null;
if ($idEnEdition === null && isset($_GET['modifier'])) {
    $idEnEdition = (int) $_GET['modifier'];
}
if ($idEnEdition !== null) {
    $tacheEnEdition = obtenirTache($db, $idEnEdition);
    if ($tacheEnEdition === null) {
        $idEnEdition = null;
    } elseif ($erreur === '') {
        // Reaffiche ce qui vient d'etre tape en cas d'erreur de validation,
        // sinon les valeurs actuellement enregistrees pour cette tache.
        $_POST['texte'] = $tacheEnEdition['texte'];
        $_POST['person_id'] = (int) $tacheEnEdition['person_id'];
        $_POST['date_cible'] = $tacheEnEdition['date_cible'];
    }
}

$tachesOuvertes = listerTachesOuvertes($db);
$tachesTerminees = listerTachesTerminees($db, 50);
$aujourdhui = date('Y-m-d');

/**
 * Le nom a afficher pour une tache. Il vient de la table persons et non de
 * la colonne texte : contrairement au journal d'activite (une trace figee),
 * une tache est une donnee vivante — renommer quelqu'un doit se voir ici.
 * On retombe sur la colonne texte si l'identifiant ne correspond plus a
 * personne, pour ne pas afficher une tache sans destinataire.
 */
function nomPersonneTache($db, $t) {
    $id = (int) $t['person_id'];
    if ($id > 0) {
        $p = obtenirPerson($db, $id);
        if ($p !== null) {
            return $p['nom'];
        }
    }
    return (string) $t['personne'];
}

function classePersonneTache($personId, $patients) {
    // La couleur suit le RANG du patient, plus son nom : une 3e personne
    // retombe sur la couleur neutre sans rien casser.
    $rang = array_search((int) $personId, array_keys($patients), true);
    if ($rang === 0) return 'badge-papa';
    if ($rang === 1) return 'badge-maman';
    return '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tâches — Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
  <?php afficherEnteteNavigation('taches'); ?>

  <div class="barre-admin">
    <h1>Tâches</h1>
  </div>
  <p class="sous-titre" style="margin-bottom:18px;">Des choses à faire qui ne sont pas (encore) un rendez-vous : "prendre rdv chez...", "annuler le rendez-vous de...".</p>

  <div class="outil" id="formulaireTache">
    <h2 class="panneau-titre"><?= $tacheEnEdition !== null ? 'Modifier la tâche' : 'Ajouter une tâche' ?></h2>

    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="<?= $tacheEnEdition !== null ? 'modifier' : 'ajouter' ?>">
      <?php if ($tacheEnEdition !== null): ?>
        <input type="hidden" name="id" value="<?= (int) $idEnEdition ?>">
      <?php endif; ?>
      <div class="champ">
        <label>Quoi</label>
        <input type="text" name="texte" placeholder="Ex. Prendre rdv chez le dentiste" required value="<?= isset($_POST['texte']) ? htmlspecialchars($_POST['texte']) : '' ?>">
      </div>
      <div class="champ-ligne">
        <div class="champ">
          <label>Concerne (facultatif)</label>
          <select name="person_id">
            <option value="">— Personne —</option>
            <?php foreach ($patients as $patient): ?>
              <option value="<?= (int) $patient['id'] ?>"<?= (isset($_POST['person_id']) && (int) $_POST['person_id'] === (int) $patient['id']) ? ' selected' : '' ?>><?= htmlspecialchars($patient['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ">
          <label>Pour quand (facultatif)</label>
          <input type="date" name="date_cible" value="<?= isset($_POST['date_cible']) ? htmlspecialchars($_POST['date_cible']) : '' ?>">
        </div>
      </div>
      <div class="form-boutons">
        <button class="principal" type="submit"><?= $tacheEnEdition !== null ? 'Enregistrer les modifications' : 'Ajouter' ?></button>
        <?php if ($tacheEnEdition !== null): ?>
          <a class="secondaire" href="/taches.php">Annuler</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="outil" style="margin-top:16px;">
    <h2 class="panneau-titre">À faire<?= count($tachesOuvertes) > 0 ? ' (' . count($tachesOuvertes) . ')' : '' ?></h2>

    <?php if (empty($tachesOuvertes)): ?>
      <p class="vide">Aucune tâche en attente.</p>
    <?php else: ?>
      <?php foreach ($tachesOuvertes as $t): ?>
        <?php $enRetard = $t['date_cible'] !== null && $t['date_cible'] < $aujourdhui; ?>
        <div class="rangee-tache">
          <form method="post" class="form-case-tache">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="fait" value="1">
            <button type="submit" class="case-tache" aria-label="Marquer comme faite" title="Marquer comme faite">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>
            </button>
          </form>
          <div class="detail-tache">
            <div class="texte-tache"><?= htmlspecialchars($t['texte']) ?></div>
            <?php if ($t['personne'] !== '' || $t['date_cible'] !== null): ?>
              <div class="meta-tache">
                <?php if ($t['personne'] !== ''): ?>
                  <span class="badge-personne <?= classePersonneTache($t['person_id'], $patients) ?>"><?= htmlspecialchars(nomPersonneTache($db, $t)) ?></span>
                <?php endif; ?>
                <?php if ($t['date_cible'] !== null): ?>
                  <span class="badge-date-tache<?= $enRetard ? ' en-retard' : '' ?>"><?= $enRetard ? 'En retard — ' : 'Pour le ' ?><?= htmlspecialchars(date('d/m/Y', strtotime($t['date_cible']))) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <a href="?modifier=<?= (int) $t['id'] ?>#formulaireTache" class="lien-modifier-tache">Modifier</a>
          <form method="post" data-confirm="Supprimer cette tâche ?">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="lien-danger">Supprimer</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if (!empty($tachesTerminees)): ?>
    <details class="outil" style="margin-top:16px;">
      <summary class="panneau-titre" style="cursor:pointer;">Terminées (<?= count($tachesTerminees) ?>)</summary>
      <?php foreach ($tachesTerminees as $t): ?>
        <div class="rangee-tache rangee-tache-faite">
          <form method="post" class="form-case-tache">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="fait" value="0">
            <button type="submit" class="case-tache case-tache-cochee" aria-label="Marquer à faire" title="Marquer à faire">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg>
            </button>
          </form>
          <div class="detail-tache">
            <div class="texte-tache"><?= htmlspecialchars($t['texte']) ?></div>
            <?php if ($t['personne'] !== ''): ?>
              <div class="meta-tache">
                <span class="badge-personne <?= classePersonneTache($t['person_id'], $patients) ?>"><?= htmlspecialchars(nomPersonneTache($db, $t)) ?></span>
              </div>
            <?php endif; ?>
          </div>
          <a href="?modifier=<?= (int) $t['id'] ?>#formulaireTache" class="lien-modifier-tache">Modifier</a>
          <form method="post" data-confirm="Supprimer cette tâche ?">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button type="submit" class="lien-danger">Supprimer</button>
          </form>
        </div>
      <?php endforeach; ?>
    </details>
  <?php endif; ?>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/assets/admin-ui.js') ?>"></script>
  <script src="/assets/entete.js?v=<?= filemtime(__DIR__ . '/assets/entete.js') ?>"></script>
</body>
</html>
