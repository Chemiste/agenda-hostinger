<?php
/**
 * Connexion au site, par compte Google.
 *
 * REMPLACE le mot de passe familial partage. Ce mot de passe donnait
 * l'acces, mais pas l'identite : apres l'avoir tape, chacun cliquait son
 * nom dans "Qui etes-vous ?" et le site le croyait sur parole. Comme
 * l'identite servait aussi de droit (qui peut modifier les pathologies),
 * n'importe quel membre de la famille pouvait s'attribuer ces droits.
 * Google atteste l'identite, il n'y a plus rien a croire sur parole.
 *
 * Se connecter avec un compte Google valide ne suffit PAS : le compte doit
 * avoir ete rattache a une personne au prealable, depuis
 * /admin/personnes.php. Sans quoi le premier venu entrerait.
 *
 * FLUX. On utilise le mode "callback JavaScript" de Google Identity
 * Services, pas le mode "login_uri". Avec login_uri, c'est Google qui
 * poste vers le site : la requete est alors inter-site, et le cookie de
 * session (SameSite=Lax) n'est pas transmis - il faut s'en remettre au
 * jeton anti-CSRF de Google. Ici le navigateur poste vers notre propre
 * domaine : le cookie de session suit normalement et on protege la
 * requete avec notre propre jeton, comme le reste du site.
 *
 * SI GOOGLE EST INJOIGNABLE (projet supprime, panne, identifiant revoque),
 * plus personne ne peut se connecter, et c'est assume : il n'existe aucun
 * mot de passe de secours. La reparation passe alors par l'hebergeur -
 * gestionnaire de fichiers pour corriger config.php, phpMyAdmin pour la
 * base. Un second secret permanent aurait ete une porte a garder toute
 * l'annee pour un incident rare.
 */

require_once __DIR__ . '/lib/auth.php';

if (isLoggedIn() && personIdSessionActuel() !== null) {
    header('Location: /index.php');
    exit;
}

$config = require __DIR__ . '/config.php';
$clientId = isset($config['google_client_id']) ? (string) $config['google_client_id'] : '';

// Jeton anti-CSRF propre au site, pose dans la session et renvoye par le
// JavaScript avec le jeton Google. Sans lui, un site tiers pourrait faire
// poster a la victime un jeton Google appartenant a l'attaquant, et la
// connecter sous l'identite de celui-ci a son insu.
if (empty($_SESSION['jeton_connexion'])) {
    $_SESSION['jeton_connexion'] = bin2hex(random_bytes(32));
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $entree = json_decode(file_get_contents('php://input'), true);
    $reponse = ['ok' => false, 'erreur' => 'Connexion impossible.'];

    try {
        if (!is_array($entree) || empty($entree['csrf'])
            || !hash_equals($_SESSION['jeton_connexion'], (string) $entree['csrf'])) {
            throw new Exception('Session expiree, recharge la page.');
        }
        require_once __DIR__ . '/lib/db.php';
        require_once __DIR__ . '/lib/google_login.php';
        require_once __DIR__ . '/lib/activity_log.php';

        $db = getDb();
        $infos = verifierJetonGoogle($db, isset($entree['credential']) ? $entree['credential'] : '', $clientId);
        $personne = personParCompteGoogle($db, $infos);

        if ($personne === null) {
            // Message volontairement peu bavard : il ne doit pas indiquer
            // si l'adresse existe dans la base ou non.
            throw new Exception("Ce compte Google n'a pas accès à l'agenda. Demande à Laurent de t'ajouter.");
        }

        connecterPersonne($personne);
        enregistrerActivite($db, 'connexion', $personne['nom']);
        // Evite qu'index.php n'ajoute une seconde ligne "Connexion" dans
        // la foulee (voir enregistrerVisiteSiNecessaire).
        $_SESSION['derniere_visite_loggee'] = time();

        $reponse = ['ok' => true];
    } catch (Exception $e) {
        error_log('[agenda] connexion Google refusee : ' . $e->getMessage());
        $reponse = ['ok' => false, 'erreur' => $e->getMessage()];
    }

    echo json_encode($reponse);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion - Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>Agenda médical</h1>

    <?php if ($clientId === '' || strpos($clientId, 'REMPLACER') === 0): ?>
      <p class="erreur">
        La connexion Google n'est pas encore configurée sur ce site.
        Renseigne <code>google_client_id</code> dans <code>config.php</code>.
      </p>
    <?php else: ?>
      <p class="sous-titre">Connecte-toi avec ton compte Google.</p>
      <p class="erreur" id="erreurConnexion" style="display:none;"></p>

      <div id="boutonGoogle" class="zone-bouton-google"></div>

      <script src="https://accounts.google.com/gsi/client" async defer></script>
      <script>
        // window.onload plutot qu'un appel direct : le script Google est
        // charge en "async defer", google.accounts n'existe pas encore au
        // moment ou cette ligne est lue.
        window.onload = function () {
          if (!window.google || !google.accounts || !google.accounts.id) {
            afficherErreur("Impossible de charger la connexion Google. Vérifie ta connexion internet.");
            return;
          }
          google.accounts.id.initialize({
            client_id: <?= json_encode($clientId) ?>,
            callback: envoyerJeton,
            auto_select: true
          });
          google.accounts.id.renderButton(document.getElementById('boutonGoogle'), {
            type: 'standard', theme: 'outline', size: 'large',
            text: 'signin_with', shape: 'rectangular', locale: 'fr'
          });
        };

        function afficherErreur(message) {
          var p = document.getElementById('erreurConnexion');
          p.textContent = message;
          p.style.display = '';
        }

        function envoyerJeton(reponseGoogle) {
          fetch('/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              credential: reponseGoogle.credential,
              csrf: <?= json_encode($_SESSION['jeton_connexion']) ?>
            })
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.ok) {
                window.location.href = '/index.php';
              } else {
                afficherErreur(data.erreur || 'Connexion impossible.');
              }
            })
            .catch(function () {
              afficherErreur("Le site n'a pas répondu. Réessaie dans un instant.");
            });
        }
      </script>
    <?php endif; ?>
  </div>
</body>
</html>
