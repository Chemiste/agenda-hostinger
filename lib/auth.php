<?php
/**
 * Gestion de la connexion.
 *
 * L'acces au site passe par un compte Google (voir login.php et
 * lib/google_login.php). Le mot de passe familial partage a disparu : il
 * donnait l'acces sans donner l'identite, et l'ecran "Qui etes-vous ?" qui
 * le suivait croyait sur parole le nom clique - alors que ce nom servait
 * aussi de droit d'acces.
 *
 * UNE SEULE PORTE : login.php, qui exige un compte Google rattache a une
 * personne active dans la table persons. Il n'y a plus aucun mot de passe
 * sur ce site.
 *
 * Le premier administrateur est designe par installer.php, qui ne
 * fonctionne que tant qu'aucun administrateur n'existe. C'est ce qui a
 * permis de supprimer le mot de passe d'administration : il ne servait
 * plus qu'a resoudre l'amorcage d'un site vide.
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

/**
 * Ouvre la session pour une personne dont l'identite vient d'etre ATTESTEE
 * par Google. Ne jamais appeler ailleurs qu'apres verifierJetonGoogle().
 *
 * session_regenerate_id() donne un nouvel identifiant de session au moment
 * ou les droits changent. Sans lui, un identifiant de session pose a
 * l'avance par un tiers resterait valable une fois la victime connectee
 * (fixation de session) - d'autant que le php.ini d'Hostinger laisse
 * session.use_strict_mode a 0, donc PHP accepte un identifiant qu'il n'a
 * pas emis lui-meme.
 */
function connecterPersonne($personne) {
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    definirPersonneSession($personne['nom'], (int) $personne['id']);
}

function logout() {
    $_SESSION = [];
    session_destroy();
}

/**
 * Identite de la personne actuellement connectee (Michel, Christiane,
 * Helene, Laurent...). Elle vient du compte Google utilise pour se
 * connecter : elle n'est plus declarative, et sert donc aussi bien au
 * journal d'activite (historique.php, lib/activity_log.php) qu'aux droits
 * (voir personneConnecteeEstAdmin plus bas).
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
    // L'identite est desormais indissociable de la connexion : elle vient
    // du compte Google. Une session sans identite n'est plus le cas normal
    // "il faut encore choisir son nom" mais un cas anormal - typiquement
    // une session ouverte par le mot de passe d'administration, qui donne
    // acces a /admin/ et non aux pages familiales. On renvoie donc vers la
    // connexion plutot que vers un ecran de choix qui n'existe plus.
    if (personIdSessionActuel() === null) {
        header('Location: /login.php');
        exit;
    }
    enregistrerVisiteSiNecessaire();
}

/**
 * Ajoute une ligne "Connexion" au journal d'activite si la derniere visite
 * enregistree pour cette session remonte a plus de 2h - sans ce garde-fou,
 * chaque page vue (index -> taches -> medecins...) creerait sa propre
 * ligne, ce qui rendrait le journal illisible. Appelee automatiquement par
 * requireIdentite() sur chaque page familiale ; login.php met deja a jour
 * $_SESSION['derniere_visite_loggee'] lui-meme pour eviter une ligne en
 * double juste apres la connexion.
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
 * La personne connectee a-t-elle le droit de modifier les donnees de sante
 * (pathologies, plan de medicaments) et d'atteindre l'administration ?
 *
 * Le drapeau est_admin de la table persons, PAS une comparaison de prenom.
 * Le code testait auparavant `personneSessionActuelle() === 'Laurent'` :
 * comme l'identite n'etait pas authentifiee, il suffisait de cliquer le bon
 * nom pour obtenir ces droits. Maintenant que Google atteste l'identite, le
 * drapeau a un sens.
 *
 * Il n'y a pas de seconde voie : le drapeau est la seule facon d'obtenir
 * ces droits, et il ne s'attribue que depuis /admin/personnes.php - ou par
 * installer.php pour le tout premier administrateur.
 */
function personneConnecteeEstAdmin() {
    $id = personIdSessionActuel();
    if ($id === null) {
        return false;
    }
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/persons.php';
    try {
        $p = obtenirPerson(getDb(), $id);
        return $p !== null && !empty($p['est_admin']);
    } catch (Exception $e) {
        // Colonne absente (migration 0022 pas encore passee) : on refuse,
        // plutot que d'ouvrir les droits par accident.
        return false;
    }
}

/**
 * Acces aux pages d'administration : reserve aux personnes portant le
 * drapeau est_admin.
 *
 * requireLogin() d'abord, pour distinguer deux situations qui n'appellent
 * pas la meme reponse : un visiteur sans session doit se connecter, un
 * membre connecte mais sans droits n'a rien a faire ici et repart vers
 * l'agenda. Le renvoyer vers la connexion l'y ferait tourner en rond,
 * puisqu'il est deja connecte.
 */
function requireAdminLogin() {
    requireLogin();
    if (!personneConnecteeEstAdmin()) {
        header('Location: /index.php');
        exit;
    }
}
