<?php
/**
 * Fiche imprimable "Traitement de ... — Plan de prise quotidien",
 * generee a partir des donnees saisies dans medicaments.php. Pense pour
 * etre imprimee ou enregistree en PDF depuis le navigateur (bouton
 * "Imprimer" -> boite de dialogue d'impression -> "Enregistrer en PDF"),
 * comme le reste du site (voir index.php, boutons Imprimer/Imprimer
 * compact) plutot qu'une generation PDF cote serveur.
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/medicaments.php';

$config = require __DIR__ . '/config.php';
$personneCible = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';

$db = getDb();
$medicaments = listerMedicaments($db, $personneCible);

$groupes = [];
foreach ($medicaments as $m) {
    $groupes[$m['moment']][] = $m;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plan de prise des médicaments — <?= htmlspecialchars($personneCible) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<style>
  body { max-width: 900px; }
  .barre-actions-plan { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin:18px 0 20px; }
  .barre-actions-plan .liens-secondaires { font-size:13px; color:var(--text-muted); display:flex; gap:14px; }
  .barre-actions-plan .liens-secondaires a { color:var(--text-muted); }
  .btn-imprimer-plan { display:inline-flex; align-items:center; gap:8px; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-md); padding:12px 20px; font-size:15px; font-weight:600; cursor:pointer; box-shadow:var(--shadow-sm); }
  .btn-imprimer-plan:hover { background:var(--accent-hover); }

  .entete-plan { text-align:center; margin-bottom:22px; }
  .entete-plan h1 { font-size:21px; margin:0; }

  .section-moment { border:2px solid; border-radius:14px; padding:16px; margin-bottom:16px; }
  .badge-moment { display:inline-block; color:#fff; font-size:12px; font-weight:700; letter-spacing:0.03em; text-transform:uppercase; padding:5px 14px; border-radius:999px; margin-bottom:12px; }
  .grille-cartes-moment { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
  .carte-medicament { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px; min-height:118px; }
  .photo-medicament { display:block; width:100%; height:60px; object-fit:contain; margin-bottom:6px; }
  .icone-medicament-defaut { display:flex; align-items:center; justify-content:center; width:100%; height:60px; margin-bottom:6px; color:var(--border-strong); }
  .icone-medicament-defaut svg { width:32px; height:32px; }
  .nom-medicament { font-size:13px; font-weight:700; color:var(--text); line-height:1.25; }
  .quantite-medicament { font-size:12px; font-weight:700; color:var(--text); margin-top:2px; }
  .detail-medicament { font-size:11px; color:var(--text-secondary); margin-top:2px; }

  @media (max-width:640px) {
    .grille-cartes-moment { grid-template-columns:repeat(2, 1fr); }
  }

  @media print {
    @page { margin: 0.85cm; }
    body { max-width:100%; padding:0; background:#fff; }
    .barre-actions-plan { display:none !important; }
    .entete-plan { margin-bottom:10px; }
    .entete-plan h1 { font-size:18px; }
    .section-moment { padding:9px 11px; margin-bottom:9px; break-inside:avoid; page-break-inside:avoid; -webkit-print-color-adjust:exact; print-color-adjust:exact; color-adjust:exact; }
    .badge-moment { font-size:10.5px; padding:3.5px 11px; margin-bottom:7px; -webkit-print-color-adjust:exact; print-color-adjust:exact; color-adjust:exact; }
    .grille-cartes-moment { gap:7px; }
    .carte-medicament { min-height:90px; padding:7px; break-inside:avoid; page-break-inside:avoid; }
    .photo-medicament, .icone-medicament-defaut { height:44px; margin-bottom:4px; }
    .icone-medicament-defaut svg { width:25px; height:25px; }
    .nom-medicament { font-size:12px; }
    .quantite-medicament { font-size:11px; }
    .detail-medicament { font-size:10px; }
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
      <a href="/medicaments.php">Modifier le plan</a>
      <a href="/index.php">Retour à l'agenda</a>
    </div>
  </div>

  <div class="entete-plan">
    <h1>Traitement de <?= htmlspecialchars($personneCible) ?> — Plan de prise quotidien</h1>
  </div>

  <?php if (empty($groupes)): ?>
    <p class="vide">Aucun médicament enregistré. <a href="/medicaments.php">Ajouter des médicaments</a>.</p>
  <?php else: ?>
    <?php $indexMoment = 0; foreach ($groupes as $moment => $meds): ?>
      <?php $couleurs = paletteMoment($indexMoment); $indexMoment++; ?>
      <div class="section-moment" style="border-color:<?= $couleurs['bordure'] ?>; background:<?= $couleurs['fond'] ?>;">
        <span class="badge-moment" style="background:<?= $couleurs['bordure'] ?>;"><?= htmlspecialchars(mb_strtoupper($moment)) ?></span>
        <div class="grille-cartes-moment">
          <?php foreach ($meds as $m): ?>
            <div class="carte-medicament">
              <?php if (!empty($m['image'])): ?>
                <img class="photo-medicament" src="/medicaments_photos/<?= rawurlencode($m['image']) ?>" alt="">
              <?php else: ?>
                <div class="icone-medicament-defaut">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="7" rx="3.5"/><path d="M8 11v7"/><circle cx="17" cy="6" r="3"/></svg>
                </div>
              <?php endif; ?>
              <div class="nom-medicament"><?= htmlspecialchars($m['nom']) ?></div>
              <?php if ($m['quantite'] !== ''): ?>
                <div class="quantite-medicament"><?= htmlspecialchars($m['quantite']) ?></div>
              <?php endif; ?>
              <?php if ($m['detail'] !== ''): ?>
                <div class="detail-medicament"><?= htmlspecialchars($m['detail']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</body>
</html>
