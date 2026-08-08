<?php
/**
 * ADMINISTRATION : journal d'activite complet.
 *
 * Comme historique.php (cote famille), mais inclut aussi les connexions
 * (qui s'est connecte au site et quand) - reserve a l'administration,
 * une information que la famille n'a pas besoin de voir au quotidien.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/activity_log.php';

$db = getDb();
$entrees = listerActivite($db, 300);

$labelsAction = [
    'connexion' => 'Connexion',
    'ajout' => 'Ajout',
    'modification' => 'Modification',
    'suppression' => 'Suppression',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Journal d'activité — Administration</title>
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
    <a href="/admin/index.php">Administration</a><span class="sep">/</span><span class="actuel">Journal d'activité</span>
  </div>

  <div class="outil">
    <h2 class="panneau-titre">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 3"/></svg>
      Journal d'activité
    </h2>
    <p class="sous-titre">Qui s'est connecté, et qui a ajouté/modifié/supprimé un rendez-vous — les <?= count($entrees) ?> dernières actions.</p>

    <?php if (empty($entrees)): ?>
      <p class="vide">Aucune activité enregistrée pour l'instant.</p>
    <?php else: ?>
      <?php foreach ($entrees as $e): ?>
        <div class="ligne-historique">
          <span class="badge-action badge-<?= htmlspecialchars($e['type_action']) ?>"><?= htmlspecialchars(isset($labelsAction[$e['type_action']]) ? $labelsAction[$e['type_action']] : $e['type_action']) ?></span>
          <div class="detail-historique">
            <div class="quand-historique"><?= htmlspecialchars(date('d/m/Y \à H:i', strtotime($e['created_at']))) ?></div>
            <?php if ($e['resume'] !== ''): ?>
              <div class="resume-historique"><?= htmlspecialchars($e['resume']) ?></div>
            <?php endif; ?>
          </div>
          <span class="qui-historique"><?= htmlspecialchars($e['personne']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</body>
</html>
