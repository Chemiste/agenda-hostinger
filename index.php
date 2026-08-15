<?php
require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/entete.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/persons.php';

$config = require __DIR__ . '/config.php';
// Les patients viennent de la table persons (voir admin/personnes.php) :
// onglets, boutons radio du formulaire et couleurs en decoulent, sans
// aucun nom ecrit en dur.
$patients = listerPatients(getDb());
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>

  <?php afficherEnteteNavigation('agenda'); ?>

  <div class="topbar">
    <!-- Deuxieme etage : le titre de la page et SES actions. "Ajouter" et
         "Imprimer" ne sont pas des destinations - ils n'ont donc rien a
         faire dans la barre de navigation ci-dessus, d'autant qu'ils
         n'imprimeraient pas la meme chose d'une page a l'autre. -->
    <div class="entete">
      <div>
        <h1>Rendez-vous</h1>
      </div>
      <div class="entete-actions">
        <button class="bouton-compact bouton-compact-principal" id="btnAjouter">
          <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          Ajouter
        </button>
        <!-- Un seul mode d'impression : le bouton imprime directement, il
             n'ouvre plus un menu "Normal / Compact" dont les intitules ne
             disaient rien a la personne qui clique. -->
        <button class="bouton-compact" id="btnImprimer" type="button">
          <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M6 17v4h12v-4"/></svg>
          Imprimer
        </button>
      </div>
    </div>

    <div class="tabs" id="tabs" role="tablist">
      <div class="tab tous active" data-filtre="Tous" tabindex="0" role="tab" aria-selected="true">Tous</div>
      <?php $rangTab = 0; foreach ($patients as $unPatient): ?>
        <?php $classeTab = $rangTab === 0 ? 'papa' : ($rangTab === 1 ? 'maman' : 'deux'); $rangTab++; ?>
        <div class="tab <?= $classeTab ?>" data-filtre="<?= (int) $unPatient['id'] ?>" tabindex="0" role="tab" aria-selected="false"><?= htmlspecialchars($unPatient['nom']) ?></div>
      <?php endforeach; ?>
      <!-- Recherche repliee par defaut, sur la meme ligne que les onglets
           (place libre a leur droite) : ca evite une ligne entiere avant
           d'arriver aux rendez-vous. Le bouton la deplie (voir app.js). -->
      <button type="button" id="btnOuvrirRecherche" class="btn-ouvrir-recherche" aria-expanded="false" aria-controls="rechercheBarre" title="Rechercher un rendez-vous">
        <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <span class="texte-btn-recherche">Rechercher</span>
      </button>
    </div>

    <div class="tabs tabs-temps" id="tabsTemps" role="tablist">
      <div class="tab-temps active" data-temps="avenir" tabindex="0" role="tab" aria-selected="true">À venir <span class="compteur-tab" id="compteurAvenir"></span></div>
      <div class="tab-temps" data-temps="passes" tabindex="0" role="tab" aria-selected="false">Passés <span class="compteur-tab" id="compteurPasses"></span></div>
      <!-- "Tous les rendez-vous" et non "Tout l'historique" : le mot
           historique evoque le passe, l'onglet semblait donc faire double
           emploi avec "Passes" alors qu'il montre passe ET futur. -->
      <div class="tab-temps" data-temps="tous" tabindex="0" role="tab" aria-selected="false">Tous les rendez-vous <span class="compteur-tab" id="compteurTous"></span></div>
    </div>

    <div class="recherche-barre" id="rechercheBarre" style="display:none;">
      <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="search" id="champRecherche" placeholder="Rechercher (médecin, notes, adresse...)" autocomplete="off" aria-label="Rechercher un rendez-vous">
      <button type="button" id="btnEffacerRecherche" class="btn-effacer-recherche" aria-label="Effacer la recherche" style="display:none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
  </div>

  <!-- Bandeau taches (telephone uniquement, voir CSS) : toujours visible
       sans avoir a scroller, juste sous les onglets. Sur desktop, les
       taches sont dans la colonne de droite (#barreTaches) a la place. -->
  <div class="bandeau-taches" id="bandeauTaches"></div>

  <div class="page-layout">
    <div class="colonne-principale">
      <!-- Visible uniquement à l'impression (voir #entete-impression dans
           style.css). La date est remplie juste avant d'imprimer, pour
           qu'une feuille retrouvée sur la table dise toujours de quand
           elle date. -->
      <div id="entete-impression">
        <h1>Rendez-vous médicaux — <span id="filtreImpression">Tous</span></h1>
        <p id="dateImpression"></p>
      </div>

      <div id="liste">
        <div class="squelette">
          <div class="squelette-carte"><div class="squelette-bandeau"></div><div class="squelette-corps"></div></div>
          <div class="squelette-carte"><div class="squelette-bandeau"></div><div class="squelette-corps"></div></div>
          <div class="squelette-carte"><div class="squelette-bandeau"></div><div class="squelette-corps"></div></div>
        </div>
      </div>
      <div id="listeCompacte"></div>
      <div id="tachesCompacte"></div>
    </div>

    <!-- Colonne taches (desktop uniquement, voir CSS) : toujours visible
         sur le cote, pas besoin d'ouvrir une autre page pour y jeter un
         oeil ou cocher une tache faite. -->
    <aside class="barre-taches" id="barreTaches"></aside>
  </div>

  <div class="overlay" id="overlay"></div>

  <div id="formCard" class="modal">
    <div class="modal-corps">
      <h2>Rendez-vous</h2>

      <!-- Chaque section est enveloppee dans .section-groupe (titre + ses
           champs, comme un seul bloc) : sur desktop large, .modal-corps
           passe en grille 2 colonnes (voir style.css) et ces blocs se
           repartissent Quand|Qui, Details medicaux|Coordonnees,
           Notes|Questions - impossible de couper proprement un titre de
           ses champs sans ce regroupement explicite. -->
      <div class="section-groupe">
        <p class="section-titre">Quand</p>
        <div class="champ-ligne champ-ligne-3">
          <div class="champ">
            <label>Date</label>
            <input type="date" id="fDate">
          </div>
          <div class="champ">
            <label>Heure</label>
            <input type="time" id="fHeure">
          </div>
          <div class="champ">
            <label>Durée</label>
            <select id="fDuree">
              <option value="15">15 min</option>
              <option value="30" selected>30 min</option>
              <option value="45">45 min</option>
              <option value="60">1 h</option>
              <option value="90">1 h 30</option>
              <option value="120">2 h</option>
            </select>
          </div>
        </div>
      </div>

      <div class="section-groupe">
        <p class="section-titre">Qui</p>
        <div class="champ">
          <div class="personnes" id="personnes">
            <?php $rangRadio = 0; foreach ($patients as $unPatient): ?>
              <?php $classeSel = $rangRadio === 0 ? 'sel-papa' : ($rangRadio === 1 ? 'sel-maman' : 'sel-deux'); $rangRadio++; ?>
              <input type="radio" name="personne" value="<?= (int) $unPatient['id'] ?>" id="pPatient<?= (int) $unPatient['id'] ?>">
              <label class="<?= $classeSel ?>" for="pPatient<?= (int) $unPatient['id'] ?>"><?= htmlspecialchars($unPatient['nom']) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="champ">
          <label>Accompagnant (facultatif)</label>
          <input type="text" id="fAccompagnant" placeholder="Ex. Laurent, Hélène, un voisin...">
        </div>
        <!-- Options remplies en JS selon la personne cochee ci-dessus (voir
             chargerPathologies()/actualiserChoixPathologies() dans app.js) :
             chacune n'a que ses propres pathologies. -->
        <div class="champ" id="champPathologie" style="display:none;">
          <label>Pathologie concernée (facultatif)</label>
          <select id="fPathologie">
            <option value="0">— Aucune —</option>
          </select>
        </div>
      </div>

      <div class="section-groupe">
        <p class="section-titre">Détails médicaux</p>
        <div class="champ">
          <label>Médecin / consultation</label>
          <input type="text" id="fMedecin" list="listeMedecins" autocomplete="off" placeholder="Ex. Dr Dupont">
          <datalist id="listeMedecins"></datalist>
          <button type="button" id="btnImporterMedecin" class="lien-importer-medecin" style="display:none;">Importer ce médecin dans le carnet</button>
        </div>
        <div class="champ">
          <label>Département (facultatif)</label>
          <input type="text" id="fDepartement" placeholder="Ex. Cardiologie">
        </div>
      </div>

      <div class="section-groupe">
        <p class="section-titre">Coordonnées (facultatif)</p>
        <div class="champ">
          <label>Adresse</label>
          <input type="text" id="fAdresse" placeholder="Ex. Rue de la Clinique 12, 1000 Bruxelles">
        </div>
        <div class="champ-ligne">
          <div class="champ">
            <label>Téléphone</label>
            <input type="tel" id="fTelephone" placeholder="Ex. 02 123 45 67">
          </div>
          <div class="champ">
            <label>Route</label>
            <input type="text" id="fRoute">
          </div>
        </div>
      </div>

      <div class="section-groupe">
        <p class="section-titre">Notes (facultatif)</p>
        <div class="champ">
          <textarea id="fNotes" rows="3" placeholder="Ex. Apporter la carte SIS et les derniers résultats"></textarea>
        </div>
      </div>

      <div class="section-groupe">
        <p class="section-titre">Questions à poser (facultatif)</p>
        <div class="champ">
          <textarea id="fQuestions" rows="3" placeholder="Une question par ligne, ex. Peut-on arrêter ce traitement ?"></textarea>
        </div>
      </div>

    </div>
    <!-- Message d'erreur et "Supprimer" places dans la barre fixe du bas
         (hors de .modal-corps qui defile) : sinon il fallait faire defiler
         tout le formulaire pour les atteindre, et une erreur de validation
         pouvait passer inapercue. -->
    <div class="form-boutons">
      <p class="erreur" id="erreurForm"></p>
      <button type="button" class="bouton-supprimer-rdv" id="btnSupprimer" style="display:none;" title="Supprimer ce rendez-vous" aria-label="Supprimer ce rendez-vous">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        <span class="texte-supprimer-rdv">Supprimer</span>
      </button>
      <button class="principal" id="btnEnregistrer">Enregistrer</button>
      <button class="secondaire" id="btnAnnuler">Annuler</button>
    </div>
  </div>

  <button class="fab" id="btnAjouterMobile" aria-label="Ajouter un rendez-vous">+</button>

  <!-- Modale de confirmation/erreur generique, pour remplacer les
       confirm()/alert() natifs du navigateur (moches et hors charte
       graphique) — voir ouvrirDialogue()/confirmerPerso()/alerterPerso()
       dans app.js. -->
  <div id="dialogueModal" class="modal modal-dialogue">
    <div class="modal-corps">
      <p id="dialogueMessage"></p>
    </div>
    <div class="form-boutons" id="dialogueBoutons"></div>
  </div>

  <div id="toast" class="toast" role="status" aria-live="polite"></div>

  <script>
    // [{id, nom}] dans l'ordre d'affichage : app.js en tire les couleurs
    // (par rang) et les libelles, au lieu de comparer a deux noms figes.
    window.PATIENTS = <?= json_encode(array_map(function ($p) {
        return ['id' => (int) $p['id'], 'nom' => $p['nom']];
    }, array_values($patients)), JSON_UNESCAPED_UNICODE) ?>;
    window.PERSONNE_CONNECTEE = <?= json_encode(personneSessionActuelle()) ?>;
  </script>
  <script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
  <script src="/assets/entete.js?v=<?= filemtime(__DIR__ . '/assets/entete.js') ?>"></script>
</body>
</html>
