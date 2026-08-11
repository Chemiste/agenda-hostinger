<?php
/**
 * OUTIL PONCTUEL : insere le plan de prise de medicaments de Christiane
 * tel qu'il figurait dans le PDF "Traitement de Christiane - Plan de
 * prise quotidien" (prescription du Dr Aurore Diricq, 8/7/2026), pour ne
 * pas avoir a ressaisir a la main tous les medicaments un par un dans
 * medicaments.php au moment de la mise en place de cette fonctionnalite.
 *
 * Les photos des boites (extraites du PDF d'origine) sont copiees dans
 * medicaments_photos/ et referencees ici. Ce script ne fait qu'inserer
 * les lignes en base : si tu executes l'import sur un environnement
 * different de celui ou ce depot a ete prepare (ex. serveur de
 * production), pense a envoyer aussi les fichiers du dossier
 * medicaments_photos/ (asa-eg.jpg, atorstatine-eg.jpg, escitalopram.jpg,
 * nebivolol-eg.jpg, pantoprazole-eg.jpg, keppra.jpg, lyrica.jpg,
 * hylo-dual-intense.jpg, calcium-eg-forte.jpg, lormetazepam-eg.jpg,
 * dafalgan-forte.jpg) par le meme moyen que d'habitude (FTP).
 *
 * Sans danger a relancer plusieurs fois : un medicament deja present
 * (meme moment + meme nom, insensible a la casse) est ignore, et le
 * medecin/la date ne sont renseignes que s'ils sont encore vides (pour
 * ne jamais ecraser une valeur deja mise a jour a la main depuis
 * medicaments.php).
 *
 * A SUPPRIMER une fois utilise.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/medicaments.php';
require_once __DIR__ . '/../lib/settings.php';

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../config.php';
$personneCible = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';

$db = getDb();

// [nom, quantite, detail, image] par moment, dans l'ordre du PDF
// d'origine. Le nom de fichier "image" correspond a un fichier deja
// present dans medicaments_photos/ (extrait du PDF d'origine).
$plan = [
    'Matin' => [
        ['ASA EG', '1 comprimé', '100 mg — anti-coagulant', 'asa-eg.jpg'],
        ['Atorstatine EG', '1 comprimé', '20 mg — cholestérol', 'atorstatine-eg.jpg'],
        ['Escitalopram', '2 comprimés', '5 mg x2 = 10 mg — antidépresseur', 'escitalopram.jpg'],
        ['Nebivolol EG', '1 comprimé', '5 mg — tension', 'nebivolol-eg.jpg'],
        ['Pantoprazole EG', '1 comprimé', '40 mg — estomac', 'pantoprazole-eg.jpg'],
        ['Keppra', '1 comprimé', '1000 mg — antiépileptique', 'keppra.jpg'],
        ['Lyrica', '1 gélule', '25 mg — douleurs neuropathiques', 'lyrica.jpg'],
        ['Hylo Dual Intense', '1 goutte / œil', 'collyre — yeux secs', 'hylo-dual-intense.jpg'],
    ],
    'Soir' => [
        ['Calcium EG Forte', '1 comprimé', 'à croquer — calcium', 'calcium-eg-forte.jpg'],
        ['Keppra', '1 comprimé', '1000 mg — antiépileptique', 'keppra.jpg'],
        ['Hylo Dual Intense', '1 goutte / œil', 'collyre — yeux secs', 'hylo-dual-intense.jpg'],
    ],
    'Au coucher' => [
        ['Lormetazepam EG', '2 comprimés', '2 mg x2 = 4 mg — somnifère', 'lormetazepam-eg.jpg'],
    ],
    'Si besoin (douleur)' => [
        ['Dafalgan Forte', '1 comprimé', '1000 mg — effervescent, contre la douleur, max 3x/jour espacé de 8h', 'dafalgan-forte.jpg'],
    ],
];

$dossierPhotos = __DIR__ . '/../medicaments_photos/';

$verifExistant = $db->prepare(
    'SELECT * FROM medicaments WHERE person = ? AND moment = ? AND LOWER(nom) = LOWER(?)'
);

$crees = 0;
$completes = 0;
$ignores = 0;
foreach ($plan as $moment => $medicaments) {
    foreach ($medicaments as $m) {
        list($nom, $quantite, $detail, $image) = $m;

        if ($image !== '' && !is_file($dossierPhotos . $image)) {
            echo "  (attention : photo $image introuvable sur ce serveur, medicament traite sans photo)\n";
            $image = '';
        }

        $verifExistant->execute([$personneCible, $moment, $nom]);
        $existant = $verifExistant->fetch();

        if ($existant !== false) {
            // Deja importe lors d'un lancement precedent : on complete
            // juste la photo si elle manque encore, sans rien dupliquer
            // ni ecraser une photo deja choisie a la main.
            if ($existant['image'] === '' && $image !== '') {
                modifierMedicament($db, $existant['id'], $existant['moment'], $existant['nom'], $existant['quantite'], $existant['detail'], $image);
                echo "PHOTO AJOUTEE : $moment - $nom\n";
                $completes++;
            } else {
                echo "IGNORE (deja present) : $moment - $nom\n";
                $ignores++;
            }
            continue;
        }

        ajouterMedicament($db, $personneCible, $moment, $nom, $quantite, $detail, $image);
        echo "AJOUTE : $moment - $nom" . ($image !== '' ? " (avec photo)" : "") . "\n";
        $crees++;
    }
}

if (getSetting($db, 'medicaments_medecin', '') === '') {
    setSetting($db, 'medicaments_medecin', 'Aurore Diricq');
    echo "REGLAGE : medecin prescripteur = Aurore Diricq\n";
}
if (getSetting($db, 'medicaments_date', '') === '') {
    setSetting($db, 'medicaments_date', '2026-07-08');
    echo "REGLAGE : date de prescription = 2026-07-08\n";
}

echo "\nTermine. $crees medicament(s) ajoute(s), $completes photo(s) completee(s), $ignores deja present(s) et inchange(s).\n";
