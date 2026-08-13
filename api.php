<?php
/**
 * API JSON utilisée par index.php (fetch côté navigateur).
 * Actions : list, add, update, delete, bulk_add, taches (lecture seule),
 * tache_toggle, medecins (lecture seule), pathologies (lecture seule),
 * importer_medecin (Laurent uniquement).
 *
 * "questions" : liste libre de questions a poser au professionnel lors du
 * rendez-vous (une par ligne), affichee sur la carte et imprimee avec le
 * reste (impression detaillee uniquement, comme "notes" - le mode
 * "compact" reste volontairement minimal, voir assets/app.js).
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/calendar_sync.php';
require_once __DIR__ . '/lib/address_aliases.php';
require_once __DIR__ . '/lib/activity_log.php';
require_once __DIR__ . '/lib/taches.php';
require_once __DIR__ . '/lib/medecins.php';
require_once __DIR__ . '/lib/pathologies.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non connecté.']);
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
        case 'taches':
            // Utilise pour l'impression et le widget "Taches" de l'accueil
            // (voir assets/app.js) : la gestion complete (ajout,
            // suppression, date cible...) reste sur la page dediee
            // taches.php, ceci ne sert qu'a la lecture.
            echo json_encode(listerTachesOuvertes($db));
            break;
        case 'tache_toggle':
            // Cocher/decocher une tache directement depuis le widget de
            // l'accueil, sans quitter la page (contrairement a taches.php
            // qui reste en soumission de formulaire classique).
            definirTacheFaite($db, isset($input['id']) ? $input['id'] : 0, !empty($input['fait']));
            echo json_encode(['ok' => true]);
            break;
        case 'medecins':
            // Carnet de medecins de reference (voir medecins.php pour la
            // gestion complete) : lecture seule, fusionne cote JS avec
            // l'auto-remplissage base sur l'historique des rendez-vous
            // (voir construireInfosParMedecin()/chargerCarnetMedecins()
            // dans assets/app.js).
            echo json_encode(listerMedecins($db));
            break;
        case 'pathologies':
            // Alimente le menu "Pathologie concernee" du formulaire de
            // rendez-vous (voir assets/app.js) : la liste affichee est
            // filtree cote JS selon la personne cochee. Lecture seule -
            // la gestion complete reste sur pathologies.php.
            $parPersonne = [];
            foreach ($PERSONNES_VALIDES as $personne) {
                $parPersonne[$personne] = array_map(function ($p) {
                    return ['id' => (string) $p['id'], 'nom' => $p['nom']];
                }, listerPathologies($db, $personne));
            }
            echo json_encode($parPersonne);
            break;
        case 'importer_medecin':
            // Bouton "Importer dans le carnet" sur le formulaire de
            // rendez-vous (voir assets/app.js) : reserve a Laurent, comme
            // le lien "Administration" - meme protection cote serveur
            // (masquer le bouton cote JS ne suffit pas).
            if (personneSessionActuelle() !== 'Laurent') {
                http_response_code(403);
                echo json_encode(['error' => 'Action réservée à Laurent.']);
                break;
            }
            $statut = fusionnerMedecinDepuisRdv(
                $db,
                isset($input['person']) ? $input['person'] : '',
                isset($input['doctor']) ? $input['doctor'] : '',
                isset($input['department']) ? $input['department'] : '',
                isset($input['location']) ? $input['location'] : '',
                isset($input['phone']) ? $input['phone'] : '',
                isset($input['route']) ? $input['route'] : ''
            );
            echo json_encode(['ok' => true, 'statut' => $statut]);
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
        throw new Exception("Merci de remplir la date, l'heure et la personne concernée.");
    }
    if (!in_array($appt['person'], $PERSONNES_VALIDES, true)) {
        throw new Exception('Personne invalide.');
    }
}

function listAppointments($db) {
    $stmt = $db->query('SELECT id, appt_date AS date, appt_time AS time, duration_minutes AS duration, person, doctor, department, location, phone, route, accompagnant, notes, questions, pathologie_id FROM appointments ORDER BY appt_date, appt_time');
    $rows = $stmt->fetchAll();

    // "location_affichage" : version simplifiee de l'adresse pour
    // l'affichage/l'impression uniquement (ex: "Avenue Hippocrate, 10,
    // 1200 Bruxelles" -> "Hopital St Luc"), voir lib/address_aliases.php.
    // "location" reste toujours l'adresse reelle : c'est elle qui est
    // enregistree et envoyee a Google Calendar (edition du rendez-vous,
    // navigation Waze/Maps depuis le calendrier partage).
    $aliases = listerAliasAdresses($db);

    // "pathologie_nom" : nom lisible de la pathologie associee, resolu ici
    // une bonne fois (une seule requete) plutot que par une jointure a
    // chaque ligne. Reste vide si le rendez-vous n'est rattache a aucune
    // pathologie (pathologie_id = 0) ou si celle-ci a ete supprimee depuis.
    $nomsPathologies = [];
    foreach ($db->query('SELECT id, nom FROM pathologies')->fetchAll() as $p) {
        $nomsPathologies[(int) $p['id']] = $p['nom'];
    }

    foreach ($rows as &$r) {
        $r['id'] = (string) $r['id'];
        $r['time'] = substr($r['time'], 0, 5);
        $r['duration'] = (int) $r['duration'];
        $r['location_affichage'] = empty($aliases) ? $r['location'] : appliquerAliasAdresse($r['location'], $aliases);
        $idPatho = (int) $r['pathologie_id'];
        $r['pathologie_id'] = (string) $idPatho;
        $r['pathologie_nom'] = isset($nomsPathologies[$idPatho]) ? $nomsPathologies[$idPatho] : '';
    }
    return $rows;
}

function dureeAppt($appt) {
    $duree = isset($appt['duration']) ? (int) $appt['duration'] : 30;
    return $duree > 0 ? $duree : 30;
}

function addAppointment($db, $sync, $appt) {
    validateAppt($appt);
    $stmt = $db->prepare('INSERT INTO appointments (appt_date, appt_time, duration_minutes, person, doctor, department, location, phone, route, accompagnant, notes, questions, pathologie_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $appt['date'],
        $appt['time'],
        dureeAppt($appt),
        $appt['person'],
        isset($appt['doctor']) ? $appt['doctor'] : '',
        isset($appt['department']) ? $appt['department'] : '',
        isset($appt['location']) ? $appt['location'] : '',
        isset($appt['phone']) ? $appt['phone'] : '',
        isset($appt['route']) ? $appt['route'] : '',
        isset($appt['accompagnant']) ? $appt['accompagnant'] : '',
        isset($appt['notes']) ? $appt['notes'] : '',
        isset($appt['questions']) ? $appt['questions'] : '',
        isset($appt['pathologie_id']) ? (int) $appt['pathologie_id'] : 0,
    ]);
    $id = $db->lastInsertId();

    $eventId = $sync->createEvent($appt);
    if ($eventId) {
        $upd = $db->prepare('UPDATE appointments SET calendar_event_id = ? WHERE id = ?');
        $upd->execute([$eventId, $id]);
    }

    enregistrerActivite($db, 'ajout', personneSessionActuelle(), resumeActivite($appt), $id);

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

    $stmt = $db->prepare('SELECT calendar_event_id, appt_date, appt_time FROM appointments WHERE id = ?');
    $stmt->execute([$appt['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new Exception('Rendez-vous introuvable.');
    }

    // Si la date/heure change, on remet le rappel à zéro pour qu'il soit
    // renvoyé au bon moment pour le nouvel horaire (voir cron/rappels.php).
    $dateHeureChangee = ($row['appt_date'] !== $appt['date']) || (substr($row['appt_time'], 0, 5) !== $appt['time']);

    $upd = $db->prepare(
        'UPDATE appointments SET appt_date = ?, appt_time = ?, duration_minutes = ?, person = ?, doctor = ?, department = ?, location = ?, phone = ?, route = ?, accompagnant = ?, notes = ?, questions = ?, pathologie_id = ?'
        . ($dateHeureChangee ? ', reminder_sent_at = NULL' : '')
        . ' WHERE id = ?'
    );
    $upd->execute([
        $appt['date'],
        $appt['time'],
        dureeAppt($appt),
        $appt['person'],
        isset($appt['doctor']) ? $appt['doctor'] : '',
        isset($appt['department']) ? $appt['department'] : '',
        isset($appt['location']) ? $appt['location'] : '',
        isset($appt['phone']) ? $appt['phone'] : '',
        isset($appt['route']) ? $appt['route'] : '',
        isset($appt['accompagnant']) ? $appt['accompagnant'] : '',
        isset($appt['notes']) ? $appt['notes'] : '',
        isset($appt['questions']) ? $appt['questions'] : '',
        isset($appt['pathologie_id']) ? (int) $appt['pathologie_id'] : 0,
        $appt['id'],
    ]);

    $nouvelId = $sync->updateEvent($row['calendar_event_id'], $appt);
    if ($nouvelId !== $row['calendar_event_id']) {
        $upd2 = $db->prepare('UPDATE appointments SET calendar_event_id = ? WHERE id = ?');
        $upd2->execute([$nouvelId, $appt['id']]);
    }

    enregistrerActivite($db, 'modification', personneSessionActuelle(), resumeActivite($appt), $appt['id']);

    return ['ok' => true];
}

function deleteAppointment($db, $sync, $id) {
    if (!$id) {
        throw new Exception('Identifiant manquant.');
    }
    // Recupere les infos du rendez-vous AVANT suppression, pour que le
    // resume du journal reste lisible une fois la ligne effacee.
    $stmt = $db->prepare('SELECT calendar_event_id, appt_date AS date, appt_time AS time, doctor, person FROM appointments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $sync->deleteEvent($row['calendar_event_id']);
    }
    $del = $db->prepare('DELETE FROM appointments WHERE id = ?');
    $del->execute([$id]);

    if ($row) {
        enregistrerActivite($db, 'suppression', personneSessionActuelle(), resumeActivite($row), $id);
    }

    return ['ok' => true];
}
