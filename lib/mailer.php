<?php
/**
 * Envoi d'emails, deux methodes possibles :
 *
 *  - Si des identifiants SMTP sont renseignes dans config.php (smtp_host),
 *    envoi via SMTP authentifie : connexion directe a la vraie boite mail
 *    avec son mot de passe, comme le ferait Outlook ou Thunderbird. C'est
 *    la methode RECOMMANDEE (voir Guide_installation_hostinger.php,
 *    section "Rappels par email") : l'email part reellement authentifie
 *    par le serveur mail (SPF/DKIM alignes), donc beaucoup moins
 *    susceptible d'atterrir en indesirables.
 *  - Sinon, repli sur la fonction mail() native de PHP : aucune config
 *    necessaire, mais delivrabilite nettement moins bonne (souvent classe
 *    en indesirables meme avec un domaine correctement configure, car le
 *    mail n'est pas authentifie comme venant reellement de la boite
 *    d'expedition - voir la doc Hostinger sur les limites de mail() :
 *    https://support.hostinger.com/en/articles/11393648).
 *
 * Aucune dependance externe (pas de Composer, pas de librairie tierce a
 * installer) : le client SMTP ci-dessous est ecrit a la main (meme
 * principe que lib/calendar_sync.php pour l'API Google), et implemente
 * juste ce qu'il faut : connexion, STARTTLS/SSL, AUTH LOGIN, MAIL
 * FROM/RCPT TO/DATA.
 */

/**
 * Construit le tableau de configuration SMTP a partir de config.php, ou
 * null si aucun serveur SMTP n'est renseigne (repli sur mail() natif).
 */
function construireConfigSmtp($config) {
    if (empty($config['smtp_host'])) {
        return null;
    }
    return [
        'host' => $config['smtp_host'],
        'port' => !empty($config['smtp_port']) ? (int) $config['smtp_port'] : 587,
        // 'tls' = STARTTLS (port 587 en general), 'ssl' = chiffrement implicite (port 465 en general).
        'securite' => !empty($config['smtp_securite']) ? $config['smtp_securite'] : 'tls',
        'utilisateur' => isset($config['smtp_utilisateur']) ? $config['smtp_utilisateur'] : '',
        'mot_de_passe' => isset($config['smtp_mot_de_passe']) ? $config['smtp_mot_de_passe'] : '',
    ];
}

/**
 * Renvoie ['ok' => bool, 'erreur' => string|null]. En cas d'echec,
 * 'erreur' contient un message exploitable (message d'avertissement PHP
 * pour mail(), ou reponse du serveur SMTP), pour pouvoir l'afficher sur la
 * page de reglages plutot qu'un message generique qui n'aide pas a
 * diagnostiquer.
 *
 * $smtp : tableau construit par construireConfigSmtp(), ou null pour
 * forcer l'utilisation de mail() natif.
 */
function envoyerEmail($destinataires, $sujet, $corps, $expediteur, $smtp = null) {
    $destinataires = array_values(array_filter(array_map('trim', $destinataires)));
    if (empty($destinataires)) {
        return ['ok' => false, 'erreur' => 'Aucun destinataire.'];
    }

    // Le sujet doit etre encode au format "mot encode" MIME des qu'il
    // contient des caracteres non-ASCII (accents), sinon certains clients
    // mail l'affichent mal ou le rejettent.
    $sujetEncode = '=?UTF-8?B?' . base64_encode($sujet) . '?=';

    if ($smtp !== null) {
        return envoyerEmailSmtp($destinataires, $sujetEncode, $corps, $expediteur, $smtp);
    }

    return envoyerEmailNatif($destinataires, $sujetEncode, $corps, $expediteur);
}

function envoyerEmailNatif($destinataires, $sujetEncode, $corps, $expediteur) {
    $to = implode(', ', $destinataires);

    $headers = [];
    if ($expediteur !== '') {
        $headers[] = 'From: Agenda medical <' . $expediteur . '>';
        $headers[] = 'Reply-To: ' . $expediteur;
    }
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    // mail() emet un E_WARNING en cas d'echec (ex: sendmail introuvable,
    // enveloppe expediteur refusee...) : on le capture temporairement au
    // lieu de le laisser juste s'afficher (ou disparaitre) dans les logs
    // serveur, pour pouvoir remonter le vrai message a l'utilisateur.
    $erreurCapturee = null;
    set_error_handler(function ($errno, $errstr) use (&$erreurCapturee) {
        $erreurCapturee = $errstr;
        return true;
    });
    $ok = mail($to, $sujetEncode, $corps, implode("\r\n", $headers));
    restore_error_handler();

    return ['ok' => $ok, 'erreur' => $ok ? null : ($erreurCapturee ?: 'Raison inconnue (mail() a renvoye false sans message).')];
}

// --- Client SMTP minimal (sans dependance externe) ---

