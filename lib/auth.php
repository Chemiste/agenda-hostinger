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
    // "secure" force le HTTPS pour ce cookie - indispensable en production
    // (agenda.hellau.be est en HTTPS), mais il faut le desactiver en local
    // (php -S sert en HTTP simple), sinon le navigateur refuse silencieusement
    // le cookie de session et on reste bloque sur la page de connexion apres
    // avoir tape le bon mot de passe.
    session_set_cookie_params([
        'lifetime' => $dureeSession,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
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
 *
 * La session memorise desormais un IDENTIFIANT (table persons, voir
 * migrations/0021_ajouter_persons.sql). Le nom en est deduit a la lecture :
 * renommer quelqu'un dans l'administration se voit donc immediatement,
 * sans le forcer a se reconnecter.
 */

/** L'identifiant de la personne connectee, ou null. */
function personIdSessionActuel() {
    return isset($_SESSION['person_id']) ? (int) $_SESSION['person_id'] : null;
}

/**
 * Le NOM de la personne connectee, ou null.
 *
 * Deduit de l'identifiant quand il est connu. Le repli sur
 * $_SESSION['personne_courante'] couvre deux cas : les sessions ouvertes
 * avant cette mise a jour (elles n'ont pas encore d'identifiant, voir
 * requireIdentite qui le rattrape), et une installation ou la migration
 * 0021 n'a pas encore ete appliquee.
 */
function personneSessionActuelle() {
    $id = personIdSessionActuel();
    if ($id !== null && $id > 0) {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/persons.php';
        try {
            $p = obtenirPerson(getDb(), $id);
            if ($p !== null) {
                return $p['nom'];
            }
        } catch (Exception $e) {
            // Base indisponible ou table absente : on retombe sur le nom
            // memorise plutot que de casser toutes les pages.
        }
    }
    return isset($_SESSION['personne_courante']) ? $_SESSION['personne_courante'] : null;
}

/**
 * @param int|null $personId Identifiant dans la table persons, ou null si
 *        la migration 0021 n'est pas encore appliquee sur cet
 *        environnement (on ne memorise alors que le nom, comme avant).
 */
function definirPersonneSession($nom, $personId = null) {
    $_SESSION['personne_courante'] = $nom;
    if ($personId !== null && (int) $personId > 0) {
        $_SESSION['person_id'] = (int) $personId;
    } else {
        unset($_SESSION['person_id']);
    }
}

/**
 * Comme requireLogin(), mais exige en plus que la personne ait indique
 * qui elle est (une fois par session) avant d'acceder a la page. Utilise
 * par les pages familiales (index.php, mes_rappels.php, historique.php) -
 * pas par l'admin, qui reste une identite unique (Laurent) protegee par
 * son propre mot de passe.
 */
function requireIdentite() {
    requireLogin();
    if (personneSessionActuelle() === null) {
        header('Location: /qui_est_ce.php');
        exit;
    }
    rattraperIdentiteSession();
    enregistrerVisiteSiNecessaire();
}

/**
 * Complete une session ouverte AVANT la mise en place de la table persons :
 * elle ne connait qu'un nom. On retrouve l'identifiant correspondant et on
 * le memorise, plutot que de renvoyer tout le monde sur "Qui etes-vous ?"
 * le jour du deploiement.
 */
function rattraperIdentiteSession() {
    if (personIdSessionActuel() !== null) {
        return;
    }
    $nom = isset($_SESSION['personne_courante']) ? $_SESSION['personne_courante'] : '';
    if ($nom === '') {
        return;
    }
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/persons.php';
    try {
        $p = personParNom(getDb(), $nom);
        if ($p !== null) {
            $_SESSION['person_id'] = $p['id'];
        }
    } catch (Exception $e) {
        // Table pas encore creee sur cet environnement : on continue avec
        // le nom seul, comme avant.
    }
}

/**
 * Ajoute une ligne "Connexion" au journal d'activite si la derniere visite
 * enregistree pour cette session remonte a plus de 2h - sans ce garde-fou,
 * chaque page vue (index -> taches -> medecins...) creerait sa propre
 * ligne, ce qui rendrait le journal illisible. Appelee automatiquement par
 * requireIdentite() sur chaque page familiale ; qui_est_ce.php met deja a
 * jour $_SESSION['derniere_visite_loggee'] lui-meme pour eviter une ligne
 * en double juste apres avoir choisi son nom.
 */
function enregistrerVisiteSiNecessaire() {
    $seuilInactivite = 60 * 60 * 2; // 2 heures
    $maintenant = time();
    $derniere = isset($_SESSION['derniere_visite_loggee']) ? (int) $_SESSION['derniere_visite_loggee'] : null;

    if ($derniere === null || ($maintenant - $derniere) > $seuilInactivite) {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/activity_log.php';
        enregistrerActivite(getDb(), 'connexion', personneSessionActuelle());
    }

    $_SESSION['derniere_visite_loggee'] = $maintenant;
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
