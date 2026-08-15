<?php
/**
 * ADMINISTRATION : sauvegardes.
 *
 * Une sauvegarde automatique (voir cron/backup.php et le guide
 * d'installation pour la configurer via un Cron Job Hostinger) exporte
 * tous les rendez-vous chaque jour. En cas de suppression accidentelle,
 * choisissez une sauvegarde d'avant la suppression : les rendez-vous qui
 * y figurent mais qui ont disparu de l'agenda actuel sont proposés à la
 * restauration.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calendar_sync.php';
require_once __DIR__ . '/../lib/persons.php';
require_once __DIR__ . '/../lib/entete_admin.php';

$config = require __DIR__ . '/../config.php';
$sync = new CalendarSync($config['google_service_account_path'], $config['google_calendar_id']);
$db = getDb();

$dossierBackups = __DIR__ . '/../backups';
$fichiersBackup = [];
if (is_dir($dossierBackups)) {
    $fichiersBackup = glob($dossierBackups . '/appointments-*.json');
    rsort($fichiersBackup); // noms horodates -> tri alphabétique = tri chronologique
}

function nomBackupValide($nom) {
    return preg_match('/^appointments-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}\.json$/', $nom) === 1;
}

// Sauvegarde choisie (menu déroulant, requête GET en lecture seule) :
// on calcule les rendez-vous présents dans cette sauvegarde mais absents
// de la base actuelle (candidats à une restauration).
$backupSelectionnee = isset($_GET['sauvegarde']) ? basename($_GET['sauvegarde']) : '';
$rendezVousDisparus = [];
$erreurBackup = '';
if ($backupSelectionnee !== '') {
    $cheminBackup = $dossierBackups . '/' . $backupSelectionnee;
    if (!nomBackupValide($backupSelectionnee) || !file_exists($cheminBackup)) {
        $erreurBackup = 'Sauvegarde introuvable.';
    } else {
        $donneesBackup = json_decode(file_get_contents($cheminBackup), true);
        if (!is_array($donneesBackup)) {
            $erreurBackup = 'Ce fichier de sauvegarde est illisible.';
        } else {
            $idsActuels = array_map('intval', array_column($db->query('SELECT id FROM appointments')->fetchAll(), 'id'));
            foreach ($donneesBackup as $ligne) {
                if (!in_array((int) $ligne['id'], $idsActuels, true)) {
                    $rendezVousDisparus[] = $ligne;
                }
            }
            // Les plus récents en premier (plus probable que ce soit ce qu'on cherche).
            usort($rendezVousDisparus, function ($a, $b) {
                return strcmp($b['appt_date'] . $b['appt_time'], $a['appt_date'] . $a['appt_time']);
            });
        }
    }
}

// Restauration effective (création en base + recréation de l'événement
// Google Calendar si la synchro est active) des rendez-vous cochés.
$nbRestaures = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restaurer_sauvegarde') {
    $nomFichier = basename($_POST['fichier'] ?? '');
    $cheminBackup = $dossierBackups . '/' . $nomFichier;
    $idsARestaurer = isset($_POST['selection']) ? array_map('intval', (array) $_POST['selection']) : [];
    $nbRestaures = 0;

    if (nomBackupValide($nomFichier) && file_exists($cheminBackup) && !empty($idsARestaurer)) {
        $donneesBackup = json_decode(file_get_contents($cheminBackup), true);
        if (is_array($donneesBackup)) {
            $parId = [];
            foreach ($donneesBackup as $ligne) {
                $parId[(int) $ligne['id']] = $ligne;
            }

            foreach ($idsARestaurer as $id) {
                if (!isset($parId[$id])) continue;
                $ligne = $parId[$id];

                // Par sécurité (ex: double clic, ou id déjà repris entre
                // temps par un autre rendez-vous) : on ne restaure pas si
                // cet id existe déjà dans la base.
                $existe = $db->prepare('SELECT COUNT(*) FROM appointments WHERE id = ?');
                $existe->execute([$id]);
                if ((int) $existe->fetchColumn() > 0) continue;

                // duration_minutes n'existe pas dans les sauvegardes faites
                // avant l'ajout de ce champ : on retombe alors sur 30 (meme
                // valeur par defaut que la colonne en base).
                $dureeRestauree = (!empty($ligne['duration_minutes']) && (int) $ligne['duration_minutes'] > 0)
                    ? (int) $ligne['duration_minutes'] : 30;

                // Les sauvegardes faites avant la migration 0021 ne
                // contiennent qu'un NOM : on retrouve l'identifiant
                // correspondant. S'il n'existe plus (personne supprimee),
                // person_id reste a 0 et le rendez-vous sera visible mais
                // sans personne rattachee - plutot que de le rattacher au
                // hasard, ou de refuser la restauration.
                $personIdLigne = isset($ligne['person_id']) ? (int) $ligne['person_id'] : 0;
                if ($personIdLigne === 0 && !empty($ligne['person'])) {
                    $patientLigne = personParNom($db, $ligne['person']);
                    if ($patientLigne !== null) {
                        $personIdLigne = $patientLigne['id'];
                    }
                }

                $stmt = $db->prepare('INSERT INTO appointments (id, appt_date, appt_time, duration_minutes, person, person_id, doctor, department, location, phone, route, accompagnant, notes, questions, pathologie_id, calendar_event_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $id,
                    $ligne['appt_date'],
                    $ligne['appt_time'],
                    $dureeRestauree,
                    $ligne['person'],
                    $personIdLigne,
                    isset($ligne['doctor']) ? $ligne['doctor'] : '',
                    isset($ligne['department']) ? $ligne['department'] : '',
                    isset($ligne['location']) ? $ligne['location'] : '',
                    isset($ligne['phone']) ? $ligne['phone'] : '',
                    isset($ligne['route']) ? $ligne['route'] : '',
                    isset($ligne['accompagnant']) ? $ligne['accompagnant'] : '',
                    isset($ligne['notes']) ? $ligne['notes'] : '',
                    isset($ligne['questions']) ? $ligne['questions'] : '',
                    isset($ligne['pathologie_id']) ? (int) $ligne['pathologie_id'] : 0,
                    '', // nouvel événement Calendar recréé ci-dessous (l'ancien id est périmé)
                    isset($ligne['created_at']) ? $ligne['created_at'] : date('Y-m-d H:i:s'),
                ]);

                $appt = [
                    'date' => $ligne['appt_date'],
                    'time' => substr($ligne['appt_time'], 0, 5),
                    'duration' => $dureeRestauree,
                    'person' => $ligne['person'],
                    'doctor' => isset($ligne['doctor']) ? $ligne['doctor'] : '',
                    'department' => isset($ligne['department']) ? $ligne['department'] : '',
                    'location' => isset($ligne['location']) ? $ligne['location'] : '',
                    'phone' => isset($ligne['phone']) ? $ligne['phone'] : '',
                    'route' => isset($ligne['route']) ? $ligne['route'] : '',
                    'accompagnant' => isset($ligne['accompagnant']) ? $ligne['accompagnant'] : '',
                    'notes' => isset($ligne['notes']) ? $ligne['notes'] : '',
                    'questions' => isset($ligne['questions']) ? $ligne['questions'] : '',
                ];
                $nouvelId = $sync->createEvent($appt);
                if ($nouvelId) {
                    $upd = $db->prepare('UPDATE appointments SET calendar_event_id = ? WHERE id = ?');
                    $upd->execute([$nouvelId, $id]);
                }

                $nbRestaures++;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sauvegardes — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
  <?php afficherEnteteAdmin(
      'Sauvegardes',
      "Une sauvegarde automatique (voir le guide d'installation pour la configurer via un Cron Job Hostinger) exporte "
      . "tous les rendez-vous chaque jour. En cas de suppression accidentelle, choisissez une sauvegarde d'avant la "
      . "suppression : les rendez-vous qui y figurent mais qui ont disparu de l'agenda actuel sont proposés à la restauration."
  ); ?>

  <div class="outil">
    <?php if (empty($fichiersBackup)): ?>
      <p class="vide">Aucune sauvegarde trouvée pour l'instant. Vérifiez que le Cron Job de sauvegarde est bien configuré (voir le guide d'installation).</p>
    <?php else: ?>

      <?php if ($nbRestaures !== null): ?>
        <p class="info">
          <?= (int) $nbRestaures ?> rendez-vous restauré(s)<?= $nbRestaures > 0 ? ' (et resynchronisé(s) avec Google Calendar si activé)' : '' ?>.
        </p>
        <p><a href="/admin/sauvegardes.php">Retour aux sauvegardes</a></p>
      <?php else: ?>

        <form method="get" style="margin-bottom:16px;">
          <div class="champ">
            <label for="sauvegarde">Choisir une sauvegarde</label>
            <select name="sauvegarde" id="sauvegarde" onchange="this.form.submit()" style="width:100%; font-size:16px; padding:12px; border-radius:8px; border:1.5px solid var(--border);">
              <option value="">— Sélectionner une date —</option>
              <?php foreach ($fichiersBackup as $chemin):
                $nom = basename($chemin);
                $horodatage = preg_replace('/^appointments-([0-9]{4})-([0-9]{2})-([0-9]{2})-([0-9]{2})([0-9]{2})\.json$/', '$3/$2/$1 à $4:$5', $nom);
              ?>
                <option value="<?= htmlspecialchars($nom) ?>" <?= $nom === $backupSelectionnee ? 'selected' : '' ?>><?= htmlspecialchars($horodatage) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>

        <?php if ($erreurBackup): ?>
          <p class="erreur"><?= htmlspecialchars($erreurBackup) ?></p>
        <?php elseif ($backupSelectionnee !== ''): ?>
          <?php if (empty($rendezVousDisparus)): ?>
            <p class="vide">Aucun rendez-vous de cette sauvegarde ne manque dans l'agenda actuel.</p>
          <?php else: ?>
            <form method="post" data-confirm="Restaurer les rendez-vous cochés ? Ils seront recréés dans l'agenda et resynchronisés avec Google Calendar si activé.">
              <input type="hidden" name="action" value="restaurer_sauvegarde">
              <input type="hidden" name="fichier" value="<?= htmlspecialchars($backupSelectionnee) ?>">

              <div class="barre-selection">
                <span class="compte-resultats"><?= count($rendezVousDisparus) ?> rendez-vous manquant(s) dans cette sauvegarde</span>
                <span class="actions-selection">
                  <button type="button" class="lien-select" data-select="all">Tout cocher</button>
                  <span class="sep">·</span>
                  <button type="button" class="lien-select" data-select="none">Tout décocher</button>
                </span>
              </div>

              <?php foreach ($rendezVousDisparus as $r): ?>
                <div class="rangee-nett">
                  <input type="checkbox" checked name="selection[]" value="<?= (int) $r['id'] ?>">
                  <div class="details">
                    <div class="rdv-quand"><?= htmlspecialchars($r['appt_date']) ?> à <?= htmlspecialchars(substr($r['appt_time'], 0, 5)) ?> — <?= htmlspecialchars($r['person']) ?></div>
                    <div class="champ-avant"><?= htmlspecialchars(isset($r['doctor']) ? $r['doctor'] : '') ?></div>
                    <?php if (!empty($r['department'])): ?>
                      <div class="champ-avant"><?= htmlspecialchars($r['department']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['location'])): ?>
                      <div class="champ-avant"><?= htmlspecialchars($r['location']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>

              <div class="form-boutons" style="margin-top:16px;">
                <button class="principal" type="submit">Restaurer la sélection</button>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>

      <?php endif; ?>
    <?php endif; ?>
  </div>

  <script>
    document.querySelectorAll('.lien-select').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('form');
        var coche = btn.dataset.select === 'all';
        form.querySelectorAll('input[type=checkbox][name="selection[]"]').forEach(function (cb) { cb.checked = coche; });
      });
    });
  </script>
  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
