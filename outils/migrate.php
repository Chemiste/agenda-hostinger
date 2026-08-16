<?php
/**
 * Runner de migrations de base de données.
 *
 * Utilisation en local (développement) :
 *   php outils/migrate.php
 *
 * Utilisation sur le serveur de production (si vous avez un accès SSH) :
 *   php outils/migrate.php
 *
 * Sans accès SSH, ouvrez ce fichier dans le navigateur
 * (https://agenda.hellau.be/outils/migrate.php) : il demande le mot de
 * passe d'ADMINISTRATION, puis une confirmation avant d'appliquer quoi
 * que ce soit.
 *
 * Chaque fichier .sql du dossier migrations/ est appliqué une seule fois,
 * dans l'ordre alphabétique (d'où les noms 0001_..., 0002_..., etc).
 * Les migrations déjà jouées sont mémorisées dans la table
 * "schema_migrations" et ne sont jamais rejouées.
 */

require_once __DIR__ . '/../lib/db.php';
// Les fonctions d'application vivent dans lib/migrations.php : installer.php
// s'en sert aussi, sur une base ou aucun administrateur n'existe encore.
require_once __DIR__ . '/../lib/migrations.php';

// --- Mode CLI ---
if (php_sapi_name() === 'cli') {
    try {
        $db = getDb();
        assurerTableMigrations($db);
        $enAttente = migrationsEnAttente($db);

        if (empty($enAttente)) {
            echo "Aucune migration en attente. La base est à jour.\n";
            exit(0);
        }

        echo count($enAttente) . " migration(s) à appliquer :\n";
        foreach ($enAttente as $fichier) {
            echo " - " . basename($fichier) . "\n";
        }

        foreach ($enAttente as $fichier) {
            appliquerMigration($db, $fichier);
            echo "OK : " . basename($fichier) . "\n";
        }

        echo "Terminé.\n";
        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, "Erreur : " . $e->getMessage() . "\n");
        exit(1);
    }
}

// --- Mode navigateur (nécessite d'être connecté) ---
require_once __DIR__ . '/../lib/auth.php';
// Mot de passe ADMIN et non simple mot de passe familial : appliquer une
// migration modifie la structure de la base. Ce n'est pas une action que
// Michel, Christiane ou Hélène ont a pouvoir declencher en tombant sur
// l'URL.
requireAdminLogin();
require_once __DIR__ . '/../lib/entete_admin.php';

$erreur = '';
$resultats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirmer'])) {
    try {
        $resultats = executerMigrations();
    } catch (Exception $e) {
        $erreur = $e->getMessage();
    }
}

$db = getDb();
assurerTableMigrations($db);
$enAttente = array_map('basename', migrationsEnAttente($db));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migrations - Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
  <?php afficherEnteteAdmin(
      'Migrations de la base de données',
      'Applique les fichiers <code>migrations/*.sql</code> qui n\'ont pas encore été joués sur cette base. '
      . 'Chacun n\'est appliqué qu\'une seule fois.'
  ); ?>

  <div class="outil">
  <?php if ($erreur): ?>
    <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
  <?php endif; ?>

  <?php if ($resultats !== null): ?>
    <?php if (empty($resultats)): ?>
      <p class="info">Aucune migration n'a été appliquée (déjà à jour).</p>
    <?php else: ?>
      <p class="info">Migrations appliquées avec succès :</p>
      <ul>
        <?php foreach ($resultats as $r): ?>
          <li><?= htmlspecialchars($r) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php elseif (empty($enAttente)): ?>
    <p class="info">La base de données est à jour, aucune migration en attente.</p>
  <?php else: ?>
    <p>Migrations en attente :</p>
    <ul>
      <?php foreach ($enAttente as $m): ?>
        <li><?= htmlspecialchars($m) ?></li>
      <?php endforeach; ?>
    </ul>
    <form method="post">
      <input type="hidden" name="confirmer" value="1">
      <button class="principal" type="submit">Lancer les migrations</button>
    </form>
  <?php endif; ?>
  </div>
</body>
</html>
