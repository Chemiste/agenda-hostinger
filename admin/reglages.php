<?php
/**
 * ADMINISTRATION : reglages techniques des rappels par email.
 *
 * Page protegee par le mot de passe admin (voir requireAdminLogin()) qui
 * permet de configurer les rappels par email sans avoir a toucher
 * config.php ni redeployer le site : activer/desactiver, delai avant le
 * rendez-vous, adresse email de Laurent (destinataire fixe de tous les
 * rappels), adresse d'expedition.
 *
 * La cle de reglage s'appelle encore reminder_email_chem, du surnom de
 * Laurent : la renommer ferait perdre l'adresse deja enregistree. Elle
 * disparaitra quand les rappels passeront a un reglage par personne (voir
 * migrations/0021_ajouter_persons.sql).
 *
 * Les adresses email de Papa/Maman et leurs preferences ("je veux aussi
 * etre prevenu des rendez-vous de l'autre") ne sont PAS ici : chacun les
 * gere lui-meme depuis mes_rappels.php, accessible avec le mot de passe
 * familial (pas besoin du mot de passe admin).
 *
 * L'envoi effectif des rappels se fait par le script cron/rappels.php, appele
 * periodiquement par un Cron Job Hostinger (voir le guide d'installation)
 * - cette page ne fait qu'enregistrer les reglages qu'il utilisera.
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
$valeurs = [];
foreach ($defauts as $cle => $defaut) {
    $valeurs[$cle] = getSetting($db, $cle, $defaut);
}

$messageEnregistre = false;
$resultatTest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valeurs['reminder_enabled'] = !empty($_POST['reminder_enabled']) ? '1' : '0';
    $valeurs['reminder_hours_before'] = isset($_POST['reminder_hours_before'])
        ? (string) max(1, (int) $_POST['reminder_hours_before']) : $valeurs['reminder_hours_before'];
    $valeurs['reminder_email_chem'] = isset($_POST['reminder_email_chem']) ? trim($_POST['reminder_email_chem']) : '';
    $valeurs['reminder_email_from'] = isset($_POST['reminder_email_from']) ? trim($_POST['reminder_email_from']) : '';

    if (isset($_POST['action']) && $_POST['action'] === 'enregistrer') {
        foreach ($valeurs as $cle => $val) {
            setSetting($db, $cle, $val);
        }
        $messageEnregistre = true;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'tester') {
        if ($valeurs['reminder_email_chem'] === '') {
            $resultatTest = [
                'ok' => false,
                'message' => 'Renseignez ton adresse email avant de tester.',
            ];
        } else {
            $corps = "Ceci est un email de test envoye depuis la page de reglages de l'agenda medical.\n\n"
                . "Si vous recevez ce message, l'envoi d'emails fonctionne correctement.\n\n"
                . "(Pensez a verifier le dossier des indesirables/spam si vous ne le voyez pas dans votre boite de reception principale.)";
            $envoi = envoyerEmail([$valeurs['reminder_email_chem']], 'Test - Agenda medical', $corps, $valeurs['reminder_email_from'], $configSmtp);
            $resultatTest = $envoi['ok']
                ? [
                    'ok' => true,
                    'message' => 'Email de test envoye a : ' . $valeurs['reminder_email_chem'] . '. Verifiez la reception (et le dossier spam).',
                ]
                : [
                    'ok' => false,
                    'message' => "L'envoi a echoue : " . $envoi['erreur'],
                ];
        }
    }
}

// --- Apercu d'un VRAI rappel -------------------------------------------
//
// Pourquoi cet outil : l'email de test ci-dessus n'envoie qu'une phrase
// fixe. Il prouve que l'envoi fonctionne, pas que le rappel est correct.
// Ici on compose le message REEL d'un rendez-vous existant - avec ses
// medicaments et ses pathologies - pour pouvoir le relire avant que
// Michel et Christiane ne le recoivent.
//
// AUCUN effet de bord : reminder_sent_at n'est pas touche. Sans cette
// precaution, previsualiser un rappel empecherait le vrai de partir.

$rdvsAVenir = $db->query(
    'SELECT id, appt_date, appt_time, person_id, person, doctor, department, location, '
    . 'phone, route, accompagnant, notes, questions, rappel_actif '
    . 'FROM appointments WHERE TIMESTAMP(appt_date, appt_time) > NOW() '
    . 'ORDER BY appt_date, appt_time LIMIT 50'
)->fetchAll();

$apercuHtml = '';
$resultatApercu = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && in_array($_POST['action'], ['apercu', 'envoyer_apercu'], true)) {

    $idChoisi = isset($_POST['rdv_id']) ? (int) $_POST['rdv_id'] : 0;
    $rdv = null;
    foreach ($rdvsAVenir as $r) {
        if ((int) $r['id'] === $idChoisi) { $rdv = $r; break; }
    }

    if ($rdv === null) {
        $resultatApercu = ['ok' => false, 'message' => 'Choisis un rendez-vous dans la liste.'];
    } else {
        $joursFrA = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $moisFrA = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                    'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $ts = strtotime($rdv['appt_date'] . ' ' . $rdv['appt_time']);
        $quandA = $joursFrA[(int) date('w', $ts)] . ' ' . (int) date('j', $ts) . ' '
                . $moisFrA[(int) date('n', $ts)] . ' ' . date('Y', $ts) . ' à ' . date('H:i', $ts);
        $nomA = ((int) $rdv['person_id'] > 0) ? nomPerson($db, $rdv['person_id']) : $rdv['person'];

        $message = composerRappel($db, $rdv, $nomA, $quandA);

        if ($_POST['action'] === 'apercu') {
            $apercuHtml = $message['html'];
        } elseif ($valeurs['reminder_email_chem'] === '') {
            $resultatApercu = ['ok' => false, 'message' => "Renseigne d'abord ton adresse email."];
        } else {
            $envoi = envoyerEmail(
                [$valeurs['reminder_email_chem']],
                '[Aperçu] Rappel : rendez-vous ' . $nomA . ' - ' . $quandA,
                $message['texte'], $valeurs['reminder_email_from'], $configSmtp, $message['html']
            );
            $resultatApercu = $envoi['ok']
                ? ['ok' => true, 'message' => 'Aperçu envoyé à ' . $valeurs['reminder_email_chem']
                    . '. Le rendez-vous n\'est PAS marqué comme rappelé : le vrai rappel partira normalement.']
                : ['ok' => false, 'message' => "L'envoi a échoué : " . $envoi['erreur']];
        }
    }
}

// L'expediteur doit correspondre a la boite authentifiee : Hostinger
// refuse - ou pire, accepte puis jette - un message expedie au nom d'une
// autre adresse. On le signale plutot que de laisser le probleme se
// manifester par des emails qui n'arrivent jamais.
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
<title>Réglages — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
<style>
  .outil { margin-bottom:16px; }
</style>
</head>
<body>
  <?php afficherEnteteAdmin(
      'Rappels par email',
      'Réglages techniques utilisés par le Cron Job qui envoie les rappels (<code>cron/rappels.php</code>).'
  ); ?>

  <?php /* L'etat de l'envoi (SMTP authentifie ou mail() natif) est la
           premiere chose a verifier quand un rappel n'arrive pas : il est
           donc affiche en tete, sous forme d'etiquette. Il vivait avant
           dans un « entete-page » invente pour cette seule page, a cote
           d'un <h1> de 20px la ou tout le site en fait 24. */ ?>
  <p class="etat-envoi">
    <?php if ($configSmtp === null): ?>
      <span class="badge-smtp attention">Envoi via mail() natif</span>
    <?php else: ?>
      <span class="badge-smtp ok">SMTP authentifié actif</span>
    <?php endif; ?>
  </p>

  <?php if ($configSmtp === null): ?>
    <p class="aide avertissement">Aucun serveur SMTP renseigné dans <code>config.php</code> : les emails ont plus de risques d'atterrir en indésirables. Voir le guide d'installation, section "Rappels par email", pour configurer un envoi authentifié — nettement plus fiable.</p>
  <?php endif; ?>

  <?php if ($messageEnregistre): ?>
    <p class="info">Réglages enregistrés.</p>
  <?php endif; ?>

  <?php if ($resultatTest !== null): ?>
    <p class="<?= $resultatTest['ok'] ? 'info' : 'erreur' ?>"><?= htmlspecialchars($resultatTest['message']) ?></p>
  <?php endif; ?>

  <div class="outil">
    <form method="post">
      <div class="champ-case">
        <input type="checkbox" name="reminder_enabled" id="reminder_enabled" value="1" <?= $valeurs['reminder_enabled'] === '1' ? 'checked' : '' ?> onchange="document.getElementById('champsSecondaires').classList.toggle('inactifs', !this.checked)">
        <label for="reminder_enabled">Activer les rappels par email</label>
      </div>

      <div id="champsSecondaires" class="champs-secondaires<?= $valeurs['reminder_enabled'] === '1' ? '' : ' inactifs' ?>">
        <div class="champ">
          <label>Délai avant le rendez-vous (en heures)</label>
          <input type="number" min="1" step="1" name="reminder_hours_before" value="<?= htmlspecialchars($valeurs['reminder_hours_before']) ?>">
          <p class="aide">Exemples : 24 = envoyé la veille à la même heure, 2 = envoyé 2h avant, 48 = envoyé 2 jours avant. Un seul délai s'applique à tous les rendez-vous.</p>
        </div>

        <div class="champ">
          <label>Ton adresse email (Laurent)</label>
          <input type="email" name="reminder_email_chem" value="<?= htmlspecialchars($valeurs['reminder_email_chem']) ?>" placeholder="toi@example.com">
          <p class="aide">Tu reçois un rappel pour tous les rendez-vous, quels que soient les réglages de tes parents.</p>
        </div>

        <div class="champ">
          <label>Adresse d'expédition (From)</label>
          <input type="text" name="reminder_email_from" value="<?= htmlspecialchars($valeurs['reminder_email_from']) ?>" placeholder="agenda@votre-domaine.be">
          <p class="aide">Idéalement une adresse existante sur votre domaine (créée dans hPanel > Emails), pour éviter que le mail parte en indésirables.</p>
          <?php if ($expediteurIncoherent): ?>
            <p class="erreur">
              Cette adresse diffère du compte SMTP utilisé pour se connecter
              (<?= htmlspecialchars($configSmtp['utilisateur']) ?>). La plupart des
              hébergeurs, dont Hostinger, refusent alors le message — ou l'acceptent
              puis le suppriment sans rien signaler. Mets les deux identiques.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-boutons" style="margin-top:16px;">
        <button class="principal" type="submit" name="action" value="enregistrer">Enregistrer les réglages</button>
        <button class="secondaire" type="submit" name="action" value="tester">Envoyer un email de test</button>
      </div>
    </form>
  </div>

  <div class="outil" style="margin-top:18px;">
    <h2 class="panneau-titre">Relire un vrai rappel</h2>
    <p class="sous-titre">
      L'email de test ci-dessus n'envoie qu'une phrase fixe : il prouve que
      l'envoi marche, pas que le rappel est juste. Ici tu composes le message
      réel d'un rendez-vous, médicaments et pathologies compris.
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
                         . (empty($r['rappel_actif']) ? '  (rappel désactivé)' : '');
              ?>
              <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($libelle) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="aide">
            Prévisualiser ne marque pas le rendez-vous comme rappelé : le vrai
            rappel partira quand même le moment venu.
          </p>
        </div>
        <div class="form-boutons" style="margin-top:12px;">
          <button class="principal" type="submit" name="action" value="apercu">Voir ici</button>
          <button class="secondaire" type="submit" name="action" value="envoyer_apercu">Me l'envoyer par email</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($apercuHtml !== ''): ?>
      <?php /* srcdoc plutot qu'une insertion directe : le message porte ses
               propres styles et sa propre balise body. Inseré dans la page,
               il en heriterait et on ne verrait pas ce que recoivent
               reellement Michel et Christiane. */ ?>
      <p class="sous-titre" style="margin-top:18px;">Ce que reçoit le destinataire :</p>
      <iframe class="apercu-rappel" title="Aperçu du rappel"
              srcdoc="<?= htmlspecialchars($apercuHtml, ENT_QUOTES, 'UTF-8') ?>"></iframe>
    <?php endif; ?>
  </div>

  <div class="callout">
    Les adresses email de tes parents et leurs préférences ("aussi recevoir les rappels de l'autre") ne se règlent pas ici : chacun les gère lui-même depuis <a href="/mes_rappels.php">Rappels par email</a>, accessible avec le mot de passe familial.
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
