<?php
/**
 * Demande "qui etes-vous ?" juste apres la connexion avec le mot de passe
 * familial (partage entre plusieurs personnes) : contrairement au mot de
 * passe, ce choix permet de savoir QUI a ajoute/modifie un rendez-vous
 * pour le journal d'activite (voir lib/activity_log.php, historique.php).
 *
 * Demande une seule fois par session (voir requireIdentite() dans
 * lib/auth.php) : une fois choisi, plus jamais redemande tant que la
 * personne ne se deconnecte pas (logout.php efface toute la session).
 */

require_once __DIR__ . '/lib/auth.php';
requireLogin();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/activity_log.php';

// Deja identifie sur cette session (ex: revenu ici via l'URL par erreur) :
// rien a refaire.
if (personneSessionActuelle() !== null) {
    header('Location: /index.php');
    exit;
}

$config = require __DIR__ . '/config.php';
$membresFamille = (isset($config['membres_famille']) && is_array($config['membres_famille']) && !empty($config['membres_famille']))
    ? $config['membres_famille']
    : ['Michel', 'Christiane', 'Helene', 'Laurent'];

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = isset($_POST['personne']) ? trim($_POST['personne']) : '';
    if (!in_array($nom, $membresFamille, true)) {
        $erreur = 'Merci de choisir un nom dans la liste.';
    } else {
        definirPersonneSession($nom);
        $db = getDb();
        enregistrerActivite($db, 'connexion', $nom);
        header('Location: /index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Qui êtes-vous ? — Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>Agenda médical</h1>
    <p class="sous-titre">Qui êtes-vous ?</p>
    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>
    <form method="post" class="choix-personnes">
      <?php foreach ($membresFamille as $nom): ?>
        <button class="secondaire" type="submit" name="personne" value="<?= htmlspecialchars($nom) ?>"><?= htmlspecialchars($nom) ?></button>
      <?php endforeach; ?>
    </form>
  </div>
</body>
</html>
