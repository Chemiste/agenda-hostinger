<?php
/**
 * ADMINISTRATION : les personnes (table "persons", voir
 * migrations/0021_ajouter_persons.sql et lib/persons.php).
 *
 * Seul endroit où les personnes existent : config.php n'en contient plus
 * aucune. Ajouter quelqu'un ou le renommer se fait ici, sans toucher au
 * code ni redéployer.
 *
 * Cette page est volontairement accessible AVANT de s'être identifié :
 * requireAdminLogin() ne demande que les deux mots de passe, pas l'écran
 * « Qui êtes-vous ? ». C'est ce qui permet d'amorcer un site neuf, où
 * cet écran n'aurait encore personne à proposer.
 *
 * Deux drapeaux par personne :
 *   Patient       — on suit sa santé : onglets de l'agenda, plan de
 *                   médicaments, pathologies, carnet de médecins.
 *   Se connecte   — elle apparaît dans "Qui est-ce ?" à l'ouverture du
 *                   site.
 * Michel et Christiane ont les deux, Hélène et Laurent seulement le
 * second.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/persons.php';
require_once __DIR__ . '/../lib/entete_admin.php';

$db = getDb();
$erreur = '';
$idEnEdition = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'ajouter') {
            ajouterPerson(
                $db,
                isset($_POST['nom']) ? $_POST['nom'] : '',
                !empty($_POST['est_patient']),
                !empty($_POST['peut_se_connecter'])
            );
        } elseif ($action === 'modifier' && isset($_POST['id'])) {
            modifierPerson(
                $db,
                $_POST['id'],
                isset($_POST['nom']) ? $_POST['nom'] : '',
                !empty($_POST['est_patient']),
                !empty($_POST['peut_se_connecter'])
            );
        } elseif ($action === 'deplacer' && isset($_POST['id'], $_POST['direction'])) {
            deplacerPerson($db, $_POST['id'], $_POST['direction']);
        } elseif ($action === 'desactiver' && isset($_POST['id'])) {
            desactiverPerson($db, $_POST['id']);
        } elseif ($action === 'reactiver' && isset($_POST['id'])) {
            reactiverPerson($db, $_POST['id']);
        } elseif ($action === 'supprimer' && isset($_POST['id'])) {
            supprimerPerson($db, $_POST['id']);
        }
        // Post/Redirect/Get : recharger la page ne rejoue pas l'action.
        header('Location: /admin/personnes.php');
        exit;
    } catch (Exception $e) {
        $erreur = $e->getMessage();
        if ($action === 'modifier' && isset($_POST['id'])) {
            $idEnEdition = (int) $_POST['id'];
        }
    }
}

if ($idEnEdition === null && isset($_GET['modifier'])) {
    $idEnEdition = (int) $_GET['modifier'];
}

$personnes = listerPersons($db);
$donneesParPersonne = [];
foreach ($personnes as $p) {
    $donneesParPersonne[$p['id']] = compterDonneesPerson($db, $p['id']);
}
$nombre = count($personnes);
$index = 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Personnes — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
  <?php afficherEnteteAdmin(
      'Personnes',
      'Qui apparaît dans le site, et à quel titre. <strong>Patient</strong> : on suit sa santé — '
      . 'onglets de l\'agenda, médicaments, pathologies, médecins. <strong>Se connecte</strong> : '
      . 'elle apparaît dans « Qui est-ce ? » à l\'ouverture. Renommer quelqu\'un ici est sans danger, '
      . 'ses données suivent.'
  ); ?>

  <?php if ($erreur): ?>
    <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
  <?php endif; ?>

  <div class="outil">
    <h2 class="panneau-titre">Les personnes (<?= $nombre ?>)</h2>

    <?php if (empty($personnes)): ?>
      <p class="vide">Aucune personne enregistrée. Ajoute au moins toi-même, plus bas.</p>
    <?php else: ?>
      <?php /* Un vrai tableau avec des en-tetes : les deux droits se lisent
               en colonne, on compare d'un coup d'oeil qui est patient et qui
               se connecte. Un « oui » ou « non » ecrit en toutes lettres a
               cote du symbole - la coche seule reposerait sur la forme, et
               l'absence de coche se confond avec une case vide. */ ?>
      <table class="tableau-personnes">
        <thead>
          <tr>
            <th scope="col">Personne</th>
            <th scope="col" class="col-droit">Patient</th>
            <th scope="col" class="col-droit">Se connecte</th>
            <th scope="col">Données rattachées</th>
            <th scope="col" class="col-ordre">Ordre</th>
            <th scope="col" class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($personnes as $p): ?>
          <?php
            $index++;
            $enEdition = $idEnEdition === $p['id'];
            $donnees = $donneesParPersonne[$p['id']];
          ?>
          <?php if ($enEdition): ?>
            <tr class="ligne-edition-personne">
              <td colspan="6">
                <form method="post" class="form-personne">
                  <input type="hidden" name="action" value="modifier">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <input type="text" name="nom" value="<?= htmlspecialchars($p['nom']) ?>" aria-label="Nom" required autofocus>
                  <label class="case-drapeau">
                    <input type="checkbox" name="est_patient" value="1"<?= $p['est_patient'] ? ' checked' : '' ?>> Patient
                  </label>
                  <label class="case-drapeau">
                    <input type="checkbox" name="peut_se_connecter" value="1"<?= $p['peut_se_connecter'] ? ' checked' : '' ?>> Se connecte
                  </label>
                  <button type="submit" class="principal">Enregistrer</button>
                  <a class="secondaire" href="/admin/personnes.php">Annuler</a>
                </form>
              </td>
            </tr>
          <?php else: ?>
            <tr class="<?= $p['actif'] ? '' : 'personne-inactive' ?>">
              <td>
                <span class="nom-personne"><?= htmlspecialchars($p['nom']) ?></span>
                <?php if (!$p['actif']): ?>
                  <span class="etiquette-personne etiquette-inactive">Désactivée</span>
                <?php endif; ?>
              </td>
              <td class="col-droit">
                <span class="marque-droit <?= $p['est_patient'] ? 'oui' : 'non' ?>"><?= $p['est_patient'] ? '✔ oui' : '✕ non' ?></span>
              </td>
              <td class="col-droit">
                <span class="marque-droit <?= $p['peut_se_connecter'] ? 'oui' : 'non' ?>"><?= $p['peut_se_connecter'] ? '✔ oui' : '✕ non' ?></span>
              </td>
              <td class="donnees-personne">
                <?php if (empty($donnees)): ?>
                  aucune donnée
                <?php else: ?>
                  <?php
                    $morceaux = [];
                    foreach ($donnees as $libelle => $n) { $morceaux[] = $n . ' ' . $libelle; }
                    echo htmlspecialchars(implode(' · ', $morceaux));
                  ?>
                <?php endif; ?>
              </td>
              <td class="col-ordre">
                <div class="boutons-deplacer-section">
                  <form method="post">
                    <input type="hidden" name="action" value="deplacer">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="direction" value="haut">
                    <button type="submit" class="bouton-deplacer" title="Monter" <?= $index === 1 ? 'disabled' : '' ?>>↑</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="deplacer">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="direction" value="bas">
                    <button type="submit" class="bouton-deplacer" title="Descendre" <?= $index === $nombre ? 'disabled' : '' ?>>↓</button>
                  </form>
                </div>
              </td>
              <td class="col-actions">
                <div class="actions-personne">
                  <a href="?modifier=<?= $p['id'] ?>" class="lien-modifier-tache">Modifier</a>
                  <?php if ($p['actif']): ?>
                    <?php /* Desactiver plutot que supprimer : les rendez-vous
                             passes et le journal d'activite gardent un nom
                             lisible. Supprimer la ligne les rendrait
                             orphelins - exactement ce que cette table est
                             venue eviter. */ ?>
                    <form method="post" data-confirm="Désactiver <?= htmlspecialchars($p['nom']) ?> ? Elle disparaît des listes et des onglets, mais son historique reste lisible.">
                      <input type="hidden" name="action" value="desactiver">
                      <input type="hidden" name="id" value="<?= $p['id'] ?>">
                      <button type="submit" class="lien-danger">Désactiver</button>
                    </form>
                  <?php else: ?>
                    <form method="post">
                      <input type="hidden" name="action" value="reactiver">
                      <input type="hidden" name="id" value="<?= $p['id'] ?>">
                      <button type="submit" class="lien-bouton">Réactiver</button>
                    </form>
                    <?php if (empty($donnees)): ?>
                      <form method="post" data-confirm="Supprimer définitivement <?= htmlspecialchars($p['nom']) ?> ? Aucune donnée n'y est rattachée.">
                        <input type="hidden" name="action" value="supprimer">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button type="submit" class="lien-danger">Supprimer</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post" class="form-personne form-ajouter-personne">
      <input type="hidden" name="action" value="ajouter">
      <input type="text" name="nom" placeholder="Nouvelle personne" aria-label="Nom de la nouvelle personne" required>
      <label class="case-drapeau"><input type="checkbox" name="est_patient" value="1"> Patient</label>
      <label class="case-drapeau"><input type="checkbox" name="peut_se_connecter" value="1" checked> Se connecte</label>
      <button type="submit" class="principal">Ajouter</button>
    </form>
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
