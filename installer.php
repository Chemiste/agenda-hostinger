<?php
/**
 * Installation d'un site neuf : cree les tables, puis designe le premier
 * administrateur a partir du compte Google qui s'y connecte.
 *
 * POURQUOI CETTE PAGE EXISTE. Se connecter au site demande d'etre inscrit
 * dans la table persons, et inscrire quelqu'un demande d'etre
 * administrateur. Sur une base vide, ces deux conditions s'excluent : plus
 * personne ne peut entrer nulle part. C'est le probleme de l'oeuf et de la
 * poule, et il se resolvait jusqu'ici avec un mot de passe
 * d'administration - un second secret a definir, a retenir et a proteger,
 * pour un usage qui ne survient qu'une fois dans la vie du site. Cette
 * page le remplace : elle amorce, puis se desactive d'elle-meme.
 *
 * ELLE SE DESACTIVE SEULE. Des qu'une personne porte le drapeau est_admin,
 * elle refuse de faire quoi que ce soit. Pas de fichier a supprimer, pas
 * d'etape a ne pas oublier : le site cesse d'etre installable au moment
 * meme ou il devient installe.
 *
 * LE JETON D'INSTALLATION protege la fenetre qui precede. Entre le moment
 * ou le site repond et celui ou tu lances l'installation, n'importe qui
 * atteignant cette adresse deviendrait administrateur - et l'agenda
 * medical de tes parents avec. Le jeton se pose dans config.php, au moment
 * meme ou tu y mets les identifiants de la base : aucune etape en plus.
 */

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/migrations.php';
require_once __DIR__ . '/lib/persons.php';

$config = require __DIR__ . '/config.php';
$clientId = isset($config['google_client_id']) ? (string) $config['google_client_id'] : '';
$jetonAttendu = isset($config['installation_token']) ? (string) $config['installation_token'] : '';

/**
 * Le site est-il deja installe ? La question se ramene a : quelqu'un
 * peut-il administrer ? Si la table n'existe pas encore, la reponse est
 * non - et c'est bien pour ca qu'on est ici.
 */
function siteDejaInstalle($db) {
    try {
        $stmt = $db->query('SELECT COUNT(*) FROM persons WHERE est_admin = 1 AND actif = 1');
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

$db = null;
$erreurBase = '';
try {
    $db = getDb();
} catch (Exception $e) {
    $erreurBase = "Connexion à la base impossible. Vérifie db_host, db_name, db_user et db_pass dans config.php.";
}

if ($db !== null && siteDejaInstalle($db)) {
    header('Location: /login.php');
    exit;
}

$etape = 'jeton';
$erreur = '';
$message = '';
$migrationsFaites = [];

if (!empty($_SESSION['installation_autorisee'])) {
    $etape = 'compte';
}

// --- Traitement AJAX : le compte Google qui se presente devient admin ---
// Le formulaire du jeton poste en formulaire classique, le bouton Google
// poste du JSON : le type de contenu suffit a les distinguer.
$estAppelJson = strpos((string) (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : ''), 'application/json') !== false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $estAppelJson) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (empty($_SESSION['installation_autorisee'])) {
            throw new Exception("Jeton d'installation non validé.");
        }
        require_once __DIR__ . '/lib/google_login.php';
        $entree = json_decode(file_get_contents('php://input'), true);
        if (!is_array($entree) || empty($entree['csrf'])
            || !hash_equals($_SESSION['jeton_installation_csrf'], (string) $entree['csrf'])) {
            throw new Exception('Session expirée, recharge la page.');
        }

        $infos = verifierJetonGoogle($db, isset($entree['credential']) ? $entree['credential'] : '', $clientId);
        if ($infos['email'] === '' || !$infos['email_verified']) {
            throw new Exception("Ce compte Google n'a pas d'adresse vérifiée.");
        }

        // Rien ne garantit qu'un autre onglet n'a pas installe le site
        // entre-temps : on revérifie juste avant d'écrire.
        if (siteDejaInstalle($db)) {
            throw new Exception('Le site vient déjà d\'être installé.');
        }

        $nom = $infos['nom'] !== '' ? $infos['nom'] : 'Administrateur';
        $id = ajouterPerson($db, $nom, false, true, $infos['email'], true);
        $stmt = $db->prepare('UPDATE persons SET google_sub = ? WHERE id = ?');
        $stmt->execute([$infos['sub'], $id]);

        unset($_SESSION['installation_autorisee'], $_SESSION['jeton_installation_csrf']);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        error_log('[agenda] installation refusee : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'erreur' => $e->getMessage()]);
    }
    exit;
}

