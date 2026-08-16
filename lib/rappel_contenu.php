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
require_once __DIR__ . '/persons.php';

/**
 * Echappement HTML. Nom explicite plutot qu'un h() de trois lettres : ce
 * projet n'a pas d'espaces de noms, et une fonction globale aussi courte
 * finirait tot ou tard par entrer en collision avec une autre.
 */
function echapperHtml($texte) {
    return htmlspecialchars((string) $texte, ENT_QUOTES, 'UTF-8');
}

/**
 * Le plan de prise, prepare pour l'affichage.
 *
 * Une entree par moment de la journee :
 *   ['libelle' => 'Matin', 'medicaments' => [ ... ]]
 * et pour chaque medicament :
 *   ['boites' => [['nom' => 'Paracetamol EG Forte', 'quantite' => '1 comprimé',
 *                  'detail' => ''], ...],
 *    'detail_commun' => '1000 mg — contre la douleur...',
 *    'avec_alternative' => true]
 *
 * DEUX REGLES REPRISES DE LA FICHE IMPRIMEE (voir medicaments.php), pour
 * que le mail dise la meme chose qu'elle :
 *
 *  1. Le detail ne s'ecrit une seule fois, en fin de ligne, QUE s'il est
 *     identique pour toutes les boites. Paracetamol et Dafalgan partagent
 *     "1000 mg - contre la douleur, max 3x/jour" : le repeter allongerait
 *     la ligne sans rien apprendre. Des que les details different
 *     (Escitalopram 5mg x2 / Sipralexa 10mg), chacun reste colle a son nom
 *     - sans quoi on melangerait deux posologies.
 *  2. La quantite ne descend JAMAIS en fin de ligne : elle est propre a
 *     chaque boite, et la deplacer laisserait croire qu'elle vaut pour les
 *     deux.
 *
 * L'avertissement "l'un OU l'autre" est pose une seule fois par
 * medicament, et non par alternative : avec deux alternatives il
 * apparaissait deux fois.
 */
function lignesPlanMedicaments($db, $personId) {
    $sections = [];
    foreach (construirePlan($db, $personId) as $section) {
        $medicaments = [];
        foreach ($section['medicaments'] as $med) {
            $boites = [['nom' => $med['nom'],
                        'detail' => isset($med['detail']) ? $med['detail'] : '',
                        'quantite' => isset($med['quantite']) ? $med['quantite'] : '']];
            foreach ($med['alternatives'] as $alt) {
                $boites[] = ['nom' => $alt['nom'],
                             'detail' => isset($alt['detail']) ? $alt['detail'] : '',
                             'quantite' => isset($alt['quantite']) ? $alt['quantite'] : ''];
            }

            $avecAlternative = count($boites) > 1;
            $detailCommun = '';
            if ($avecAlternative && $boites[0]['detail'] !== '') {
                $detailCommun = $boites[0]['detail'];
                foreach ($boites as $b) {
                    if ($b['detail'] !== $detailCommun) { $detailCommun = ''; break; }
                }
            }
            if ($detailCommun !== '') {
                foreach ($boites as $i => $b) {
                    $boites[$i]['detail'] = '';
                }
            }

            $medicaments[] = [
                'boites' => $boites,
                'detail_commun' => $detailCommun,
                'avec_alternative' => $avecAlternative,
            ];
        }
        if (!empty($medicaments)) {
            $sections[] = ['libelle' => $section['moment']['libelle'], 'medicaments' => $medicaments];
        }
    }
    return $sections;
}

/** Une boite, en texte : "Paracetamol EG Forte 1000 mg — 1 comprimé". */
function libelleBoite($b) {
    $texte = $b['nom'];
    if ($b['detail'] !== '') {
        $texte .= ' ' . $b['detail'];
    }
    if ($b['quantite'] !== '') {
        $texte .= ' — ' . $b['quantite'];
    }
    return $texte;
}

/**
 * Le mois d'une date, en toutes lettres : "août 2026".
 *
 * Sert d'intertitre dans la liste des rendez-vous a venir. C'est LUI qui
 * porte l'annee, et c'est pour cela qu'il existe : la liste va jusqu'a
 * l'annee suivante, et une ligne "mar 18 janv." sans annee serait
 * ambigue. La repeter sur chaque ligne allongerait en revanche une colonne
 * deja etroite sur un telephone.
 */
