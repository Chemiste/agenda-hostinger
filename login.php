<?php
require_once __DIR__ . '/lib/auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motDePasse = $_POST['password'] ?? '';
    if (attemptLogin($motDePasse)) {
        header('Location: index.php');
        exit;
    }
    $erreur = 'Mot de passe incorrect.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion - Agenda médical</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>Agenda médical</h1>
    <p class="sous-titre">Entrez le mot de passe familial</p>
    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="password" name="password" placeholder="Mot de passe" autofocus required>
      <button class="principal" type="submit">Se connecter</button>
    </form>
  </div>
</body>
</html>