<?php
/**
 * Verification d'un jeton d'identite Google ("ID token"), sans Composer.
 *
 * Le bouton "Se connecter avec Google" (bibliotheque Google Identity
 * Services, chargee par login.php) poste vers le site un JWT signe par
 * Google. Ce fichier repond a une seule question : ce jeton vient-il
 * vraiment de Google, et pour qui a-t-il ete emis ?
 *
 * On ne fait confiance a RIEN de ce que le navigateur envoie. Un jeton se
 * fabrique en trois lignes de JavaScript ; seule la signature prouve
 * quelque chose. C'est exactement l'operation inverse de
 * lib/calendar_sync.php, qui SIGNE un JWT pour parler a Google - ici on en
 * VERIFIE un.
 *
 * Google recommande sa bibliotheque officielle, qui passe par Composer.
 * Ce site n'a aucune dependance externe et tourne sur un hebergement
 * mutualise : on s'en tient a openssl et curl, deja utilises ailleurs.
 *
 * Les quatre controles exiges par la documentation Google :
 *   1. signature RS256 valide, avec les cles publiques de Google
 *   2. "aud" egal a NOTRE identifiant client (sinon un jeton emis pour un
 *      autre site, par un attaquant qui en possede un, serait accepte)
 *   3. "iss" egal a accounts.google.com
 *   4. "exp" pas encore passe
 */

/** URL des certificats publics de Google, au format PEM. */
const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

/** Cles de la table settings ou le cache des certificats est range. */
const GOOGLE_CERTS_CACHE = 'google_certs_pem';
const GOOGLE_CERTS_EXPIRE = 'google_certs_expire_at';

/**
 * Decodage base64url (RFC 7515) : l'alphabet des JWT remplace "+" et "/"
 * par "-" et "_", et supprime le remplissage "=".
 */
function base64urlDecodeGoogle($texte) {
    $texte = strtr($texte, '-_', '+/');
    $reste = strlen($texte) % 4;
    if ($reste > 0) {
        $texte .= str_repeat('=', 4 - $reste);
    }
    $decode = base64_decode($texte, true);
    return $decode === false ? '' : $decode;
}

/**
 * Les certificats publics de Google, sous la forme identifiant => PEM.
 *
 * Google fait tourner ces cles regulierement, et repond avec un en-tete
 * Cache-Control indiquant combien de temps les garder. On respecte ce
 * delai : les retelecharger a chaque connexion ajouterait un aller-retour
 * reseau a chaque ouverture de session, et les garder pour toujours
 * casserait tout le jour d'une rotation.
 *
 * Le cache vit dans la table settings et non dans un fichier temporaire.
 * Ce site a deja perdu sa synchronisation Calendar a cause d'un jeton
 * coince dans /tmp, invisible et impossible a vider autrement qu'avec un
 * script ecrit pour l'occasion. En base, il se lit et se vide comme le
 * reste.
 */
