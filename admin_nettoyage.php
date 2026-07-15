<?php
/**
 * ADMINISTRATION : nettoyage des rendez-vous, import .ics et sauvegardes.
 *
 * Outil de maintenance, protege par un DEUXIEME mot de passe (distinct
 * du mot de passe familial, voir requireAdminLogin() / admin_login.php)
 * pour que le reste de la famille n'y ait pas acces meme s'il tombe sur
 * l'URL de cette page.
 *
 * Outils disponibles :
 *  1. "Importer un fichier .ics" : import ponctuel de rendez-vous depuis
 *     un fichier .ics exporte d'un autre agenda.
 *  2. "Raccourcir les noms complets" : detecte tout seul "pour <Prenom>
 *     <Nom de famille>" (le prenom configure suivi d'un nom de famille
 *     colle par certains imports) et raccourcit en "pour <Prenom>", vu
 *     que la personne est deja indiquee par le badge colore.
 *  3. "Retirer un texte" : on tape un texte exact (ex: une adresse, ou une
 *     phrase comme "Le lieu du rendez-vous"), et on choisit ou le ranger
 *     (Adresse, Telephone, ou nulle part si c'est juste une mention a
 *     supprimer).
 *  4. "Extraction automatique du telephone et de la route" : detecte tout
 *     seul les numeros de telephone (et les mentions "Route NNN" a cote)
 *     colles dans le texte (avec ou sans la mention complete "Le lieu du
 *     rendez-vous ... Route NNN Tel : ...") et les range dans les champs
 *     Telephone et Route.
 *  5. "Sauvegardes" : consulte les sauvegardes automatiques (voir
 *     backup.php) et restaure un rendez-vous supprime par erreur.
 *
 * Pour les outils 2 a 4, si le rendez-vous est deja synchronise avec
 * Google Calendar, l'evenement est mis a jour.
 *
 * A garder sur le serveur : contrairement a import_calendar.php, cet outil
 * n'est pas a usage unique, pas besoin de le supprimer apres usage.
 */

require_once __DIR__ . '/lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/calendar_sync.php';

$config = require __DIR__ . '/config.php';
$sync = new CalendarSync($config['google_service_account_path'], $config['google_calendar_id']);
$db = getDb();

$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';

$erreur = '';
$motifsTexte = isset($_POST['motifs']) ? $_POST['motifs'] : '';
$destinationsValides = ['location', 'phone', 'route', 'supprimer'];
$destination = (isset($_POST['destination']) && in_array($_POST['destination'], $destinationsValides, true))
    ? $_POST['destination'] : 'location';
$resultats = [];
$resultatApplication = null;
$resultatsTel = [];
$resultatApplicationTel = null;
$resultatsNoms = [];
$resultatApplicationNoms = null;

// --- Sauvegardes : lister les fichiers disponibles (voir backup.php) ---
$dossierBackups = __DIR__ . '/backups';
$fichiersBackup = [];
if (is_dir($dossierBackups)) {
    $fichiersBackup = glob($dossierBackups . '/appointments-*.json');
    rsort($fichiersBackup); // noms horodates -> tri alphabetique = tri chronologique
}

function nomBackupValide($nom) {
    return preg_match('/^appointments-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}\.json$/', $nom) === 1;
}

// Sauvegarde choisie (menu deroulant, requete GET en lecture seule) :
// on calcule les rendez-vous presents dans cette sauvegarde mais absents
// de la base actuelle (candidats a une restauration).
$backupSelectionnee = isset($_GET['sauvegarde']) ? basename($_GET['sauvegarde']) : '';
$rendezVousDisparus = [];
$erreurBackup = '';
if ($backupSelectionnee !== '') {
    $cheminBackup = $dossierBackups . '/' . $backupSelectionnee;
    if (!nomBackupValide($backupSelectionnee) || !file_exists($cheminBackup)) {
        $erreurBackup = 'Sauvegarde introuvable.';
    } else {
        $donneesBackup = json_decode(file_get_contents($cheminBackup), true);
        if (!is_array($donneesBackup)) {
            $erreurBackup = 'Ce fichier de sauvegarde est illisible.';
        } else {
            $idsActuels = array_map('intval', array_column($db->query('SELECT id FROM appointments')->fetchAll(), 'id'));
            foreach ($donneesBackup as $ligne) {
                if (!in_array((int) $ligne['id'], $idsActuels, true)) {
                    $rendezVousDisparus[] = $ligne;
                }
            }
            // Les plus recents en premier (plus probable que ce soit ce qu'on cherche).
            usort($rendezVousDisparus, function ($a, $b) {
                return strcmp($b['appt_date'] . $b['appt_time'], $a['appt_date'] . $a['appt_time']);
            });
        }
    }
}

