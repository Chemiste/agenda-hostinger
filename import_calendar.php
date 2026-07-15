<?php
/**
 * IMPORT PONCTUEL depuis Google Calendar.
 *
 * A utiliser UNE SEULE FOIS pour recuperer dans le site les rendez-vous
 * deja presents dans le calendrier Google que vous utilisiez avant.
 *
 * Necessite que la synchronisation Google Calendar soit deja configuree
 * dans config.php (google_calendar_id + service-account.json), voir le
 * guide d'installation.
 *
 * Une fois l'import termine et verifie, supprimez ou renommez ce fichier
 * du serveur (comme generate_password.php) : le relancer par erreur ne
 * cree pas de doublons (les evenements deja importes sont detectes via
 * leur identifiant Google Calendar), mais il n'a plus de raison de
 * rester accessible en ligne.
 */

require_once __DIR__ . '/lib/auth.php';
requireLogin();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/calendar_sync.php';

$config = require __DIR__ . '/config.php';
$sync = new CalendarSync($config['google_service_account_path'], $config['google_calendar_id']);
$db = getDb();

$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';
// Un rendez-vous ne concerne jamais qu'une seule personne (pas de "les deux").
$PERSONNES_VALIDES = [$p1, $p2];

$erreur = '';
$evenements = [];
$resultatImport = null;

if (!$sync->isEnabled()) {
    $erreur = "La synchronisation Google Calendar n'est pas configuree (google_calendar_id / service-account.json dans config.php). Configurez-la d'abord (voir le guide), meme si vous ne voulez pas garder la synchro active ensuite.";
}

$dateDebut = isset($_POST['date_debut']) ? $_POST['date_debut'] : date('Y-m-d', strtotime('-3 months'));
$dateFin = isset($_POST['date_fin']) ? $_POST['date_fin'] : date('Y-m-d', strtotime('+1 year'));

// Reconnait un prefixe generique "[Quelque chose] " en debut de titre (c'est
// le format utilise par la synchronisation du site, quels que soient les
// noms configures). Si aucun prefixe n'est trouve, on retombe sur la
// premiere personne configuree, a corriger manuellement dans l'apercu si
// besoin (un rendez-vous ne concerne toujours qu'une seule personne).
function prefixeVersPersonne($summary, $labelParDefaut) {
    if (preg_match('/^\[(.+?)\]\s*/', $summary, $m)) {
        $reste = preg_replace('/^\[(.+?)\]\s*/', '', $summary, 1);
        return [$reste, $m[1]];
    }
    return [$summary, $labelParDefaut];
}

function convertirEvenementGoogle($event, $labelParDefaut) {
    $summaryBrut = isset($event['summary']) ? $event['summary'] : 'Rendez-vous';
    list($summary, $personne) = prefixeVersPersonne($summaryBrut, $labelParDefaut);

    $toutelaJournee = isset($event['start']['date']);
    if ($toutelaJournee) {
        $date = $event['start']['date'];
        $heure = '09:00';
    } else {
        $dt = new DateTime($event['start']['dateTime']);
        $dt->setTimezone(new DateTimeZone('Europe/Paris'));
        $date = $dt->format('Y-m-d');
        $heure = $dt->format('H:i');
    }

    return [
        'googleEventId' => isset($event['id']) ? $event['id'] : '',
        'summary' => $summary,
        'location' => isset($event['location']) ? $event['location'] : '',
        'description' => isset($event['description']) ? $event['description'] : '',
        'date' => $date,
        'time' => $heure,
        'person' => $personne,
        'toutelaJournee' => $toutelaJournee,
    ];
}

function dejaImporte($db, $googleEventId) {
    if (!$googleEventId) return false;
    $stmt = $db->prepare('SELECT id FROM appointments WHERE calendar_event_id = ? LIMIT 1');
    $stmt->execute([$googleEventId]);
    return (bool) $stmt->fetch();
}

function importerLigne($db, $item) {
    global $PERSONNES_VALIDES;
    if (empty($item['date']) || empty($item['time']) || empty($item['person']) || !in_array($item['person'], $PERSONNES_VALIDES, true)) {
        throw new Exception('Donnee invalide pour un des rendez-vous selectionnes.');
    }
    if (dejaImporte($db, isset($item['googleEventId']) ? $item['googleEventId'] : '')) {
        return false;
    }
    $doctor = trim($item['summary']);
    $location = isset($item['location']) ? $item['location'] : '';
    $stmt = $db->prepare('INSERT INTO appointments (appt_date, appt_time, person, doctor, location, notes, calendar_event_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $item['date'],
        $item['time'],
        $item['person'],
        $doctor,
        $location,
        isset($item['description']) ? $item['description'] : '',
        isset($item['googleEventId']) ? $item['googleEventId'] : '',
    ]);
    return true;
}

