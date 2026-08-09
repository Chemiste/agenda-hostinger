<?php
/**
 * OUTIL DEV UNIQUEMENT : importe un export de production (voir
 * admin/exporter_donnees.php) dans la base de développement locale.
 *
 * Remplace INTÉGRALEMENT le contenu actuel de la table "appointments" par
 * celui du fichier importé (pas une fusion) : pratique pour retrouver des
 * données réalistes en local après avoir vidé/testé sur la base de dev.
 *
 * Protégé par un garde-fou strict : refuse de s'exécuter si la base
 * connectée n'est pas explicitement nommée "agenda_dev" (voir
 * Guide_dev_local_et_versions.md, qui recommande ce nom pour la base
 * locale). Sans ce garde-fou, exécuter cet outil par erreur en production
 * effacerait tous les vrais rendez-vous.
 *
 * calendar_event_id est systématiquement remis à vide à l'import : les
 * identifiants d'événements Google Calendar de la sauvegarde appartiennent
 * au calendrier de production, pas au calendrier (généralement désactivé)
 * de la base de dev — les garder tels quels n'aurait aucun sens ici.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';

$config = require __DIR__ . '/../config.php';

// --- Garde-fou : jamais en dehors d'une base de dev ---
$estEnvironnementDev = isset($config['db_name']) && $config['db_name'] === 'agenda_dev';

$erreur = '';
$resultat = null;

if ($estEnvironnementDev && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirmer'])) {
    if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        $erreur = "Aucun fichier reçu (ou erreur d'upload). Réessayez.";
    } else {
        $contenu = file_get_contents($_FILES['fichier']['tmp_name']);
        $lignes = json_decode($contenu, true);
        if (!is_array($lignes)) {
            $erreur = "Ce fichier n'est pas un export JSON valide (voir admin/exporter_donnees.php sur le site de production).";
        } else {
            try {
                $db = getDb();
                $db->beginTransaction();
                $db->exec('DELETE FROM appointments');

                $stmt = $db->prepare(
                    'INSERT INTO appointments
                        (id, appt_date, appt_time, duration_minutes, person, doctor, department, location, phone, route, accompagnant, notes, reminder_sent_at, calendar_event_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($lignes as $l) {
                    $duree = (!empty($l['duration_minutes']) && (int) $l['duration_minutes'] > 0) ? (int) $l['duration_minutes'] : 30;
                    $stmt->execute([
                        (int) $l['id'],
                        $l['appt_date'],
                        $l['appt_time'],
                        $duree,
                        $l['person'],
                        $l['doctor'] ?? '',
                        $l['department'] ?? '',
                        $l['location'] ?? '',
                        $l['phone'] ?? '',
                        $l['route'] ?? '',
                        $l['accompagnant'] ?? '',
                        $l['notes'] ?? '',
                        $l['reminder_sent_at'] ?? null,
                        '', // calendar_event_id : toujours vide, voir note en tete de fichier
                        $l['created_at'] ?? date('Y-m-d H:i:s'),
                    ]);
                }

                $db->commit();
                $resultat = count($lignes);
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $erreur = 'Erreur pendant l\'import : ' . $e->getMessage();
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
<title>Importer les données de prod (dev) - Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
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
    <a href="/admin/index.php">Administration</a><span class="sep">/</span><span class="actuel">Importer un export</span>
  </div>

  <div class="outil">
    <h2 class="panneau-titre">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
      Importer les données de production en local
    </h2>

    <?php if (!$estEnvironnementDev): ?>
      <p class="erreur">
        Cet outil est bloqué : la base connectée (<code><?= htmlspecialchars($config['db_name'] ?? '?') ?></code>) n'est pas
        la base de développement locale (<code>agenda_dev</code>). Par sécurité, il ne peut s'exécuter que si
        <code>config.php</code> pointe explicitement vers <code>agenda_dev</code>, pour éviter d'écraser des vraies
        données par erreur.
      </p>
    <?php elseif ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <?php if ($resultat !== null): ?>
      <p class="info"><?= (int) $resultat ?> rendez-vous importés. La base de dev reflète maintenant l'export.</p>
      <p><a href="/admin/index.php">Retour à l'administration</a></p>
    <?php elseif ($estEnvironnementDev): ?>
      <p class="sous-titre">
        Remplace tous les rendez-vous de la base de dev actuelle par le contenu d'un export
        (généré depuis <code>admin/exporter_donnees.php</code> sur le site de production).
        Les identifiants d'événements Google Calendar ne sont pas conservés.
      </p>
      <form method="post" enctype="multipart/form-data" data-confirm="Remplacer toutes les données de la base de dev par le contenu de ce fichier ? Ce n'est pas une fusion : les rendez-vous actuels de la base de dev seront perdus.">
        <div class="champ">
          <label>Fichier d'export (.json)</label>
          <input type="file" name="fichier" accept=".json" required>
        </div>
        <p class="alerte-champ">Ceci remplace toutes les données actuelles de la base de dev — pas de fusion.</p>
        <input type="hidden" name="confirmer" value="1">
        <button class="principal" type="submit">Importer (remplace tout)</button>
      </form>
    <?php endif; ?>
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
