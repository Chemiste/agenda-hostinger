<?php
/**
 * Reglages personnels des rappels par email, accessibles avec le mot de
 * passe familial (pas besoin du mot de passe d'administration) : chacun
 * choisit sa propre adresse email, peut activer/desactiver les rappels
 * pour lui-meme (sans avoir a effacer son adresse), et peut aussi choisir
 * d'etre prevenu des rendez-vous de l'autre personne. Contrairement a
 * admin_reglages.php (delai, activer/desactiver globalement, adresse
 * d'expedition), ces reglages-ci sont penses pour etre modifies
 * directement par la famille, sans passer par l'administration.
 */

require_once __DIR__ . '/lib/auth.php';
requireLogin();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/mailer.php';

$config = require __DIR__ . '/config.php';
$configSmtp = construireConfigSmtp($config);
$p1 = isset($config['personne_1']) ? $config['personne_1'] : 'Papa';
$p2 = isset($config['personne_2']) ? $config['personne_2'] : 'Maman';

$db = getDb();

// Avant la v1.16.0, une seule adresse email etait partagee pour "les
// parents" (reglee dans l'administration). Si les nouveaux champs
// individuels n'ont encore jamais ete remplis, on part de cette ancienne
// valeur plutot que de repartir de zero.
$ancienEmailPartage = getSetting($db, 'reminder_email_parents', '');

$defauts = [
    'reminder_email_person1' => $ancienEmailPartage,
    'reminder_notify_self_person1' => '1',
    'reminder_notify_other_person1' => '0',
    'reminder_email_person2' => $ancienEmailPartage,
    'reminder_notify_self_person2' => '1',
    'reminder_notify_other_person2' => '0',
];
$valeurs = [];
foreach ($defauts as $cle => $defaut) {
    $valeurs[$cle] = getSetting($db, $cle, $defaut);
}

