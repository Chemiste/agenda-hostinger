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
 * Les réglages techniques (activé/désactivé, délai, adresse de Laurent,
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
require_once __DIR__ . '/../lib/rappels_personnes.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/rappel_contenu.php';
// nomPerson() arrivait jusqu'ici par un chemin indirect (via
// rappels_personnes.php). On la demande explicitement : une
// reorganisation des includes ne doit pas casser les rappels en silence.
require_once __DIR__ . '/../lib/persons.php';

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

// Reglages par personne : voir lib/rappels_personnes.php. Ils sont
// indexes par identifiant, donc valables pour autant de patients qu'on
// veut - l'ancienne version ne connaissait que "person1" et "person2",
// une troisieme personne n'aurait jamais rien recu, en silence.
$patients = listerPatients($db);
$reglages = lireReglagesRappel($db, $patients);

$emailChem = trim(getSetting($db, 'reminder_email_chem', ''));
$emailFrom = trim(getSetting($db, 'reminder_email_from', ''));

$auMoinsUneAdresse = $emailChem !== '';
foreach ($reglages as $r) {
    if ($r['email'] !== '') { $auMoinsUneAdresse = true; break; }
}
if (!$auMoinsUneAdresse) {
    echo "Rappels activés mais aucune adresse email configurée (admin/reglages.php / mes_rappels.php).";
    exit;
}

$stmt = $db->prepare(
    'SELECT * FROM appointments ' .
    'WHERE reminder_sent_at IS NULL ' .
    // Rappel desactive sur ce rendez-vous precis (case decochee dans le
    // formulaire) : on ne l'envoie pas, et on ne marque pas non plus
    // reminder_sent_at - reactiver la case doit pouvoir redonner lieu a un
    // rappel si la date n'est pas encore passee.
    'AND rappel_actif = 1 ' .
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
    $destinataires = destinatairesRappel($rdv['person_id'], $reglages, $emailChem);
    if (empty($destinataires)) {
        $ignores++;
        continue;
    }

    $quand = formaterDateFr($rdv['appt_date'], $rdv['appt_time'], $joursFr, $moisFr);
    $nomConcerne = ((int) $rdv['person_id'] > 0) ? nomPerson($db, $rdv['person_id']) : $rdv['person'];

    // La composition du message vit dans lib/rappel_contenu.php : elle
    // engendre les versions texte ET HTML cote a cote, pour qu'elles ne
    // divergent jamais.
    $message = composerRappel($db, $rdv, $nomConcerne, $quand);
    $corps = $message['texte'];

    $sujet = 'Rappel : rendez-vous ' . $nomConcerne . ' - ' . $quand;

    $envoi = envoyerEmail($destinataires, $sujet, $corps, $emailFrom, $configSmtp, $message['html']);

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
