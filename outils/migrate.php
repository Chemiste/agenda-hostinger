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
 * (https://agenda.hellau.be/outils/migrate.php) : il vous demandera de
 * vous connecter puis de confirmer avant d'appliquer quoi que ce soit.
 *
 * Chaque fichier .sql du dossier migrations/ est appliqué une seule fois,
 * dans l'ordre alphabétique (d'où les noms 0001_..., 0002_..., etc).
 * Les migrations déjà jouées sont mémorisées dans la table
 * "schema_migrations" et ne sont jamais rejouées.
 */

require_once __DIR__ . '/../lib/db.php';

function migrationsDisponibles() {
    $fichiers = glob(__DIR__ . '/../migrations/*.sql');
    sort($fichiers);
    return $fichiers;
}

function assurerTableMigrations($db) {
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function migrationsDejaAppliquees($db) {
    $stmt = $db->query('SELECT migration FROM schema_migrations');
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function migrationsEnAttente($db) {
    $appliquees = migrationsDejaAppliquees($db);
    $enAttente = [];
    foreach (migrationsDisponibles() as $fichier) {
        $nom = basename($fichier);
        if (!in_array($nom, $appliquees, true)) {
            $enAttente[] = $fichier;
        }
    }
    return $enAttente;
}

function appliquerMigration($db, $fichier) {
    $nom = basename($fichier);
    $sql = file_get_contents($fichier);

    if ($sql === false || trim($sql) === '') {
        throw new Exception("Migration $nom : le fichier est vide ou illisible (mal copié sur cet environnement ?). Aucune modification n'a été marquée comme appliquée.");
    }

    $requetes = array_filter(array_map('trim', explode(';', $sql)), function ($r) {
        return $r !== '';
    });

    if (empty($requetes)) {
        throw new Exception("Migration $nom : aucune instruction SQL exploitable trouvée dans le fichier. Aucune modification n'a été marquée comme appliquée.");
    }

    // Pas de transaction ici : en MySQL/MariaDB, un CREATE TABLE / ALTER TABLE
    // (DDL) déclenche un commit implicite du côté serveur. Si on ouvrait une
    // transaction PDO autour, le commit() explicite qui suit échoue ensuite
    // avec "There is no active transaction". Les migrations utilisent donc
    // des instructions idempotentes (IF NOT EXISTS, etc.) pour rester sûres
    // en cas de relance après un échec partiel.
    try {
        foreach ($requetes as $requete) {
            $db->exec($requete);
        }
        $stmt = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
        $stmt->execute([$nom]);
        return true;
    } catch (Exception $e) {
        throw new Exception("Migration $nom : " . $e->getMessage());
    }
}

function executerMigrations() {
    $db = getDb();
    assurerTableMigrations($db);
    $enAttente = migrationsEnAttente($db);
    $resultats = [];
    foreach ($enAttente as $fichier) {
        appliquerMigration($db, $fichier);
        $resultats[] = basename($fichier);
    }
    return $resultats;
}

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
requireLogin();

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
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <h1>Migrations de la base de données</h1>

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

  <p style="margin-top:2rem;"><a href="/index.php">Retour à l'agenda</a></p>
</body>
</html>
