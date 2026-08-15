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
 * Les onglets en haut de page choisissent le patient ("?person="). Des
 * liens plutôt qu'une bascule en JavaScript comme sur Pathologies : cette
 * page s'imprime, et on veut n'imprimer QUE le plan affiché.
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/entete.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/medicaments.php';
require_once __DIR__ . '/lib/persons.php';

$peutModifier = personneSessionActuelle() === 'Laurent';

$db = getDb();

// Le patient dont on affiche le plan, choisi par les onglets ("?person=").
//
// Seuls les patients ayant REELLEMENT un plan a montrer ont un onglet :
// une page de consultation qui propose un onglet menant a "Aucun
// medicament enregistre" ne rend service a personne. La creation d'un
// plan se fait dans /admin/medicaments.php, ou tous les patients restent
// selectionnables - c'est la qu'on en a besoin.
//
// Des liens plutot qu'une bascule en JavaScript, contrairement a
// Pathologies : cette page s'imprime, et on veut n'imprimer QUE le plan
// affiche - pas les deux l'un apres l'autre.
$patients = listerPatients($db);

// Le plan de chaque patient, moments vides retires (un moment sans
// medicament n'a rien a faire sur la feuille - il reste modifiable dans
// la page de gestion).
$plansParPatient = [];
foreach ($patients as $unPatient) {
    $sections = [];
    foreach (construirePlan($db, $unPatient['id']) as $section) {
        if (!empty($section['medicaments'])) {
            $sections[] = $section;
        }
    }
    if (!empty($sections)) {
        $plansParPatient[(int) $unPatient['id']] = $sections;
    }
}
$patientsAvecPlan = array_intersect_key($patients, $plansParPatient);

$personCibleId = (int) (isset($_GET['person']) ? $_GET['person'] : 0);
if (!isset($patientsAvecPlan[$personCibleId])) {
    $personCibleId = !empty($patientsAvecPlan) ? (int) key($patientsAvecPlan) : 0;
}
$plan = isset($plansParPatient[$personCibleId]) ? $plansParPatient[$personCibleId] : [];

// Personne n'a de plan : on garde quand meme un nom pour le titre, celui
// du premier patient, plutot qu'un "Personne" sec.
if (isset($patients[$personCibleId])) {
    $personneCible = $patients[$personCibleId]['nom'];
} else {
    $premier = reset($patients);
    $personneCible = $premier !== false ? $premier['nom'] : 'Personne';
}

/**
 * Une « boîte » du plan : sa photo, son nom, sa quantité, et son détail —
 * sauf si ce détail est déjà écrit une seule fois pour toute la carte
 * (voir $detailCommun plus bas). Sert aussi bien au médicament seul qu'à
 * chacune des deux boîtes d'une carte « l'un OU l'autre ».
 */
