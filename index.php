<?php
require_once __DIR__ . '/lib/auth.php';
requireIdentite();

$config = require __DIR__ . '/config.php';
$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';
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

  <div class="topbar">
    <div class="entete">
      <div>
        <h1>Agenda médical</h1>
      </div>
      <div class="entete-actions">
        <button class="bouton-compact bouton-compact-principal" id="btnAjouter">
          <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          Ajouter
        </button>
        <div class="menu-suspendu" id="menuImpression">
          <button class="bouton-compact bouton-menu-suspendu" id="btnMenuImprimer" type="button" aria-haspopup="true" aria-expanded="false">
            <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M6 17v4h12v-4"/></svg>
            Imprimer
            <svg class="icone icone-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="menu-deroulant" id="menuImpressionListe">
            <button type="button" id="btnImprimer">Normal</button>
            <button type="button" id="btnImprimerCompact">Compact (grille)</button>
          </div>
        </div>
        <div class="menu-suspendu" id="menuCompte">
          <button class="bouton-compact bouton-menu-suspendu" id="btnMenuCompte" type="button" aria-haspopup="true" aria-expanded="false">
            <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= htmlspecialchars(personneSessionActuelle()) ?>
            <svg class="icone icone-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="menu-deroulant" id="menuCompteListe">
            <?php if (personneSessionActuelle() === 'Laurent'): ?>
            <a href="/admin/index.php"><svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20a8 8 0 1 0 0-16 8 8 0 0 0 0 16z"/><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>Administration</a>
            <?php endif; ?>
            <a href="/historique.php"><svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 3"/></svg>Historique</a>
            <a href="/taches.php"><svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="6" height="6" rx="1"/><path d="M3 15h6v6H3z"/><path d="M13 5h8M13 9h8M13 15h8M13 19h8"/></svg>Tâches</a>
            <a href="/mes_rappels.php"><svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>Rappels par email</a>
            <a href="/logout.php"><svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Déconnexion</a>
          </div>
        </div>
      </div>
    </div>

    <div class="tabs" id="tabs" role="tablist">
      <div class="tab tous active" data-filtre="Tous" tabindex="0" role="tab" aria-selected="true">Tous</div>
      <div class="tab papa" data-filtre="<?= htmlspecialchars($p1) ?>" tabindex="0" role="tab" aria-selected="false"><?= htmlspecialchars($p1) ?></div>
      <div class="tab maman" data-filtre="<?= htmlspecialchars($p2) ?>" tabindex="0" role="tab" aria-selected="false"><?= htmlspecialchars($p2) ?></div>
    </div>

    <div class="tabs tabs-temps" id="tabsTemps" role="tablist">
      <div class="tab-temps active" data-temps="avenir" tabindex="0" role="tab" aria-selected="true">À venir <span class="compteur-tab" id="compteurAvenir"></span></div>
      <div class="tab-temps" data-temps="passes" tabindex="0" role="tab" aria-selected="false">Passés <span class="compteur-tab" id="compteurPasses"></span></div>
      <div class="tab-temps" data-temps="tous" tabindex="0" role="tab" aria-selected="false">Tout l'historique <span class="compteur-tab" id="compteurTous"></span></div>
    </div>

    <div class="recherche-barre">
      <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="search" id="champRecherche" placeholder="Rechercher (médecin, notes, adresse...)" autocomplete="off" aria-label="Rechercher un rendez-vous">
      <button type="button" id="btnEffacerRecherche" class="btn-effacer-recherche" aria-label="Effacer la recherche" style="display:none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
  </div>

  <div id="entete-impression">
    <h1>Rendez-vous médicaux — <span id="filtreImpression">Tous</span></h1>
  </div>

  <div id="liste">
    <div class="squelette">
      <div class="squelette-carte"><div class="squelette-bandeau"></div><div class="squelette-corps"></div></div>
      <div class="squelette-carte"><div class="squelette-bandeau"></div><div class="squelette-corps"></div></div>
      <div class="squelette-carte"><div class="squelette-bandeau"></div><div class="squelette-corps"></div></div>
    </div>
  </div>
  <div id="listeCompacte"></div>

  <div class="overlay" id="overlay"></div>

  <div id="formCard" class="modal">
    <div class="modal-corps">
      <h2>Rendez-vous</h2>

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

      <p class="section-titre">Qui</p>
      <div class="champ">
        <div class="personnes" id="personnes">
          <input type="radio" name="personne" value="<?= htmlspecialchars($p1) ?>" id="pPapa">
          <label class="sel-papa" for="pPapa"><?= htmlspecialchars($p1) ?></label>
          <input type="radio" name="personne" value="<?= htmlspecialchars($p2) ?>" id="pMaman">
          <label class="sel-maman" for="pMaman"><?= htmlspecialchars($p2) ?></label>
        </div>
      </div>
      <div class="champ">
        <label>Accompagnant (facultatif)</label>
        <input type="text" id="fAccompagnant" placeholder="Ex. Chem, Laurent, Hélène...">
      </div>

      <p class="section-titre">Détails médicaux</p>
      <div class="champ">
        <label>Médecin / consultation</label>
        <input type="text" id="fMedecin" list="listeMedecins" autocomplete="off">
        <datalist id="listeMedecins"></datalist>
      </div>
      <div class="champ">
        <label>Département (facultatif)</label>
        <input type="text" id="fDepartement">
      </div>

      <p class="section-titre">Coordonnées (facultatif)</p>
      <div class="champ">
        <label>Adresse</label>
        <input type="text" id="fAdresse">
      </div>
      <div class="champ-ligne">
        <div class="champ">
          <label>Téléphone</label>
          <input type="tel" id="fTelephone">
        </div>
        <div class="champ">
          <label>Route</label>
          <input type="text" id="fRoute">
        </div>
      </div>

      <p class="section-titre">Notes (facultatif)</p>
      <div class="champ">
        <textarea id="fNotes" rows="3"></textarea>
      </div>

      <p class="erreur" id="erreurForm"></p>
      <button type="button" class="lien-danger" id="btnSupprimer" style="display:none;">Supprimer ce rendez-vous</button>
    </div>
    <div class="form-boutons">
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
    window.PERSONNE_1 = <?= json_encode($p1) ?>;
    window.PERSONNE_2 = <?= json_encode($p2) ?>;
  </script>
  <script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
