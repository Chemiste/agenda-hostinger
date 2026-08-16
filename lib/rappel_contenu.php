<?php
/**
 * Composition du mail de rappel envoye la veille d'un rendez-vous.
 *
 * DEUX VERSIONS DU MEME CONTENU, texte et HTML, engendrees cote a cote pour
 * ne jamais diverger. Elles partent ensemble dans le message (voir
 * construireCorpsMime dans lib/mailer.php) et le logiciel de messagerie
 * choisit celle qu'il sait afficher.
 *
 * A QUI CE MAIL S'ADRESSE. Michel et Christiane, en salle d'attente, sur
 * leur telephone. C'est ce qui dicte toutes les decisions de mise en
 * forme :
 *
 *  - GRANDES TAILLES. Le mail impose ses tailles en pixels, contrairement
 *    au texte brut qui suivait le reglage du telephone. Si on ne les
 *    choisit pas genereusement, passer en HTML serait une REGRESSION de
 *    lisibilite pour eux. D'ou 17px de base et 22px pour l'essentiel.
 *  - AUCUNE IMAGE. Beaucoup de clients les bloquent par defaut, et la
 *    photo d'une boite de medicament ne sert a rien ici - elle sert a
 *    remplir le pilulier a la maison, pas a repondre au medecin.
 *  - DES TABLEAUX, PAS DE GRILLE NI DE FLEXBOX. Les clients de messagerie
 *    ne les gerent pas, exactement comme les bibliotheques PDF.
 *  - LE STYLE EN LIGNE. Gmail supprime les feuilles de style.
 *  - JAMAIS LA COULEUR SEULE pour porter une information : Chem est
 *    daltonien, et un ecran de telephone en plein soleil ne vaut guere
 *    mieux.
 *
 * L'INFORMATION UTILE D'ABORD. Le rendez-vous en haut, puis les questions
 * a poser, puis les medicaments, puis les pathologies. Quelqu'un qui ouvre
 * le mail devant le medecin doit trouver sans faire defiler.
 */

require_once __DIR__ . '/medicaments.php';
require_once __DIR__ . '/pathologies.php';

/**
 * Echappement HTML. Nom explicite plutot qu'un h() de trois lettres : ce
 * projet n'a pas d'espaces de noms, et une fonction globale aussi courte
 * finirait tot ou tard par entrer en collision avec une autre.
 */
function echapperHtml($texte) {
    return htmlspecialchars((string) $texte, ENT_QUOTES, 'UTF-8');
}

/**
 * Le plan de prise, aplati en lignes lisibles.
 *
 * Une entree par moment de la journee : ['libelle' => 'Matin',
 * 'lignes' => ['Escitalopram 10 mg — 1 comprimé', ...]].
 *
 * Les alternatives sont rendues sur la MEME ligne que leur principal,
 * separees par « OU », et jamais comme deux entrees distinctes : le plan
 * imprime consacre trois signaux redondants a eviter que Michel ou
 * Christiane prenne les deux. Les lister l'un sous l'autre ici defairait
 * cette precaution.
 */
function lignesPlanMedicaments($db, $personId) {
    $sections = [];
    foreach (construirePlan($db, $personId) as $section) {
        $lignes = [];
        foreach ($section['medicaments'] as $med) {
            $texte = $med['nom'];
            if (!empty($med['detail'])) {
                $texte .= ' ' . $med['detail'];
            }
            if (!empty($med['quantite'])) {
                $texte .= ' — ' . $med['quantite'];
            }
            foreach ($med['alternatives'] as $alt) {
                $texteAlt = $alt['nom'];
                if (!empty($alt['detail'])) {
                    $texteAlt .= ' ' . $alt['detail'];
                }
                $texte .= '   OU   ' . $texteAlt . ' (l\'un OU l\'autre, jamais les deux)';
            }
            $lignes[] = $texte;
        }
        if (!empty($lignes)) {
            $sections[] = ['libelle' => $section['moment']['libelle'], 'lignes' => $lignes];
        }
    }
    return $sections;
}

/**
 * Compose les deux versions du rappel.
 *
 * @return array{texte:string, html:string}
 */
function composerRappel($db, $rdv, $nomConcerne, $quand) {
    $personId = (int) $rdv['person_id'];

    $questions = [];
    if (!empty($rdv['questions'])) {
        foreach (preg_split('/\r\n|\r|\n/', trim($rdv['questions'])) as $q) {
            $q = trim($q);
            if ($q !== '') {
                $questions[] = $q;
            }
        }
    }

    $infos = [];
    if (!empty($rdv['doctor']))       $infos['Médecin / consultation'] = $rdv['doctor'];
    if (!empty($rdv['department']))   $infos['Service'] = $rdv['department'];
    if (!empty($rdv['location']))     $infos['Adresse'] = $rdv['location'];
    if (!empty($rdv['route']))        $infos['Route'] = $rdv['route'];
    if (!empty($rdv['phone']))        $infos['Téléphone'] = $rdv['phone'];
    if (!empty($rdv['accompagnant'])) $infos['Accompagné(e) de'] = $rdv['accompagnant'];

    $medicaments = $personId > 0 ? lignesPlanMedicaments($db, $personId) : [];
    $pathologies = $personId > 0 ? listerPathologies($db, $personId) : [];

    return [
        'texte' => rappelEnTexte($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies),
        'html'  => rappelEnHtml($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies),
    ];
}

