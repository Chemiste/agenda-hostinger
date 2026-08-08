<?php
/**
 * ADMINISTRATION : alias d'affichage pour les adresses.
 *
 * Permet de definir des remplacements comme "Avenue Hippocrate, 10, 1200
 * Bruxelles" -> "Hopital St Luc", appliques UNIQUEMENT a l'affichage a
 * l'ecran et a l'impression (voir api.php::listAppointments() et
 * lib/address_aliases.php). Le champ "Adresse" du rendez-vous - celui
 * enregistre en base et envoye a Google Calendar - n'est jamais modifie :
 * Waze/Maps continuent donc de recevoir l'adresse reelle depuis un
 * evenement du calendrier partage.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/address_aliases.php';

$db = getDb();
$erreur = '';
$succes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter') {
        try {
            ajouterAliasAdresse($db, isset($_POST['motif']) ? $_POST['motif'] : '', isset($_POST['remplacement']) ? $_POST['remplacement'] : '');
            $succes = 'Alias ajouté.';
        } catch (Exception $e) {
            $erreur = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'supprimer' && isset($_POST['id'])) {
        supprimerAliasAdresse($db, $_POST['id']);
        $succes = 'Alias supprimé.';
    }
}

$aliases = listerAliasAdresses($db);

// Adresses distinctes deja utilisees par au moins un rendez-vous, en
// excluant celles qui ont deja un alias (motif identique, ou qui seraient
// deja transformees par un alias existant vu que la comparaison se fait
// par sous-chaine - voir appliquerAliasAdresse()) : on ne propose dans la
// liste que des adresses "brutes" sans alias, plutot que de laisser
// ressaisir un texte a la main (source d'erreurs de frappe).
$adressesDistinctes = $db->query("SELECT DISTINCT location FROM appointments WHERE location IS NOT NULL AND location <> '' ORDER BY location")->fetchAll(PDO::FETCH_COLUMN);
$adressesSansAlias = array_values(array_filter($adressesDistinctes, function ($adresse) use ($aliases) {
    return appliquerAliasAdresse($adresse, $aliases) === $adresse;
}));

// Nombre de rendez-vous concernes par chaque alias (utile pour juger si
// un alias sert encore avant de le supprimer). Meme logique de
// correspondance que l'affichage reel (sous-chaine, insensible a la casse).
$comptesParAdresse = [];
foreach ($db->query("SELECT location, COUNT(*) AS nb FROM appointments WHERE location IS NOT NULL AND location <> '' GROUP BY location") as $ligne) {
    $comptesParAdresse[$ligne['location']] = (int) $ligne['nb'];
}
foreach ($aliases as &$a) {
    $nb = 0;
    foreach ($comptesParAdresse as $adresse => $compte) {
        if (stripos($adresse, $a['motif']) !== false) $nb += $compte;
    }
    $a['usage'] = $nb;
}
unset($a);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Alias d'adresses — Administration</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/../assets/admin.css') ?>">
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
    <a href="/admin/index.php">Administration</a><span class="sep">/</span><span class="actuel">Alias d'adresses</span>
  </div>

  <div class="outil">
    <h2 class="panneau-titre">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      Alias d'adresses
    </h2>
    <p class="sous-titre">
      Remplace une adresse par un nom plus parlant (ex : "Avenue Hippocrate, 10, 1200 Bruxelles" → "Hôpital St Luc"),
      uniquement à l'écran et à l'impression. L'adresse réelle enregistrée dans le rendez-vous et envoyée à Google
      Calendar n'est jamais modifiée, pour que Waze/Maps continuent de fonctionner depuis le calendrier.
    </p>

    <?php if ($erreur): ?>
      <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
    <?php endif; ?>
    <?php if ($succes): ?>
      <p class="info"><?= htmlspecialchars($succes) ?></p>
    <?php endif; ?>

    <?php if (empty($adressesDistinctes)): ?>
      <p class="vide">Aucune adresse enregistrée dans les rendez-vous pour l'instant.</p>
    <?php elseif (empty($adressesSansAlias)): ?>
      <p class="vide">Toutes les adresses utilisées dans les rendez-vous ont déjà un alias.</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="ajouter">
        <div class="champ">
          <label>Adresse (utilisée dans au moins un rendez-vous, sans alias pour l'instant)</label>
          <select name="motif" required>
            <option value="">— Sélectionner une adresse —</option>
            <?php foreach ($adressesSansAlias as $adresse): ?>
              <option value="<?= htmlspecialchars($adresse) ?>"><?= htmlspecialchars($adresse) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ">
          <label>Remplacée par</label>
          <input type="text" name="remplacement" placeholder="Hôpital St Luc" required>
        </div>
        <div class="form-boutons">
          <button class="principal" type="submit">Ajouter cet alias</button>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="outil" style="margin-top:16px;">
    <h2 class="panneau-titre" style="font-size:15px;">Alias existants</h2>

    <?php if (empty($aliases)): ?>
      <p class="vide">Aucun alias pour l'instant.</p>
    <?php else: ?>
      <?php foreach ($aliases as $a): ?>
        <div class="rangee-alias">
          <div class="detail-alias">
            <span class="motif-alias"><?= htmlspecialchars($a['motif']) ?></span>
            <span class="fleche-alias">→</span>
            <span class="remplacement-alias"><?= htmlspecialchars($a['remplacement']) ?></span>
            <span class="usage-alias"><?= (int) $a['usage'] ?> rendez-vous</span>
          </div>
          <form method="post" data-confirm="Supprimer cet alias ? Les adresses concernées réafficheront le texte complet.">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button type="submit" class="lien-danger">Supprimer</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/../assets/admin-ui.js') ?>"></script>
</body>
</html>
