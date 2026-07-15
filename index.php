<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();

$config = require __DIR__ . '/config.php';
$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Agenda medical</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <div class="topbar">
    <div class="entete">
      <div>
        <h1>Agenda medical</h1>
        <p class="sous-titre">Rendez-vous de <?= htmlspecialchars($p1) ?> et <?= htmlspecialchars($p2) ?></p>
      </div>
      <a class="deconnexion" href="logout.php">Deconnexion</a>
    </div>

    <div class="tabs" id="tabs">
      <div class="tab tous active" data-filtre="Tous">Tous</div>
      <div class="tab papa" data-filtre="<?= htmlspecialchars($p1) ?>"><?= htmlspecialchars($p1) ?></div>
      <div class="tab maman" data-filtre="<?= htmlspecialchars($p2) ?>"><?= htmlspecialchars($p2) ?></div>
    </div>

    <div class="tabs tabs-temps" id="tabsTemps">
      <div class="tab-temps active" data-temps="avenir">A venir</div>
      <div class="tab-temps" data-temps="passes">Passes</div>
      <div class="tab-temps" data-temps="tous">Tous</div>
    </div>
  </div>

  <div id="entete-impression">
    <h1>Rendez-vous medicaux — <span id="filtreImpression">Tous</span></h1>
  </div>

  <div class="actions">
    <button class="principal" id="btnAjouter">+ Ajouter un rendez-vous</button>
    <button class="secondaire" id="btnImprimer">Imprimer</button>
    <button class="secondaire" id="btnImprimerCompact">Imprimer (compact)</button>
  </div>

  <div id="liste">
    <div class="squelette">
      <div class="squelette-ligne"></div>
      <div class="squelette-ligne"></div>
      <div class="squelette-ligne"></div>
    </div>
  </div>
  <div id="listeCompacte"></div>

  <div class="overlay" id="overlay"></div>

  <div id="formCard" class="modal">
    <div class="modal-corps">
      <h2>Rendez-vous</h2>
      <div class="champ-ligne">
        <div class="champ">
          <label>Date</label>
          <input type="date" id="fDate">
        </div>
        <div class="champ">
          <label>Heure</label>
          <input type="time" id="fHeure">
        </div>
      </div>
      <div class="champ">
        <label>Pour qui ?</label>
        <div class="personnes" id="personnes">
          <input type="radio" name="personne" value="<?= htmlspecialchars($p1) ?>" id="pPapa">
          <label class="sel-papa" for="pPapa"><?= htmlspecialchars($p1) ?></label>
          <input type="radio" name="personne" value="<?= htmlspecialchars($p2) ?>" id="pMaman">
          <label class="sel-maman" for="pMaman"><?= htmlspecialchars($p2) ?></label>
        </div>
      </div>
      <div class="champ">
        <label>Medecin / consultation</label>
        <input type="text" id="fMedecin" placeholder="Dr Martin, cardiologue">
      </div>
      <div class="champ">
        <label>Departement (facultatif)</label>
        <input type="text" id="fDepartement" placeholder="Cardiologie">
      </div>
      <div class="champ">
        <label>Adresse (facultatif)</label>
        <input type="text" id="fAdresse" placeholder="Avenue Hippocrate 10, 1200 Bruxelles">
      </div>
      <div class="champ-ligne">
        <div class="champ">
          <label>Telephone (facultatif)</label>
          <input type="tel" id="fTelephone" placeholder="02 764 28 12">
        </div>
        <div class="champ">
          <label>Route (facultatif)</label>
          <input type="text" id="fRoute" placeholder="Route 555">
        </div>
      </div>
      <div class="champ">
        <label>Notes (facultatif)</label>
        <textarea id="fNotes" rows="4" placeholder="Apporter les résultats de prise de sang"></textarea>
      </div>
      <p class="erreur" id="erreurForm"></p>
    </div>
    <div class="form-boutons">
      <button class="principal" id="btnEnregistrer">Enregistrer</button>
      <button class="secondaire" id="btnAnnuler">Annuler</button>
    </div>
  </div>

  <button class="fab" id="btnAjouterMobile" aria-label="Ajouter un rendez-vous">+</button>

  <script>
    window.PERSONNE_1 = <?= json_encode($p1) ?>;
    window.PERSONNE_2 = <?= json_encode($p2) ?>;
  </script>
  <script src="assets/app.js"></script>
</body>
</html>