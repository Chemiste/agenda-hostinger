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
require_once __DIR__ . '/../lib/entete_admin.php';

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
</head>
<body>
  <?php afficherEnteteAdmin('Administration', '', true); ?>

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

  <?php /* Toutes les tuiles vivent dans la meme grille, aucune ne prend
           toute la largeur : avec une tuile par ligne, chaque outil coutait
           une ligne entiere et la page s'etirait sur pres de deux ecrans.
           Les rubriques sont aussi regroupees - "Alias d'adresses" concerne
           l'affichage des rendez-vous, et le journal d'activite va de pair
           avec les sauvegardes. */ ?>
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
    <a class="carte-accueil carte-rdv" href="/admin/alias_adresses.php">
      <div class="titre">Alias d'adresses</div>
      <div class="detail">Simplifier l'affichage</div>
    </a>
  </div>

  <?php /* La saisie du plan de medicaments vit ici et non plus sur la page
           familiale : elle n'est le travail que de Laurent, et l'ecran que
           consultent Michel et Christiane doit rester une simple fiche a
           lire (voir /medicaments.php). */ ?>
  <div class="groupe-titre">Santé</div>
  <div class="grille-cartes">
    <a class="carte-accueil carte-adresse" href="/admin/medicaments.php">
      <div class="titre">Plan de médicaments</div>
      <div class="detail">Médicaments, quantités et moments</div>
    </a>
  </div>

  <div class="groupe-titre">Suivi</div>
  <div class="grille-cartes">
    <a class="carte-accueil carte-backup" href="/admin/historique.php">
      <div class="titre">Journal d'activité</div>
      <div class="detail">Connexions et modifications</div>
    </a>
    <a class="carte-accueil carte-backup" href="/admin/sauvegardes.php">
      <div class="titre">Restaurer un rendez-vous</div>
      <div class="detail"><?= $nbBackups ?> sauvegarde<?= $nbBackups > 1 ? 's' : '' ?></div>
    </a>
  </div>

  <div class="groupe-titre">Notifications</div>
  <div class="grille-cartes">
    <a class="carte-accueil carte-notif" href="/admin/reglages.php">
      <div class="titre">Rappels par email</div>
      <div class="detail"><?= $reminderEnabled ? 'Activés · délai ' . htmlspecialchars($reminderDelai) . 'h' : 'Désactivés' ?></div>
    </a>
    <?php /* Adresses et preferences par personne : retirees du menu
             familial, Michel et Christiane ne s'en servent pas et c'est
             Laurent qui les renseigne pour eux. La page reste la meme, seul
             son point d'entree change. */ ?>
    <a class="carte-accueil carte-notif" href="/mes_rappels.php">
      <div class="titre">Adresses par personne</div>
      <div class="detail">Qui reçoit quels rappels</div>
    </a>
  </div>

  <div class="groupe-titre">Développement</div>
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
</body>
</html>
