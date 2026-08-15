<?php
/**
 * ADMINISTRATION : les personnes (table "persons", voir
 * migrations/0021_ajouter_persons.sql et lib/persons.php).
 *
 * Remplace les clés "personne_1", "personne_2" et "membres_famille" de
 * config.php : ajouter quelqu'un ou le renommer se fait ici, sans toucher
 * au code ni redéployer.
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

$config = require __DIR__ . '/../config.php';
$db = getDb();
$erreur = '';
$idEnEdition = null;
$resumeReprise = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'reprendre_config') {
            // Pas de redirection ici : on veut afficher ce qui a change.
            $resumeReprise = reprendrePersonnesDeConfig($db, $config);
        } elseif ($action === 'ajouter') {
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
        if ($resumeReprise === null) {
            // Post/Redirect/Get : recharger la page ne rejoue pas l'action.
            header('Location: /admin/personnes.php');
            exit;
        }
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

  <?php if ($resumeReprise !== null): ?>
    <?php if (empty($resumeReprise['crees']) && empty($resumeReprise['modifies'])): ?>
      <p class="info">Rien à reprendre : tout le monde est déjà là avec les bons droits.</p>
    <?php else: ?>
      <p class="info">
        <?php if (!empty($resumeReprise['crees'])): ?>
          Ajouté<?= count($resumeReprise['crees']) > 1 ? 's' : '' ?> : <?= htmlspecialchars(implode(', ', $resumeReprise['crees'])) ?>.
        <?php endif; ?>
        <?php if (!empty($resumeReprise['modifies'])): ?>
          Droits complétés : <?= htmlspecialchars(implode(', ', $resumeReprise['modifies'])) ?>.
        <?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>

  <div class="outil">
    <h2 class="panneau-titre">Les personnes (<?= $nombre ?>)</h2>

    <?php if (empty($personnes)): ?>
      <p class="vide">Aucune personne enregistrée. Ajoute au moins toi-même, plus bas.</p>
    <?php else: ?>
      <ul class="liste-personnes">
        <?php foreach ($personnes as $p): ?>
          <?php
            $index++;
            $enEdition = $idEnEdition === $p['id'];
            $donnees = $donneesParPersonne[$p['id']];
          ?>
          <li class="rangee-personne<?= $p['actif'] ? '' : ' inactive' ?>">
            <?php if ($enEdition): ?>
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
            <?php else: ?>
              <div class="identite-personne">
                <span class="nom-personne"><?= htmlspecialchars($p['nom']) ?></span>
                <?php if (!$p['actif']): ?>
                  <span class="etiquette-personne etiquette-inactive">Désactivée</span>
                <?php endif; ?>
                <?php if ($p['est_patient']): ?>
                  <span class="etiquette-personne">Patient</span>
                <?php endif; ?>
                <?php if ($p['peut_se_connecter']): ?>
                  <span class="etiquette-personne">Se connecte</span>
                <?php endif; ?>
              </div>

              <span class="donnees-personne">
                <?php if (empty($donnees)): ?>
                  aucune donnée
                <?php else: ?>
                  <?php
                    $morceaux = [];
                    foreach ($donnees as $libelle => $n) { $morceaux[] = $n . ' ' . $libelle; }
                    echo htmlspecialchars(implode(' · ', $morceaux));
                  ?>
                <?php endif; ?>
              </span>

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
                    <button type="submit" class="lien-modifier-tache" style="border:none;background:none;font:inherit;font-size:13px;font-weight:600;padding:0;cursor:pointer;">Réactiver</button>
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
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" class="form-personne form-ajouter-personne">
      <input type="hidden" name="action" value="ajouter">
      <input type="text" name="nom" placeholder="Nouvelle personne" aria-label="Nom de la nouvelle personne" required>
      <label class="case-drapeau"><input type="checkbox" name="est_patient" value="1"> Patient</label>
      <label class="case-drapeau"><input type="checkbox" name="peut_se_connecter" value="1" checked> Se connecte</label>
      <button type="submit" class="principal">Ajouter</button>
    </form>
  </div>

  <?php /* La migration deduit les personnes des donnees reelles, mais elle
           lit activity_log pour reperer les membres de la famille - or ce
           journal dit qui S'EST connecte, pas qui PEUT se connecter.
           Quelqu'un qui n'a jamais ouvert le site n'y figure pas. Et un
           fichier .sql ne peut pas lire config.php, ou vit la vraie liste.
           D'ou cette reprise manuelle, relancable sans risque. */ ?>
  <div class="outil" style="margin-top:16px;">
    <h2 class="panneau-titre">Reprendre depuis config.php</h2>
    <p class="aide">
      Ajoute les personnes déclarées dans <code>config.php</code>
      (<code>membres_famille</code>, <code>personne_1</code>, <code>personne_2</code>)
      qui manqueraient ici, et complète leurs droits. Utile juste après la migration :
      celle-ci ne peut deviner qu'une personne <em>peut</em> se connecter que si elle
      s'est déjà connectée au moins une fois.
      N'enlève jamais de droit — si tu as décoché une case exprès, elle le reste.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="reprendre_config">
      <button type="submit" class="secondaire">Reprendre depuis config.php</button>
    </form>
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
