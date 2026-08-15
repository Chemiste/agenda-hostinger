<?php
/**
 * Fiche imprimable "Pathologies de ..." pour une personne, generee a
 * partir des donnees saisies dans pathologies.php - a emmener a un
 * rendez-vous ou montrer a un nouveau medecin. Meme principe que
 * medicaments.php : impression navigateur (bouton "Imprimer" -> boite de
 * dialogue -> "Enregistrer en PDF"), pas de generation PDF cote serveur.
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/pathologies.php';
require_once __DIR__ . '/lib/persons.php';

$db = getDb();
$patients = listerPatients($db);

// "?person=" porte desormais un identifiant. Un identifiant inconnu
// retombe sur le premier patient plutot que d'afficher une fiche vide.
$personCibleId = (int) (isset($_GET['person']) ? $_GET['person'] : 0);
if (!isset($patients[$personCibleId])) {
    $personCibleId = !empty($patients) ? (int) key($patients) : 0;
}
$personneCible = isset($patients[$personCibleId]) ? $patients[$personCibleId]['nom'] : 'Personne';

$pathologies = listerPathologies($db, $personCibleId);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pathologies — <?= htmlspecialchars($personneCible) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<style>
  body { max-width: 820px; }
  .barre-actions-plan { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin:18px 0 20px; }
  .barre-actions-plan .liens-secondaires { font-size:13px; color:var(--text-muted); display:flex; gap:14px; }
  .barre-actions-plan .liens-secondaires a { color:var(--text-muted); }
  .btn-imprimer-plan { display:inline-flex; align-items:center; gap:8px; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-md); padding:12px 20px; font-size:15px; font-weight:600; cursor:pointer; box-shadow:var(--shadow-sm); }
  .btn-imprimer-plan:hover { background:var(--accent-hover); }

  .entete-plan { text-align:center; margin-bottom:22px; }
  .entete-plan h1 { font-size:21px; margin:0; }

  .carte-pathologie { background:var(--surface); border:1.5px solid var(--border); border-radius:14px; padding:16px 18px; margin-bottom:14px; }
  .nom-pathologie { font-size:16px; font-weight:700; color:var(--text); margin-bottom:8px; }
  .bloc-pathologie { margin-top:8px; }
  .bloc-pathologie .etiquette { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; color:var(--text-muted); margin-bottom:3px; }
  .bloc-pathologie .texte { font-size:14px; color:var(--text-secondary); line-height:1.45; white-space:pre-line; }
  .liste-rdv-lies { margin:0; padding-left:18px; font-size:14px; color:var(--text-secondary); line-height:1.45; }
  .liste-rdv-lies li { margin:1px 0; }

  @media print {
    @page { margin: 0.9cm; }
    body { max-width:100%; padding:0; background:#fff; }
    .barre-actions-plan { display:none !important; }
    .entete-plan { margin-bottom:12px; }
    .entete-plan h1 { font-size:18px; }
    .carte-pathologie { padding:10px 12px; margin-bottom:9px; break-inside:avoid; page-break-inside:avoid; }
    .nom-pathologie { font-size:13px; margin-bottom:5px; }
    .bloc-pathologie { margin-top:5px; }
    .bloc-pathologie .etiquette { font-size:9px; }
    .bloc-pathologie .texte { font-size:11px; }
    .liste-rdv-lies { font-size:11px; padding-left:15px; }
  }
</style>
</head>
<body>
  <div class="barre-actions-plan">
    <button type="button" class="btn-imprimer-plan" onclick="window.print()">
      <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M6 17v4h12v-4"/></svg>
      Imprimer / Enregistrer en PDF
    </button>
    <div class="liens-secondaires">
      <?php foreach ($patients as $autre): ?>
        <?php if ((int) $autre['id'] !== $personCibleId): ?>
          <a href="/pathologies_plan.php?person=<?= (int) $autre['id'] ?>">Voir la fiche de <?= htmlspecialchars($autre['nom']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <a href="/pathologies.php">Modifier la liste</a>
      <a href="/index.php">Retour à l'agenda</a>
    </div>
  </div>

  <div class="entete-plan">
    <h1>Pathologies de <?= htmlspecialchars($personneCible) ?></h1>
  </div>

  <?php if (empty($pathologies)): ?>
    <p class="vide">Aucune pathologie enregistrée pour <?= htmlspecialchars($personneCible) ?>. <a href="/pathologies.php">Ajouter une pathologie</a>.</p>
  <?php else: ?>
    <?php foreach ($pathologies as $path): ?>
      <div class="carte-pathologie">
        <div class="nom-pathologie"><?= htmlspecialchars($path['nom']) ?></div>
        <?php if ($path['cause'] !== ''): ?>
          <div class="bloc-pathologie">
            <div class="etiquette">Cause / raison</div>
            <div class="texte"><?= htmlspecialchars($path['cause']) ?></div>
          </div>
        <?php endif; ?>
        <?php if ($path['traitement'] !== ''): ?>
          <div class="bloc-pathologie">
            <div class="etiquette">Ce qui est fait pour soigner</div>
            <div class="texte"><?= htmlspecialchars($path['traitement']) ?></div>
          </div>
        <?php endif; ?>
        <?php $rdvsLies = listerRendezVousPathologie($db, $path['id']); ?>
        <?php if (!empty($rdvsLies)): ?>
          <div class="bloc-pathologie">
            <div class="etiquette">Rendez-vous à venir</div>
            <ul class="liste-rdv-lies">
              <?php foreach ($rdvsLies as $rdvLie): ?>
                <li><?= htmlspecialchars(libelleRendezVousPathologie($rdvLie)) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</body>
</html>
