<?php
/**
 * ADMINISTRATION : import d'un fichier .ics.
 *
 * Importe des rendez-vous depuis un fichier .ics exporté d'un autre
 * agenda (Google Calendar, Outlook, etc.). Le fichier est lu et analysé
 * côté navigateur (voir assets/admin.js) ; seuls les rendez-vous
 * sélectionnés sont envoyés au serveur (api.php, action bulk_add), qui
 * se charge de la synchronisation Google Calendar comme pour un ajout
 * normal.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();

$config = require __DIR__ . '/../config.php';
$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Importer un fichier .ics — Administration</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .barre-admin { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:8px; }
  .barre-admin a { font-size:13px; color:var(--text-muted); }
  .fil-admin { font-size:13px; color:var(--text-muted); margin-bottom:18px; }
  .fil-admin a { color:var(--text-muted); text-decoration:none; }
  .fil-admin a:hover { text-decoration:underline; }
  .fil-admin .sep { margin:0 4px; }
  .fil-admin .actuel { color:var(--text); font-weight:600; }
  .outil { background:#fff; border-radius:12px; padding:18px; box-shadow: var(--shadow-sm); }
</style>
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
    <a href="/admin/index.php">Administration</a><span class="sep">/</span><span class="actuel">Importer un fichier .ics</span>
  </div>

  <div class="outil">
    <h2 style="margin-top:0;">Importer un fichier .ics</h2>
    <p class="sous-titre">Importe des rendez-vous depuis un fichier .ics exporté d'un autre agenda (Google Calendar, Outlook, etc.).</p>
    <button class="secondaire" id="btnImportIcs">Choisir un fichier .ics</button>
    <input type="file" id="fichierIcs" accept=".ics,text/calendar" style="display:none;">
  </div>

  <div class="overlay" id="overlay"></div>

  <div id="icsCard" class="modal">
    <div class="modal-corps">
      <h2>Rendez-vous trouvés dans le fichier</h2>
      <p class="erreur" id="erreurIcs"></p>
      <div id="listeIcs"></div>
    </div>
    <div class="form-boutons">
      <button class="principal" id="btnImporterSelection">Importer la sélection</button>
      <button class="secondaire" id="btnAnnulerIcs">Annuler</button>
    </div>
  </div>

  <script>
    window.PERSONNE_1 = <?= json_encode($p1) ?>;
    window.PERSONNE_2 = <?= json_encode($p2) ?>;
  </script>
  <script src="/assets/admin.js"></script>
</body>
</html>
