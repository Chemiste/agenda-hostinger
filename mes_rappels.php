<?php
/**
 * Reglages personnels des rappels par email, accessibles avec le mot de
 * passe familial (pas besoin du mot de passe d'administration) : chacun
 * choisit sa propre adresse email, peut activer/desactiver les rappels
 * pour lui-meme (sans avoir a effacer son adresse), et peut aussi choisir
 * d'etre prevenu des rendez-vous de l'autre personne. Contrairement a
 * admin/reglages.php (delai, activer/desactiver globalement, adresse
 * d'expedition), ces reglages-ci sont penses pour etre modifies
 * directement par la famille, sans passer par l'administration.
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/rappels_personnes.php';
require_once __DIR__ . '/lib/mailer.php';

$config = require __DIR__ . '/config.php';
$configSmtp = construireConfigSmtp($config);

$db = getDb();
$reminderEnabled = getSetting($db, 'reminder_enabled', '0') === '1';

// Une carte par patient, engendree par une boucle : les deux blocs etaient
// ecrits en dur pour "person1" et "person2", une troisieme personne
// n'aurait eu ni champ ni rappel (voir lib/rappels_personnes.php).
$patients = listerPatients($db);
$reglages = lireReglagesRappel($db, $patients);

// "?enregistre=1" plutot qu'un simple booleen mis a jour dans la meme
// requete : permet de rediriger apres l'enregistrement (Post/Redirect/Get,
// comme tâches/médecins/médicaments) pour qu'un rafraichissement de page
// ne redemande pas "voulez-vous renvoyer le formulaire ?".
$messageEnregistre = isset($_GET['enregistre']);
$resultatTest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Relit d'abord tout ce qui a ete saisi, pour que l'envoi de test
    // utilise l'adresse a l'ecran meme si elle n'est pas encore enregistree.
    foreach ($patients as $patient) {
        $id = (int) $patient['id'];
        $reglages[$id] = [
            'email' => isset($_POST['email_' . $id]) ? trim($_POST['email_' . $id]) : '',
            'soi' => !empty($_POST['soi_' . $id]),
            'autres' => !empty($_POST['autres_' . $id]),
        ];
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'enregistrer') {
        foreach ($reglages as $id => $r) {
            enregistrerReglagesRappel($db, $id, $r['email'], $r['soi'], $r['autres']);
        }
        header('Location: /mes_rappels.php?enregistre=1');
        exit;
    } elseif (strpos($action, 'tester_') === 0) {
        $idTeste = (int) substr($action, strlen('tester_'));
        if (isset($reglages[$idTeste])) {
            $email = $reglages[$idTeste]['email'];
            $nomPersonne = $patients[$idTeste]['nom'];

            if ($email === '') {
                $resultatTest = [
                    'cible' => $idTeste,
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
                    'cible' => $idTeste,
                    'ok' => $envoi['ok'],
                    'message' => $envoi['ok']
                        ? "Email de test envoyé à $email. Vérifiez la réception (et le dossier spam)."
                        : "L'envoi a échoué : " . $envoi['erreur'],
                ];
            }
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
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
  <div class="barre-admin">
    <h1>Rappels par email</h1>
    <div>
      <span class="qui-connecte"><?= htmlspecialchars(personneSessionActuelle()) ?></span>
      <a href="/index.php">Retour à l'agenda</a>
    </div>
  </div>
  <p class="sous-titre" style="margin-bottom:18px;">Chacun renseigne ici son adresse email, active ou désactive les rappels pour lui-même (sans avoir à effacer son adresse), et peut aussi choisir d'être prévenu des rendez-vous des autres.</p>

  <?php if (!$reminderEnabled): ?>
    <p class="aide avertissement" style="margin:8px 0 16px;">Les rappels par email sont actuellement désactivés pour tout le monde (réglage géré dans l'administration). Les réglages ci-dessous seront pris en compte dès qu'ils seront réactivés.</p>
  <?php endif; ?>

  <?php if ($messageEnregistre): ?>
    <p class="info">Réglages enregistrés.</p>
  <?php endif; ?>

  <form method="post">
    <?php $premier = true; foreach ($patients as $patient): ?>
      <?php $id = (int) $patient['id']; $r = $reglages[$id]; ?>
      <div class="outil"<?= $premier ? '' : ' style="margin-top:16px;"' ?>>
        <h2 class="panneau-titre"><?= htmlspecialchars($patient['nom']) ?></h2>
        <div class="champ">
          <label>Adresse email de <?= htmlspecialchars($patient['nom']) ?></label>
          <input type="email" name="email_<?= $id ?>" value="<?= htmlspecialchars($r['email']) ?>" placeholder="<?= htmlspecialchars(strtolower($patient['nom'])) ?>@example.com">
          <p class="aide">L'adresse peut rester enregistrée même si les rappels sont désactivés ci-dessous.</p>
        </div>
        <div class="champ-case">
          <input type="checkbox" name="soi_<?= $id ?>" id="soi<?= $id ?>" value="1" <?= $r['soi'] ? 'checked' : '' ?>>
          <label for="soi<?= $id ?>">Je souhaite recevoir un rappel pour mes rendez-vous</label>
        </div>
        <?php /* "des autres" et non "de X" : avec plus de deux personnes,
                 nommer l'autre n'a plus de sens. */ ?>
        <div class="champ-case">
          <input type="checkbox" name="autres_<?= $id ?>" id="autres<?= $id ?>" value="1" <?= $r['autres'] ? 'checked' : '' ?>>
          <label for="autres<?= $id ?>">Recevoir aussi les rappels des rendez-vous des autres personnes</label>
        </div>

        <?php if ($resultatTest !== null && $resultatTest['cible'] === $id): ?>
          <p class="<?= $resultatTest['ok'] ? 'info' : 'erreur' ?>"><?= htmlspecialchars($resultatTest['message']) ?></p>
        <?php endif; ?>

        <button class="secondaire" type="submit" name="action" value="tester_<?= $id ?>">Envoyer un email de test à <?= htmlspecialchars($patient['nom']) ?></button>
      </div>
      <?php $premier = false; ?>
    <?php endforeach; ?>

    <div class="form-boutons" style="margin-top:16px;">
      <button class="principal" type="submit" name="action" value="enregistrer">Enregistrer les réglages</button>
    </div>
  </form>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/assets/admin-ui.js') ?>"></script>
</body>
</html>