if (!$erreur && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'charger') {
    try {
        $timeMin = $dateDebut . 'T00:00:00Z';
        $timeMax = $dateFin . 'T23:59:59Z';
        $bruts = $sync->listEvents($timeMin, $timeMax);
        $evenements = array_map(function ($event) use ($p1) {
            return convertirEvenementGoogle($event, $p1);
        }, $bruts);
    } catch (Exception $e) {
        $erreur = $e->getMessage();
    }
}

if (!$erreur && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'importer') {
    try {
        $selection = json_decode(isset($_POST['selection']) ? $_POST['selection'] : '[]', true);
        if (!is_array($selection)) $selection = [];
        $importes = 0;
        $ignores = 0;
        foreach ($selection as $item) {
            if (importerLigne($db, $item)) {
                $importes++;
            } else {
                $ignores++;
            }
        }
        $resultatImport = ['importes' => $importes, 'ignores' => $ignores];
    } catch (Exception $e) {
        $erreur = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Import depuis Google Calendar</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  .rangee-evt { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--border); }
  .rangee-evt input[type=checkbox] { width:20px; height:20px; }
  .rangee-evt .details { flex:1; }
  .rangee-evt select { font-size:15px; padding:8px; border-radius:8px; border:2px solid var(--border); }
</style>
</head>
<body>
  <h1>Import ponctuel depuis Google Calendar</h1>
  <p class="sous-titre">A utiliser une seule fois, puis supprimez ce fichier du serveur.</p>

  <?php if ($erreur): ?>
    <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
  <?php endif; ?>

  <?php if ($resultatImport !== null): ?>
    <p class="info">
      <?= (int) $resultatImport['importes'] ?> rendez-vous importe(s).
      <?php if ($resultatImport['ignores'] > 0): ?>
        <?= (int) $resultatImport['ignores'] ?> ignore(s) (deja importes precedemment).
      <?php endif; ?>
    </p>
    <p><a href="index.php">Voir l'agenda</a> · <a href="import_calendar.php">Relancer un import</a> (sur une autre periode par exemple)</p>

  <?php else: ?>

    <form method="post" style="margin-bottom:24px;">
      <input type="hidden" name="action" value="charger">
      <div class="champ">
        <label>Du</label>
        <input type="date" name="date_debut" value="<?= htmlspecialchars($dateDebut) ?>">
      </div>
      <div class="champ">
        <label>Au</label>
        <input type="date" name="date_fin" value="<?= htmlspecialchars($dateFin) ?>">
      </div>
      <button class="principal" type="submit">Charger les evenements de cette periode</button>
    </form>

    <?php if (!empty($evenements)): ?>
      <form method="post" id="formImport">
        <input type="hidden" name="action" value="importer">
        <input type="hidden" name="selection" id="selectionChamp">

        <p><?= count($evenements) ?> evenement(s) trouve(s). Decochez ceux a ne pas importer, et corrigez la personne si le calendrier ne le precisait pas.</p>

        <div id="listeEvenements">
          <?php foreach ($evenements as $i => $e): ?>
            <div class="rangee-evt">
              <input type="checkbox" checked class="evt-checked" data-idx="<?= $i ?>">
              <div class="details">
                <div style="font-weight:600;"><?= htmlspecialchars($e['summary']) ?></div>
                <?php if ($e['location']): ?>
                  <div style="font-size:13px; color:#999;"><?= htmlspecialchars($e['location']) ?></div>
                <?php endif; ?>
                <div style="font-size:14px; color:#777;">
                  <?= htmlspecialchars($e['date']) ?> a <?= htmlspecialchars($e['time']) ?>
                  <?= $e['toutelaJournee'] ? ' (toute la journee, heure a verifier)' : '' ?>
                </div>
              </div>
              <select class="evt-person" data-idx="<?= $i ?>">
                <option value="<?= htmlspecialchars($p1) ?>" <?= $e['person'] === $p1 ? 'selected' : '' ?>><?= htmlspecialchars($p1) ?></option>
                <option value="<?= htmlspecialchars($p2) ?>" <?= $e['person'] === $p2 ? 'selected' : '' ?>><?= htmlspecialchars($p2) ?></option>
              </select>
              <input type="hidden" class="evt-data" data-idx="<?= $i ?>" value='<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>'>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-boutons" style="margin-top:16px;">
          <button class="principal" type="submit" id="btnImporter">Importer la selection</button>
        </div>
      </form>

      <script>
        document.getElementById('formImport').addEventListener('submit', function (e) {
          var selection = [];
          document.querySelectorAll('.evt-checked:checked').forEach(function (c) {
            var idx = c.dataset.idx;
            var data = JSON.parse(document.querySelector('.evt-data[data-idx="' + idx + '"]').value);
            var personne = document.querySelector('.evt-person[data-idx="' + idx + '"]').value;
            data.person = personne;
            selection.push(data);
          });
          document.getElementById('selectionChamp').value = JSON.stringify(selection);
        });
      </script>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <p class="vide">Aucun evenement trouve sur cette periode.</p>
    <?php endif; ?>

  <?php endif; ?>

  <p style="margin-top:2rem;"><a href="index.php">Retour a l'agenda</a></p>
</body>
</html>