function afficherBoiteMedicament($b, $detailCommun) {
    if (!empty($b['image'])) {
        echo '<img class="photo-medicament" src="/medicaments_photos/'
            . rawurlencode($b['image']) . '" alt="">';
    } else {
        echo '<div class="icone-medicament-defaut">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="11" width="18" height="7" rx="3.5"/><path d="M8 11v7"/>'
            . '<circle cx="17" cy="6" r="3"/></svg></div>';
    }
    echo '<div class="nom-medicament">' . htmlspecialchars($b['nom']) . '</div>';
    if ($b['quantite'] !== '') {
        echo '<div class="quantite-medicament">' . htmlspecialchars($b['quantite']) . '</div>';
    }
    if ($b['detail'] !== '' && $b['detail'] !== $detailCommun) {
        echo '<div class="detail-medicament">' . htmlspecialchars($b['detail']) . '</div>';
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
<?php /* admin.css porte .barre-admin et .onglets-patients, utilises ici
         comme sur les autres pages familiales. Sans lui, le titre
         "Médicaments" ne se plaçait pas à la même hauteur que celui de
         Pathologies ou Médecins : le <h1> gardait ses marges par défaut.
         Aucune règle d'impression dedans, la feuille n'est pas affectée. */ ?>
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
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

  /* --- Carte "l'un OU l'autre" ---------------------------------------
     Trois signaux redondants, volontairement : le bandeau, le cadre plus
     epais, et la mention au-dessus de chaque boite. Deux boites cote a
     cote ressemblent par defaut a deux medicaments a prendre ensemble ;
     sur un plan de medicaments lu par des personnes agees, le cout d'une
     erreur justifie de le dire trois fois. Aucun de ces trois signaux ne
     repose sur la couleur seule. */
  .carte-avec-alternative { border:2px solid #993C1D; padding:0; overflow:hidden; }
  .bandeau-un-seul {
    display:flex; align-items:center; justify-content:center; gap:6px;
    background:#FAECE7; color:#4A1B0C; border-bottom:1px solid #F0997B;
    font-size:12.5px; font-weight:800; letter-spacing:0.01em;
    padding:6px 10px; text-align:center;
  }
  .bandeau-un-seul svg { flex:none; width:15px; height:15px; }
  .paire-medicaments { display:grid; grid-template-columns:1fr auto 1fr; gap:10px; align-items:stretch; padding:10px 12px 0; }
  .boite-medicament { min-width:0; }
  .mention-boite { font-size:11px; font-weight:800; letter-spacing:0.04em; color:var(--text-secondary); margin-bottom:5px; }
  /* Le "OU" au centre, entre deux traits verticaux : la barre traverse
     toute la hauteur, on ne peut pas lire les deux boites comme une
     enumeration. */
  .separateur-ou-vertical { display:flex; flex-direction:column; align-items:center; gap:5px; align-self:stretch; }
  .separateur-ou-vertical::before, .separateur-ou-vertical::after { content:""; flex:1; width:2px; background:#993C1D; }
  .separateur-ou-vertical span {
    flex:none; font-size:13px; font-weight:800; letter-spacing:0.06em; color:#4A1B0C;
    background:#FAECE7; border:1.5px solid #993C1D; border-radius:999px; padding:2px 9px;
  }
  /* Detail identique aux deux boites : ecrit une seule fois, en bas. La
     quantite ne descend jamais ici, elle est propre a chaque boite. */
  .detail-commun { text-align:center; padding:6px 12px 10px; margin-top:0; }

  @media (max-width:900px) {
    .grille-cartes-moment { grid-template-columns:repeat(2, 1fr); }
  }
  @media (max-width:640px) {
    /* Deux boites cote a cote sur un telephone seraient illisibles : on
       repasse en empile, mais le bandeau, le cadre et les mentions
       restent - c'est eux qui portent l'avertissement. */
    .grille-cartes-moment { grid-template-columns:1fr; }
    .carte-avec-alternative { grid-column:span 1 !important; }
    .paire-medicaments { grid-template-columns:1fr; gap:6px; }
    .separateur-ou-vertical { flex-direction:row; align-self:auto; padding:2px 0; }
    .separateur-ou-vertical::before, .separateur-ou-vertical::after { width:auto; height:2px; }
  }

  @media print {
    /* La feuille prend le pas sur les regles d'impression generales de
       style.css : marges plus serrees, et tout le mobilier de navigation
       disparait pour ne laisser que le plan. */
    @page { margin: 0.85cm; }
    body { max-width:100%; padding:0; background:#fff; }
    .barre-actions-medicaments, .barre-admin, .sous-titre-medicaments, .onglets-patients { display:none !important; }
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

    /* La carte "l'un OU l'autre" garde tous ses reperes a l'impression -
       c'est justement sur le papier, pose pres des boites, qu'ils
       servent. Seules les tailles sont resserrees. */
    .carte-avec-alternative { padding:0; }
    .bandeau-un-seul { font-size:10.5px; padding:4px 8px; gap:5px; -webkit-print-color-adjust:exact; print-color-adjust:exact; color-adjust:exact; }
    .bandeau-un-seul svg { width:12px; height:12px; }
    .paire-medicaments { gap:7px; padding:7px 8px 0; }
    .mention-boite { font-size:9.5px; margin-bottom:3px; }
    .separateur-ou-vertical span { font-size:10.5px; padding:1px 7px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .separateur-ou-vertical::before, .separateur-ou-vertical::after {
      -webkit-print-color-adjust:exact;
      print-color-adjust:exact;
    }
    .detail-commun { padding:4px 8px 7px; }
  }
</style>
</head>
<body>
  <?php afficherEnteteNavigation('medicaments'); ?>

  <div class="barre-admin">
    <h1>Médicaments</h1>
  </div>
  <p class="sous-titre sous-titre-medicaments" style="margin-bottom:18px;">
    Plan de prise de <?= htmlspecialchars($personneCible) ?>, dans l'ordre des bacs du pilulier.
  </p>

  <?php /* Onglets masques quand il n'y a qu'un patient : un onglet unique
           n'offre aucun choix, il ne ferait qu'occuper une ligne. */ ?>
  <?php if (count($patientsAvecPlan) > 1): ?>
    <div class="tabs onglets-patients" role="tablist">
      <?php $rangOnglet = 0; foreach ($patientsAvecPlan as $unPatient): ?>
        <?php
          $classeOnglet = $rangOnglet === 0 ? 'papa' : ($rangOnglet === 1 ? 'maman' : 'tous');
          $rangOnglet++;
          $estActif = (int) $unPatient['id'] === $personCibleId;
        ?>
        <a class="tab <?= $classeOnglet ?><?= $estActif ? ' active' : '' ?>" href="?person=<?= (int) $unPatient['id'] ?>" role="tab" aria-selected="<?= $estActif ? 'true' : 'false' ?>"><?= htmlspecialchars($unPatient['nom']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="barre-actions-medicaments">
    <button type="button" class="btn-imprimer-plan" onclick="window.print()">
      <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M6 17v4h12v-4"/></svg>
      Imprimer / Enregistrer en PDF
    </button>
    <?php if ($peutModifier): ?>
      <a class="lien-gerer-plan" href="/admin/medicaments.php?person=<?= $personCibleId ?>">
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
            <?php
              // Le medicament et ses alternatives, dans l'ordre d'affichage
              // de gauche a droite. Une carte sans alternative n'a qu'une
              // seule boite et se comporte comme avant.
              $boites = array_merge([$m], $m['alternatives']);
              $avecAlternative = count($boites) > 1;

              // Largeur de la carte, en colonnes : une par boite. Elle ne
              // s'etire jamais pour combler une fin de ligne - une carte
              // etiree n'aurait plus la meme taille que les autres, et le
              // plan doit rester lisible comme une grille reguliere.
              // L'ordre des medicaments est calcule en amont pour remplir
              // les lignes, voir ordonnerPourGrille() dans
              // lib/medicaments.php.
              $colonnes = min(count($boites), 3);

              // Detail commun ecrit une seule fois, en bas de carte : pour
              // Paracetamol/Dafalgan c'est la meme phrase de deux lignes,
              // l'imprimer deux fois ne fait qu'allonger la feuille. Des que
              // les details different (Escitalopram 5mg x2 / Sipralexa 10mg),
              // chacun reste colle a sa boite. La QUANTITE, elle, ne descend
              // jamais en bas : elle est propre a chaque boite.
              $detailCommun = '';
              if ($avecAlternative && $m['detail'] !== '') {
                  $detailCommun = $m['detail'];
                  foreach ($boites as $b) {
                      if ($b['detail'] !== $m['detail']) { $detailCommun = ''; break; }
                  }
              }
            ?>
            <?php if (!$avecAlternative): ?>
              <div class="carte-medicament">
                <?php afficherBoiteMedicament($m, ''); ?>
              </div>
            <?php else: ?>
              <!-- Medicament a alternative : la carte occupe deux colonnes et
                   les boites sont cote a cote. Empilees, elles rendaient la
                   carte deux fois plus haute que ses voisines, qui
                   s'etiraient pour rien - c'est ce qui faisait deborder la
                   feuille sur une seconde page.

                   Le risque de cette mise cote a cote, c'est qu'elle
                   ressemble a deux medicaments a prendre tous les deux.
                   D'ou l'avertissement redondant : bandeau en tete, cadre
                   plus epais, "OU" au centre, et mention au-dessus de chaque
                   boite. Sur un plan de medicaments lu par des personnes
                   agees, mieux vaut le dire trois fois qu'une. -->
              <div class="carte-medicament carte-avec-alternative" style="grid-column:span <?= $colonnes ?>;">
                <div class="bandeau-un-seul">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                  UN SEUL DES DEUX — jamais les deux ensemble
                </div>
                <div class="paire-medicaments">
                  <?php foreach ($boites as $i => $b): ?>
                    <?php if ($i > 0): ?>
                      <div class="separateur-ou-vertical"><span>OU</span></div>
                    <?php endif; ?>
                    <div class="boite-medicament">
                      <div class="mention-boite"><?= $i === 0 ? 'À PRENDRE' : 'OU, À LA PLACE' ?></div>
                      <?php afficherBoiteMedicament($b, $detailCommun); ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if ($detailCommun !== ''): ?>
                  <div class="detail-medicament detail-commun"><?= htmlspecialchars($detailCommun) ?></div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <script src="/assets/entete.js?v=<?= filemtime(__DIR__ . '/assets/entete.js') ?>"></script>
</body>
</html>
