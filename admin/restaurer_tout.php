<?php
/**
 * Reconstruction de la base a partir d'une sauvegarde complete.
 *
 * A NE PAS CONFONDRE avec admin/sauvegardes.php, qui repeche un rendez-vous
 * efface par erreur sans toucher au reste. Ici on REMPLACE des tables
 * entieres par leur etat a une date donnee : c'est le geste d'apres
 * sinistre, pas un geste courant.
 *
 * POURQUOI CETTE PAGE EXISTE. Le cron sauvegardait cinq tables que rien ne
 * savait remettre en place, et en oubliait cinq autres - dont medecins, le
 * carnet d'adresses saisi a la main pendant des mois. En cas de perte, il
 * aurait fallu fabriquer le SQL a partir des fichiers JSON. La sauvegarde
 * inspirait donc plus de confiance qu'elle n'en meritait.
 *
 * TROIS GARDE-FOUS, parce que c'est destructif :
 *   1. une sauvegarde de securite est prise AVANT d'ecraser quoi que ce
 *      soit - se tromper de date reste rattrapable
 *   2. la page montre d'abord ce que contient chaque fichier face a ce que
 *      contient la base, pour qu'on voie ce qu'on s'apprete a perdre
 *   3. il faut cocher chaque table ET taper un mot de confirmation
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/entete_admin.php';
require_once __DIR__ . '/../lib/sauvegardes.php';

const MOT_DE_CONFIRMATION = 'REMPLACER';

$db = getDb();
$dossierBackups = __DIR__ . '/../backups';
$erreur = '';
$resultats = [];
$sauvegardeSecurite = '';

/**
 * Les sauvegardes disponibles, groupees par horodatage.
 * "appointments-2026-08-16-0300.json" -> jeu "2026-08-16-0300".
 */
function jeuxDeSauvegarde($dossier) {
    $jeux = [];
    foreach (tablesSauvegardees() as $prefixe => $table) {
        foreach (glob($dossier . '/' . $prefixe . '-*.json') as $chemin) {
            $nom = basename($chemin, '.json');
            $horodatage = substr($nom, strlen($prefixe) + 1);
            $jeux[$horodatage][$prefixe] = $chemin;
        }
    }
    krsort($jeux);
    return $jeux;
}

$jeux = jeuxDeSauvegarde($dossierBackups);
$jeuChoisi = isset($_REQUEST['jeu']) && isset($jeux[$_REQUEST['jeu']]) ? $_REQUEST['jeu'] : '';

