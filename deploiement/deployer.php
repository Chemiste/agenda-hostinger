<?php
/**
 * Script de deploiement manuel : televerse par FTP (ou FTPS) les fichiers
 * qui different reellement de leur copie en ligne, en excluant les
 * fichiers/dossiers sensibles ou inutiles en production (voir
 * exclusions.txt).
 *
 * A lancer depuis votre machine locale (jamais depuis le serveur - ce
 * script sert justement a y envoyer des fichiers) :
 *
 *   php deploiement/deployer.php            compare au FTP, televerse ce qui differe (ou est absent), apres confirmation
 *   php deploiement/deployer.php --test      pareil, mais affiche juste la liste sans rien televerser
 *   php deploiement/deployer.php --tout      televerse TOUT le depot (fichiers non exclus), sans comparer au FTP
 *
 * "--testftp" reste accepte comme synonyme de "--test", par habitude.
 *
 * Comment savoir quoi televerser : le script compare le contenu REEL de
 * chaque fichier local (pas juste sa date) a sa copie sur le serveur -
 * une premiere version comparait a une date de "dernier deploiement"
 * memorisee localement, ce qui donnait des resultats incoherents avec la
 * realite du serveur (ex: tout propose au premier lancement, meme les
 * fichiers deja identiques en ligne). Desormais un seul mecanisme, la
 * meme reponse dans tous les modes.
 *
 * Configuration requise (une seule fois) :
 *   cp deploiement/deploy.config.example.php deploiement/deploy.config.php
 *   puis remplir vos identifiants FTP dans ce fichier. Il contient un mot
 *   de passe : il est exclu de Git (voir .gitignore), ne le committez jamais.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('RACINE_DEPOT', dirname(__DIR__));
define('DOSSIER_SCRIPT', __DIR__);
define('FICHIER_EXCLUSIONS', DOSSIER_SCRIPT . '/exclusions.txt');
define('FICHIER_CONFIG', DOSSIER_SCRIPT . '/deploy.config.php');

$args = array_slice($argv, 1);
$modeTout = in_array('--tout', $args, true);
$modeTest = in_array('--test', $args, true) || in_array('--testftp', $args, true);

if (!file_exists(FICHIER_CONFIG)) {
    fwrite(STDERR, "Configuration manquante : deploiement/deploy.config.php\n");
    fwrite(STDERR, "Copiez deploiement/deploy.config.example.php vers deploiement/deploy.config.php\net remplissez vos identifiants FTP.\n");
    exit(1);
}
$config = require FICHIER_CONFIG;

if (empty($config['ftp_host']) || empty($config['ftp_user']) || !isset($config['ftp_pass']) || empty($config['ftp_remote_path'])) {
    fwrite(STDERR, "deploy.config.php incomplet : ftp_host, ftp_user, ftp_pass et ftp_remote_path sont obligatoires.\n");
    exit(1);
}

/**
 * Charge les motifs d'exclusion depuis exclusions.txt (un motif par
 * ligne, "#" pour les commentaires). Absence du fichier = aucune
 * exclusion supplementaire (deploiement/ reste tout de meme protege, voir
 * plus bas).
 */
function chargerExclusions($fichier) {
    if (!file_exists($fichier)) return [];
    $lignes = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $motifs = [];
    foreach ($lignes as $l) {
        $l = trim($l);
        if ($l === '' || strpos($l, '#') === 0) continue;
        $motifs[] = $l;
    }
    return $motifs;
}

/**
 * Un motif se terminant par "/" exclut tout un dossier (prefixe de
 * chemin). Sinon, comparaison par motif (fnmatch, donc "*" fonctionne)
 * sur le chemin complet ET sur le seul nom de fichier - pour pouvoir
 * ecrire aussi bien "config.php" que "assets/*.bak".
 */
function estExclu($cheminRelatif, $motifs) {
    foreach ($motifs as $motif) {
        if (substr($motif, -1) === '/') {
            if (strpos($cheminRelatif . '/', $motif) === 0) return true;
        } elseif (fnmatch($motif, $cheminRelatif) || fnmatch($motif, basename($cheminRelatif))) {
            return true;
        }
    }
    return false;
}

/** Parcourt tout le depot et renvoie la liste des chemins relatifs (fichiers uniquement). */
function listerFichiers($racine) {
    $fichiers = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $info) {
        if (!$info->isFile()) continue;
        $relatif = ltrim(str_replace($racine, '', $info->getPathname()), '/\\');
        $fichiers[] = str_replace('\\', '/', $relatif);
    }
    return $fichiers;
}

/**
 * Ouvre la connexion FTP/FTPS et s'authentifie, ou arrete le script avec
 * un message clair en cas d'echec.
 */
