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
require_once __DIR__ . '/../lib/entete_admin.php';

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/persons.php';

// Le menu deroulant "qui ?" de chaque ligne importee est engendre a partir
// de la table persons : deux noms ecrits en dur ignoraient une eventuelle
// troisieme personne.
$patients = listerPatients(getDb());
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Importer un fichier .ics — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
  <?php afficherEnteteAdmin(
      'Importer un fichier .ics',
      "Importe des rendez-vous depuis un fichier .ics exporté d'un autre agenda (Google Calendar, Outlook, etc.)."
  ); ?>

  <div class="outil">
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
    window.PATIENTS = <?= json_encode(array_map(function ($p) {
        return ['id' => (int) $p['id'], 'nom' => $p['nom']];
    }, array_values($patients)), JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="/assets/admin.js?v=<?= filemtime(__DIR__ . '/../assets/admin.js') ?>"></script>
</body>
</html>