// Restauration effective (creation en base + recreation de l'evenement
// Google Calendar si la synchro est active) des rendez-vous coches.
$nbRestaures = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restaurer_sauvegarde') {
    $nomFichier = basename($_POST['fichier'] ?? '');
    $cheminBackup = $dossierBackups . '/' . $nomFichier;
    $idsARestaurer = isset($_POST['selection']) ? array_map('intval', (array) $_POST['selection']) : [];
    $nbRestaures = 0;

    if (nomBackupValide($nomFichier) && file_exists($cheminBackup) && !empty($idsARestaurer)) {
        $donneesBackup = json_decode(file_get_contents($cheminBackup), true);
        if (is_array($donneesBackup)) {
            $parId = [];
            foreach ($donneesBackup as $ligne) {
                $parId[(int) $ligne['id']] = $ligne;
            }

            foreach ($idsARestaurer as $id) {
                if (!isset($parId[$id])) continue;
                $ligne = $parId[$id];

                // Par securite (ex: double clic, ou id deja repris entre
                // temps par un autre rendez-vous) : on ne restaure pas si
                // cet id existe deja dans la base.
                $existe = $db->prepare('SELECT COUNT(*) FROM appointments WHERE id = ?');
                $existe->execute([$id]);
                if ((int) $existe->fetchColumn() > 0) continue;

                $stmt = $db->prepare('INSERT INTO appointments (id, appt_date, appt_time, person, doctor, department, location, phone, route, notes, calendar_event_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $id,
                    $ligne['appt_date'],
                    $ligne['appt_time'],
                    $ligne['person'],
                    isset($ligne['doctor']) ? $ligne['doctor'] : '',
                    isset($ligne['department']) ? $ligne['department'] : '',
                    isset($ligne['location']) ? $ligne['location'] : '',
                    isset($ligne['phone']) ? $ligne['phone'] : '',
                    isset($ligne['route']) ? $ligne['route'] : '',
                    isset($ligne['notes']) ? $ligne['notes'] : '',
                    '', // nouvel evenement Calendar recree ci-dessous (l'ancien id est perime)
                    isset($ligne['created_at']) ? $ligne['created_at'] : date('Y-m-d H:i:s'),
                ]);

                $appt = [
                    'date' => $ligne['appt_date'],
                    'time' => substr($ligne['appt_time'], 0, 5),
                    'person' => $ligne['person'],
                    'doctor' => isset($ligne['doctor']) ? $ligne['doctor'] : '',
                    'department' => isset($ligne['department']) ? $ligne['department'] : '',
                    'location' => isset($ligne['location']) ? $ligne['location'] : '',
                    'phone' => isset($ligne['phone']) ? $ligne['phone'] : '',
                    'route' => isset($ligne['route']) ? $ligne['route'] : '',
                    'notes' => isset($ligne['notes']) ? $ligne['notes'] : '',
                ];
                $nouvelId = $sync->createEvent($appt);
                if ($nouvelId) {
                    $upd = $db->prepare('UPDATE appointments SET calendar_event_id = ? WHERE id = ?');
                    $upd->execute([$nouvelId, $id]);
                }

                $nbRestaures++;
            }
        }
    }
}

function parseMotifs($texte) {
    $lignes = preg_split('/\r\n|\r|\n/', $texte);
    $motifs = [];
    foreach ($lignes as $l) {
        $l = trim($l);
        if ($l !== '' && !in_array($l, $motifs, true)) {
            $motifs[] = $l;
        }
    }
    return $motifs;
}

// Retire le motif du texte (peu importe la casse) puis nettoie les
// separateurs qui trainent (tirets, virgules, espaces multiples...).
function retirerMotif($texte, $motif) {
    $texte = str_ireplace($motif, '', $texte);
    $texte = preg_replace('/\s*[-—:]\s*$/', '', $texte);
    $texte = preg_replace('/^\s*[-—:]\s*/', '', $texte);
    $texte = preg_replace('/\s{2,}/', ' ', $texte);
    $texte = preg_replace('/(\r?\n\s*){2,}/', "\n", $texte);
    $texte = trim($texte, " \t\n\r\0\x0B-—,:");
    return trim($texte);
}

function normaliserTelephone($texte) {
    return trim(preg_replace('/\s{2,}/', ' ', $texte));
}

// Essaie de reperer un numero de telephone (et, s'il est present juste a
// cote, un numero de "Route") dans un texte. Priorite au format connu
// "Le lieu du rendez-vous : Route NNN Tel.: NN NNN NN NN" (confirmations
// de certains hopitaux), sinon repli sur un numero belge "isole" (avec ou
// sans le mot "Tel" devant).
function detecterTelephone($texte) {
    if ($texte === '') return null;

    if (preg_match('/Le\s+lieu\s+du\s+rendez-vous\s*:?\s*(Route\s*\d+)?\s*T[ée]l\.?\s*:?\s*([0-9][0-9\s.\/]{6,14}[0-9])/iu', $texte, $m)) {
        return [
            'motif' => $m[0],
            'telephone' => normaliserTelephone($m[2]),
            'route' => (isset($m[1]) && $m[1] !== '') ? normaliserTelephone($m[1]) : '',
        ];
    }
    if (preg_match('/(?:T[ée]l\.?\s*:?\s*)?(0[0-9](?:[\s.\/-]?[0-9]){7,8})/u', $texte, $m)) {
        return ['motif' => $m[0], 'telephone' => normaliserTelephone($m[1]), 'route' => ''];
    }
    return null;
}

