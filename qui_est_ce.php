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
require_once __DIR__ . '/lib/persons.php';
require_once __DIR__ . '/lib/activity_log.php';

// Deja identifie sur cette session (ex: revenu ici via l'URL par erreur) :
// rien a refaire.
if (personneSessionActuelle() !== null) {
    header('Location: /index.php');
    exit;
}

$db = getDb();

// La liste vient de la base, et de nulle part ailleurs (voir
// admin/personnes.php). Sur une installation neuve la table est vide :
// le message plus bas renvoie vers l'administration, qui reste
// accessible sans etre identifie (elle ne demande que les deux mots de
// passe, pas l'ecran "Qui etes-vous ?").
$membres = listerMembresFamille($db);

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choisi = null;
    if (isset($_POST['person_id'])) {
        $id = (int) $_POST['person_id'];
        foreach ($membres as $m) {
            if ((int) $m['id'] === $id) {
                $choisi = $m;
                break;
            }
        }
    }

    if ($choisi === null) {
        $erreur = 'Merci de choisir un nom dans la liste.';
    } else {
        definirPersonneSession($choisi['nom'], (int) $choisi['id']);
        enregistrerActivite($db, 'connexion', $choisi['nom']);
        // Evite qu'index.php (via requireIdentite -> enregistrerVisiteSiNecessaire)
        // n'enregistre une deuxieme ligne "Connexion" dans la foulee.
        $_SESSION['derniere_visite_loggee'] = time();
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
      <?php foreach ($membres as $m): ?>
        <button class="secondaire" type="submit" name="person_id" value="<?= (int) $m['id'] ?>"><?= htmlspecialchars($m['nom']) ?></button>
      <?php endforeach; ?>
    </form>
    <?php if (empty($membres)): ?>
      <p class="erreur">Aucune personne n'est autorisée à se connecter. Ajoute-les depuis l'administration, page « Personnes ».</p>
    <?php endif; ?>
  </div>
</body>
</html>