function certificatsGoogle($db) {
    require_once __DIR__ . '/settings.php';

    $expire = (int) getSetting($db, GOOGLE_CERTS_EXPIRE, '0');
    $cache = getSetting($db, GOOGLE_CERTS_CACHE, '');
    if ($cache !== '' && $expire > time()) {
        $certs = json_decode($cache, true);
        if (is_array($certs) && !empty($certs)) {
            return $certs;
        }
    }

    $ch = curl_init(GOOGLE_CERTS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $reponse = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tailleEntetes = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($reponse === false || $code !== 200) {
        // Repli sur le cache meme perime : mieux vaut une cle qui a
        // peut-etre tourne qu'un site ou plus personne ne peut entrer
        // parce que Google est momentanement injoignable.
        if ($cache !== '') {
            $certs = json_decode($cache, true);
            if (is_array($certs) && !empty($certs)) {
                return $certs;
            }
        }
        throw new Exception('Impossible de recuperer les cles publiques de Google.');
    }

    $entetes = substr($reponse, 0, $tailleEntetes);
    $corps = substr($reponse, $tailleEntetes);
    $certs = json_decode($corps, true);
    if (!is_array($certs) || empty($certs)) {
        throw new Exception('Reponse inattendue de Google pour les cles publiques.');
    }

    // "Cache-Control: public, max-age=20512, must-revalidate, no-transform"
    $duree = 3600;
    if (preg_match('/max-age=(\d+)/i', $entetes, $m)) {
        $duree = max(300, (int) $m[1]);
    }
    setSetting($db, GOOGLE_CERTS_CACHE, json_encode($certs));
    setSetting($db, GOOGLE_CERTS_EXPIRE, (string) (time() + $duree));

    return $certs;
}

/**
 * Verifie un jeton d'identite Google et retourne ce qu'il affirme.
 *
 * @return array{sub:string,email:string,email_verified:bool,nom:string}
 * @throws Exception si le jeton est invalide, pour quelqu'un d'autre,
 *         expire, ou mal signe. L'appelant ne doit RIEN croire du jeton
 *         tant que cette fonction n'a pas rendu la main sans exception.
 */
function verifierJetonGoogle($db, $jwt, $clientId) {
    if ($clientId === '' || strpos($clientId, 'REMPLACER') === 0) {
        throw new Exception("La connexion Google n'est pas configuree (google_client_id absent de config.php).");
    }

    $parties = explode('.', (string) $jwt);
    if (count($parties) !== 3) {
        throw new Exception('Jeton mal forme.');
    }
    list($enteteB64, $chargeB64, $signatureB64) = $parties;

    $entete = json_decode(base64urlDecodeGoogle($enteteB64), true);
    $charge = json_decode(base64urlDecodeGoogle($chargeB64), true);
    if (!is_array($entete) || !is_array($charge)) {
        throw new Exception('Jeton illisible.');
    }

    // On impose l'algorithme au lieu de suivre celui annonce dans le
    // jeton. Accepter "alg" tel quel est la faille classique des
    // verificateurs de JWT ecrits a la main : un attaquant annonce
    // "alg":"none" et la signature n'est plus verifiee du tout.
    if (!isset($entete['alg']) || $entete['alg'] !== 'RS256' || empty($entete['kid'])) {
        throw new Exception('Signature du jeton dans un format inattendu.');
    }

    $certs = certificatsGoogle($db);
    if (!isset($certs[$entete['kid']])) {
        throw new Exception('Cle de signature inconnue.');
    }
    $clePublique = openssl_pkey_get_public($certs[$entete['kid']]);
    if ($clePublique === false) {
        throw new Exception('Cle publique Google illisible.');
    }

    $signature = base64urlDecodeGoogle($signatureB64);
    $signe = openssl_verify($enteteB64 . '.' . $chargeB64, $signature, $clePublique, OPENSSL_ALGO_SHA256);
    if ($signe !== 1) {
        throw new Exception('Signature invalide.');
    }

    // hash_equals plutot que == : la comparaison ne doit pas etre plus
    // rapide quand les premiers caracteres different.
    if (!isset($charge['aud']) || !hash_equals($clientId, (string) $charge['aud'])) {
        throw new Exception("Ce jeton n'a pas ete emis pour ce site.");
    }
    $emetteurs = ['accounts.google.com', 'https://accounts.google.com'];
    if (!isset($charge['iss']) || !in_array($charge['iss'], $emetteurs, true)) {
        throw new Exception('Emetteur inattendu.');
    }
    // 60 secondes de tolerance : l'horloge du serveur et celle de Google
    // ne sont pas synchronisees a la seconde pres.
    if (!isset($charge['exp']) || (int) $charge['exp'] < time() - 60) {
        throw new Exception('Jeton expire, reessaie.');
    }
    if (empty($charge['sub'])) {
        throw new Exception('Jeton sans identifiant de compte.');
    }

    return [
        'sub' => (string) $charge['sub'],
        'email' => isset($charge['email']) ? strtolower(trim((string) $charge['email'])) : '',
        'email_verified' => !empty($charge['email_verified']),
        'nom' => isset($charge['name']) ? (string) $charge['name'] : '',
    ];
}

/**
 * Retrouve la personne correspondant a un compte Google verifie.
 *
 * D'abord par google_sub, l'identifiant stable du compte. Ensuite
 * seulement par adresse, et uniquement pour les personnes pas encore
 * reliees : c'est l'enrolement, la toute premiere connexion apres que tu
 * as saisi l'adresse dans /admin/personnes.php. Le sub est alors memorise
 * et l'adresse ne sert plus jamais a identifier - une adresse peut etre
 * abandonnee puis reattribuee, un sub jamais.
 *
 * @return array|null La personne, ou null si ce compte n'est rattache a
 *         personne : dans ce cas l'acces est refuse. Se connecter avec un
 *         compte Google valide ne donne aucun droit en soi, il faut que tu
 *         aies inscrit cette adresse au prealable.
 */
function personParCompteGoogle($db, $infos) {
    $stmt = $db->prepare('SELECT * FROM persons WHERE google_sub = ? AND actif = 1 LIMIT 1');
    $stmt->execute([$infos['sub']]);
    $p = $stmt->fetch();
    if ($p) {
        return $p;
    }

    if ($infos['email'] === '' || !$infos['email_verified']) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT * FROM persons WHERE google_sub IS NULL AND LOWER(google_email) = ? ' .
        'AND actif = 1 AND peut_se_connecter = 1 LIMIT 1'
    );
    $stmt->execute([$infos['email']]);
    $p = $stmt->fetch();
    if (!$p) {
        return null;
    }

    $maj = $db->prepare('UPDATE persons SET google_sub = ? WHERE id = ?');
    $maj->execute([$infos['sub'], (int) $p['id']]);
    $p['google_sub'] = $infos['sub'];
    return $p;
}
