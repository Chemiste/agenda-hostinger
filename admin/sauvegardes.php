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

                $stmt = $db->prepare('INSERT INTO appointments (id, appt_date, appt_time, person, doctor, department, location, phone, route, notes, calendar_event_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $id,
                    $ligne['appt_date'],
                    $ligne['appt_time'],
                    $ligne['person'],
                    isset($ligne['doctor']) ? $ligne['doctor'] : '',
                    isset($ligne['department']) ? $ligne['department'] : '',
                    isset($ligne['location']) ? $ligne['location'] : '',
                    isset($ligne['phone']) ? $ligne['phone'] : '',
                    isset($ligne['route']) ? $ligne['route'] : '',
                    isset($ligne['notes']) ? $ligne['notes'] : '',
                    '', // nouvel événement Calendar recréé ci-dessous (l'ancien id est périmé)
                    isset($ligne['created_at']) ? $ligne['created_at'] : date('Y-m-d H:i:s'),
                ]);

                $appt = [
                    'date' => $ligne['appt_date'],
                    'time' => substr($ligne['appt_time'], 0, 5),
                    'person' => $ligne['person'],
                    'doctor' => isset($ligne['doctor']) ? $ligne['doctor'] : '',
                    'department' => isset($ligne['department']) ? $ligne['department'] : '',
                    'location' => isset($ligne['location']) ? $ligne['location'] : '',
                    'phone' => isset($ligne['phone']) ? $ligne['phone'] : '',
                    'route' => isset($ligne['route']) ? $ligne['route'] : '',
                    'notes' => isset($ligne['notes']) ? $ligne['notes'] : '',
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
  .rangee-nett { display:flex; align-items:flex-start; gap:10px; padding:12px 0; border-bottom:1px solid var(--border); }
  .rangee-nett input[type=checkbox] { width:20px; height:20px; margin-top:3px; }
  .rangee-nett .details { flex:1; }
  .rangee-nett .champ-avant { font-size:13px; color:#999; }
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
    <a href="/admin/index.php">Administration</a><span class="sep">/</span><span class="actuel">Sauvegardes</span>
  </div>

  <div class="outil">
    <h2 style="margin-top:0;">Sauvegardes</h2>
    <p class="sous-titre">Une sauvegarde automatique (voir le guide d'installation pour la configurer via un Cron Job Hostinger) exporte tous les rendez-vous chaque jour. En cas de suppression accidentelle, choisissez une sauvegarde d'avant la suppression : les rendez-vous qui y figurent mais qui ont disparu de l'agenda actuel sont proposés à la restauration.</p>

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
            <form method="post">
              <input type="hidden" name="action" value="restaurer_sauvegarde">
              <input type="hidden" name="fichier" value="<?= htmlspecialchars($backupSelectionnee) ?>">
              <p><?= count($rendezVousDisparus) ?> rendez-vous de cette sauvegarde manque(nt) actuellement. Décochez ceux à ne pas restaurer.</p>

              <?php foreach ($rendezVousDisparus as $r): ?>
                <div class="rangee-nett">
                  <input type="checkbox" checked name="selection[]" value="<?= (int) $r['id'] ?>">
                  <div class="details">
                    <div style="font-weight:600;"><?= htmlspecialchars($r['appt_date']) ?> à <?= htmlspecialchars(substr($r['appt_time'], 0, 5)) ?> — <?= htmlspecialchars($r['person']) ?></div>
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
</body>
</html>
