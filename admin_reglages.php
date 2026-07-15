<?php
/**
 * ADMINISTRATION : reglages techniques des rappels par email.
 *
 * Page protegee par le mot de passe admin (voir requireAdminLogin()) qui
 * permet de configurer les rappels par email sans avoir a toucher
 * config.php ni redeployer le site : activer/desactiver, delai avant le
 * rendez-vous, adresse email de Chem (destinataire fixe de tous les
 * rappels), adresse d'expedition.
 *
 * Les adresses email de Papa/Maman et leurs preferences ("je veux aussi
 * etre prevenu des rendez-vous de l'autre") ne sont PAS ici : chacun les
 * gere lui-meme depuis mes_rappels.php, accessible avec le mot de passe
 * familial (pas besoin du mot de passe admin).
 *
 * L'envoi effectif des rappels se fait par le script rappels.php, appele
 * periodiquement par un Cron Job Hostinger (voir le guide d'installation)
 * - cette page ne fait qu'enregistrer les reglages qu'il utilisera.
 */

require_once __DIR__ . '/lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/mailer.php';

$config = require __DIR__ . '/config.php';
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Réglages — Administration</title>
<link rel="stylesheet" href="assets/style.css">
<style>
  .outil { background:#fff; border-radius:12px; padding:18px; margin-bottom:24px; box-shadow: var(--shadow-sm); }
  .outil h2 { margin-top:0; }
  .barre-admin { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:8px; }
  .barre-admin a { font-size:13px; color:var(--text-muted, #888); }
  .champ-case { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
  .champ-case input[type=checkbox] { width:22px; height:22px; }
  .champ-case label { font-weight:600; }
  .aide { font-size:13px; color:#777; margin-top:4px; }
</style>
</head>
<body>
  <div class="barre-admin">
    <h1 style="margin:0;">Réglages</h1>
    <div>
      <a href="admin_nettoyage.php">Nettoyage</a>
      &nbsp;·&nbsp;
      <a href="index.php">Retour à l'agenda</a>
      &nbsp;·&nbsp;
      <a href="admin_logout.php">Déconnexion admin</a>
    </div>
  </div>

  <div class="outil">
    <h2>Rappels par email</h2>
    <p class="sous-titre">Réglages techniques (délai, activer/désactiver, ta propre adresse, adresse d'expédition). L'envoi effectif est fait par un Cron Job Hostinger qui appelle <code>rappels.php</code> régulièrement (voir le guide d'installation) — cette page enregistre juste les réglages qu'il utilisera.</p>
    <p class="sous-titre">Les adresses email de tes parents et leurs préférences ("aussi recevoir les rappels de l'autre") ne se règlent pas ici : chacun les gère lui-même depuis <a href="mes_rappels.php">mes_rappels.php</a>, accessible avec le mot de passe familial.</p>

    <?php if ($configSmtp === null): ?>
      <p class="aide" style="color:#c60;">Envoi via <code>mail()</code> natif (aucun serveur SMTP renseigné dans <code>config.php</code>) : les emails ont plus de risques d'atterrir en indésirables. Voir le guide d'installation, section "Rappels par email", pour configurer un envoi SMTP authentifié — nettement plus fiable.</p>
    <?php else: ?>
      <p class="aide">Envoi via SMTP authentifié (<?= htmlspecialchars($configSmtp['host']) ?>) — configuration recommandée, déjà active.</p>
    <?php endif; ?>

    <?php if ($messageEnregistre): ?>
      <p class="info">Réglages enregistrés.</p>
    <?php endif; ?>

    <?php if ($resultatTest !== null): ?>
      <p class="<?= $resultatTest['ok'] ? 'info' : 'erreur' ?>"><?= htmlspecialchars($resultatTest['message']) ?></p>
    <?php endif; ?>

    <form method="post">
      <div class="champ-case">
        <input type="checkbox" name="reminder_enabled" id="reminder_enabled" value="1" <?= $valeurs['reminder_enabled'] === '1' ? 'checked' : '' ?>>
        <label for="reminder_enabled">Activer les rappels par email</label>
      </div>

      <div class="champ">
        <label>Délai avant le rendez-vous (en heures)</label>
        <input type="number" min="1" step="1" name="reminder_hours_before" value="<?= htmlspecialchars($valeurs['reminder_hours_before']) ?>">
        <p class="aide">Exemples : 24 = envoyé la veille à la même heure, 2 = envoyé 2h avant, 48 = envoyé 2 jours avant. Un seul délai s'applique à tous les rendez-vous.</p>
      </div>

      <div class="champ">
        <label>Ton adresse email (Chem)</label>
        <input type="email" name="reminder_email_chem" value="<?= htmlspecialchars($valeurs['reminder_email_chem']) ?>" placeholder="toi@example.com">
        <p class="aide">Tu reçois un rappel pour tous les rendez-vous, quels que soient les réglages de tes parents.</p>
      </div>

      <div class="champ">
        <label>Adresse d'expédition (From)</label>
        <input type="text" name="reminder_email_from" value="<?= htmlspecialchars($valeurs['reminder_email_from']) ?>" placeholder="agenda@votre-domaine.be">
        <p class="aide">Idéalement une adresse existante sur votre domaine (créée dans hPanel > Emails), pour éviter que le mail parte en indésirables.</p>
      </div>

      <div class="form-boutons" style="margin-top:16px;">
        <button class="principal" type="submit" name="action" value="enregistrer">Enregistrer les réglages</button>
        <button class="secondaire" type="submit" name="action" value="tester">Envoyer un email de test</button>
      </div>
    </form>
  </div>
</body>
</html>