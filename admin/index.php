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
<link rel="stylesheet" href="/assets/style.css">
<style>
  .barre-admin { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:8px; }
  .barre-admin a { font-size:13px; color:var(--text-muted); }
  .stats-rangee { display:flex; gap:10px; margin-bottom:22px; }
  .stat { flex:1; background:var(--surface); border-radius:var(--radius-sm); padding:10px 14px; box-shadow:var(--shadow-sm); }
  .stat .label { font-size:12px; color:var(--text-muted); margin-bottom:2px; }
  .stat .valeur { font-size:20px; font-weight:700; color:var(--text); }
  .groupe-titre { font-size:13px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.03em; margin:22px 2px 10px; }
  .groupe-titre:first-of-type { margin-top:4px; }
  .grille-cartes { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .carte-accueil { display:block; text-decoration:none; border-radius:var(--radius-md); padding:16px; box-shadow:var(--shadow-sm); transition:transform var(--dur) var(--ease), box-shadow var(--dur) var(--ease); }
  .carte-accueil:hover { box-shadow:var(--shadow-md); }
  .carte-accueil:active { transform:scale(0.98); }
  .carte-accueil .titre { font-size:15px; font-weight:700; margin-top:2px; }
  .carte-accueil .detail { font-size:13px; margin-top:3px; }
  .carte-large { grid-column:1 / -1; display:flex; align-items:center; justify-content:space-between; }
  .carte-large .fleche { font-size:18px; }
  .carte-rdv { background:#efeafd; }
  .carte-rdv .titre { color:#4c1d95; }
  .carte-rdv .detail { color:#6d28d9; }
  .carte-backup { background:#e6f7f1; }
  .carte-backup .titre { color:#065f46; }
  .carte-backup .detail { color:#0f766e; }
  .carte-notif { background:#fdf1ea; }
  .carte-notif .titre { color:#7c2d12; }
  .carte-notif .detail { color:#b45309; }
  @media (max-width:480px) { .grille-cartes { grid-template-columns:1fr; } }
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

  <div class="groupe-titre">Sauvegardes</div>
  <a class="carte-accueil carte-large carte-backup" href="/admin/sauvegardes.php">
    <div>
      <div class="titre">Restaurer un rendez-vous</div>
      <div class="detail"><?= $nbBackups ?> sauvegarde<?= $nbBackups > 1 ? 's' : '' ?> disponible<?= $nbBackups > 1 ? 's' : '' ?></div>
    </div>
    <span class="fleche">›</span>
  </a>

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
