<?php
/**
 * Envoi d'emails via la fonction mail() native de PHP : aucune dependance
 * (pas de Composer, pas de compte SMTP tiers a configurer), fonctionne
 * sur la quasi-totalite des hebergements, dont Hostinger.
 *
 * Limite a connaitre : pas de garantie de delivrabilite parfaite, un
 * email peut atterrir dans les indesirables selon la configuration du
 * domaine d'envoi. Utilisez le bouton "Envoyer un email de test" de la
 * page de reglages (admin_reglages.php) pour verifier que ça arrive bien
 * (et pensez a regarder le dossier spam la premiere fois) avant de
 * compter dessus pour de vrais rappels.
 */

/**
 * Renvoie ['ok' => bool, 'erreur' => string|null]. En cas d'echec,
 * 'erreur' contient le message d'avertissement PHP capture (si mail() en
 * a emis un), pour pouvoir l'afficher sur la page de reglages plutot
 * qu'un message generique "l'envoi a echoue" qui n'aide pas a diagnostiquer.
 */
function envoyerEmail($destinataires, $sujet, $corps, $expediteur) {
    $destinataires = array_values(array_filter(array_map('trim', $destinataires)));
    if (empty($destinataires)) return ['ok' => false, 'erreur' => 'Aucun destinataire.'];

    $to = implode(', ', $destinataires);

    $headers = [];
    if ($expediteur !== '') {
        $headers[] = 'From: Agenda medical <' . $expediteur . '>';
        $headers[] = 'Reply-To: ' . $expediteur;
    }
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    // Le sujet doit etre encode au format "mot encode" MIME des qu'il
    // contient des caracteres non-ASCII (accents), sinon certains clients
    // mail l'affichent mal ou le rejettent.
    $sujetEncode = '=?UTF-8?B?' . base64_encode($sujet) . '?=';

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