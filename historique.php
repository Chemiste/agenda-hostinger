<?php
/**
 * Historique des rendez-vous, cote famille : qui a ajoute, modifie ou
 * supprime un rendez-vous, et quand. Ne montre PAS les connexions (voir
 * admin/historique.php pour la vue complete, reservee a l'administration).
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/activity_log.php';

$db = getDb();
$entrees = listerActivite($db, 150, ['ajout', 'modification', 'suppression']);

$labelsAction = ['ajout' => 'Ajout', 'modification' => 'Modification', 'suppression' => 'Suppression'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Journal d'activité — Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
  <div class="barre-admin">
    <h1>Journal d'activité</h1>
    <div>
      <span class="qui-connecte"><?= htmlspecialchars(personneSessionActuelle()) ?></span>
      <a href="/index.php">Retour à l'agenda</a>
    </div>
  </div>
  <p class="sous-titre" style="margin-bottom:18px;">Qui a ajouté, modifié ou supprimé un rendez-vous, et quand — les <?= count($entrees) ?> dernières actions.</p>

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
</body>
</html>
