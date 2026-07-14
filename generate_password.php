<?php
/**
 * UTILITAIRE A USAGE UNIQUE.
 *
 * Ouvrez cette page une fois dans votre navigateur (ex :
 * https://agenda.hellau.be/generate_password.php), choisissez votre mot
 * de passe familial, copiez le hash genere dans config.php
 * (family_password_hash), puis SUPPRIMEZ ce fichier du serveur.
 */

$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['password'])) {
    $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Generer le mot de passe</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>Generer un mot de passe</h1>
    <p class="sous-titre">Choisissez le mot de passe familial, copiez le hash obtenu dans config.php, puis supprimez ce fichier.</p>
    <form method="post">
      <input type="text" name="password" placeholder="Mot de passe souhaite" required>
      <button class="principal" type="submit">Generer le hash</button>
    </form>
    <?php if ($hash): ?>
      <p class="info">Copiez cette valeur dans config.php (family_password_hash) :</p>
      <textarea readonly style="width:100%; height:80px; font-family:monospace; font-size:14px;"><?= htmlspecialchars($hash) ?></textarea>
      <p class="erreur">Pensez a supprimer ce fichier (generate_password.php) du serveur une fois termine.</p>
    <?php endif; ?>
  </div>
</body>
</html>