// Reperer une mention "Route NNN" isolee (sans telephone a cote, par
// exemple parce qu'il a deja ete extrait lors d'un nettoyage precedent).
// Pas de limite de mot en fin de motif : certains imports collent le
// numero directement au mot suivant sans espace (ex. "Route 411Merci..."),
// le "\d+" s'arrete de toute facon au premier caractere non numerique.
function detecterRouteSeule($texte) {
    if ($texte === '') return null;
    if (preg_match('/\bRoute\s*\d+/iu', $texte, $m)) {
        return ['motif' => $m[0], 'route' => normaliserTelephone($m[0])];
    }
    return null;
}

// Analyse un champ de texte (doctor ou notes) : detecte et retire un
// eventuel telephone (avec sa mention "Route NNN" associee si presente),
// puis, s'il reste une mention "Route NNN" isolee, la detecte et la
// retire aussi. Renvoie null si rien trouve dans ce champ.
function analyserTelRoute($texte) {
    if ($texte === '') return null;
    $motifs = [];
    $telephone = '';
    $route = '';
    $resultat = $texte;

    $dTel = detecterTelephone($resultat);
    if ($dTel) {
        $motifs[] = $dTel['motif'];
        $telephone = $dTel['telephone'];
        $route = $dTel['route'];
        $resultat = retirerMotif($resultat, $dTel['motif']);
    }

    if ($route === '') {
        $dRoute = detecterRouteSeule($resultat);
        if ($dRoute) {
            $motifs[] = $dRoute['motif'];
            $route = $dRoute['route'];
            $resultat = retirerMotif($resultat, $dRoute['motif']);
        }
    }

    if ($telephone === '' && $route === '') return null;

    return ['texte' => $resultat, 'motifs' => $motifs, 'telephone' => $telephone, 'route' => $route];
}

function chercherAppointments($db, $motifs) {
    $stmt = $db->query('SELECT id, appt_date AS date, appt_time AS time, person, doctor, department, location, phone, route, notes, calendar_event_id FROM appointments ORDER BY appt_date, appt_time');
    $tous = $stmt->fetchAll();
    $trouves = [];
    foreach ($tous as $r) {
        $matches = [];
        foreach ($motifs as $motif) {
            if ($motif === '') continue;
            if (stripos($r['doctor'], $motif) !== false) {
                $matches[] = ['champ' => 'doctor', 'motif' => $motif];
            }
            if (stripos($r['notes'], $motif) !== false) {
                $matches[] = ['champ' => 'notes', 'motif' => $motif];
            }
        }
        if (!empty($matches)) {
            $r['matches'] = $matches;
            $trouves[] = $r;
        }
    }
    return $trouves;
}

function chercherTelephones($db) {
    $stmt = $db->query('SELECT id, appt_date AS date, appt_time AS time, person, doctor, department, location, phone, route, notes, calendar_event_id FROM appointments ORDER BY appt_date, appt_time');
    $tous = $stmt->fetchAll();
    $trouves = [];
    foreach ($tous as $r) {
        $detectDoctor = analyserTelRoute($r['doctor']);
        $detectNotes = analyserTelRoute($r['notes']);
        if ($detectDoctor || $detectNotes) {
            $r['detect'] = ['doctor' => $detectDoctor, 'notes' => $detectNotes];
            $trouves[] = $r;
        }
    }
    return $trouves;
}

// Reperer "pour Michel Louis" (prenom configure + nom de famille colle
// derriere) et proposer de raccourcir en "pour Michel", puisque le nom de
// la personne est deja affiche a part (badge colore). Le nom de famille
// peut faire 1 ou 2 mots (ex : noms composes).
function detecterNomComplet($texte, $prenom) {
    if ($texte === '' || $prenom === '') return null;
    $pattern = '/\bpour\s+' . preg_quote($prenom, '/') . '\s+[\p{L}\'-]+(?:\s+[\p{L}\'-]+)?/iu';
    if (preg_match($pattern, $texte, $m)) {
        return ['motif' => $m[0], 'remplacement' => 'pour ' . $prenom];
    }
    return null;
}