// --- Etape 1 : le jeton, puis les migrations ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jeton'])) {
    if ($jetonAttendu === '' || strpos($jetonAttendu, 'REMPLACER') === 0) {
        $erreur = "Aucun jeton d'installation n'est défini dans config.php.";
    } elseif (!hash_equals($jetonAttendu, (string) $_POST['jeton'])) {
        // Une seconde de pause : sans elle, on pourrait essayer des
        // milliers de jetons par minute sur cette page.
        sleep(1);
        $erreur = "Jeton incorrect.";
    } else {
        try {
            $migrationsFaites = executerMigrations();
            $_SESSION['installation_autorisee'] = true;
            $_SESSION['jeton_installation_csrf'] = bin2hex(random_bytes(32));
            $etape = 'compte';
            $message = empty($migrationsFaites)
                ? 'La base était déjà à jour.'
                : count($migrationsFaites) . ' migration(s) appliquée(s).';
        } catch (Exception $e) {
            $erreur = 'Création des tables impossible : ' . $e->getMessage();
        }
    }
}

if ($etape === 'compte' && empty($_SESSION['jeton_installation_csrf'])) {
    $_SESSION['jeton_installation_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation - Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="page-login">
  <div class="login-card">
    <h1>Installation</h1>

    <?php if ($erreurBase !== ''): ?>
      <p class="erreur"><?= htmlspecialchars($erreurBase) ?></p>

    <?php elseif ($etape === 'jeton'): ?>
      <p class="sous-titre">
        Saisis le jeton d'installation que tu as mis dans <code>config.php</code>
        (champ <code>installation_token</code>).
      </p>
      <?php if ($erreur !== ''): ?><p class="erreur"><?= htmlspecialchars($erreur) ?></p><?php endif; ?>
      <form method="post">
        <input type="password" name="jeton" placeholder="Jeton d'installation" autofocus required>
        <button class="principal" type="submit">Continuer</button>
      </form>

    <?php else: ?>
      <?php if ($message !== ''): ?><p class="sous-titre"><?= htmlspecialchars($message) ?></p><?php endif; ?>
      <p class="sous-titre">
        Connecte-toi maintenant avec <strong>ton</strong> compte Google : il
        deviendra le premier administrateur, et c'est depuis l'administration
        que tu inscriras ensuite les autres membres de la famille.
      </p>
      <p class="erreur" id="erreurInstallation" style="display:none;"></p>

      <?php if ($clientId === '' || strpos($clientId, 'REMPLACER') === 0): ?>
        <p class="erreur">
          <code>google_client_id</code> n'est pas renseigné dans <code>config.php</code>.
        </p>
      <?php else: ?>
        <div id="boutonGoogle" class="zone-bouton-google"></div>

        <script src="https://accounts.google.com/gsi/client" async defer></script>
        <script>
          window.onload = function () {
            if (!window.google || !google.accounts || !google.accounts.id) {
              afficherErreur("Impossible de charger la connexion Google.");
              return;
            }
            google.accounts.id.initialize({
              client_id: <?= json_encode($clientId) ?>,
              callback: envoyerJeton
            });
            google.accounts.id.renderButton(document.getElementById('boutonGoogle'), {
              type: 'standard', theme: 'outline', size: 'large',
              text: 'signin_with', shape: 'rectangular', locale: 'fr'
            });
          };

          function afficherErreur(message) {
            var p = document.getElementById('erreurInstallation');
            p.textContent = message;
            p.style.display = '';
          }

          function envoyerJeton(reponseGoogle) {
            fetch('/installer.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                credential: reponseGoogle.credential,
                csrf: <?= json_encode($_SESSION['jeton_installation_csrf']) ?>
              })
            })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data.ok) {
                  window.location.href = '/login.php';
                } else {
                  afficherErreur(data.erreur || 'Installation impossible.');
                }
              })
              .catch(function () {
                afficherErreur("Le site n'a pas répondu.");
              });
          }
        </script>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
