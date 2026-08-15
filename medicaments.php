<?php
/**
 * MÉDICAMENTS — page de consultation, pour toute la famille.
 *
 * Une seule chose ici : le plan de prise, groupé par moment de la journée
 * (Matin, 15h00, Soir, Au coucher). Ce découpage n'est pas cosmétique —
 * Michel et Christiane remplissent des piluliers dont les bacs
 * correspondent exactement à ces moments, donc la page doit se lire dans
 * le même ordre que le pilulier se remplit.
 *
 * C'est aussi la page qu'on imprime (bouton "Imprimer") : plus de fiche
 * séparée, la mise en page d'impression est ici (voir @media print), la
 * navigation et les boutons disparaissent à l'impression.
 *
 * Toute la SAISIE (ajouter un médicament, gérer les moments, les photos)
 * vit dans /admin/medicaments.php : l'écran que consultent les parents ne
 * doit pas être encombré de formulaires qui ne les concernent pas.
 *
 * Limité à Christiane pour l'instant (personne_2) - les tables gèrent déjà
 * plusieurs personnes, il suffirait d'ajouter un sélecteur.
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/entete.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/medicaments.php';

$config = require __DIR__ . '/config.php';
$personneCible = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';
$peutModifier = personneSessionActuelle() === 'Laurent';

$db = getDb();

// Le plan complet, deja assemble : les moments dans l'ordre, et pour
// chacun les medicaments qui s'y prennent avec leur quantite a ce
// moment-la et leurs eventuelles alternatives (voir lib/medicaments.php).
$plan = [];
foreach (construirePlan($db, $personneCible) as $section) {
    // Un moment sans aucun medicament n'occupe pas de place ici : il reste
    // visible dans la page de gestion, ou on peut le renommer ou l'effacer.
    if (!empty($section['medicaments'])) {
        $plan[] = $section;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Médicaments — <?= htmlspecialchars($personneCible) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<style>
  .barre-actions-medicaments { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 18px; }
  .btn-imprimer-plan { display:inline-flex; align-items:center; gap:8px; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-md); padding:12px 20px; font-size:15px; font-weight:600; cursor:pointer; box-shadow:var(--shadow-sm); }
  .btn-imprimer-plan:hover { background:var(--accent-hover); }
  .lien-gerer-plan { display:inline-flex; align-items:center; gap:6px; font-size:13.5px; color:var(--text-secondary); text-decoration:none; border:1px solid var(--border); border-radius:var(--radius-md); padding:11px 16px; }
  .lien-gerer-plan:hover { border-color:var(--accent); color:var(--accent); }

  /* Titre de la feuille imprimee. A l'ecran il ferait doublon avec le
     titre "Médicaments" de la page, il n'apparait donc qu'au moment
     d'imprimer. */
  .entete-plan { display:none; text-align:center; margin-bottom:22px; }
  .entete-plan h1 { font-size:21px; margin:0; }

  .section-moment { border:2px solid; border-radius:14px; padding:16px; margin-bottom:16px; }
  .badge-moment { display:inline-block; color:#fff; font-size:13px; font-weight:700; letter-spacing:0.03em; text-transform:uppercase; padding:5px 14px; border-radius:999px; margin-bottom:12px; }
  .grille-cartes-moment { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
  .carte-medicament { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:12px; }
  .photo-medicament { display:block; width:100%; height:86px; object-fit:contain; margin-bottom:8px; }
  .icone-medicament-defaut { display:flex; align-items:center; justify-content:center; width:100%; height:86px; margin-bottom:8px; color:var(--border-strong); }
  .icone-medicament-defaut svg { width:36px; height:36px; }
  .nom-medicament { font-size:15px; font-weight:700; color:var(--text); line-height:1.25; }
  .quantite-medicament { font-size:14px; font-weight:700; color:var(--text); margin-top:3px; }
  .detail-medicament { font-size:12.5px; color:var(--text-secondary); margin-top:3px; }

  /* Separateur "OU" entre un medicament et son alternative, dans la meme
     carte : un trait de part et d'autre du mot pour qu'on voie tout de
     suite qu'il s'agit d'un choix, pas d'un second medicament a prendre
     en plus. */
  .separateur-ou { display:flex; align-items:center; gap:8px; margin:10px 0 6px; }
  .separateur-ou::before, .separateur-ou::after { content:""; flex:1; height:1px; background:var(--border-strong); }
  .separateur-ou span { font-size:12px; font-weight:800; letter-spacing:0.08em; color:var(--text-secondary); }
  .photo-alternative { height:64px; }

  @media (max-width:900px) {
    .grille-cartes-moment { grid-template-columns:repeat(2, 1fr); }
  }
  @media (max-width:520px) {
    .grille-cartes-moment { grid-template-columns:1fr; }
  }

  @media print {
    /* La feuille prend le pas sur les regles d'impression generales de
       style.css : marges plus serrees, et tout le mobilier de navigation
       disparait pour ne laisser que le plan. */
    @page { margin: 0.85cm; }
    body { max-width:100%; padding:0; background:#fff; }
    .barre-actions-medicaments, .barre-admin, .sous-titre-medicaments { display:none !important; }
    .entete-plan { display:block; margin-bottom:10px; }
    .entete-plan h1 { font-size:18px; }
    .section-moment { padding:9px 11px; margin-bottom:9px; break-inside:avoid; page-break-inside:avoid; -webkit-print-color-adjust:exact; print-color-adjust:exact; color-adjust:exact; }
    .badge-moment { font-size:10.5px; padding:3.5px 11px; margin-bottom:7px; -webkit-print-color-adjust:exact; print-color-adjust:exact; color-adjust:exact; }
    .grille-cartes-moment { grid-template-columns:repeat(3, 1fr); gap:7px; }
    .carte-medicament { min-height:90px; padding:7px; break-inside:avoid; page-break-inside:avoid; }
    .photo-medicament, .icone-medicament-defaut { height:44px; margin-bottom:4px; }
    .icone-medicament-defaut svg { width:25px; height:25px; }
    .nom-medicament { font-size:12px; }
    .quantite-medicament { font-size:11px; }
    .detail-medicament { font-size:10px; }
    .separateur-ou { margin:6px 0 4px; }
    .separateur-ou span { font-size:10px; }
    .separateur-ou::before, .separateur-ou::after {
      background:#999;
      -webkit-print-color-adjust:exact;
      print-color-adjust:exact;
    }
    .photo-alternative { height:36px; }
  }
</style>
</head>
<body>
  <?php afficherEnteteNavigation('medicaments'); ?>

  <div class="barre-admin">
    <h1>Médicaments</h1>
  </div>
  <p class="sous-titre sous-titre-medicaments" style="margin-bottom:16px;">
    Plan de prise de <?= htmlspecialchars($personneCible) ?>, dans l'ordre des bacs du pilulier.
  </p>

  <div class="barre-actions-medicaments">
    <button type="button" class="btn-imprimer-plan" onclick="window.print()">
      <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M6 17v4h12v-4"/></svg>
      Imprimer / Enregistrer en PDF
    </button>
    <?php if ($peutModifier): ?>
      <a class="lien-gerer-plan" href="/admin/medicaments.php">
        <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        Modifier le plan
      </a>
    <?php endif; ?>
  </div>

  <div class="entete-plan">
    <h1>Traitement de <?= htmlspecialchars($personneCible) ?> — Plan de prise quotidien</h1>
  </div>

  <?php if (empty($plan)): ?>
    <p class="vide">
      Aucun médicament enregistré.
      <?php if ($peutModifier): ?><a href="/admin/medicaments.php">Créer le plan</a>.<?php endif; ?>
    </p>
  <?php else: ?>
    <?php foreach ($plan as $indexMoment => $section): ?>
      <?php $couleurs = paletteMoment($indexMoment); ?>
      <div class="section-moment" style="border-color:<?= $couleurs['bordure'] ?>; background:<?= $couleurs['fond'] ?>;">
        <span class="badge-moment" style="background:<?= $couleurs['bordure'] ?>;"><?= htmlspecialchars(mb_strtoupper($section['moment']['libelle'])) ?></span>
        <div class="grille-cartes-moment">
          <?php foreach ($section['medicaments'] as $m): ?>
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

              <?php foreach ($m['alternatives'] as $alt): ?>
                <!-- "OU" bien visible : sur la feuille posee pres des
                     medicaments, il faut comprendre d'un coup d'oeil qu'on
                     prend l'un OU l'autre, pas les deux. -->
                <div class="separateur-ou"><span>OU</span></div>
                <?php if (!empty($alt['image'])): ?>
                  <img class="photo-medicament photo-alternative" src="/medicaments_photos/<?= rawurlencode($alt['image']) ?>" alt="">
                <?php endif; ?>
                <div class="nom-medicament"><?= htmlspecialchars($alt['nom']) ?></div>
                <?php if ($alt['quantite'] !== ''): ?>
                  <div class="quantite-medicament"><?= htmlspecialchars($alt['quantite']) ?></div>
                <?php endif; ?>
                <?php if ($alt['detail'] !== ''): ?>
                  <div class="detail-medicament"><?= htmlspecialchars($alt['detail']) ?></div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <script src="/assets/entete.js?v=<?= filemtime(__DIR__ . '/assets/entete.js') ?>"></script>
</body>
</html>
