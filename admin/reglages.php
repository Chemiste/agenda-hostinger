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
 * L'envoi effectif des rappels se fait par le script cron/rappels.php, appele
 * periodiquement par un Cron Job Hostinger (voir le guide d'installation)
 * - cette page ne fait qu'enregistrer les reglages qu'il utilisera.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/mailer.php';

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Réglages — Administration</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .outil { background:#fff; border-radius:12px; padding:18px; margin-bottom:16px; box-shadow: var(--shadow-sm); }
  .outil h2 { margin-top:0; font-size:15px; }
  .barre-admin { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:8px; }
  .barre-admin a { font-size:13px; color:var(--text-muted, #888); }
  .fil-admin { font-size:13px; color:var(--text-muted); margin-bottom:18px; }
  .fil-admin a { color:var(--text-muted); text-decoration:none; }
  .fil-admin a:hover { text-decoration:underline; }
  .fil-admin .sep { margin:0 4px; }
  .fil-admin .actuel { color:var(--text); font-weight:600; }
  .entete-page { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:4px; flex-wrap:wrap; }
  .entete-page h1 { font-size:20px; margin:0; }
  .badge-smtp { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:5px 12px; border-radius:999px; white-space:nowrap; }
  .badge-smtp.ok { background:#e6f7f1; color:#0f766e; }
  .badge-smtp.attention { background:#fdf1ea; color:#b45309; }
  .sous-titre-page { margin:2px 0 20px; }
  .champ-case { display:flex; align-items:center; gap:10px; margin-bottom:2px; }
  .champ-case input[type=checkbox] { width:22px; height:22px; }
  .champ-case label { font-weight:600; }
  .aide { font-size:13px; color:#777; margin-top:4px; }
  .champs-secondaires { margin-top:16px; padding-top:16px; border-top:1px solid var(--border); transition:opacity var(--dur) var(--ease); }
  .champs-secondaires.inactifs { opacity:0.45; }
  .callout { background:var(--tous-bg); border-radius:var(--radius-md); padding:14px 16px; font-size:14px; color:var(--text); }
  .callout a { font-weight:600; }
</style>
</head>
<body>
  <div class="barre-admin">
    <div>
      <a href="/index.php">Retour à l'agenda</a>
      &nbsp;·&nbsp;
      <a href="/admin/logout.php">Déconnexion admin</a>
    </div>
  </div>
  <div class="fil-admin">
    <a href="/admin/index.php">Administration</a><span class="sep">/</span><span class="actuel">Réglages</span>
  </div>

  <div class="entete-page">
    <h1>Rappels par email</h1>
    <?php if ($configSmtp === null): ?>
      <span class="badge-smtp attention">Envoi via mail() natif</span>
    <?php else: ?>
      <span class="badge-smtp ok">SMTP authentifié actif</span>
    <?php endif; ?>
  </div>
  <p class="sous-titre sous-titre-page">Réglages techniques utilisés par le Cron Job qui envoie les rappels (<code>cron/rappels.php</code>).</p>

  <?php if ($configSmtp === null): ?>
    <p class="aide" style="color:#b45309; margin:-8px 0 16px;">Aucun serveur SMTP renseigné dans <code>config.php</code> : les emails ont plus de risques d'atterrir en indésirables. Voir le guide d'installation, section "Rappels par email", pour configurer un envoi authentifié — nettement plus fiable.</p>
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
          <label>Ton adresse email (Chem)</label>
          <input type="email" name="reminder_email_chem" value="<?= htmlspecialchars($valeurs['reminder_email_chem']) ?>" placeholder="toi@example.com">
          <p class="aide">Tu reçois un rappel pour tous les rendez-vous, quels que soient les réglages de tes parents.</p>
        </div>

        <div class="champ">
          <label>Adresse d'expédition (From)</label>
          <input type="text" name="reminder_email_from" value="<?= htmlspecialchars($valeurs['reminder_email_from']) ?>" placeholder="agenda@votre-domaine.be">
          <p class="aide">Idéalement une adresse existante sur votre domaine (créée dans hPanel > Emails), pour éviter que le mail parte en indésirables.</p>
        </div>
      </div>

      <div class="form-boutons" style="margin-top:16px;">
        <button class="principal" type="submit" name="action" value="enregistrer">Enregistrer les réglages</button>
        <button class="secondaire" type="submit" name="action" value="tester">Envoyer un email de test</button>
      </div>
    </form>
  </div>

  <div class="callout">
    Les adresses email de tes parents et leurs préférences ("aussi recevoir les rappels de l'autre") ne se règlent pas ici : chacun les gère lui-même depuis <a href="/mes_rappels.php">Rappels par email</a>, accessible avec le mot de passe familial.
  </div>
</body>
</html>
