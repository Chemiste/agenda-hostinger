<?php
/**
 * ADMINISTRATION : export complet des rendez-vous.
 *
 * Génère un fichier JSON contenant tous les rendez-vous de la base
 * actuelle (même format que les sauvegardes automatiques de cron/backup.php)
 * et le propose au téléchargement immédiatement, plutôt que d'attendre la
 * prochaine sauvegarde planifiée.
 *
 * Usage typique : lancé en production, le fichier téléchargé est ensuite
 * importé en local via outils/importer_donnees_dev.php, pour tester avec
 * des données réalistes plutôt qu'avec une base de dev vide.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';

// Le telechargement (POST) doit se faire avant tout affichage HTML, pour
// pouvoir envoyer les en-tetes de fichier proprement.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['exporter'])) {
    $db = getDb();
    $lignes = $db->query('SELECT * FROM appointments ORDER BY id')->fetchAll();
    $json = json_encode($lignes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $nomFichier = 'agenda-export-' . date('Y-m-d-Hi') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

$db = getDb();
$nbRdv = (int) $db->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Exporter les données — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
  <div class="barre-admin">
    <div>
      <a href="/index.php">Retour à l'agenda</a>
      &nbsp;·&nbsp;
      <a href="/admin/logout.php">Déconnexion admin</a>
    </div>
  </div>
  <div class="fil-admin">
    <a href="/admin/index.php">Administration</a><span class="sep">/</span><span class="actuel">Exporter les données</span>
  </div>

  <div class="outil">
    <h2 style="margin-top:0;">Exporter les données</h2>
    <p class="sous-titre">
      Télécharge un instantané complet des <?= $nbRdv ?> rendez-vous actuellement en base, au format JSON.
      À utiliser pour ramener des données réalistes dans une base de développement locale via
      <code>outils/importer_donnees_dev.php</code> — jamais pour éditer les rendez-vous eux-mêmes.
    </p>
    <form method="post">
      <input type="hidden" name="exporter" value="1">
      <button class="principal" type="submit">Télécharger l'export (<?= $nbRdv ?> rendez-vous)</button>
    </form>
  </div>

  <p style="margin-top:2rem;"><a href="/index.php">Retour à l'agenda</a></p>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
