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
 * TOUT POST SE TERMINE PAR UNE REDIRECTION. Sans cela, la page affichee
 * est la reponse au formulaire : rafraichir (F5, ou revenir en arriere)
 * fait apparaitre le "voulez-vous renvoyer les informations ?" du
 * navigateur, et accepter REJOUE l'action - un deuxieme email part. Le
 * message a afficher passe donc par la session et n'est lu qu'une fois.
 *
 * L'apercu a l'ecran, lui, passe en GET : il ne fait que LIRE. Son adresse
 * (?action=apercu&rdv_id=12) peut etre rechargee ou mise en favori sans
 * rien declencher.
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

function rdvParId($rdvs, $id) {
    foreach ($rdvs as $r) {
        if ((int) $r['id'] === (int) $id) {
            return $r;
        }
    }
    return null;
}

/**
 * Range le message a afficher, puis RECHARGE la page en GET.
 *
 * C'est ce qui evite le « voulez-vous renvoyer les informations ? » de
 * Firefox : apres un POST, la page affichee EST la reponse au POST, donc
 * la rafraichir renvoie le formulaire - et reenvoie l'email au passage.
 * En repartant sur une adresse ordinaire, F5 ne fait plus que relire.
 *
 * Le message ne survivrait pas a la redirection : il passe par la session,
 * qui existe deja pour la connexion, et n'est lu qu'une fois.
 */
function rechargerAvecMessage($flash, $rdvId = 0) {
    $_SESSION['flash_reglages'] = $flash;
    // Chemin absolu depuis la racine du site, comme partout ailleurs : un
    // chemin relatif se resoudrait differemment selon l'adresse d'ou vient
    // l'appel.
    header('Location: /admin/reglages.php' . ($rdvId > 0 ? '?rdv_id=' . (int) $rdvId : ''));
    exit;
}

// Les actions qui ECRIVENT ou qui ENVOIENT n'arrivent que par POST. Seul
// l'apercu passe par GET : il ne fait que lire, donc son adresse peut etre
// rechargee, mise en favori ou partagee sans rien declencher.
$action = isset($_POST['action']) ? $_POST['action'] : '';

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
    rechargerAvecMessage(['bloc' => 'reglages', 'ok' => true, 'message' => 'Réglages enregistrés.']);
}

// --- 2. Email de test --------------------------------------------------
if ($action === 'tester') {
    if ($valeurs['reminder_email_chem'] === '') {
        rechargerAvecMessage(['bloc' => 'test', 'ok' => false,
            'message' => "Aucune adresse enregistrée : remplis « Ton adresse email » ci-dessus, puis Enregistrer."]);
    }
    $corps = "Ceci est un email de test envoyé depuis la page de réglages de l'agenda médical.\n\n"
        . "Si tu reçois ce message, l'envoi fonctionne.";
    $envoi = envoyerEmail([$valeurs['reminder_email_chem']], 'Test - Agenda medical',
                          $corps, $valeurs['reminder_email_from'], $configSmtp);
    rechargerAvecMessage(['bloc' => 'test', 'ok' => $envoi['ok'],
        'message' => $envoi['ok']
            ? 'Envoyé à ' . $valeurs['reminder_email_chem'] . '.'
            : "Échec : " . $envoi['erreur']]);
}

// --- 3. S'envoyer un vrai rappel ---------------------------------------
//
// AUCUN effet de bord sur les rappels : reminder_sent_at n'est jamais
// touche. Sans cette precaution, previsualiser un rappel empecherait le
// vrai de partir - l'outil de verification casserait ce qu'il sert a
// verifier.
if ($action === 'envoyer_apercu') {
    $idChoisi = isset($_POST['rdv_id']) ? (int) $_POST['rdv_id'] : 0;
    $rdv = rdvParId($rdvsAVenir, $idChoisi);

    if ($rdv === null) {
        rechargerAvecMessage(['bloc' => 'apercu', 'ok' => false,
            'message' => 'Choisis un rendez-vous dans la liste.']);
    }
    if ($valeurs['reminder_email_chem'] === '') {
        rechargerAvecMessage(['bloc' => 'apercu', 'ok' => false,
            'message' => "Aucune adresse enregistrée : remplis « Ton adresse email » dans les réglages ci-dessus, puis Enregistrer."], $idChoisi);
    }

    $quandA = libelleQuand($rdv['appt_date'], $rdv['appt_time']);
    $nomA = ((int) $rdv['person_id'] > 0) ? nomPerson($db, $rdv['person_id']) : $rdv['person'];
    $message = composerRappel($db, $rdv, $nomA, $quandA);
    $envoi = envoyerEmail(
        [$valeurs['reminder_email_chem']],
        '[Aperçu] Rappel : rendez-vous ' . $nomA . ' - ' . $quandA,
        $message['texte'], $valeurs['reminder_email_from'], $configSmtp, $message['html']
    );
    rechargerAvecMessage(['bloc' => 'apercu', 'ok' => $envoi['ok'],
        'message' => $envoi['ok']
            ? 'Aperçu envoyé à ' . $valeurs['reminder_email_chem']
              . '. Le rendez-vous n\'est pas marqué comme rappelé : le vrai rappel partira normalement.'
            : "Échec : " . $envoi['erreur']], $idChoisi);
}

// --- 4. Apercu a l'ecran (lecture seule, donc en GET) -------------------
$idChoisi = isset($_GET['rdv_id']) ? (int) $_GET['rdv_id'] : 0;
$apercuHtml = '';
$resultatApercu = null;

if (isset($_GET['action']) && $_GET['action'] === 'apercu') {
    $rdv = rdvParId($rdvsAVenir, $idChoisi);
    if ($rdv === null) {
        $resultatApercu = ['ok' => false, 'message' => 'Choisis un rendez-vous dans la liste.'];
    } else {
        $apercuHtml = composerRappel(
            $db, $rdv,
            ((int) $rdv['person_id'] > 0) ? nomPerson($db, $rdv['person_id']) : $rdv['person'],
            libelleQuand($rdv['appt_date'], $rdv['appt_time'])
        )['html'];
    }
}

// Le message laisse par la redirection, lu une seule fois.
$flash = isset($_SESSION['flash_reglages']) ? $_SESSION['flash_reglages'] : null;
unset($_SESSION['flash_reglages']);

$messageEnregistre = ($flash !== null && $flash['bloc'] === 'reglages');
$resultatTest = ($flash !== null && $flash['bloc'] === 'test') ? $flash : null;
if ($flash !== null && $flash['bloc'] === 'apercu') {
    $resultatApercu = $flash;
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
      <?php /* En GET : afficher un apercu ne fait que lire, l'adresse peut
               donc etre rechargee sans que Firefox demande a « renvoyer les
               informations ». Seul l'envoi par email repart en POST, via
               formmethod sur son propre bouton. */ ?>
      <form method="get" action="/admin/reglages.php">
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
              <option value="<?= (int) $r['id'] ?>"<?= ($idChoisi === (int) $r['id']) ? ' selected' : '' ?>><?= htmlspecialchars($libelle) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-boutons" style="margin-top:12px;">
          <button class="principal" type="submit" name="action" value="apercu">Afficher ici</button>
          <button class="secondaire" type="submit" name="action" value="envoyer_apercu" formmethod="post">Me l'envoyer par email</button>
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