function chercherNomsComplets($db, $p1, $p2) {
    $stmt = $db->query('SELECT id, appt_date AS date, appt_time AS time, person, doctor, department, location, phone, notes, calendar_event_id FROM appointments ORDER BY appt_date, appt_time');
    $tous = $stmt->fetchAll();
    $trouves = [];
    foreach ($tous as $r) {
        $detect = detecterNomComplet($r['doctor'], $p1) ?: detecterNomComplet($r['doctor'], $p2);
        if ($detect) {
            $r['detect'] = $detect;
            $trouves[] = $r;
        }
    }
    return $trouves;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rechercher') {
    $motifs = parseMotifs($motifsTexte);
    if (empty($motifs)) {
        $erreur = 'Merci de saisir au moins un motif (un texte a rechercher) par ligne.';
    } else {
        $resultats = chercherAppointments($db, $motifs);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'appliquer') {
    $motifs = parseMotifs($motifsTexte);
    $selection = isset($_POST['selection']) ? $_POST['selection'] : [];
    if (!is_array($selection)) $selection = [];

    $modifies = 0;
    $ignoresChampRempli = 0;

    foreach ($selection as $id) {
        $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) continue;

        $doctor = $r['doctor'];
        $notes = $r['notes'];
        $location = $r['location'];
        $telephone = $r['phone'];
        $route = $r['route'];
        $motifTrouve = '';

        foreach ($motifs as $motif) {
            if ($motif === '') continue;
            if (stripos($doctor, $motif) !== false) {
                $doctor = retirerMotif($doctor, $motif);
                $motifTrouve = $motif;
            }
            if (stripos($notes, $motif) !== false) {
                $notes = retirerMotif($notes, $motif);
                $motifTrouve = $motif;
            }
        }

        if ($motifTrouve === '') continue;

        if ($destination === 'phone') {
            if ($telephone !== '') { $ignoresChampRempli++; continue; }
            $telephone = $motifTrouve;
        } elseif ($destination === 'location') {
            if ($location !== '') { $ignoresChampRempli++; continue; }
            $location = $motifTrouve;
        } elseif ($destination === 'route') {
            if ($route !== '') { $ignoresChampRempli++; continue; }
            $route = $motifTrouve;
        }
        // $destination === 'supprimer' : rien de plus, le texte est deja
        // retire des champs doctor/notes ci-dessus.

        $upd = $db->prepare('UPDATE appointments SET doctor = ?, location = ?, phone = ?, route = ?, notes = ? WHERE id = ?');
        $upd->execute([$doctor, $location, $telephone, $route, $notes, $id]);
        $modifies++;

        if (!empty($r['calendar_event_id'])) {
            $appt = [
                'date' => $r['appt_date'],
                'time' => substr($r['appt_time'], 0, 5),
                'person' => $r['person'],
                'doctor' => $doctor,
                'department' => $r['department'],
                'location' => $location,
                'phone' => $telephone,
                'route' => $route,
                'notes' => $notes,
            ];
            $sync->updateEvent($r['calendar_event_id'], $appt);
        }
    }

    $resultatApplication = ['modifies' => $modifies, 'ignores' => $ignoresChampRempli];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rechercher_tel') {
    $resultatsTel = chercherTelephones($db);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'appliquer_tel') {
    $selection = isset($_POST['selection']) ? $_POST['selection'] : [];
    if (!is_array($selection)) $selection = [];

    $modifies = 0;
    $ignores = 0;

    foreach ($selection as $id) {
        $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) continue;

        $doctor = $r['doctor'];
        $notes = $r['notes'];
        $telephone = $r['phone'];
        $route = $r['route'];
        $champIgnore = false;
        $trouve = false;

        $dDoctor = analyserTelRoute($doctor);
        if ($dDoctor) {
            $trouve = true;
            $doctor = $dDoctor['texte'];
            if ($dDoctor['telephone'] !== '') {
                if ($telephone === '') { $telephone = $dDoctor['telephone']; } else { $champIgnore = true; }
            }
            if ($dDoctor['route'] !== '') {
                if ($route === '') { $route = $dDoctor['route']; } else { $champIgnore = true; }
            }
        }
        $dNotes = analyserTelRoute($notes);
        if ($dNotes) {
            $trouve = true;
            $notes = $dNotes['texte'];
            if ($dNotes['telephone'] !== '') {
                if ($telephone === '') { $telephone = $dNotes['telephone']; } else { $champIgnore = true; }
            }
            if ($dNotes['route'] !== '') {
                if ($route === '') { $route = $dNotes['route']; } else { $champIgnore = true; }
            }
        }

        if (!$trouve) continue;

        $upd = $db->prepare('UPDATE appointments SET doctor = ?, notes = ?, phone = ?, route = ? WHERE id = ?');
        $upd->execute([$doctor, $notes, $telephone, $route, $id]);
        $modifies++;
        if ($champIgnore) $ignores++;

        if (!empty($r['calendar_event_id'])) {
            $appt = [
                'date' => $r['appt_date'],
                'time' => substr($r['appt_time'], 0, 5),
                'person' => $r['person'],
                'doctor' => $doctor,
                'department' => $r['department'],
                'location' => $r['location'],
                'phone' => $telephone,
                'route' => $route,
                'notes' => $notes,
            ];
            $sync->updateEvent($r['calendar_event_id'], $appt);
        }
    }

    $resultatApplicationTel = ['modifies' => $modifies, 'ignores' => $ignores];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rechercher_noms') {
    $resultatsNoms = chercherNomsComplets($db, $p1, $p2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'appliquer_noms') {
    $selection = isset($_POST['selection']) ? $_POST['selection'] : [];
    if (!is_array($selection)) $selection = [];

    $modifies = 0;

    foreach ($selection as $id) {
        $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) continue;

        $detect = detecterNomComplet($r['doctor'], $p1) ?: detecterNomComplet($r['doctor'], $p2);
        if (!$detect) continue;

        $doctor = str_ireplace($detect['motif'], $detect['remplacement'], $r['doctor']);

        $upd = $db->prepare('UPDATE appointments SET doctor = ? WHERE id = ?');
        $upd->execute([$doctor, $id]);
        $modifies++;

        if (!empty($r['calendar_event_id'])) {
            $appt = [
                'date' => $r['appt_date'],
                'time' => substr($r['appt_time'], 0, 5),
                'person' => $r['person'],
                'doctor' => $doctor,
                'department' => $r['department'],
                'location' => $r['location'],
                'phone' => $r['phone'],
                'notes' => $r['notes'],
            ];
            $sync->updateEvent($r['calendar_event_id'], $appt);
        }
    }

    $resultatApplicationNoms = ['modifies' => $modifies];
}

