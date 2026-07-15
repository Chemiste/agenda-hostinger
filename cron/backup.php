<?php
/**
 * SAUVEGARDE AUTOMATIQUE (à usage Cron, pas de connexion interactive).
 *
 * Destiné à être appelé périodiquement par un Cron Job Hostinger (hPanel
 * > Avancé > Cron Jobs), par exemple chaque nuit à 3h, en visitant :
 *
 *   https://votre-domaine/cron/backup.php?token=VOTRE_JETON
 *
 * VOTRE_JETON est la valeur de 'backup_token' dans config.php : ce n'est
 * pas un mot de passe interactif (il n'y a pas de formulaire), juste une
 * chaîne secrète dans l'URL pour éviter que n'importe qui puisse
 * déclencher une sauvegarde en tombant sur cette page. Générez-la par
 * exemple avec `openssl rand -hex 20` ou un générateur de mots de passe.
 *
 * À chaque appel : exporte l'intégralité de la table "appointments" dans
 * un fichier JSON horodaté, dans le dossier backups/ (protégé par
 * .htaccess : aucun accès direct possible depuis un navigateur, même en
 * connaissant le nom exact du fichier). Les sauvegardes de plus de
 * RETENTION_JOURS jours sont supprimées automatiquement pour ne pas
 * accumuler indéfiniment.
 *
 * En cas de suppression accidentelle d'un rendez-vous, la page
 * admin/sauvegardes.php permet de comparer une sauvegarde à l'état
 * actuel et de restaurer les rendez-vous disparus.
 */

require_once __DIR__ . '/../lib/db.php';

const RETENTION_JOURS = 60;

$config = require __DIR__ . '/../config.php';
$token = isset($config['backup_token']) ? $config['backup_token'] : '';

header('Content-Type: text/plain; charset=utf-8');

if ($token === '' || $token === 'REMPLACER_PAR_UNE_CHAINE_ALEATOIRE') {
    http_response_code(403);
    echo "Sauvegarde désactivée : définissez 'backup_token' dans config.php.";
    exit;
}

$fourni = isset($_GET['token']) ? $_GET['token'] : '';
if (!is_string($fourni) || $fourni === '' || !hash_equals($token, $fourni)) {
    http_response_code(403);
    echo 'Jeton invalide.';
    exit;
}

$dossier = __DIR__ . '/../backups';
if (!is_dir($dossier)) {
    mkdir($dossier, 0755, true);
}

try {
    $db = getDb();
    $lignes = $db->query('SELECT * FROM appointments ORDER BY id')->fetchAll();
} catch (Exception $e) {
    http_response_code(500);
    echo 'Erreur base de données : ' . $e->getMessage();
    exit;
}

$horodatage = date('Y-m-d-Hi');
$fichier = $dossier . '/appointments-' . $horodatage . '.json';
file_put_contents($fichier, json_encode($lignes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Nettoyage des sauvegardes trop anciennes.
$limite = time() - RETENTION_JOURS * 86400;
foreach (glob($dossier . '/appointments-*.json') as $f) {
    if (filemtime($f) < $limite) {
        @unlink($f);
    }
}

echo 'OK : ' . count($lignes) . ' rendez-vous sauvegardés dans ' . basename($fichier) . '.';
