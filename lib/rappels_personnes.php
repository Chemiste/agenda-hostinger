<?php
/**
 * Réglages de rappel par personne : son adresse email, et deux choix —
 * « je veux mes propres rappels » et « je veux aussi ceux des autres ».
 *
 * Ces réglages s'appelaient `reminder_email_person1` et
 * `reminder_email_person2` : deux personnes, pas une de plus, et le lien
 * entre « person1 » et Michel n'existait que dans l'ordre de config.php.
 * Ils sont désormais indexés par IDENTIFIANT de personne (table persons,
 * migration 0021), donc valables pour autant de personnes qu'on veut.
 *
 * La lecture retombe sur les anciennes clés pour les deux premiers
 * patients tant que rien n'a été réenregistré : personne ne doit avoir à
 * ressaisir son adresse le jour de la mise à jour. Dès le premier
 * enregistrement depuis mes_rappels.php, les nouvelles clés prennent le
 * relais et les anciennes ne servent plus.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/persons.php';

function cleRappelEmail($personId) { return 'reminder_email_p' . (int) $personId; }
function cleRappelSoi($personId) { return 'reminder_notify_self_p' . (int) $personId; }
function cleRappelAutres($personId) { return 'reminder_notify_other_p' . (int) $personId; }

/**
 * Les réglages de tous les patients : [id => ['email', 'soi', 'autres']].
 *
 * @param array $patients Résultat de listerPatients(), dans l'ordre.
 */
function lireReglagesRappel($db, $patients) {
    // Anciennes cles, utilisees en secours pour les deux premiers patients
    // seulement — c'est tout ce que l'ancien format savait exprimer.
    $ancienPartage = getSetting($db, 'reminder_email_parents', '');
    $anciennes = [
        0 => [
            'email' => getSetting($db, 'reminder_email_person1', $ancienPartage),
            'soi' => getSetting($db, 'reminder_notify_self_person1', '1'),
            'autres' => getSetting($db, 'reminder_notify_other_person1', '0'),
        ],
        1 => [
            'email' => getSetting($db, 'reminder_email_person2', $ancienPartage),
            'soi' => getSetting($db, 'reminder_notify_self_person2', '1'),
            'autres' => getSetting($db, 'reminder_notify_other_person2', '0'),
        ],
    ];

    $reglages = [];
    $rang = 0;
    foreach ($patients as $patient) {
        $id = (int) $patient['id'];
        $secours = isset($anciennes[$rang]) ? $anciennes[$rang] : ['email' => '', 'soi' => '1', 'autres' => '0'];

        $reglages[$id] = [
            'email' => trim(getSetting($db, cleRappelEmail($id), $secours['email'])),
            'soi' => getSetting($db, cleRappelSoi($id), $secours['soi']) === '1',
            'autres' => getSetting($db, cleRappelAutres($id), $secours['autres']) === '1',
        ];
        $rang++;
    }
    return $reglages;
}

function enregistrerReglagesRappel($db, $personId, $email, $soi, $autres) {
    setSetting($db, cleRappelEmail($personId), trim((string) $email));
    setSetting($db, cleRappelSoi($personId), $soi ? '1' : '0');
    setSetting($db, cleRappelAutres($personId), $autres ? '1' : '0');
}

/**
 * À qui envoyer le rappel d'un rendez-vous donné.
 *
 *   - la personne concernée, si elle a une adresse et n'a pas coupé ses
 *     propres rappels ;
 *   - toute autre personne ayant demandé à recevoir aussi les rappels des
 *     autres ;
 *   - l'adresse fixe de Laurent, dans tous les cas.
 *
 * La version précédente traitait explicitement « person1 » et « person2 » :
 * une troisième personne n'aurait jamais rien reçu, silencieusement.
 *
 * @param int $personIdRdv Le patient concerné par le rendez-vous.
 */
function destinatairesRappel($personIdRdv, $reglages, $emailFixe) {
    $personIdRdv = (int) $personIdRdv;
    $destinataires = [];

    if (isset($reglages[$personIdRdv])) {
        $sien = $reglages[$personIdRdv];
        if ($sien['email'] !== '' && $sien['soi']) {
            $destinataires[] = $sien['email'];
        }
    }

    foreach ($reglages as $id => $r) {
        if ((int) $id === $personIdRdv) {
            continue;
        }
        if ($r['autres'] && $r['email'] !== '') {
            $destinataires[] = $r['email'];
        }
    }

    if (trim((string) $emailFixe) !== '') {
        $destinataires[] = trim((string) $emailFixe);
    }

    return array_values(array_unique($destinataires));
}