// Suggestions : rendez-vous dont le medecin ou les notes contiennent un
// code postal (4 chiffres) suivi de lettres, indice classique d'une
// adresse collee au reste du texte. Juste pour aider a reperer quel texte
// copier-coller dans la zone "motifs" ci-dessus.
$suggestions = [];
try {
    $stmt = $db->query("SELECT DISTINCT doctor AS texte FROM appointments WHERE doctor REGEXP '[0-9]{4}[^0-9]*[A-Za-zÀ-ÿ]' UNION SELECT DISTINCT notes AS texte FROM appointments WHERE notes REGEXP '[0-9]{4}[^0-9]*[A-Za-zÀ-ÿ]'");
    $suggestions = array_column($stmt->fetchAll(), 'texte');
} catch (Exception $e) {
    // Pas grave si la suggestion echoue (ex: REGEXP non supporte) : ce
    // n'est qu'une aide, le formulaire fonctionne sans.
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nettoyage des rendez-vous — Administration</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  .rangee-nett { display:flex; align-items:flex-start; gap:10px; padding:12px 0; border-bottom:1px solid var(--border); }
  .rangee-nett input[type=checkbox] { width:20px; height:20px; margin-top:3px; }
  .rangee-nett .details { flex:1; }
  .rangee-nett .champ-avant { font-size:13px; color:#999; }
  .rangee-nett .champ-apres { font-size:13px; color: var(--accent, #2a7); }
  mark { background: #fff3a0; padding: 0 2px; border-radius: 3px; }
  textarea.motifs { width:100%; min-height:100px; font-family:inherit; font-size:15px; padding:10px; border-radius:8px; border:2px solid var(--border); box-sizing:border-box; }
  .suggestions { font-size:13px; color:#777; margin-top:8px; }
  .suggestions ul { margin:6px 0 0; padding-left:18px; max-height:160px; overflow:auto; }
  .destination-choix { display:flex; flex-direction:column; gap:6px; }
  .destination-choix label { font-weight:400; font-size:15px; display:flex; align-items:center; gap:8px; cursor:pointer; }
  .outil { background:#fff; border-radius:12px; padding:18px; margin-bottom:24px; box-shadow: var(--shadow-sm); }
  .outil h2 { margin-top:0; }
  .barre-admin { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:8px; }
  .barre-admin a { font-size:13px; color:var(--text-muted, #888); }
</style>
</head>
<body>
  <div class="barre-admin">
    <h1 style="margin:0;">Administration</h1>
    <div>
      <a href="index.php">Retour a l'agenda</a>
      &nbsp;·&nbsp;
      <a href="admin_logout.php">Deconnexion admin</a>
    </div>
  </div>
  <p class="sous-titre">Import .ics, correction des rendez-vous existants et sauvegardes.</p>

  <div class="outil">
    <h2>Importer un fichier .ics</h2>
    <p class="sous-titre">Importe des rendez-vous depuis un fichier .ics exporte d'un autre agenda (Google Calendar, Outlook, etc.).</p>
    <button class="secondaire" id="btnImportIcs">Choisir un fichier .ics</button>
    <input type="file" id="fichierIcs" accept=".ics,text/calendar" style="display:none;">
  </div>

  <div class="outil">
    <h2>Extraction automatique du telephone et de la route</h2>
    <p class="sous-titre">Detecte tout seul les numeros de telephone (et les mentions "Route NNN" a cote) dans "Medecin / consultation" et "Notes" — avec ou sans la mention complete "Le lieu du rendez-vous ... Route NNN Tel : ..." — les retire du texte et remplit les champs Telephone et Route.</p>

    <?php if ($resultatApplicationTel !== null): ?>
      <p class="info">
        <?= (int) $resultatApplicationTel['modifies'] ?> rendez-vous corrige(s).
        <?php if ($resultatApplicationTel['ignores'] > 0): ?>
          <?= (int) $resultatApplicationTel['ignores'] ?> avaient deja le champ Telephone ou Route rempli (texte tout de meme nettoye, valeur existante conservee).
        <?php endif; ?>
      </p>
      <p><a href="admin_nettoyage.php">Relancer une recherche</a></p>
    <?php else: ?>

      <form method="post">
        <input type="hidden" name="action" value="rechercher_tel">
        <div class="form-boutons">
          <button class="principal" type="submit">Rechercher les numeros de telephone</button>
        </div>
      </form>

      <?php if (!empty($resultatsTel)): ?>
        <form method="post" style="margin-top:20px;">
          <input type="hidden" name="action" value="appliquer_tel">
          <p><?= count($resultatsTel) ?> rendez-vous trouve(s). Decochez ceux a ne pas corriger.</p>

          <?php foreach ($resultatsTel as $r): ?>
            <div class="rangee-nett">
              <input type="checkbox" checked name="selection[]" value="<?= (int) $r['id'] ?>">
              <div class="details">
                <div style="font-weight:600;"><?= htmlspecialchars($r['date']) ?> a <?= htmlspecialchars(substr($r['time'], 0, 5)) ?> — <?= htmlspecialchars($r['person']) ?></div>
                <?php foreach (['doctor' => 'Medecin / consultation', 'notes' => 'Notes'] as $champCle => $champLabel):
                  $d = $r['detect'][$champCle];
                  if (!$d) continue;
                  $texteChamp = htmlspecialchars($r[$champCle]);
                  foreach ($d['motifs'] as $mot) {
                    $texteChamp = preg_replace('/(' . preg_quote($mot, '/') . ')/i', '<mark>$1</mark>', $texteChamp);
                  }
                ?>
                  <div class="champ-avant"><?= htmlspecialchars($champLabel) ?> : <?= $texteChamp ?></div>
                  <?php if ($d['telephone'] !== ''): ?>
                    <div class="champ-apres">Telephone detecte : <?= htmlspecialchars($d['telephone']) ?></div>
                  <?php endif; ?>
                  <?php if ($d['route'] !== ''): ?>
                    <div class="champ-apres">Route detectee : <?= htmlspecialchars($d['route']) ?></div>
                  <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($r['phone'] !== ''): ?>
                  <div class="champ-avant" style="color:#c60;">Champ Telephone deja rempli ("<?= htmlspecialchars($r['phone']) ?>").</div>
                <?php endif; ?>
                <?php if ($r['route'] !== ''): ?>
                  <div class="champ-avant" style="color:#c60;">Champ Route deja rempli ("<?= htmlspecialchars($r['route']) ?>").</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="form-boutons" style="margin-top:16px;">
            <button class="principal" type="submit">Appliquer la correction</button>
          </div>
        </form>
      <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rechercher_tel'): ?>
        <p class="vide">Aucun numero de telephone detecte.</p>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <div class="outil">
    <h2>Raccourcir les noms complets</h2>
    <p class="sous-titre">Detecte "pour <?= htmlspecialchars($p1) ?> Nom-de-famille" ou "pour <?= htmlspecialchars($p2) ?> Nom-de-famille" dans "Medecin / consultation" (nom de famille colle par certains imports) et le raccourcit en "pour <?= htmlspecialchars($p1) ?>" / "pour <?= htmlspecialchars($p2) ?>" — la personne est de toute facon deja indiquee par le badge colore.</p>

    <?php if ($resultatApplicationNoms !== null): ?>
      <p class="info"><?= (int) $resultatApplicationNoms['modifies'] ?> rendez-vous corrige(s).</p>
      <p><a href="admin_nettoyage.php">Relancer une recherche</a></p>
    <?php else: ?>

      <form method="post">
        <input type="hidden" name="action" value="rechercher_noms">
        <div class="form-boutons">
          <button class="principal" type="submit">Rechercher les noms complets</button>
        </div>
      </form>

      <?php if (!empty($resultatsNoms)): ?>
        <form method="post" style="margin-top:20px;">
          <input type="hidden" name="action" value="appliquer_noms">
          <p><?= count($resultatsNoms) ?> rendez-vous trouve(s). Decochez ceux a ne pas corriger.</p>

          <?php foreach ($resultatsNoms as $r):
            $surligne = preg_replace('/(' . preg_quote($r['detect']['motif'], '/') . ')/i', '<mark>$1</mark>', htmlspecialchars($r['doctor']));
          ?>
            <div class="rangee-nett">
              <input type="checkbox" checked name="selection[]" value="<?= (int) $r['id'] ?>">
              <div class="details">
                <div style="font-weight:600;"><?= htmlspecialchars($r['date']) ?> a <?= htmlspecialchars(substr($r['time'], 0, 5)) ?> — <?= htmlspecialchars($r['person']) ?></div>
                <div class="champ-avant">Medecin / consultation : <?= $surligne ?></div>
                <div class="champ-apres">Deviendra : "<?= htmlspecialchars(str_ireplace($r['detect']['motif'], $r['detect']['remplacement'], $r['doctor'])) ?>"</div>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="form-boutons" style="margin-top:16px;">
            <button class="principal" type="submit">Appliquer la correction</button>
          </div>
        </form>
      <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rechercher_noms'): ?>
        <p class="vide">Aucun nom complet detecte.</p>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <div class="outil">
    <h2>Retirer un texte</h2>
    <p class="sous-titre">Pour un texte exact qui revient tel quel (une adresse, une phrase inutile comme "Le lieu du rendez-vous"...).</p>

    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>

    <?php if ($resultatApplication !== null): ?>
      <p class="info">
        <?= (int) $resultatApplication['modifies'] ?> rendez-vous corrige(s).
        <?php if ($resultatApplication['ignores'] > 0): ?>
          <?= (int) $resultatApplication['ignores'] ?> ignore(s) car le champ de destination etait deja rempli (a corriger manuellement si besoin).
        <?php endif; ?>
      </p>
      <p><a href="index.php">Voir l'agenda</a> · <a href="admin_nettoyage.php">Faire un autre nettoyage</a></p>

    <?php else: ?>

      <form method="post">
        <input type="hidden" name="action" value="rechercher">
        <div class="champ">
          <label>Motifs a rechercher (un texte exact par ligne)</label>
          <textarea class="motifs" name="motifs" placeholder="Avenue Hippocrate, 10, 1200 Bruxelles, Belgique"><?= htmlspecialchars($motifsTexte) ?></textarea>
        </div>

        <div class="champ">
          <label>Ou ranger ce texte ?</label>
          <div class="destination-choix">
            <label><input type="radio" name="destination" value="location" <?= $destination === 'location' ? 'checked' : '' ?>> Dans le champ Adresse</label>
            <label><input type="radio" name="destination" value="phone" <?= $destination === 'phone' ? 'checked' : '' ?>> Dans le champ Telephone</label>
            <label><input type="radio" name="destination" value="route" <?= $destination === 'route' ? 'checked' : '' ?>> Dans le champ Route</label>
            <label><input type="radio" name="destination" value="supprimer" <?= $destination === 'supprimer' ? 'checked' : '' ?>> Nulle part, juste le supprimer</label>
          </div>
        </div>

        <?php if (!empty($suggestions)): ?>
          <div class="suggestions">
            Textes actuellement enregistres qui contiennent un code postal (a copier-coller ci-dessus si c'est une adresse a retirer) :
            <ul>
              <?php foreach ($suggestions as $s): ?>
                <li><?= htmlspecialchars($s) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="form-boutons" style="margin-top:16px;">
          <button class="principal" type="submit">Rechercher</button>
        </div>
      </form>

      <?php if (!empty($resultats)): ?>
        <form method="post" style="margin-top:24px;">
          <input type="hidden" name="action" value="appliquer">
          <input type="hidden" name="motifs" value="<?= htmlspecialchars($motifsTexte) ?>">
          <input type="hidden" name="destination" value="<?= htmlspecialchars($destination) ?>">

          <p><?= count($resultats) ?> rendez-vous trouve(s). Decochez ceux a ne pas corriger.</p>

          <?php foreach ($resultats as $r): ?>
            <div class="rangee-nett">
              <input type="checkbox" checked name="selection[]" value="<?= (int) $r['id'] ?>">
              <div class="details">
                <div style="font-weight:600;"><?= htmlspecialchars($r['date']) ?> a <?= htmlspecialchars(substr($r['time'], 0, 5)) ?> — <?= htmlspecialchars($r['person']) ?></div>
                <?php foreach ($r['matches'] as $m):
                  $label = $m['champ'] === 'doctor' ? 'Medecin / consultation' : 'Notes';
                  $valeurBrute = $m['champ'] === 'doctor' ? $r['doctor'] : $r['notes'];
                  $surligne = preg_replace('/(' . preg_quote($m['motif'], '/') . ')/i', '<mark>$1</mark>', htmlspecialchars($valeurBrute));
                ?>
                  <div class="champ-avant"><?= htmlspecialchars($label) ?> : <?= $surligne ?></div>
                <?php endforeach; ?>
                <?php if ($destination === 'location' && $r['location'] !== ''): ?>
                  <div class="champ-avant" style="color:#c60;">Champ Adresse deja rempli ("<?= htmlspecialchars($r['location']) ?>") : cette ligne sera ignoree.</div>
                <?php elseif ($destination === 'phone' && $r['phone'] !== ''): ?>
                  <div class="champ-avant" style="color:#c60;">Champ Telephone deja rempli ("<?= htmlspecialchars($r['phone']) ?>") : cette ligne sera ignoree.</div>
                <?php elseif ($destination === 'route' && $r['route'] !== ''): ?>
                  <div class="champ-avant" style="color:#c60;">Champ Route deja rempli ("<?= htmlspecialchars($r['route']) ?>") : cette ligne sera ignoree.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="form-boutons" style="margin-top:16px;">
            <button class="principal" type="submit">Appliquer la correction</button>
          </div>
        </form>
      <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rechercher' && !$erreur): ?>
        <p class="vide">Aucun rendez-vous ne contient ce texte.</p>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <div class="outil">
    <h2>Sauvegardes</h2>
    <p class="sous-titre">Une sauvegarde automatique (voir backup.php et le guide d'installation pour la configurer via un Cron Job Hostinger) exporte tous les rendez-vous chaque jour. En cas de suppression accidentelle, choisissez une sauvegarde d'avant la suppression : les rendez-vous qui y figurent mais qui ont disparu de l'agenda actuel sont proposes a la restauration.</p>

    <?php if (empty($fichiersBackup)): ?>
      <p class="vide">Aucune sauvegarde trouvee pour l'instant. Verifiez que le Cron Job de sauvegarde est bien configure (voir le guide d'installation).</p>
    <?php else: ?>

      <?php if ($nbRestaures !== null): ?>
        <p class="info">
          <?= (int) $nbRestaures ?> rendez-vous restaure(s)<?= $nbRestaures > 0 ? ' (et resynchronise(s) avec Google Calendar si active)' : '' ?>.
        </p>
        <p><a href="admin_nettoyage.php">Retour aux sauvegardes</a></p>
      <?php else: ?>

        <form method="get" style="margin-bottom:16px;">
          <div class="champ">
            <label for="sauvegarde">Choisir une sauvegarde</label>
            <select name="sauvegarde" id="sauvegarde" onchange="this.form.submit()" style="width:100%; font-size:16px; padding:12px; border-radius:8px; border:1.5px solid var(--border);">
              <option value="">— Selectionner une date —</option>
              <?php foreach ($fichiersBackup as $chemin):
                $nom = basename($chemin);
                $horodatage = preg_replace('/^appointments-([0-9]{4})-([0-9]{2})-([0-9]{2})-([0-9]{2})([0-9]{2})\.json$/', '$3/$2/$1 a $4:$5', $nom);
              ?>
                <option value="<?= htmlspecialchars($nom) ?>" <?= $nom === $backupSelectionnee ? 'selected' : '' ?>><?= htmlspecialchars($horodatage) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>

        <?php if ($erreurBackup): ?>
          <p class="erreur"><?= htmlspecialchars($erreurBackup) ?></p>
        <?php elseif ($backupSelectionnee !== ''): ?>
          <?php if (empty($rendezVousDisparus)): ?>
            <p class="vide">Aucun rendez-vous de cette sauvegarde ne manque dans l'agenda actuel.</p>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="action" value="restaurer_sauvegarde">
              <input type="hidden" name="fichier" value="<?= htmlspecialchars($backupSelectionnee) ?>">
              <p><?= count($rendezVousDisparus) ?> rendez-vous de cette sauvegarde manque(nt) actuellement. Decochez ceux a ne pas restaurer.</p>

              <?php foreach ($rendezVousDisparus as $r): ?>
                <div class="rangee-nett">
                  <input type="checkbox" checked name="selection[]" value="<?= (int) $r['id'] ?>">
                  <div class="details">
                    <div style="font-weight:600;"><?= htmlspecialchars($r['appt_date']) ?> a <?= htmlspecialchars(substr($r['appt_time'], 0, 5)) ?> — <?= htmlspecialchars($r['person']) ?></div>
                    <div class="champ-avant"><?= htmlspecialchars(isset($r['doctor']) ? $r['doctor'] : '') ?></div>
                    <?php if (!empty($r['department'])): ?>
                      <div class="champ-avant"><?= htmlspecialchars($r['department']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($r['location'])): ?>
                      <div class="champ-avant"><?= htmlspecialchars($r['location']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>

              <div class="form-boutons" style="margin-top:16px;">
                <button class="principal" type="submit">Restaurer la selection</button>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>

      <?php endif; ?>
    <?php endif; ?>
  </div>

  <p style="margin-top:2rem;"><a href="index.php">Retour a l'agenda</a></p>

  <div class="overlay" id="overlay"></div>

  <div id="icsCard" class="modal">
    <div class="modal-corps">
      <h2>Rendez-vous trouves dans le fichier</h2>
      <p class="erreur" id="erreurIcs"></p>
      <div id="listeIcs"></div>
    </div>
    <div class="form-boutons">
      <button class="principal" id="btnImporterSelection">Importer la selection</button>
      <button class="secondaire" id="btnAnnulerIcs">Annuler</button>
    </div>
  </div>

  <script>
    window.PERSONNE_1 = <?= json_encode($p1) ?>;
    window.PERSONNE_2 = <?= json_encode($p2) ?>;
  </script>
  <script src="assets/admin.js"></script>
</body>
</html>