// --- Restauration effective ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restaurer' && $jeuChoisi !== '') {
    $tablesChoisies = isset($_POST['tables']) ? (array) $_POST['tables'] : [];
    $motTape = isset($_POST['confirmation']) ? trim((string) $_POST['confirmation']) : '';

    if (empty($tablesChoisies)) {
        $erreur = 'Aucune table sélectionnée.';
    } elseif ($motTape !== MOT_DE_CONFIRMATION) {
        $erreur = 'Le mot de confirmation ne correspond pas. Rien n\'a été modifié.';
    } else {
        try {
            // Garde-fou n°1, avant toute écriture.
            $secu = ecrireSauvegarde($db, $dossierBackups, '-avant-restauration');
            $sauvegardeSecurite = $secu['horodatage'];

            // Dans l'ordre de tablesSauvegardees() (persons en premier),
            // et non dans celui où les cases ont été cochées.
            foreach (tablesSauvegardees() as $prefixe => $table) {
                if (!in_array($prefixe, $tablesChoisies, true)) continue;
                if (!isset($jeux[$jeuChoisi][$prefixe])) {
                    $resultats[$prefixe] = 'aucun fichier dans ce jeu';
                    continue;
                }
                $lignes = json_decode(file_get_contents($jeux[$jeuChoisi][$prefixe]), true);
                if (!is_array($lignes)) {
                    $resultats[$prefixe] = 'fichier illisible, table laissée intacte';
                    continue;
                }
                try {
                    $n = restaurerTableDepuisJson($db, $table, $lignes);
                    $resultats[$prefixe] = $n . ' ligne(s) restaurée(s)';
                } catch (Exception $e) {
                    $resultats[$prefixe] = 'échec : ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $erreur = 'Sauvegarde de sécurité impossible, restauration annulée : ' . $e->getMessage();
        }
    }
}

// --- Comparaison fichier / base, pour le jeu choisi ---
$comparaisons = [];
if ($jeuChoisi !== '') {
    foreach (tablesSauvegardees() as $prefixe => $table) {
        $ligne = ['fichier' => null, 'base' => null, 'notes' => []];
        try {
            $ligne['base'] = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        } catch (Exception $e) {
            $ligne['notes'][] = "table absente de la base";
        }
        if (isset($jeux[$jeuChoisi][$prefixe])) {
            $contenu = json_decode(file_get_contents($jeux[$jeuChoisi][$prefixe]), true);
            if (is_array($contenu)) {
                $ligne['fichier'] = count($contenu);
                if ($ligne['base'] !== null) {
                    $c = comparerSauvegardeAuSchema($db, $table, $contenu);
                    if (!empty($c['absentes_du_fichier'])) {
                        $ligne['notes'][] = 'valeur par défaut pour : ' . implode(', ', $c['absentes_du_fichier']);
                    }
                    if (!empty($c['disparues'])) {
                        $ligne['notes'][] = 'ignoré (colonnes disparues) : ' . implode(', ', $c['disparues']);
                    }
                }
            } else {
                $ligne['notes'][] = 'fichier illisible';
            }
        }
        $comparaisons[$prefixe] = $ligne;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reconstruire la base — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
<?php afficherEnteteAdmin('Reconstruire la base', "Remet des tables entières dans l'état d'une sauvegarde. À n'utiliser qu'après une perte de données — pour repêcher un rendez-vous effacé par erreur, passez par Sauvegardes."); ?>

  <div class="outil">
    <?php if ($erreur !== ''): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <?php if (!empty($resultats)): ?>
      <h2 class="panneau-titre">Restauration terminée</h2>
      <?php if ($sauvegardeSecurite !== ''): ?>
        <p class="sous-titre">
          Une sauvegarde de l'état précédent a été prise juste avant, sous
          l'horodatage <code><?= htmlspecialchars($sauvegardeSecurite) ?></code> :
          si vous vous êtes trompé de date, vous pouvez revenir en arrière en
          la restaurant à son tour.
        </p>
      <?php endif; ?>
      <ul class="liste-resultats">
        <?php foreach ($resultats as $prefixe => $texte): ?>
          <li><strong><?= htmlspecialchars($prefixe) ?></strong> — <?= htmlspecialchars($texte) ?></li>
        <?php endforeach; ?>
      </ul>
      <p><a class="bouton-compact" href="/admin/restaurer_tout.php">Revenir</a></p>

    <?php elseif (empty($jeux)): ?>
      <p class="vide">Aucune sauvegarde dans <code>backups/</code>.</p>

    <?php elseif ($jeuChoisi === ''): ?>
      <h2 class="panneau-titre">Choisir une sauvegarde</h2>
      <p class="sous-titre">
        Chaque ligne est un jeu complet, écrit en une fois par la sauvegarde
        automatique. Les jeux suffixés <code>-avant-restauration</code> ont été
        pris juste avant une reconstruction précédente.
      </p>
      <ul class="liste-jeux">
        <?php foreach ($jeux as $horodatage => $fichiers): ?>
          <li>
            <a href="?jeu=<?= urlencode($horodatage) ?>"><?= htmlspecialchars($horodatage) ?></a>
            <span class="detail-jeu"><?= count($fichiers) ?> table(s)</span>
          </li>
        <?php endforeach; ?>
      </ul>

    <?php else: ?>
      <h2 class="panneau-titre">Sauvegarde du <?= htmlspecialchars($jeuChoisi) ?></h2>
      <p class="sous-titre">
        Comparez avant de cocher : la colonne « en base » disparaîtra,
        remplacée par la colonne « dans le fichier ».
      </p>

      <form method="post">
        <input type="hidden" name="action" value="restaurer">
        <input type="hidden" name="jeu" value="<?= htmlspecialchars($jeuChoisi) ?>">

        <table class="tableau-personnes">
          <thead>
            <tr>
              <th scope="col">Restaurer</th>
              <th scope="col">Table</th>
              <th scope="col" class="col-droit">En base</th>
              <th scope="col" class="col-droit">Dans le fichier</th>
              <th scope="col">Remarques</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($comparaisons as $prefixe => $c): ?>
            <tr>
              <td>
                <?php if ($c['fichier'] !== null && $c['base'] !== null): ?>
                  <input type="checkbox" name="tables[]" value="<?= htmlspecialchars($prefixe) ?>" id="t-<?= htmlspecialchars($prefixe) ?>">
                <?php endif; ?>
              </td>
              <td><label for="t-<?= htmlspecialchars($prefixe) ?>"><?= htmlspecialchars($prefixe) ?></label></td>
              <td class="col-droit"><?= $c['base'] === null ? '—' : (int) $c['base'] ?></td>
              <td class="col-droit"><?= $c['fichier'] === null ? 'absent' : (int) $c['fichier'] ?></td>
              <td class="donnees-personne"><?= htmlspecialchars(implode(' · ', $c['notes'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <div class="zone-confirmation">
          <p>
            Les tables cochées seront <strong>entièrement remplacées</strong>.
            Une sauvegarde de l'état actuel sera prise automatiquement avant.
            Pour confirmer, tapez <code><?= MOT_DE_CONFIRMATION ?></code> :
          </p>
          <input type="text" name="confirmation" placeholder="<?= MOT_DE_CONFIRMATION ?>" autocomplete="off" required>
          <button type="submit" class="lien-danger bouton-confirmer-restauration">Remplacer les tables cochées</button>
          <a class="bouton-compact" href="/admin/restaurer_tout.php">Annuler</a>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