function libelleMoisFr($date) {
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
             'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime($date . ' 12:00');
    return $mois[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Jour court, sans le mois ni l'annee : "mar 18, 14:00".
 *
 * Le mois est deja dans l'intertitre au-dessus (voir libelleMoisFr). Le
 * jour de la SEMAINE, lui, reste : c'est ce qu'on regarde quand on cherche
 * si une date proposee au comptoir tombe deja sur autre chose.
 */
function jourCourtFr($date, $heure) {
    $jours = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
    $ts = strtotime($date . ' ' . $heure);
    return $jours[(int) date('w', $ts)] . ' ' . (int) date('j', $ts) . ', ' . date('H:i', $ts);
}

/**
 * TOUS les rendez-vous a venir des DEUX personnes, hors celui qui fait
 * l'objet du rappel, regroupes par mois.
 *
 * POURQUOI LES DEUX. Michel et Christiane vont souvent en consultation
 * ensemble, et la question posee au comptoir en repartant est toujours la
 * meme : "vous etes libres quand ?". Avoir les deux agendas sous les yeux
 * evite le rendez-vous pris a l'aveugle qu'il faut deplacer le soir meme.
 * La colonne "Qui" n'est donc pas decorative : sans elle, la liste
 * melangerait deux agendas sans qu'on puisse les demeler.
 *
 * POURQUOI AUCUNE LIMITE. Une premiere version s'arretait a huit semaines
 * et douze lignes, pour ne pas allonger le mail. C'etait passer a cote de
 * l'usage : certains rendez-vous se prennent pour l'annee suivante, et une
 * liste tronquee repond "rien de prevu" pour une date ou il y a en realite
 * deja quelque chose. Une reponse fausse est pire qu'une liste longue -
 * d'autant que cette liste est en fin de message, apres tout ce qui sert
 * pendant la consultation.
 *
 * REGROUPES PAR MOIS pour rester lisibles sur cette longueur, et parce que
 * c'est l'intertitre qui porte l'annee.
 *
 * @return array liste de ['mois' => 'août 2026', 'lignes' => [...]]
 */
function prochainsRendezVous($db, $idExclu) {
    $stmt = $db->prepare(
        'SELECT id, appt_date, appt_time, person_id, person, doctor, department '
        . 'FROM appointments '
        . 'WHERE TIMESTAMP(appt_date, appt_time) > NOW() '
        . 'AND id <> ? '
        . 'ORDER BY appt_date, appt_time'
    );
    $stmt->execute([(int) $idExclu]);

    $groupes = [];
    foreach ($stmt->fetchAll() as $r) {
        $mois = libelleMoisFr($r['appt_date']);
        if (!isset($groupes[$mois])) {
            $groupes[$mois] = ['mois' => $mois, 'lignes' => []];
        }
        $groupes[$mois]['lignes'][] = [
            'quand' => jourCourtFr($r['appt_date'], $r['appt_time']),
            'qui' => ((int) $r['person_id'] > 0) ? nomPerson($db, $r['person_id']) : $r['person'],
            // Un rendez-vous sans medecin renseigne existe (prise de sang,
            // examen) : mieux vaut une case explicite qu'une case vide, ou
            // l'on ne sait pas si l'information manque ou si elle n'a pas
            // ete recopiee.
            'objet' => trim((string) $r['doctor']) !== '' ? $r['doctor'] : '—',
            'service' => trim((string) $r['department']),
        ];
    }

    // Reindexe : les cles textuelles ("août 2026") ne servaient qu'au
    // regroupement, et l'ordre chronologique vient deja du ORDER BY.
    return array_values($groupes);
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
    // Toutes personnes confondues, et non celles du seul patient concerne :
    // voir prochainsRendezVous.
    $autresRdvs = prochainsRendezVous($db, isset($rdv['id']) ? (int) $rdv['id'] : 0);

    return [
        'texte' => rappelEnTexte($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies, $autresRdvs),
        'html'  => rappelEnHtml($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies, $autresRdvs),
    ];
}

function rappelEnTexte($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies, $autresRdvs = []) {
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
            foreach ($section['medicaments'] as $med) {
                $morceaux = [];
                foreach ($med['boites'] as $b) {
                    $morceaux[] = libelleBoite($b);
                }
                // "OU" en majuscules : le texte brut n'a pas de gras, la
                // casse est le seul relief disponible.
                $l[] = '- ' . implode('   OU   ', $morceaux);
                // Le detail commun sur sa propre ligne, comme sur la fiche
                // imprimee ou il est centre sous les deux boites : mis a la
                // suite, il empilait trois tirets ("— 1 comprimé — 1000 mg
                // — contre la douleur") et devenait illisible.
                if ($med['detail_commun'] !== '') {
                    $l[] = '  ' . $med['detail_commun'];
                }
                if ($med['avec_alternative']) {
                    $l[] = '  UN SEUL DES DEUX, jamais les deux ensemble';
                }
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
    if (!empty($autresRdvs)) {
        $l[] = '';
        $l[] = 'TOUS LES AUTRES RENDEZ-VOUS À VENIR';
        foreach ($autresRdvs as $groupe) {
            $l[] = '';
            $l[] = mb_strtoupper($groupe['mois'], 'UTF-8');
            foreach ($groupe['lignes'] as $r) {
                // Le nom en premier, avant la date : en texte brut il n'y a
                // pas de colonnes, et c'est "de qui parle-t-on" qui permet
                // de sauter les lignes qui ne nous concernent pas.
                $ligne = '- ' . $r['qui'] . ' — ' . $r['quand'] . ' — ' . $r['objet'];
                if ($r['service'] !== '') {
                    $ligne .= ' (' . $r['service'] . ')';
                }
                $l[] = $ligne;
            }
        }
    }
    return implode("\n", $l);
}

function rappelEnHtml($nomConcerne, $quand, $infos, $rdv, $questions, $medicaments, $pathologies, $autresRdvs = []) {
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
            foreach ($section['medicaments'] as $med) {
                $morceaux = [];
                foreach ($med['boites'] as $b) {
                    $morceaux[] = echapperHtml(libelleBoite($b));
                }
                // "OU" en gras : c'est le mot qui porte tout le sens de la
                // ligne. Noye dans le reste, il se lit comme un "et".
                $ligne = implode(' <strong>OU</strong> ', $morceaux);
                // Sur sa propre ligne, comme sur la fiche imprimee : a la
                // suite, il empilait trois tirets et devenait illisible.
                if ($med['detail_commun'] !== '') {
                    $ligne .= '<br><span style="font-size:15px;color:#5b6068;">'
                            . echapperHtml($med['detail_commun']) . '</span>';
                }
                if ($med['avec_alternative']) {
                    // Ecrit en toutes lettres, sur sa propre ligne : Chem est
                    // daltonien, et cette precaution ne doit dependre ni de
                    // la couleur ni de la place disponible.
                    $ligne .= '<br><span style="font-size:15px;font-weight:700;color:#993536;">'
                            . 'Un seul des deux, jamais les deux ensemble</span>';
                }
                $o[] = '<li style="margin-bottom:8px;">' . $ligne . '</li>';
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

    // AUTRES RENDEZ-VOUS. Le seul vrai tableau du message, parce que c'est
    // le seul contenu vraiment tabulaire : trois colonnes courtes et de
    // meme nature d'une ligne a l'autre. Les medicaments, eux, restent en
    // liste - leurs lignes n'ont pas la meme forme (une boite, ou deux
    // separees par OU, plus une posologie commune) et un tableau aurait
    // demande des cellules fusionnees pour rien.
    if (!empty($autresRdvs)) {
        $o[] = '<div style="' . $sTitre . '">Tous les autres rendez-vous à venir</div>';
        $o[] = '<div style="' . $sTexte . 'font-size:15px;color:#5b6068;margin:0 0 10px;">'
             . 'Pour vérifier, quand on vous propose une date, que rien n\'est déjà prévu ce jour-là.</div>';
        $o[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">';

        $sEntete = $police . 'font-size:13px;font-weight:700;text-transform:uppercase;'
                 . 'letter-spacing:0.04em;color:#5b6068;padding:0 8px 6px 0;border-bottom:2px solid #e6e8ec;';
        // En-tetes en gris et en petit : ils servent a lire la premiere
        // ligne, pas a etre relus a chaque ligne suivante.
        $o[] = '<tr>'
             . '<th align="left" style="' . $sEntete . '">Qui</th>'
             . '<th align="left" style="' . $sEntete . '">Quand</th>'
             . '<th align="left" style="' . $sEntete . 'padding-right:0;">Médecin</th>'
             . '</tr>';

        $cell = $police . 'font-size:16px;color:#1c1d20;padding:8px 8px 8px 0;'
              . 'vertical-align:top;border-bottom:1px solid #e6e8ec;';

        foreach ($autresRdvs as $groupe) {
            // Intertitre de mois sur toute la largeur : c'est lui qui porte
            // l'annee, et il decoupe une liste qui peut faire trente lignes.
            $o[] = '<tr><td colspan="3" style="' . $police . 'font-size:14px;font-weight:700;'
                 . 'text-transform:uppercase;letter-spacing:0.04em;color:#5b6068;'
                 . 'padding:16px 0 4px;">' . echapperHtml($groupe['mois']) . '</td></tr>';

            foreach ($groupe['lignes'] as $r) {
                $objet = echapperHtml($r['objet']);
                if ($r['service'] !== '') {
                    // Le service sous le nom du medecin plutot qu'a cote :
                    // une quatrieme colonne ramenerait chaque cellule a deux
                    // ou trois caracteres de large sur un telephone.
                    $objet .= '<br><span style="font-size:14px;color:#5b6068;">' . echapperHtml($r['service']) . '</span>';
                }
                $o[] = '<tr>'
                     // Le nom en gras : c'est la colonne qu'on parcourt du
                     // regard pour retrouver ses propres rendez-vous.
                     . '<td style="' . $cell . 'font-weight:700;white-space:nowrap;">' . echapperHtml($r['qui']) . '</td>'
                     . '<td style="' . $cell . 'white-space:nowrap;">' . echapperHtml($r['quand']) . '</td>'
                     . '<td style="' . $cell . 'padding-right:0;">' . $objet . '</td>'
                     . '</tr>';
            }
        }
        $o[] = '</table>';
    }

    $o[] = '<div style="' . $police . 'font-size:13px;color:#8b9099;margin-top:28px;'
         . 'border-top:1px solid #e6e8ec;padding-top:12px;">Envoyé automatiquement par l\'agenda médical.</div>';
    $o[] = '</div></body></html>';

    return implode("\n", $o);
}