$messageEnregistre = false;
$resultatTest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valeurs['reminder_email_person1'] = isset($_POST['reminder_email_person1']) ? trim($_POST['reminder_email_person1']) : '';
    $valeurs['reminder_notify_self_person1'] = !empty($_POST['reminder_notify_self_person1']) ? '1' : '0';
    $valeurs['reminder_notify_other_person1'] = !empty($_POST['reminder_notify_other_person1']) ? '1' : '0';
    $valeurs['reminder_email_person2'] = isset($_POST['reminder_email_person2']) ? trim($_POST['reminder_email_person2']) : '';
    $valeurs['reminder_notify_self_person2'] = !empty($_POST['reminder_notify_self_person2']) ? '1' : '0';
    $valeurs['reminder_notify_other_person2'] = !empty($_POST['reminder_notify_other_person2']) ? '1' : '0';

    if (isset($_POST['action']) && $_POST['action'] === 'enregistrer') {
        foreach ($valeurs as $cle => $val) {
            setSetting($db, $cle, $val);
        }
        $messageEnregistre = true;
    } elseif (isset($_POST['action']) && in_array($_POST['action'], ['tester_person1', 'tester_person2'], true)) {
        $estPerson1 = $_POST['action'] === 'tester_person1';
        $email = $estPerson1 ? $valeurs['reminder_email_person1'] : $valeurs['reminder_email_person2'];
        $nomPersonne = $estPerson1 ? $p1 : $p2;

        if ($email === '') {
            $resultatTest = [
                'cible' => $_POST['action'],
                'ok' => false,
                'message' => 'Renseignez une adresse email avant de tester.',
            ];
        } else {
            $emailFrom = getSetting($db, 'reminder_email_from', '');
            $corps = "Ceci est un email de test pour $nomPersonne, envoye depuis l'agenda medical.\n\n"
                . "Si vous recevez ce message, les rappels de rendez-vous fonctionneront bien pour vous.\n\n"
                . "(Pensez a verifier le dossier des indesirables/spam si vous ne le voyez pas dans votre boite de reception principale.)";
            $envoi = envoyerEmail([$email], 'Test - Agenda medical', $corps, $emailFrom, $configSmtp);
            $resultatTest = [
                'cible' => $_POST['action'],
                'ok' => $envoi['ok'],
                'message' => $envoi['ok']
                    ? "Email de test envoyé à $email. Vérifiez la réception (et le dossier spam)."
                    : "L'envoi a échoué : " . $envoi['erreur'],
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rappels par email</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  .outil { background:#fff; border-radius:12px; padding:18px; margin-bottom:24px; box-shadow: var(--shadow-sm); }
  .outil h2 { margin-top:0; }
  .barre-admin { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:8px; }
  .barre-admin a { font-size:13px; color:var(--text-muted, #888); }
  .champ-case { display:flex; align-items:center; gap:10px; margin: 14px 0; }
  .champ-case input[type=checkbox] { width:22px; height:22px; flex-shrink:0; }
  .champ-case label { font-weight:400; font-size:15px; }
  .aide { font-size:13px; color:#777; margin-top:4px; }
</style>
</head>
<body>
  <div class="barre-admin">
    <h1 style="margin:0;">Rappels par email</h1>
    <div>
      <a href="index.php">Retour à l'agenda</a>
    </div>
  </div>
  <p class="sous-titre">Chacun renseigne ici son adresse email, active ou désactive les rappels pour lui-même (sans avoir à effacer son adresse), et peut aussi choisir d'être prévenu des rendez-vous de l'autre.</p>

  <?php if ($messageEnregistre): ?>
    <p class="info">Réglages enregistrés.</p>
  <?php endif; ?>

  <form method="post">
    <div class="outil">
      <h2><?= htmlspecialchars($p1) ?></h2>
      <div class="champ">
        <label>Adresse email de <?= htmlspecialchars($p1) ?></label>
        <input type="email" name="reminder_email_person1" value="<?= htmlspecialchars($valeurs['reminder_email_person1']) ?>" placeholder="<?= htmlspecialchars($p1) ?>@example.com">
        <p class="aide">L'adresse peut rester enregistrée même si les rappels sont désactivés ci-dessous.</p>
      </div>
      <div class="champ-case">
        <input type="checkbox" name="reminder_notify_self_person1" id="soi1" value="1" <?= $valeurs['reminder_notify_self_person1'] === '1' ? 'checked' : '' ?>>
        <label for="soi1">Je souhaite recevoir un rappel pour mes rendez-vous</label>
      </div>
      <div class="champ-case">
        <input type="checkbox" name="reminder_notify_other_person1" id="autre1" value="1" <?= $valeurs['reminder_notify_other_person1'] === '1' ? 'checked' : '' ?>>
        <label for="autre1">Recevoir aussi les rappels des rendez-vous de <?= htmlspecialchars($p2) ?></label>
      </div>

      <?php if ($resultatTest !== null && $resultatTest['cible'] === 'tester_person1'): ?>
        <p class="<?= $resultatTest['ok'] ? 'info' : 'erreur' ?>"><?= htmlspecialchars($resultatTest['message']) ?></p>
      <?php endif; ?>

      <button class="secondaire" type="submit" name="action" value="tester_person1">Envoyer un email de test à <?= htmlspecialchars($p1) ?></button>
    </div>

    <div class="outil">
      <h2><?= htmlspecialchars($p2) ?></h2>
      <div class="champ">
        <label>Adresse email de <?= htmlspecialchars($p2) ?></label>
        <input type="email" name="reminder_email_person2" value="<?= htmlspecialchars($valeurs['reminder_email_person2']) ?>" placeholder="<?= htmlspecialchars($p2) ?>@example.com">
        <p class="aide">L'adresse peut rester enregistrée même si les rappels sont désactivés ci-dessous.</p>
      </div>
      <div class="champ-case">
        <input type="checkbox" name="reminder_notify_self_person2" id="soi2" value="1" <?= $valeurs['reminder_notify_self_person2'] === '1' ? 'checked' : '' ?>>
        <label for="soi2">Je souhaite recevoir un rappel pour mes rendez-vous</label>
      </div>
      <div class="champ-case">
        <input type="checkbox" name="reminder_notify_other_person2" id="autre2" value="1" <?= $valeurs['reminder_notify_other_person2'] === '1' ? 'checked' : '' ?>>
        <label for="autre2">Recevoir aussi les rappels des rendez-vous de <?= htmlspecialchars($p1) ?></label>
      </div>

      <?php if ($resultatTest !== null && $resultatTest['cible'] === 'tester_person2'): ?>
        <p class="<?= $resultatTest['ok'] ? 'info' : 'erreur' ?>"><?= htmlspecialchars($resultatTest['message']) ?></p>
      <?php endif; ?>

      <button class="secondaire" type="submit" name="action" value="tester_person2">Envoyer un email de test à <?= htmlspecialchars($p2) ?></button>
    </div>

    <div class="form-boutons" style="margin-top:16px;">
      <button class="principal" type="submit" name="action" value="enregistrer">Enregistrer les réglages</button>
    </div>
  </form>
</body>
</html>