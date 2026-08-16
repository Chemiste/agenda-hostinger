<?php
/**
 * ADMINISTRATION : rappels par email.
 *
 * La page est organisee dans l'ordre ou on s'en sert :
 *   1. les reglages (actifs ? quel delai ? quelles adresses ?)
 *   2. verifier que l'envoi fonctionne (une phrase fixe)
 *   3. relire un VRAI rappel avant que Michel et Christiane ne le recoivent
 *
 * POINT IMPORTANT SUR LES FORMULAIRES. Il y en a plusieurs sur cette page,
 * et ils sont independants. Les reglages sont donc TOUJOURS lus en base ;
 * ils ne sont remplaces par le contenu du formulaire que si c'est bien le
 * formulaire des reglages qui a ete envoye.
 *
 * Sans cette precaution, cliquer sur un bouton d'une autre section faisait
 * lire des champs absents de la requete : l'adresse email devenait vide
 * ("renseigne ton adresse" alors qu'elle etait enregistree), et la case
 * "activer" passait a zero, ce qui grisait tout le bloc au-dessus. Un seul
 * defaut, deux symptomes incomprehensibles.
 *
 * COROLLAIRE : le test et l'apercu utilisent les valeurs ENREGISTREES, pas
 * ce qui est affiche a l'ecran. Modifier un champ sans enregistrer puis
 * cliquer sur "tester" testerait sinon autre chose que ce que le cron
 * utilisera - exactement le genre de verification qui rassure a tort.
 *
 * Les adresses de Michel et Christiane et leurs preferences ne sont PAS
 * ici : chacun les gere depuis mes_rappels.php.
 *
 * L'envoi reel est fait par cron/rappels.php, appele par un Cron Job
 * Hostinger. Cette page ne fait qu'enregistrer ce qu'il utilisera.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/entete_admin.php';
require_once __DIR__ . '/../lib/rappel_contenu.php';
require_once __DIR__ . '/../lib/persons.php';

$config = require __DIR__ . '/../config.php';
$configSmtp = construireConfigSmtp($config);
$db = getDb();

$defauts = [
    'reminder_enabled' => '0',
    'reminder_hours_before' => '24',
    'reminder_email_chem' => '',
    'reminder_email_from' => 'agenda@hellau.be',
];

// Toujours depuis la base : c'est l'etat de reference.
$valeurs = [];
foreach ($defauts as $cle => $defaut) {
    $valeurs[$cle] = getSetting($db, $cle, $defaut);
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$messageEnregistre = false;
$resultatTest = null;
$resultatApercu = null;
$apercuHtml = '';

// --- 1. Enregistrement des reglages ------------------------------------
if ($action === 'enregistrer') {
    $valeurs['reminder_enabled'] = !empty($_POST['reminder_enabled']) ? '1' : '0';
    $valeurs['reminder_hours_before'] = isset($_POST['reminder_hours_before'])
        ? (string) max(1, (int) $_POST['reminder_hours_before'])
        : $valeurs['reminder_hours_before'];
    $valeurs['reminder_email_chem'] = isset($_POST['reminder_email_chem']) ? trim($_POST['reminder_email_chem']) : '';
    $valeurs['reminder_email_from'] = isset($_POST['reminder_email_from']) ? trim($_POST['reminder_email_from']) : '';

    foreach ($valeurs as $cle => $val) {
        setSetting($db, $cle, $val);
    }
    $messageEnregistre = true;
}

// --- 2. Email de test --------------------------------------------------
if ($action === 'tester') {
    if ($valeurs['reminder_email_chem'] === '') {
        $resultatTest = ['ok' => false, 'message' => "Aucune adresse enregistrée : remplis « Ton adresse email » ci-dessus, puis Enregistrer."];
    } else {
        $corps = "Ceci est un email de test envoyé depuis la page de réglages de l'agenda médical.\n\n"
            . "Si tu reçois ce message, l'envoi fonctionne.";
        $envoi = envoyerEmail([$valeurs['reminder_email_chem']], 'Test - Agenda medical',
                              $corps, $valeurs['reminder_email_from'], $configSmtp);
        $resultatTest = $envoi['ok']
            ? ['ok' => true, 'message' => 'Envoyé à ' . $valeurs['reminder_email_chem'] . '.']
            : ['ok' => false, 'message' => "Échec : " . $envoi['erreur']];
    }
}

// --- 3. Apercu d'un vrai rappel ----------------------------------------
//
// AUCUN effet de bord : reminder_sent_at n'est jamais touche. Sans cette
// precaution, previsualiser un rappel empecherait le vrai de partir -
// l'outil de verification casserait ce qu'il sert a verifier.

$rdvsAVenir = $db->query(
    'SELECT id, appt_date, appt_time, person_id, person, doctor, department, location, '
    . 'phone, route, accompagnant, notes, questions, rappel_actif '
    . 'FROM appointments WHERE TIMESTAMP(appt_date, appt_time) > NOW() '
    . 'ORDER BY appt_date, appt_time LIMIT 50'
)->fetchAll();

function libelleQuand($date, $heure) {
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
             'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime($date . ' ' . $heure);
    return $jours[(int) date('w', $ts)] . ' ' . (int) date('j', $ts) . ' '
         . $mois[(int) date('n', $ts)] . ' ' . date('Y', $ts) . ' à ' . date('H:i', $ts);
}

if ($action === 'apercu' || $action === 'envoyer_apercu') {
    $idChoisi = isset($_POST['rdv_id']) ? (int) $_POST['rdv_id'] : 0;
    $rdv = null;
    foreach ($rdvsAVenir as $r) {
        if ((int) $r['id'] === $idChoisi) { $rdv = $r; break; }
    }

    if ($rdv === null) {
        $resultatApercu = ['ok' => false, 'message' => 'Choisis un rendez-vous dans la liste.'];
    } else {
        $quandA = libelleQuand($rdv['appt_date'], $rdv['appt_time']);
        $nomA = ((int) $rdv['person_id'] > 0) ? nomPerson($db, $rdv['person_id']) : $rdv['person'];
        $message = composerRappel($db, $rdv, $nomA, $quandA);

        if ($action === 'apercu') {
            $apercuHtml = $message['html'];
        } elseif ($valeurs['reminder_email_chem'] === '') {
            $resultatApercu = ['ok' => false, 'message' => "Aucune adresse enregistrée : remplis « Ton adresse email » dans les réglages ci-dessus, puis Enregistrer."];
        } else {
            $envoi = envoyerEmail(
                [$valeurs['reminder_email_chem']],
                '[Aperçu] Rappel : rendez-vous ' . $nomA . ' - ' . $quandA,
                $message['texte'], $valeurs['reminder_email_from'], $configSmtp, $message['html']
            );
            $resultatApercu = $envoi['ok']
                ? ['ok' => true, 'message' => 'Aperçu envoyé à ' . $valeurs['reminder_email_chem']
                    . '. Le rendez-vous n\'est pas marqué comme rappelé : le vrai rappel partira normalement.']
                : ['ok' => false, 'message' => "Échec : " . $envoi['erreur']];
        }
    }
}

// Hostinger exige que l'expediteur corresponde a la boite authentifiee.
// Sinon le message est refuse - ou pire, accepte puis supprime en silence.
$expediteurIncoherent = $configSmtp !== null
    && $configSmtp['utilisateur'] !== ''
    && $valeurs['reminder_email_from'] !== ''
    && strcasecmp($configSmtp['utilisateur'], $valeurs['reminder_email_from']) !== 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rappels par email — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
</head>
<body>
<?php afficherEnteteAdmin('Rappels par email', "Le mail envoyé automatiquement avant chaque rendez-vous : quand il part, à qui, et à quoi il ressemble."); ?>

  <?php /* Etat courant, avant les formulaires : on doit savoir ce que fait
           le site aujourd'hui avant de vouloir le changer. */ ?>
  <div class="outil recap-rappels">
    <div class="ligne-recap">
      <span class="cle-recap">Rappels</span>
      <span class="val-recap">
        <?= $valeurs['reminder_enabled'] === '1'
            ? '✔ activés, envoyés ' . htmlspecialchars($valeurs['reminder_hours_before']) . ' h avant le rendez-vous'
            : '✕ désactivés — aucun mail ne part' ?>
      </span>
    </div>
    <div class="ligne-recap">
      <span class="cle-recap">Tu reçois</span>
      <span class="val-recap">
        <?= $valeurs['reminder_email_chem'] !== ''
            ? htmlspecialchars($valeurs['reminder_email_chem'])
            : '✕ aucune adresse enregistrée' ?>
      </span>
    </div>
    <div class="ligne-recap">
      <span class="cle-recap">Envoi via</span>
      <span class="val-recap">
        <?= $configSmtp !== null
            ? 'SMTP authentifié (' . htmlspecialchars($configSmtp['utilisateur']) . ')'
            : 'fonction mail() de PHP — délivrabilité médiocre, configure le SMTP dans config.php' ?>
      </span>
    </div>
  </div>

  <!-- 1 ---------------------------------------------------------------->
  <div class="outil" style="margin-top:18px;">
    <h2 class="panneau-titre">1. Réglages</h2>
    <?php if ($messageEnregistre): ?>
      <p class="info">Réglages enregistrés.</p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="enregistrer">

      <div class="champ-case">
        <input type="checkbox" name="reminder_enabled" id="reminder_enabled" value="1" <?= $valeurs['reminder_enabled'] === '1' ? 'checked' : '' ?>>
        <label for="reminder_enabled">Envoyer des rappels par email</label>
      </div>

      <div class="champ">
        <label for="delai">Combien de temps avant le rendez-vous</label>
        <input type="number" min="1" step="1" id="delai" name="reminder_hours_before" value="<?= htmlspecialchars($valeurs['reminder_hours_before']) ?>">
        <p class="aide">En heures. 24 = la veille à la même heure, 48 = deux jours avant. Le même délai s'applique à tous les rendez-vous.</p>
      </div>

      <div class="champ">
        <label for="email_chem">Ton adresse email</label>
        <input type="email" id="email_chem" name="reminder_email_chem" value="<?= htmlspecialchars($valeurs['reminder_email_chem']) ?>" placeholder="toi@example.com">
        <p class="aide">Tu reçois un rappel pour tous les rendez-vous, quels que soient les réglages de tes parents. C'est aussi à cette adresse que partent les tests et les aperçus ci-dessous.</p>
      </div>

      <div class="champ">
        <label for="email_from">Adresse d'expédition</label>
        <input type="text" id="email_from" name="reminder_email_from" value="<?= htmlspecialchars($valeurs['reminder_email_from']) ?>" placeholder="agenda@votre-domaine.be">
        <p class="aide">Ce que voient tes parents comme expéditeur. Doit être une boîte qui existe réellement sur ton domaine.</p>
        <?php if ($expediteurIncoherent): ?>
          <p class="erreur">
            Différente du compte SMTP utilisé pour se connecter
            (<?= htmlspecialchars($configSmtp['utilisateur']) ?>). Hostinger refuse
            alors le message, ou l'accepte puis le supprime sans rien signaler.
            Mets les deux identiques.
          </p>
        <?php endif; ?>
      </div>

      <div class="form-boutons" style="margin-top:16px;">
        <button class="principal" type="submit">Enregistrer</button>
      </div>
    </form>
  </div>

  <!-- 2 ---------------------------------------------------------------->
  <div class="outil" style="margin-top:18px;">
    <h2 class="panneau-titre">2. Vérifier que l'envoi fonctionne</h2>
    <p class="sous-titre">Envoie une phrase fixe à ton adresse. Ça ne teste que la mécanique d'envoi, pas le contenu des rappels.</p>
    <?php if ($resultatTest !== null): ?>
      <p class="<?= $resultatTest['ok'] ? 'info' : 'erreur' ?>"><?= htmlspecialchars($resultatTest['message']) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="tester">
      <button class="secondaire" type="submit">Envoyer un email de test</button>
    </form>
  </div>

  <!-- 3 ---------------------------------------------------------------->
  <div class="outil" style="margin-top:18px;">
    <h2 class="panneau-titre">3. Relire un vrai rappel</h2>
    <p class="sous-titre">
      Compose le message réel d'un rendez-vous — médicaments et pathologies
      compris — tel que tes parents le recevront. Prévisualiser ne marque pas
      le rendez-vous comme rappelé : le vrai partira quand même.
    </p>

    <?php if ($resultatApercu !== null): ?>
      <p class="<?= $resultatApercu['ok'] ? 'info' : 'erreur' ?>"><?= htmlspecialchars($resultatApercu['message']) ?></p>
    <?php endif; ?>

    <?php if (empty($rdvsAVenir)): ?>
      <p class="vide">Aucun rendez-vous à venir.</p>
    <?php else: ?>
      <form method="post">
        <div class="champ">
          <label for="rdv_id">Rendez-vous</label>
          <select name="rdv_id" id="rdv_id">
            <?php foreach ($rdvsAVenir as $r): ?>
              <?php
                $nomR = ((int) $r['person_id'] > 0) ? nomPerson($db, $r['person_id']) : $r['person'];
                $libelle = date('d/m/Y H:i', strtotime($r['appt_date'] . ' ' . $r['appt_time']))
                         . ' — ' . $nomR
                         . ($r['doctor'] !== '' ? ' — ' . $r['doctor'] : '')
                         . (empty($r['rappel_actif']) ? '   (rappel désactivé sur ce rendez-vous)' : '');
              ?>
              <option value="<?= (int) $r['id'] ?>"<?= (isset($idChoisi) && $idChoisi === (int) $r['id']) ? ' selected' : '' ?>><?= htmlspecialchars($libelle) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-boutons" style="margin-top:12px;">
          <button class="principal" type="submit" name="action" value="apercu">Afficher ici</button>
          <button class="secondaire" type="submit" name="action" value="envoyer_apercu">Me l'envoyer par email</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($apercuHtml !== ''): ?>
      <?php /* Dans une iframe : le message porte ses propres styles et sa
               propre balise body. Pose directement dans la page, il
               heriterait de la feuille du site et on ne verrait donc pas ce
               que recoivent reellement Michel et Christiane. */ ?>
      <p class="sous-titre" style="margin-top:18px;">Ce que reçoit le destinataire :</p>
      <iframe class="apercu-rappel" title="Aperçu du rappel"
              srcdoc="<?= htmlspecialchars($apercuHtml, ENT_QUOTES, 'UTF-8') ?>"></iframe>
    <?php endif; ?>
  </div>

  <div class="callout">
    Les adresses de Michel et Christiane, et leur choix de recevoir aussi les rappels de l'autre, ne se règlent pas ici : chacun les gère depuis <a href="/mes_rappels.php">Rappels par email</a>.
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