function rappelEnTexte($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies) {
    $l = [];
    $l[] = 'Rappel de rendez-vous : ' . $quand;
    $l[] = '';
    $l[] = 'Personne concernée : ' . $nomConcerne;
    foreach ($infos as $libelle => $valeur) {
        $l[] = $libelle . ' : ' . $valeur;
    }
    if (!empty($rdv['notes'])) {
        $l[] = '';
        $l[] = 'Notes : ' . $rdv['notes'];
    }
    if (!empty($questions)) {
        $l[] = '';
        $l[] = 'QUESTIONS À POSER';
        foreach ($questions as $q) {
            $l[] = '- ' . $q;
        }
    }
    if (!empty($medicaments)) {
        $l[] = '';
        $l[] = 'MÉDICAMENTS DE ' . mb_strtoupper($nomConcerne, 'UTF-8');
        foreach ($medicaments as $section) {
            $l[] = '';
            $l[] = $section['libelle'] . ' :';
            foreach ($section['lignes'] as $ligne) {
                $l[] = '- ' . $ligne;
            }
        }
    }
    if (!empty($pathologies)) {
        $l[] = '';
        $l[] = 'PATHOLOGIES SUIVIES';
        foreach ($pathologies as $path) {
            $l[] = '';
            $l[] = '- ' . $path['nom'];
            if (!empty($path['cause']))      $l[] = '  Cause : ' . $path['cause'];
            if (!empty($path['traitement'])) $l[] = '  Suivi : ' . $path['traitement'];
        }
    }
    return implode("\n", $l);
}

function rappelEnHtml($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies) {
    // Styles repetes a chaque balise : les clients de messagerie
    // suppriment les feuilles de style, y compris celles placees dans
    // <head>. C'est verbeux, mais c'est la seule facon fiable.
    $police = 'font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;';
    $sTitre = $police . 'font-size:20px;font-weight:700;color:#1c1d20;margin:26px 0 10px;';
    $sTexte = $police . 'font-size:17px;line-height:1.5;color:#1c1d20;margin:0 0 6px;';
    $sCle   = $police . 'font-size:15px;color:#5b6068;padding:5px 12px 5px 0;vertical-align:top;white-space:nowrap;';
    $sVal   = $police . 'font-size:17px;color:#1c1d20;padding:5px 0;vertical-align:top;';

    $o = [];
    $o[] = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">';
    $o[] = '<meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    $o[] = '<body style="margin:0;padding:18px;background:#ffffff;">';
    $o[] = '<div style="max-width:640px;margin:0 auto;">';

    // Le plus gros texte du message : c'est ce qu'on lit en premier.
    $o[] = '<div style="' . $police . 'font-size:22px;font-weight:700;line-height:1.35;color:#1c1d20;">'
         . echapperHtml($nomConcerne) . '</div>';
    $o[] = '<div style="' . $police . 'font-size:22px;line-height:1.35;color:#1c1d20;margin:2px 0 18px;">'
         . echapperHtml($quand) . '</div>';

    if (!empty($infos)) {
        $o[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-top:2px solid #e6e8ec;">';
        foreach ($infos as $libelle => $valeur) {
            $o[] = '<tr><td style="' . $sCle . '">' . echapperHtml($libelle) . '</td>'
                 . '<td style="' . $sVal . '">' . echapperHtml($valeur) . '</td></tr>';
        }
        $o[] = '</table>';
    }

    if (!empty($rdv['notes'])) {
        $o[] = '<div style="' . $sTitre . '">Notes</div>';
        $o[] = '<div style="' . $sTexte . '">' . nl2br(echapperHtml($rdv['notes'])) . '</div>';
    }

    if (!empty($questions)) {
        $o[] = '<div style="' . $sTitre . '">Questions à poser</div>';
        $o[] = '<ul style="' . $police . 'font-size:17px;line-height:1.6;color:#1c1d20;margin:0;padding-left:22px;">';
        foreach ($questions as $q) {
            $o[] = '<li style="margin-bottom:5px;">' . echapperHtml($q) . '</li>';
        }
        $o[] = '</ul>';
    }

    if (!empty($medicaments)) {
        $o[] = '<div style="' . $sTitre . '">Médicaments de ' . echapperHtml($nomConcerne) . '</div>';
        foreach ($medicaments as $section) {
            $o[] = '<div style="' . $police . 'font-size:15px;font-weight:700;text-transform:uppercase;'
                 . 'letter-spacing:0.04em;color:#5b6068;margin:14px 0 4px;">' . echapperHtml($section['libelle']) . '</div>';
            $o[] = '<ul style="' . $police . 'font-size:17px;line-height:1.6;color:#1c1d20;margin:0;padding-left:22px;">';
            foreach ($section['lignes'] as $ligne) {
                $o[] = '<li style="margin-bottom:4px;">' . echapperHtml($ligne) . '</li>';
            }
            $o[] = '</ul>';
        }
    }

    if (!empty($pathologies)) {
        $o[] = '<div style="' . $sTitre . '">Pathologies suivies</div>';
        foreach ($pathologies as $path) {
            $o[] = '<div style="' . $police . 'font-size:17px;font-weight:700;color:#1c1d20;margin:12px 0 2px;">'
                 . echapperHtml($path['nom']) . '</div>';
            if (!empty($path['cause'])) {
                $o[] = '<div style="' . $sTexte . 'font-size:16px;color:#5b6068;">Cause : '
                     . nl2br(echapperHtml($path['cause'])) . '</div>';
            }
            if (!empty($path['traitement'])) {
                $o[] = '<div style="' . $sTexte . 'font-size:16px;color:#5b6068;">Suivi : '
                     . nl2br(echapperHtml($path['traitement'])) . '</div>';
            }
        }
    }

    $o[] = '<div style="' . $police . 'font-size:13px;color:#8b9099;margin-top:28px;'
         . 'border-top:1px solid #e6e8ec;padding-top:12px;">Envoyé automatiquement par l\'agenda médical.</div>';
    $o[] = '</div></body></html>';

    return implode("\n", $o);
}