function connecterFtp($config) {
    if (!function_exists('ftp_connect')) {
        fwrite(STDERR, "L'extension PHP \"ftp\" n'est pas installée (sudo dnf install php-ftp), impossible de continuer.\n");
        exit(1);
    }
    $port = !empty($config['ftp_port']) ? (int) $config['ftp_port'] : 21;
    $connexion = !empty($config['ftp_ssl'])
        ? @ftp_ssl_connect($config['ftp_host'], $port)
        : @ftp_connect($config['ftp_host'], $port);
    if (!$connexion) {
        fwrite(STDERR, "Impossible de se connecter à {$config['ftp_host']}:{$port}.\n");
        exit(1);
    }
    if (!@ftp_login($connexion, $config['ftp_user'], $config['ftp_pass'])) {
        fwrite(STDERR, "Identifiants FTP refusés.\n");
        exit(1);
    }
    ftp_pasv($connexion, true);
    return $connexion;
}

/**
 * Recupere le contenu d'un fichier distant en memoire (sans fichier
 * temporaire sur le disque), ou null si le fichier n'existe pas sur le
 * serveur (ou toute autre erreur de lecture - dans les deux cas, tant pis,
 * le fichier sera propose au televersement).
 */
function recupererContenuDistant($connexion, $cheminDistant) {
    $tmp = fopen('php://temp', 'r+');
    $ok = @ftp_fget($connexion, $tmp, $cheminDistant, FTP_BINARY);
    if (!$ok) {
        fclose($tmp);
        return null;
    }
    rewind($tmp);
    $contenu = stream_get_contents($tmp);
    fclose($tmp);
    return $contenu;
}

/**
 * Cree recursivement les sous-dossiers distants manquants (ftp_put echoue
 * si le dossier parent n'existe pas). @ftp_mkdir echoue silencieusement
 * si le dossier existe deja - pas grave, c'est ignore volontairement.
 */
function creerDossiersDistant($connexion, $dossierDistant, $base) {
    if ($dossierDistant === $base || $dossierDistant === '.' || $dossierDistant === '/' || $dossierDistant === '') return;
    creerDossiersDistant($connexion, dirname($dossierDistant), $base);
    @ftp_mkdir($connexion, $dossierDistant);
}

// --- Fichiers candidats (tous les fichiers du depot, hors exclusions) ---

$exclusions = chargerExclusions(FICHIER_EXCLUSIONS);
// Le script (et sa config FTP) ne doit jamais partir vers la production,
// meme si quelqu'un retire cette ligne de exclusions.txt par erreur.
$exclusions[] = 'deploiement/';

echo "Analyse du dépôt...\n";
$candidats = [];
foreach (listerFichiers(RACINE_DEPOT) as $relatif) {
    if (!estExclu($relatif, $exclusions)) $candidats[] = $relatif;
}
sort($candidats);

// --- Determiner quoi televerser ---

$connexion = connecterFtp($config);
$base = rtrim($config['ftp_remote_path'], '/');

if ($modeTout) {
    $aTeleverser = $candidats;
} else {
    echo "Comparaison de " . count($candidats) . " fichier(s) avec {$config['ftp_host']}...\n";
    $aTeleverser = [];
    foreach ($candidats as $relatif) {
        $local = RACINE_DEPOT . '/' . $relatif;
        $distant = $base . '/' . $relatif;
        $contenuDistant = recupererContenuDistant($connexion, $distant);
        if ($contenuDistant === null) {
            echo "  ? $relatif (absent sur le serveur)\n";
            $aTeleverser[] = $relatif;
        } elseif ($contenuDistant !== file_get_contents($local)) {
            echo "  ≠ $relatif (différent)\n";
            $aTeleverser[] = $relatif;
        }
    }
    echo "\n";
}

if (empty($aTeleverser)) {
    echo "Rien à téléverser : le serveur correspond déjà à votre copie locale.\n";
    ftp_close($connexion);
    exit(0);
}

echo count($aTeleverser) . " fichier(s) à téléverser :\n";
foreach ($aTeleverser as $f) {
    echo "  - $f\n";
}

if ($modeTest) {
    echo "\n(Mode --test : rien n'a été téléversé.)\n";
    ftp_close($connexion);
    exit(0);
}

echo "\nTéléverser ces fichiers vers {$config['ftp_host']} ? (o/N) ";
$reponse = trim((string) fgets(STDIN));
if (strtolower($reponse) !== 'o') {
    echo "Annulé.\n";
    ftp_close($connexion);
    exit(0);
}

// --- Televersement ---

$dossiersCrees = [];
$reussis = 0;
$echecs = [];

foreach ($aTeleverser as $relatif) {
    $local = RACINE_DEPOT . '/' . $relatif;
    $distant = $base . '/' . $relatif;
    $dossierDistant = dirname($distant);

    if (!isset($dossiersCrees[$dossierDistant])) {
        creerDossiersDistant($connexion, $dossierDistant, $base);
        $dossiersCrees[$dossierDistant] = true;
    }

    echo "→ $relatif ... ";
    if (@ftp_put($connexion, $distant, $local, FTP_BINARY)) {
        echo "ok\n";
        $reussis++;
    } else {
        echo "ÉCHEC\n";
        $echecs[] = $relatif;
    }
}

ftp_close($connexion);

echo "\n$reussis/" . count($aTeleverser) . " fichier(s) téléversé(s).\n";
if (!empty($echecs)) {
    echo "Échecs (à vérifier puis relancer le script pour réessayer) :\n";
    foreach ($echecs as $f) echo "  - $f\n";
}
