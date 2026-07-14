<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Agenda medical</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <div class="entete">
    <div>
      <h1>Agenda medical</h1>
      <p class="sous-titre">Rendez-vous de Papa et Maman</p>
    </div>
    <a class="deconnexion" href="logout.php">Deconnexion</a>
  </div>

  <div id="entete-impression">
    <h1>Rendez-vous medicaux — <span id="filtreImpression">Tous</span></h1>
    <p id="dateImpression"></p>
  </div>

  <div class="tabs" id="tabs">
    <div class="tab tous active" data-filtre="Tous">Tous</div>
    <div class="tab papa" data-filtre="Papa">Papa</div>
    <div class="tab maman" data-filtre="Maman">Maman</div>
  </div>

  <div class="actions">
    <button class="principal" id="btnAjouter">+ Ajouter un rendez-vous</button>
    <button class="secondaire" id="btnImprimer">Imprimer</button>
  </div>

  <button class="secondaire" id="btnImportIcs">Importer un fichier .ics</button>
  <input type="file" id="fichierIcs" accept=".ics,text/calendar" style="display:none;">

  <div id="icsCard">
    <h2>Rendez-vous trouves dans le fichier</h2>
    <p class="erreur" id="erreurIcs"></p>
    <div id="listeIcs"></div>
    <div class="form-boutons">
      <button class="principal" id="btnImporterSelection">Importer la selection</button>
      <button class="secondaire" id="btnAnnulerIcs">Annuler</button>
    </div>
  </div>

  <div id="formCard">
    <div class="champ">
      <label>Date</label>
      <input type="date" id="fDate">
    </div>
    <div class="champ">
      <label>Heure</label>
      <input type="time" id="fHeure">
    </div>
    <div class="champ">
      <label>Pour qui ?</label>
      <div class="personnes" id="personnes">
        <input type="radio" name="personne" value="Papa" id="pPapa">
        <label class="sel-papa" for="pPapa">Papa</label>
        <input type="radio" name="personne" value="Maman" id="pMaman">
        <label class="sel-maman" for="pMaman">Maman</label>
        <input type="radio" name="personne" value="Les deux" id="pDeux">
        <label class="sel-deux" for="pDeux">Les deux</label>
      </div>
    </div>
    <div class="champ">
      <label>Medecin / lieu</label>
      <input type="text" id="fMedecin" placeholder="Dr Martin, cardiologue">
    </div>
    <div class="champ">
      <label>Departement (facultatif)</label>
      <input type="text" id="fDepartement" placeholder="Cardiologie">
    </div>
    <div class="champ">
      <label>Notes (facultatif)</label>
      <input type="text" id="fNotes" placeholder="Apporter les résultats de prise de sang">
    </div>
    <p class="erreur" id="erreurForm"></p>
    <div class="form-boutons">
      <button class="principal" id="btnEnregistrer">Enregistrer</button>
      <button class="secondaire" id="btnAnnuler">Annuler</button>
    </div>
  </div>

  <div id="liste">
    <p class="chargement">Chargement des rendez-vous…</p>
  </div>

  <script src="assets/app.js"></script>
</body>
</html>