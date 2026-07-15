<?php
require_once __DIR__ . '/../lib/auth.php';
requireLogin();

if (isAdminLoggedIn()) {
    header('Location: /admin/nettoyage.php');
    exit;
}

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $motDePasse = $_POST['password'] ?? '';
    if (attemptAdminLogin($motDePasse)) {
        header('Location: /admin/nettoyage.php');
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
<title>Administration - Agenda médical</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>Administration</h1>
    <p class="sous-titre">Cette section est réservée : entrez le mot de passe d'administration.</p>
    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="password" name="password" placeholder="Mot de passe admin" autofocus required>
      <button class="principal" type="submit">Entrer</button>
    </form>
    <p style="margin-top:16px;"><a href="/index.php">Retour à l'agenda</a></p>
  </div>
</body>
</html>