function envoyerEmailSmtp($destinataires, $sujetEncode, $corps, $expediteur, $smtp) {
    $host = $smtp['host'];
    $port = $smtp['port'];
    $securite = $smtp['securite'];
    $utilisateur = $smtp['utilisateur'];
    $motDePasse = $smtp['mot_de_passe'];

    $cible = ($securite === 'ssl' ? 'ssl://' : 'tcp://') . $host;
    $flux = @stream_socket_client($cible . ':' . $port, $errno, $errstr, 15);
    if (!$flux) {
        return ['ok' => false, 'erreur' => "Connexion SMTP impossible ($host:$port) : $errstr"];
    }
    stream_set_timeout($flux, 15);

    $lireReponse = function () use ($flux) {
        $reponse = '';
        while (($ligne = fgets($flux, 515)) !== false) {
            $reponse .= $ligne;
            // Une ligne finale a un espace en 4e position (pas un tiret) :
            // c'est la derniere ligne de la reponse multi-lignes.
            if (isset($ligne[3]) && $ligne[3] === ' ') {
                break;
            }
        }
        return $reponse;
    };
    $envoyerCommande = function ($commande) use ($flux, $lireReponse) {
        fwrite($flux, $commande . "\r\n");
        return $lireReponse();
    };
    $codeReponse = function ($reponse) {
        return (int) substr($reponse, 0, 3);
    };

    $rep = $lireReponse(); // banniere de connexion du serveur
    if ($codeReponse($rep) !== 220) {
        fclose($flux);
        return ['ok' => false, 'erreur' => "Le serveur SMTP n'a pas repondu correctement a la connexion : " . trim($rep)];
    }

    $nomLocal = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';

    $rep = $envoyerCommande('EHLO ' . $nomLocal);
    if ($codeReponse($rep) !== 250) {
        fclose($flux);
        return ['ok' => false, 'erreur' => 'EHLO refuse : ' . trim($rep)];
    }

    if ($securite === 'tls') {
        $rep = $envoyerCommande('STARTTLS');
        if ($codeReponse($rep) !== 220) {
            fclose($flux);
            return ['ok' => false, 'erreur' => 'STARTTLS refuse : ' . trim($rep)];
        }
        $methode = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $methode |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (!@stream_socket_enable_crypto($flux, true, $methode)) {
            fclose($flux);
            return ['ok' => false, 'erreur' => "Impossible d'etablir le chiffrement TLS avec le serveur SMTP."];
        }
        // Le serveur "oublie" tout apres le chiffrement : il faut renvoyer EHLO.
        $rep = $envoyerCommande('EHLO ' . $nomLocal);
        if ($codeReponse($rep) !== 250) {
            fclose($flux);
            return ['ok' => false, 'erreur' => 'EHLO (apres TLS) refuse : ' . trim($rep)];
        }
    }

    if ($utilisateur !== '') {
        $rep = $envoyerCommande('AUTH LOGIN');
        if ($codeReponse($rep) !== 334) {
            fclose($flux);
            return ['ok' => false, 'erreur' => 'AUTH LOGIN refuse : ' . trim($rep)];
        }
        $rep = $envoyerCommande(base64_encode($utilisateur));
        if ($codeReponse($rep) !== 334) {
            fclose($flux);
            return ['ok' => false, 'erreur' => "Nom d'utilisateur SMTP refuse : " . trim($rep)];
        }
        $rep = $envoyerCommande(base64_encode($motDePasse));
        if ($codeReponse($rep) !== 235) {
            fclose($flux);
            return ['ok' => false, 'erreur' => 'Authentification SMTP refusee (mot de passe incorrect ?) : ' . trim($rep)];
        }
    }

    $adresseExpediteur = $expediteur !== '' ? $expediteur : $utilisateur;

    $rep = $envoyerCommande('MAIL FROM:<' . $adresseExpediteur . '>');
    if ($codeReponse($rep) !== 250) {
        fclose($flux);
        return ['ok' => false, 'erreur' => 'Expediteur refuse : ' . trim($rep)];
    }

    foreach ($destinataires as $dest) {
        $rep = $envoyerCommande('RCPT TO:<' . $dest . '>');
        $code = $codeReponse($rep);
        if ($code !== 250 && $code !== 251) {
            fclose($flux);
            return ['ok' => false, 'erreur' => "Destinataire refuse ($dest) : " . trim($rep)];
        }
    }

    $rep = $envoyerCommande('DATA');
    if ($codeReponse($rep) !== 354) {
        fclose($flux);
        return ['ok' => false, 'erreur' => 'Commande DATA refusee : ' . trim($rep)];
    }

    $entetes = [];
    $entetes[] = 'From: Agenda medical <' . $adresseExpediteur . '>';
    $entetes[] = 'To: ' . implode(', ', $destinataires);
    $entetes[] = 'Reply-To: ' . $adresseExpediteur;
    $entetes[] = 'Subject: ' . $sujetEncode;
    $entetes[] = 'Content-Type: text/plain; charset=UTF-8';
    $entetes[] = 'Content-Transfer-Encoding: 8bit';
    $entetes[] = 'Date: ' . date('r');

    // Point-doublage RFC 5321 : une ligne du corps qui commencerait par un
    // point doit en avoir un second, sinon le serveur l'interprete comme
    // la fin du message (la sequence de fin est "\r\n.\r\n").
    $corpsEchappe = preg_replace('/^\./m', '..', $corps);

    $message = implode("\r\n", $entetes) . "\r\n\r\n" . $corpsEchappe . "\r\n.";
    $rep = $envoyerCommande($message);
    if ($codeReponse($rep) !== 250) {
        fclose($flux);
        return ['ok' => false, 'erreur' => "L'envoi du message a echoue : " . trim($rep)];
    }

    $envoyerCommande('QUIT');
    fclose($flux);

    return ['ok' => true, 'erreur' => null];
}