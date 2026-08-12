<?php
/**
 * OUTIL PONCTUEL : cree dans Google Calendar les evenements manquants pour
 * les rendez-vous ajoutes pendant que la synchro etait cassee (projet
 * Google Cloud supprime, voir diagnostic precedent) - ces rendez-vous ont
 * ete enregistres sur le site mais jamais envoyes a Google Calendar
 * (calendar_event_id vide en base), silencieusement.
 *
 * Parcourt tous les rendez-vous avec calendar_event_id vide, cree
 * l'evenement Google Calendar correspondant, et enregistre son id en base
 * (comme le ferait un ajout normal). Sans danger a relancer plusieurs
 * fois : les rendez-vous deja lies (calendar_event_id rempli) sont
 * ignores.
 *
 * A SUPPRIMER une fois utilise.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/calendar_sync.php';

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../config.php';
$sync = new CalendarSync($config['google_service_account_path'], $config['google_calendar_id']);
$db = getDb();

if (!$sync->isEnabled()) {
    echo "ARRET : la synchro Google Calendar n'est pas active (calendar_id ou service-account.json manquant).\n";
    exit;
}

$stmt = $db->query("SELECT id, appt_date, appt_time, person, doctor, department, location, phone, route, accompagnant, notes, questions FROM appointments WHERE calendar_event_id IS NULL OR calendar_event_id = '' ORDER BY appt_date, appt_time");
$aTraiter = $stmt->fetchAll();

echo "Rendez-vous sans evenement Google Calendar trouves : " . count($aTraiter) . "\n\n";

$reussis = 0;
$echecs = 0;

foreach ($aTraiter as $r) {
    $appt = [
        'date' => $r['appt_date'],
        'time' => substr($r['appt_time'], 0, 5),
        'person' => $r['person'],
        'doctor' => $r['doctor'],
        'department' => $r['department'],
        'location' => $r['location'],
        'phone' => $r['phone'],
        'route' => $r['route'],
        'accompagnant' => $r['accompagnant'],
        'notes' => $r['notes'],
        'questions' => $r['questions'],
    ];

    $eventId = $sync->createEvent($appt);
    $label = $r['appt_date'] . ' ' . substr($r['appt_time'], 0, 5) . ' - ' . $r['person'] . ' - ' . ($r['doctor'] !== '' ? $r['doctor'] : 'Rendez-vous');

    if ($eventId) {
        $upd = $db->prepare('UPDATE appointments SET calendar_event_id = ? WHERE id = ?');
        $upd->execute([$eventId, $r['id']]);
        echo "OK   : $label\n";
        $reussis++;
    } else {
        echo "ECHEC : $label\n";
        $echecs++;
    }
}

echo "\n--- Termine : $reussis synchronises, $echecs echecs. ---\n";
