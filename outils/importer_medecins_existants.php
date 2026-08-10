<?php
/**
 * OUTIL PONCTUEL : pré-remplit le carnet de médecins (voir
 * migrations/0014_add_medecins.sql, medecins.php) à partir des rendez-vous
 * déjà enregistrés, pour ne pas avoir à ressaisir à la main tous les
 * médecins déjà connus au moment de l'ajout de cette fonctionnalité.
 *
 * Regroupe les rendez-vous par (personne, médecin), garde les
 * coordonnées (département, adresse, téléphone, route) du rendez-vous le
 * plus récent pour chaque médecin - même logique que la mémorisation
 * automatique du formulaire (construireInfosParMedecin() dans
 * assets/app.js) - puis crée une entrée dans le carnet pour chaque
 * médecin qui n'y figure pas encore.
 *
 * Sans danger à relancer plusieurs fois : un médecin déjà présent dans le
 * carnet (même personne + même nom, insensible à la casse) est ignoré,
 * seuls les nouveaux sont ajoutés.
 *
 * A SUPPRIMER une fois utilisé.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/medecins.php';

header('Content-Type: text/plain; charset=utf-8');

$db = getDb();

$stmt = $db->query(
    "SELECT person, doctor, department, location, phone, route, appt_date, appt_time " .
    "FROM appointments WHERE doctor != '' ORDER BY appt_date ASC, appt_time ASC"
);
$rangees = $stmt->fetchAll();

echo "Rendez-vous avec un médecin renseigné : " . count($rangees) . "\n\n";

// Un rendez-vous plus recent (parcouru apres, car trie par date croissante)
// ecrase les coordonnees du precedent pour le meme (personne, medecin) :
// on garde ainsi les infos les plus a jour, comme le fait deja
// l'auto-remplissage du formulaire.
$parMedecin = [];
foreach ($rangees as $r) {
    $doctor = trim($r['doctor']);
    if ($doctor === '') {
        continue;
    }
    $cle = $r['person'] . '|' . mb_strtolower($doctor);
    $parMedecin[$cle] = [
        'person' => $r['person'],
        'doctor' => $doctor,
        'department' => $r['department'],
        'location' => $r['location'],
        'phone' => $r['phone'],
        'route' => $r['route'],
    ];
}

echo "Médecins distincts trouvés (personne + nom) : " . count($parMedecin) . "\n\n";

$verifExistant = $db->prepare('SELECT COUNT(*) FROM medecins WHERE person = ? AND LOWER(doctor) = LOWER(?)');

$crees = 0;
$ignores = 0;
foreach ($parMedecin as $m) {
    $verifExistant->execute([$m['person'], $m['doctor']]);
    if ((int) $verifExistant->fetchColumn() > 0) {
        echo "IGNORE (deja dans le carnet) : {$m['person']} - {$m['doctor']}\n";
        $ignores++;
        continue;
    }
    try {
        ajouterMedecin($db, $m['person'], $m['doctor'], $m['department'], $m['location'], $m['phone'], $m['route'], '');
        echo "AJOUTE : {$m['person']} - {$m['doctor']}\n";
        $crees++;
    } catch (Exception $e) {
        echo "ECHEC  : {$m['person']} - {$m['doctor']} (" . $e->getMessage() . ")\n";
    }
}

echo "\nTermine. $crees medecin(s) ajoute(s) au carnet, $ignores deja present(s) (ignores).\n";
