<?php
/**
 * API JSON utilisee par index.php (fetch cote navigateur).
 * Actions : list, add, update, delete, bulk_add.
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/calendar_sync.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non connecte.']);
    exit;
}

$config = require __DIR__ . '/config.php';
$sync = new CalendarSync($config['google_service_account_path'], $config['google_calendar_id']);
$db = getDb();

// Un rendez-vous ne concerne jamais qu'une seule personne (pas de "les deux").
$PERSONNES_VALIDES = [
    isset($config['personne_1']) ? $config['personne_1'] : 'Papa',
    isset($config['personne_2']) ? $config['personne_2'] : 'Maman',
];

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
}

try {
    switch ($action) {
        case 'list':
            echo json_encode(listAppointments($db));
            break;
        case 'add':
            echo json_encode(addAppointment($db, $sync, $input));
            break;
        case 'update':
            echo json_encode(updateAppointmentAction($db, $sync, $input));
            break;
        case 'delete':
            echo json_encode(deleteAppointment($db, $sync, isset($input['id']) ? $input['id'] : ''));
            break;
        case 'bulk_add':
            $liste = isset($input['appointments']) ? $input['appointments'] : [];
            echo json_encode(['count' => bulkAdd($db, $sync, $liste)]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function validateAppt($appt) {
    global $PERSONNES_VALIDES;
    if (empty($appt['date']) || empty($appt['time']) || empty($appt['person'])) {
        throw new Exception("Merci de remplir la date, l'heure et la personne concernee.");
    }
    if (!in_array($appt['person'], $PERSONNES_VALIDES, true)) {
        throw new Exception('Personne invalide.');
    }
}

function listAppointments($db) {
    $stmt = $db->query('SELECT id, appt_date AS date, appt_time AS time, person, doctor, department, location, phone, route, notes FROM appointments ORDER BY appt_date, appt_time');
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (string) $r['id'];
        $r['time'] = substr($r['time'], 0, 5);
    }
    return $rows;
}

function addAppointment($db, $sync, $appt) {
    validateAppt($appt);
    $stmt = $db->prepare('INSERT INTO appointments (appt_date, appt_time, person, doctor, department, location, phone, route, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $appt['date'],
        $appt['time'],
        $appt['person'],
        isset($appt['doctor']) ? $appt['doctor'] : '',
        isset($appt['department']) ? $appt['department'] : '',
        isset($appt['location']) ? $appt['location'] : '',
        isset($appt['phone']) ? $appt['phone'] : '',
        isset($appt['route']) ? $appt['route'] : '',
        isset($appt['notes']) ? $appt['notes'] : '',
    ]);
    $id = $db->lastInsertId();

    $eventId = $sync->createEvent($appt);
    if ($eventId) {
        $upd = $db->prepare('UPDATE appointments SET calendar_event_id = ? WHERE id = ?');
        $upd->execute([$eventId, $id]);
    }

    return ['id' => (string) $id];
}

function bulkAdd($db, $sync, $appts) {
    $count = 0;
    foreach ($appts as $appt) {
        try {
            addAppointment($db, $sync, $appt);
            $count++;
        } catch (Exception $e) {
            // ligne invalide : on l'ignore et on continue les suivantes
        }
    }
    return $count;
}

function updateAppointmentAction($db, $sync, $appt) {
    validateAppt($appt);
    if (empty($appt['id'])) {
        throw new Exception('Identifiant manquant.');
    }

    $stmt = $db->prepare('SELECT calendar_event_id FROM appointments WHERE id = ?');
    $stmt->execute([$appt['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new Exception('Rendez-vous introuvable.');
    }

    $upd = $db->prepare('UPDATE appointments SET appt_date = ?, appt_time = ?, person = ?, doctor = ?, department = ?, location = ?, phone = ?, route = ?, notes = ? WHERE id = ?');
    $upd->execute([
        $appt['date'],
        $appt['time'],
        $appt['person'],
        isset($appt['doctor']) ? $appt['doctor'] : '',
        isset($appt['department']) ? $appt['department'] : '',
        isset($appt['location']) ? $appt['location'] : '',
        isset($appt['phone']) ? $appt['phone'] : '',
        isset($appt['route']) ? $appt['route'] : '',
        isset($appt['notes']) ? $appt['notes'] : '',
        $appt['id'],
    ]);

    $nouvelId = $sync->updateEvent($row['calendar_event_id'], $appt);
    if ($nouvelId !== $row['calendar_event_id']) {
        $upd2 = $db->prepare('UPDATE appointments SET calendar_event_id = ? WHERE id = ?');
        $upd2->execute([$nouvelId, $appt['id']]);
    }

    return ['ok' => true];
}

function deleteAppointment($db, $sync, $id) {
    if (!$id) {
        throw new Exception('Identifiant manquant.');
    }
    $stmt = $db->prepare('SELECT calendar_event_id FROM appointments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $sync->deleteEvent($row['calendar_event_id']);
    }
    $del = $db->prepare('DELETE FROM appointments WHERE id = ?');
    $del->execute([$id]);
    return ['ok' => true];
}