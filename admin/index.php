<?php
/**
 * ADMINISTRATION : accueil.
 *
 * Point d'entree de la zone d'administration (protegee par le mot de
 * passe admin, voir requireAdminLogin()) : regroupe les outils par theme
 * plutot que de tout empiler sur une seule page.
 *
 *  - Rendez-vous : import .ics (import.php), correction de rendez-vous
 *    existants (corriger.php - regroupe 3 outils sous forme d'onglets).
 *  - Sauvegardes : consultation et restauration (sauvegardes.php).
 *  - Notifications : reglages des rappels par email (reglages.php).
 *
 * C'est cette page (et non plus admin/nettoyage.php, qui n'existe plus)
 * qu'il faut garder en favori pour acceder directement a l'administration.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';

$config = require __DIR__ . '/../config.php';
$estEnvironnementDev = isset($config['db_name']) && $config['db_name'] === 'agenda_dev';

$db = getDb();

$nbAVenir = (int) $db->query('SELECT COUNT(*) FROM appointments WHERE TIMESTAMP(appt_date, appt_time) >= NOW()')->fetchColumn();

function formaterDateRelativeAdmin($timestamp) {
    $aujourdhui = strtotime('today');
    $jourFichier = strtotime(date('Y-m-d', $timestamp));
    $diffJours = (int) round(($aujourdhui - $jourFichier) / 86400);
    $heure = date('H:i', $timestamp);
    if ($diffJours === 0) return "Aujourd'hui, " . $heure;
    if ($diffJours === 1) return 'Hier, ' . $heure;
    if ($diffJours > 1 && $diffJours < 7) return 'Il y a ' . $diffJours . ' jours';
    return date('d/m/Y', $timestamp);
}

$dossierBackups = __DIR__ . '/../backups';
$fichiersBackup = is_dir($dossierBackups) ? glob($dossierBackups . '/appointments-*.json') : [];
$nbBackups = count($fichiersBackup);
$dernierBackupTexte = 'Aucune';
if ($nbBackups > 0) {
    $mtimes = array_map('filemtime', $fichiersBackup);
    $dernierBackupTexte = formaterDateRelativeAdmin(max($mtimes));
}

$reminderEnabled = getSetting($db, 'reminder_enabled', '0') === '1';
$reminderDelai = getSetting($db, 'reminder_hours_before', '24');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
<style>
  .barre-admin { margin-bottom:20px; }
</style>
</head>
<body>
  <div class="barre-admin">
    <h1 style="margin:0;">Administration</h1>
    <div>
      <a href="/index.php">Retour à l'agenda</a>
      &nbsp;·&nbsp;
      <a href="/admin/logout.php">Déconnexion admin</a>
    </div>
  </div>

  <div class="stats-rangee">
    <div class="stat">
      <div class="label">Rendez-vous à venir</div>
      <div class="valeur"><?= $nbAVenir ?></div>
    </div>
    <div class="stat">
      <div class="label">Dernière sauvegarde</div>
      <div class="valeur"><?= htmlspecialchars($dernierBackupTexte) ?></div>
    </div>
  </div>

  <div class="groupe-titre">Rendez-vous</div>
  <div class="grille-cartes">
    <a class="carte-accueil carte-rdv" href="/admin/import.php">
      <div class="titre">Importer un fichier .ics</div>
      <div class="detail">Depuis un autre agenda</div>
    </a>
    <a class="carte-accueil carte-rdv" href="/admin/corriger.php">
      <div class="titre">Corriger des rendez-vous</div>
      <div class="detail">3 outils de nettoyage</div>
    </a>
  </div>

  <div class="groupe-titre">Affichage</div>
  <a class="carte-accueil carte-large carte-adresse" href="/admin/alias_adresses.php">
    <div>
      <div class="titre">Alias d'adresses</div>
      <div class="detail">Simplifier l'affichage sans toucher au calendrier</div>
    </div>
    <span class="fleche">›</span>
  </a>

  <div class="groupe-titre">Sauvegardes</div>
  <a class="carte-accueil carte-large carte-backup" href="/admin/sauvegardes.php">
    <div>
      <div class="titre">Restaurer un rendez-vous</div>
      <div class="detail"><?= $nbBackups ?> sauvegarde<?= $nbBackups > 1 ? 's' : '' ?> disponible<?= $nbBackups > 1 ? 's' : '' ?></div>
    </div>
    <span class="fleche">›</span>
  </a>

  <div class="groupe-titre">Données de développement</div>
  <div class="grille-cartes">
    <a class="carte-accueil carte-dev" href="/admin/exporter_donnees.php">
      <div class="titre">Exporter les données</div>
      <div class="detail">Instantané JSON à jour</div>
    </a>
    <?php if ($estEnvironnementDev): ?>
      <a class="carte-accueil carte-dev" href="/outils/importer_donnees_dev.php">
        <div class="titre">Importer un export</div>
        <div class="detail">Remplace la base de dev</div>
      </a>
    <?php endif; ?>
  </div>

  <div class="groupe-titre">Notifications</div>
  <a class="carte-accueil carte-large carte-notif" href="/admin/reglages.php">
    <div>
      <div class="titre">Réglages des rappels par email</div>
      <div class="detail"><?= $reminderEnabled ? 'Activés · délai ' . htmlspecialchars($reminderDelai) . 'h' : 'Désactivés' ?></div>
    </div>
    <span class="fleche">›</span>
  </a>
</body>
</html>
