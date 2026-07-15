<?php
/**
 * RAPPELS PAR EMAIL (à usage Cron, pas de connexion interactive).
 *
 * Destiné à être appelé périodiquement par un Cron Job Hostinger (hPanel
 * > Avancé > Cron Jobs), par exemple toutes les 15 minutes, en visitant :
 *
 *   https://votre-domaine/cron/rappels.php?token=VOTRE_JETON
 *
 * VOTRE_JETON est la valeur de 'reminder_token' dans config.php : ce n'est
 * pas un mot de passe interactif, juste une chaîne secrète dans l'URL pour
 * éviter que n'importe qui puisse déclencher l'envoi en tombant sur cette
 * page. Générez-la par exemple avec `openssl rand -hex 20`.
 *
 * Les réglages techniques (activé/désactivé, délai, adresse de Chem,
 * expéditeur) se configurent depuis admin/reglages.php. Les adresses de
 * Papa/Maman et leurs préférences ("aussi recevoir les rappels de
 * l'autre") se configurent depuis mes_rappels.php, pas ici.
 *
 * À chaque appel : cherche les rendez-vous à venir dont l'heure de rappel
 * (date/heure du rendez-vous moins le délai réglé) est déjà passée, mais
 * dont le rendez-vous lui-même n'a pas encore eu lieu, et pour lesquels
 * aucun rappel n'a encore été envoyé (reminder_sent_at IS NULL). Peut donc
 * être appelé aussi souvent que voulu sans risque de doublon.
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/mailer.php';

$config = require __DIR__ . '/../config.php';
$token = isset($config['reminder_token']) ? $config['reminder_token'] : '';
$configSmtp = construireConfigSmtp($config);

header('Content-Type: text/plain; charset=utf-8');

if ($token === '' || $token === 'REMPLACER_PAR_UNE_CHAINE_ALEATOIRE') {
    http_response_code(403);
    echo "Rappels désactivés : définissez 'reminder_token' dans config.php.";
    exit;
}

$fourni = isset($_GET['token']) ? $_GET['token'] : '';
if (!is_string($fourni) || $fourni === '' || !hash_equals($token, $fourni)) {
    http_response_code(403);
    echo 'Jeton invalide.';
    exit;
}

try {
    $db = getDb();
} catch (Exception $e) {
    http_response_code(500);
    echo 'Erreur base de données : ' . $e->getMessage();
    exit;
}

if (getSetting($db, 'reminder_enabled', '0') !== '1') {
    echo 'Rappels désactivés dans les réglages (admin/reglages.php).';
    exit;
}

$heures = (int) getSetting($db, 'reminder_hours_before', '24');
if ($heures < 1) {
    $heures = 24;
}

$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';

$emailChem = trim(getSetting($db, 'reminder_email_chem', ''));
$emailFrom = trim(getSetting($db, 'reminder_email_from', ''));
$emailPerson1 = trim(getSetting($db, 'reminder_email_person1', ''));
$notifySelfPerson1 = getSetting($db, 'reminder_notify_self_person1', '1') === '1';
$notifyOtherPerson1 = getSetting($db, 'reminder_notify_other_person1', '0') === '1';
$emailPerson2 = trim(getSetting($db, 'reminder_email_person2', ''));
$notifySelfPerson2 = getSetting($db, 'reminder_notify_self_person2', '1') === '1';
$notifyOtherPerson2 = getSetting($db, 'reminder_notify_other_person2', '0') === '1';

if ($emailChem === '' && $emailPerson1 === '' && $emailPerson2 === '') {
    echo "Rappels activés mais aucune adresse email configurée (admin/reglages.php / mes_rappels.php).";
    exit;
}

// Destinataires pour un rendez-vous donne : la personne concernee (si
// elle a renseigne son email ET n'a pas desactive ses propres rappels),
// plus l'autre personne si elle a choisi de recevoir aussi les rappels de
// celle-ci, plus Chem dans tous les cas (destinataire fixe, voir
// admin/reglages.php).
function destinatairesRappel(
    $personneRdv, $p1, $p2,
    $emailPerson1, $notifySelfPerson1, $notifyOtherPerson1,
    $emailPerson2, $notifySelfPerson2, $notifyOtherPerson2,
    $emailChem
) {
    $destinataires = [];
    if ($personneRdv === $p1) {
        if ($emailPerson1 !== '' && $notifySelfPerson1) $destinataires[] = $emailPerson1;
        if ($notifyOtherPerson2 && $emailPerson2 !== '') $destinataires[] = $emailPerson2;
    } elseif ($personneRdv === $p2) {
        if ($emailPerson2 !== '' && $notifySelfPerson2) $destinataires[] = $emailPerson2;
        if ($notifyOtherPerson1 && $emailPerson1 !== '') $destinataires[] = $emailPerson1;
    }
    if ($emailChem !== '') $destinataires[] = $emailChem;

    return array_values(array_unique($destinataires));
}

$stmt = $db->prepare(
    'SELECT * FROM appointments ' .
    'WHERE reminder_sent_at IS NULL ' .
    "AND TIMESTAMP(appt_date, appt_time) > NOW() " .
    'AND TIMESTAMP(appt_date, appt_time) <= DATE_ADD(NOW(), INTERVAL ? HOUR) ' .
    'ORDER BY appt_date, appt_time'
);
$stmt->execute([$heures]);
$rdvs = $stmt->fetchAll();

if (empty($rdvs)) {
    echo 'Aucun rappel à envoyer pour le moment.';
    exit;
}

$joursFr = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$moisFr = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function formaterDateFr($appt_date, $appt_time, $joursFr, $moisFr) {
    $ts = strtotime($appt_date . ' ' . $appt_time);
    $jour = $joursFr[(int) date('w', $ts)];
    $numJour = (int) date('j', $ts);
    $mois = $moisFr[(int) date('n', $ts)];
    $annee = date('Y', $ts);
    $heure = date('H:i', $ts);
    return "$jour $numJour $mois $annee à $heure";
}

$envoyes = 0;
$echecs = 0;
$ignores = 0;

foreach ($rdvs as $rdv) {
    $destinataires = destinatairesRappel(
        $rdv['person'], $p1, $p2,
        $emailPerson1, $notifySelfPerson1, $notifyOtherPerson1,
        $emailPerson2, $notifySelfPerson2, $notifyOtherPerson2,
        $emailChem
    );
    if (empty($destinataires)) {
        $ignores++;
        continue;
    }

    $quand = formaterDateFr($rdv['appt_date'], $rdv['appt_time'], $joursFr, $moisFr);

    $lignes = [];
    $lignes[] = 'Rappel de rendez-vous : ' . $quand;
    $lignes[] = '';
    $lignes[] = 'Personne concernée : ' . $rdv['person'];
    if (!empty($rdv['doctor'])) $lignes[] = 'Médecin / consultation : ' . $rdv['doctor'];
    if (!empty($rdv['department'])) $lignes[] = 'Service : ' . $rdv['department'];
    if (!empty($rdv['location'])) $lignes[] = 'Adresse : ' . $rdv['location'];
    if (!empty($rdv['route'])) $lignes[] = 'Route : ' . $rdv['route'];
    if (!empty($rdv['phone'])) $lignes[] = 'Téléphone : ' . $rdv['phone'];
    if (!empty($rdv['notes'])) {
        $lignes[] = '';
        $lignes[] = 'Notes : ' . $rdv['notes'];
    }
    $corps = implode("\n", $lignes);

    $sujet = 'Rappel : rendez-vous ' . $rdv['person'] . ' - ' . $quand;

    $envoi = envoyerEmail($destinataires, $sujet, $corps, $emailFrom, $configSmtp);

    if ($envoi['ok']) {
        $maj = $db->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?');
        $maj->execute([$rdv['id']]);
        $envoyes++;
    } else {
        $echecs++;
    }
}

echo "OK : $envoyes rappel(s) envoyé(s)"
    . ($echecs > 0 ? ", $echecs échec(s)" : '')
    . ($ignores > 0 ? ", $ignores ignoré(s) (aucun destinataire configuré)" : '')
    . '.';
