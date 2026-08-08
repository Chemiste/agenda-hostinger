<?php
/**
 * Gestion de la connexion (un seul mot de passe familial partage).
 */

// Par defaut, PHP utilise un cookie de session "de navigateur" (expire a la
// fermeture du navigateur) et une duree de vie cote serveur assez courte
// (souvent 24 min d'inactivite chez la plupart des hebergeurs). Sur
// telephone, le navigateur/l'appli est tres souvent ferme ou mis en arriere-
// plan par le systeme pour liberer de la memoire, ce qui a le meme effet
// qu'une fermeture reelle - la connexion est alors perdue tres souvent,
// meme en visitant le site plusieurs fois par jour. On etend ici la duree
// du cookie et de la session cote serveur a 90 jours, pour rester connecte
// beaucoup plus longtemps sur un appareil personnel.
if (session_status() === PHP_SESSION_NONE) {
    $dureeSession = 60 * 60 * 24 * 90; // 90 jours
    ini_set('session.gc_maxlifetime', (string) $dureeSession);
    session_set_cookie_params([
        'lifetime' => $dureeSession,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isLoggedIn() {
    return !empty($_SESSION['logged_in']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        // Chemin absolu (depuis la racine du site) plutot que relatif : les
        // pages qui appellent cette fonction ne sont pas toutes au meme
        // niveau (racine, admin/, outils/...), un chemin relatif se
        // resoudrait differemment selon d'ou l'appel vient.
        header('Location: /login.php');
        exit;
    }
}

function attemptLogin($password) {
    $config = require __DIR__ . '/../config.php';
    if (!isset($config['family_password_hash']) || $config['family_password_hash'] === 'REMPLACER_PAR_LE_HASH_GENERE') {
        return false;
    }
    if (password_verify($password, $config['family_password_hash'])) {
        $_SESSION['logged_in'] = true;
        return true;
    }
    return false;
}

function logout() {
    $_SESSION = [];
    session_destroy();
}

/**
 * Identite de la personne actuellement connectee (Michel, Christiane,
 * Helene, Laurent...) - distincte du mot de passe familial partage : le
 * mot de passe donne acces au site, ce choix (qui_est_ce.php) permet de
 * savoir QUI a fait quoi pour le journal d'activite (voir historique.php
 * et admin/historique.php, lib/activity_log.php).
 */
function personneSessionActuelle() {
    return isset($_SESSION['personne_courante']) ? $_SESSION['personne_courante'] : null;
}

function definirPersonneSession($nom) {
    $_SESSION['personne_courante'] = $nom;
}

/**
 * Comme requireLogin(), mais exige en plus que la personne ait indique
 * qui elle est (une fois par session) avant d'acceder a la page. Utilise
 * par les pages familiales (index.php, mes_rappels.php, historique.php) -
 * pas par l'admin, qui reste une identite unique (Chem) protegee par son
 * propre mot de passe.
 */
function requireIdentite() {
    requireLogin();
    if (personneSessionActuelle() === null) {
        header('Location: /qui_est_ce.php');
        exit;
    }
}

/**
 * Deuxieme niveau de protection pour les pages d'administration
 * (nettoyage des donnees, import .ics, sauvegardes...), avec un mot de
 * passe distinct du mot de passe familial. Objectif : meme si quelqu'un
 * de la famille tombe sur l'URL d'une page admin, il lui faut un
 * deuxieme mot de passe (connu de vous seul) pour y entrer.
 */

function isAdminLoggedIn() {
    return !empty($_SESSION['admin_logged_in']);
}

function requireAdminLogin() {
    requireLogin();
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function attemptAdminLogin($password) {
    $config = require __DIR__ . '/../config.php';
    if (!isset($config['admin_password_hash']) || $config['admin_password_hash'] === 'REMPLACER_PAR_LE_HASH_GENERE') {
        return false;
    }
    if (password_verify($password, $config['admin_password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

function adminLogout() {
    unset($_SESSION['admin_logged_in']);
}
