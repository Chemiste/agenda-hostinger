<?php
/**
 * Application des migrations de base de donnees.
 *
 * Ces fonctions vivaient dans outils/migrate.php. Elles ont ete sorties
 * ici parce que installer.php en a besoin lui aussi : sur une base neuve,
 * il faut creer les tables AVANT que le premier administrateur existe -
 * donc sans pouvoir passer par outils/migrate.php, qui exige justement un
 * administrateur.
 *
 * Chaque fichier .sql du dossier migrations/ est applique une seule fois,
 * dans l'ordre alphabetique (d'ou les noms 0001_..., 0002_...). Les
 * migrations deja jouees sont memorisees dans la table
 * "schema_migrations" et ne sont jamais rejouees.
 */

require_once __DIR__ . '/db.php';

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
