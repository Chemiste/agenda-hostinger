<?php
/**
 * Suivi des pathologies (voir lib/pathologies.php) : pour Michel et
 * Christiane, une liste de pathologies avec leur cause/raison et ce qui
 * est fait pour les soigner (kiné, médecin, médicaments...), en texte
 * libre. Pensé pour répondre rapidement à "qu'est-ce qu'on m'a dit pour
 * mon dos ?" lors d'un rendez-vous, même des mois plus tard - voir aussi
 * pathologies_plan.php pour la fiche imprimable par personne.
 *
 * Modification (ajout/édition/suppression) réservée à Laurent, comme
 * medicaments.php - les autres consultent et impriment. $peutModifier
 * protège aussi bien l'affichage des formulaires/boutons que le
 * traitement des actions POST.
 */

require_once __DIR__ . '/lib/auth.php';
requireIdentite();
require_once __DIR__ . '/lib/entete.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/pathologies.php';
require_once __DIR__ . '/lib/persons.php';

// ATTENTION - ne pas revenir a personneSessionActuelle() === 'Laurent'.
// Ce test a existe, et il n'en etait pas un : l'identite venait d'un nom
// clique dans un ecran de choix, que rien ne verifiait. N'importe qui dans
// la famille pouvait se declarer Laurent et supprimer les pathologies de
// Michel ou de Christiane. Depuis que Google atteste l'identite, le
// drapeau est_admin de la table persons a du sens.
$peutModifier = personneConnecteeEstAdmin();

$db = getDb();

// Les patients viennent de la table persons (voir admin/personnes.php) :
// une troisieme personne apparait ici sans toucher au code, et renommer
// quelqu'un ne detache plus ses pathologies.
$patients = listerPatients($db);
$erreur = '';
$idEnEdition = null;

if ($peutModifier && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter') {
        try {
            $personIdForm = validerPatient($db, isset($_POST['person_id']) ? $_POST['person_id'] : 0);
            ajouterPathologie(
                $db,
                $personIdForm,
                isset($_POST['nom']) ? $_POST['nom'] : '',
                isset($_POST['cause']) ? $_POST['cause'] : '',
                isset($_POST['traitement']) ? $_POST['traitement'] : ''
            );
            // Post/Redirect/Get (comme medicaments.php) : repart sur un
            // formulaire vide plutot que de rester rempli apres l'ajout,
            // et revient a la bonne section (Michel ou Christiane).
            header('Location: /pathologies.php?p=' . $personIdForm . '');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
        }
    } elseif ($_POST['action'] === 'modifier' && isset($_POST['id'])) {
        try {
            modifierPathologie(
                $db,
                $_POST['id'],
                isset($_POST['nom']) ? $_POST['nom'] : '',
                isset($_POST['cause']) ? $_POST['cause'] : '',
                isset($_POST['traitement']) ? $_POST['traitement'] : ''
            );
            $personIdForm = validerPatient($db, isset($_POST['person_id']) ? $_POST['person_id'] : 0);
            header('Location: /pathologies.php?p=' . $personIdForm . '');
            exit;
        } catch (Exception $e) {
            $erreur = $e->getMessage();
            $idEnEdition = (int) $_POST['id'];
        }
    } elseif ($_POST['action'] === 'supprimer' && isset($_POST['id'])) {
        supprimerPathologie($db, $_POST['id']);
        $personIdForm = validerPatient($db, isset($_POST['person_id']) ? $_POST['person_id'] : 0);
        header('Location: /pathologies.php?p=' . $personIdForm);
        exit;
    }
}

// La personne dont le formulaire doit s'ouvrir en edition (via
// ?modifier=id) ou rester affichee apres un ajout (via ?p=...) : sert a
// savoir dans laquelle des deux sections (Michel/Christiane) placer le
// formulaire pre-rempli. Sur un echec de validation (POST qui retombe ici
// sans redirection), $_POST['person'] joue le meme role que "?p=..." pour
// savoir dans quelle section reafficher l'erreur et les valeurs saisies.
$personIdAffiche = (int) (isset($_GET['p']) ? $_GET['p'] : (isset($_POST['person_id']) ? $_POST['person_id'] : 0));

// $idEnEdition peut deja etre defini a ce stade suite a un echec de
// validation sur "modifier" (voir plus haut) : dans ce cas on garde cet id
// plutot que celui de l'URL. Dans les deux cas, on recharge la pathologie
// depuis la base pour savoir a qui elle appartient (section a afficher) -
// mais on ne remplace les valeurs du formulaire par celles de la base que
// si ce n'est PAS un echec de validation (sinon on effacerait ce que la
// personne venait de taper).
if ($idEnEdition === null && $peutModifier && isset($_GET['modifier'])) {
    $idEnEdition = (int) $_GET['modifier'];
}
$pathologieEnEdition = null;
if ($peutModifier && $idEnEdition !== null) {
    $pathologieEnEdition = obtenirPathologie($db, $idEnEdition);
    if ($pathologieEnEdition === null) {
        $idEnEdition = null;
    } else {
        $personIdAffiche = (int) $pathologieEnEdition['person_id'];
        if ($erreur === '') {
            $_POST['nom'] = $pathologieEnEdition['nom'];
            $_POST['cause'] = $pathologieEnEdition['cause'];
            $_POST['traitement'] = $pathologieEnEdition['traitement'];
        }
    }
}

$pathologiesParPersonne = [];
foreach ($patients as $patient) {
    $pathologiesParPersonne[$patient['id']] = listerPathologies($db, $patient['id']);
}

// Personne dont la section est visible au chargement : celle deja ciblee
// par un lien "Modifier"/un ajout (?p=... ou pathologie en edition), sinon
// la premiere de la liste par defaut. Le reste (bascule d'un onglet a
// l'autre sans recharger la page) est gere en JS plus bas - ne depend pas
// du nombre de personnes, contrairement a l'ancien affichage empile qui
// devenait plus long a chaque personne ajoutee.
$personIdActif = isset($patients[$personIdAffiche])
    ? $personIdAffiche
    : (!empty($patients) ? (int) key($patients) : 0);

// Meme mapping de couleurs que classeBadge() dans app.js. Il repose
// desormais sur le RANG du patient et non sur son nom : une 3e personne
// retombe sur "deux" sans rien casser.
function classePersonnePathologie($rang) {
    if ($rang === 0) return 'papa';
    if ($rang === 1) return 'maman';
    return 'deux';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pathologies — Agenda médical</title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="/assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body>
  <?php afficherEnteteNavigation('pathologies'); ?>

  <?php /* Titre a gauche, actions compactes a droite : meme disposition
           que la barre du titre de l'agenda. "Imprimer" pointe vers la
           fiche de la personne de l'onglet actif - son href est reecrit en
           JS a chaque changement d'onglet (voir plus bas). */ ?>
  <div class="barre-admin">
    <h1>Pathologies</h1>
    <div class="entete-actions">
      <?php if ($peutModifier): ?>
        <button type="button" class="bouton-compact bouton-compact-principal" id="btnAjouterPathologie" data-ouvre-modal="modalPathologie">
          <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
          Ajouter
        </button>
      <?php endif; ?>
      <a class="bouton-compact" id="lienFichePathologies" href="/pathologies_plan.php?person=<?= $personIdActif ?>">
        <svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6"/><rect x="4" y="9" width="16" height="8" rx="1"/><path d="M6 17v4h12v-4"/></svg>
        Imprimer
      </a>
    </div>
  </div>
  <p class="sous-titre" style="margin-bottom:18px;">
    Pour chaque pathologie : la cause/raison, et ce qui est fait pour la soigner (kiné, médecin, médicaments...).
    <?php if (!$peutModifier): ?>
      Seul Laurent peut modifier cette liste — tu peux la consulter et l'imprimer.
    <?php endif; ?>
  </p>

  <div class="tabs" id="tabsPersonnesPathologies" role="tablist">
    <?php $rang = 0; foreach ($patients as $patient): ?>
      <div class="tab <?= classePersonnePathologie($rang++) ?> <?= $patient['id'] === $personIdActif ? 'active' : '' ?>" data-personne="<?= (int) $patient['id'] ?>" tabindex="0" role="tab" aria-selected="<?= $patient['id'] === $personIdActif ? 'true' : 'false' ?>"><?= htmlspecialchars($patient['nom']) ?></div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($patients as $patient): ?>
    <?php
      $personne = $patient['nom'];
      $personId = (int) $patient['id'];
      $enEditionIci = $peutModifier && $pathologieEnEdition !== null && (int) $pathologieEnEdition['person_id'] === $personId;
      $listePersonne = $pathologiesParPersonne[$personId];
    ?>
    <!-- Pas de carte englobante ni de titre repetant le nom de la personne :
         l'onglet actif l'indique deja, et l'emboitement de cartes (padding +
         marges a chaque niveau) laissait beaucoup de blanc perdu. -->
    <div class="section-personne-pathologies <?= $personId === $personIdActif ? 'active' : '' ?>" id="section-<?= $personId ?>" data-personne="<?= $personId ?>">
      <?php if (empty($listePersonne)): ?>
        <div class="outil">
          <p class="vide">Aucune pathologie enregistrée pour <?= htmlspecialchars($personne) ?>.</p>
        </div>
      <?php else: ?>
        <div class="outil">
          <div class="grille-medecins">
            <?php foreach ($listePersonne as $path): ?>
              <div class="rangee-medecin">
                <div class="detail-medecin">
                  <div class="nom-medecin"><?= htmlspecialchars($path['nom']) ?></div>
                  <?php if ($path['cause'] !== ''): ?>
                    <div class="specialite-medecin"><strong>Cause :</strong> <?= nl2br(htmlspecialchars($path['cause'])) ?></div>
                  <?php endif; ?>
                  <?php if ($path['traitement'] !== ''): ?>
                    <div class="coord-medecin"><strong>Suivi :</strong> <?= nl2br(htmlspecialchars($path['traitement'])) ?></div>
                  <?php endif; ?>
                  <?php $rdvsLies = listerRendezVousPathologie($db, $path['id']); ?>
                  <?php if (!empty($rdvsLies)): ?>
                    <div class="rdv-lies-pathologie">
                      <div class="etiquette-rdv-lies">Rendez-vous à venir</div>
                      <ul>
                        <?php foreach ($rdvsLies as $rdvLie): ?>
                          <li><?= htmlspecialchars(libelleRendezVousPathologie($rdvLie)) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
                <?php if ($peutModifier): ?>
                <div class="actions-medecin">
                  <a href="?modifier=<?= (int) $path['id'] ?>" class="lien-modifier-tache">Modifier</a>
                  <form method="post" data-confirm="Supprimer cette pathologie ?">
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id" value="<?= (int) $path['id'] ?>">
                    <input type="hidden" name="person_id" value="<?= $personId ?>">
                    <button type="submit" class="lien-danger">Supprimer</button>
                  </form>
                </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <script>
  // Bascule d'une personne a l'autre sans recharger la page : les deux
  // sections existent deja dans le HTML (voir .section-personne-pathologies
  // ci-dessus), on se contente de montrer/cacher - pas de code qui depend
  // du nombre de personnes, contrairement a l'ancien affichage empile.
  (function () {
    var onglets = document.querySelectorAll('#tabsPersonnesPathologies .tab');
    var sections = document.querySelectorAll('.section-personne-pathologies');
    // "Imprimer" et "Ajouter" vivent en haut de page, hors des sections :
    // ils doivent donc suivre l'onglet actif, sinon on imprimerait la
    // fiche de quelqu'un d'autre que celui qu'on regarde.
    var lienFiche = document.getElementById('lienFichePathologies');
    var champPersonne = document.getElementById('champPersonnePathologie');
    function activerPersonne(id) {
      onglets.forEach(function (o) {
        var actif = o.dataset.personne === id;
        o.classList.toggle('active', actif);
        o.setAttribute('aria-selected', actif ? 'true' : 'false');
      });
      sections.forEach(function (s) {
        s.classList.toggle('active', s.dataset.personne === id);
      });
      if (lienFiche) {
        lienFiche.href = '/pathologies_plan.php?person=' + encodeURIComponent(id);
      }
      // Le formulaire d'ajout s'ouvre pre-rempli sur la personne affichee
      // (modifiable dans la modale). Absent en modification : la personne
      // y est figee, transmise en champ cache.
      if (champPersonne) { champPersonne.value = id; }
    }
    onglets.forEach(function (o) {
      o.addEventListener('click', function () { activerPersonne(o.dataset.personne); });
      o.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activerPersonne(o.dataset.personne); }
      });
    });
  })();
  </script>

  <?php /* UNE SEULE modale, avec un choix de personne, plutot qu'une par
           patient : le formulaire est identique pour tout le monde, seul
           le destinataire change. Elle reste posee HORS des sections par
           personne - une section inactive est en display:none, et une
           modale position:fixed a l'interieur ne s'afficherait jamais.
           En modification la personne n'est pas modifiable
           (modifierPathologie() ne la touche pas) : elle est rappelee
           dans le titre et transmise en champ cache, plutot qu'offerte
           dans une liste qui n'aurait aucun effet. */ ?>
  <?php if ($peutModifier): ?>
    <?php $personIdModale = $pathologieEnEdition !== null ? (int) $pathologieEnEdition['person_id'] : $personIdActif; ?>
    <?php /* modal-large (760px au lieu de 460px) : "Cause" et "Suivi" sont
             cote a cote et ce sont des textes libres de plusieurs lignes -
             a la largeur par defaut, chaque zone ne faisait qu'une petite
             colonne de ~210px. Meme largeur que la modale des medicaments. */ ?>
    <div class="modal modal-large" id="modalPathologie"<?= ($pathologieEnEdition !== null || $erreur !== '') ? ' data-ouvrir-au-chargement' : '' ?>>
      <div class="modal-corps">
        <h2>
          <?php if ($pathologieEnEdition !== null): ?>
            Modifier la pathologie de <?= htmlspecialchars(nomPerson($db, $personIdModale)) ?>
          <?php else: ?>
            Ajouter une pathologie
          <?php endif; ?>
        </h2>

        <?php if ($erreur !== ''): ?>
          <p class="erreur"><?= htmlspecialchars($erreur) ?></p>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="action" value="<?= $pathologieEnEdition !== null ? 'modifier' : 'ajouter' ?>">
          <?php if ($pathologieEnEdition !== null): ?>
            <input type="hidden" name="id" value="<?= (int) $idEnEdition ?>">
            <input type="hidden" name="person_id" value="<?= $personIdModale ?>">
          <?php else: ?>
            <div class="champ">
              <label for="champPersonnePathologie">Personne</label>
              <select name="person_id" id="champPersonnePathologie" required>
                <?php foreach ($patients as $patient): ?>
                  <option value="<?= (int) $patient['id'] ?>"<?= (int) $patient['id'] === $personIdModale ? ' selected' : '' ?>><?= htmlspecialchars($patient['nom']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="champ">
            <label>Pathologie</label>
            <input type="text" name="nom" placeholder="Ex. Dos, Bras..." required value="<?= isset($_POST['nom']) ? htmlspecialchars($_POST['nom']) : '' ?>">
          </div>
          <div class="champ-ligne">
            <div class="champ">
              <label>Cause / raison (facultatif)</label>
              <textarea name="cause" rows="5" placeholder="Ex. Tassement des vertèbres, selon le scanner fait à St Luc"><?= isset($_POST['cause']) ? htmlspecialchars($_POST['cause']) : '' ?></textarea>
            </div>
            <div class="champ">
              <label>Ce qui est fait pour soigner (facultatif)</label>
              <textarea name="traitement" rows="5" placeholder="Ex. Kiné 2x/semaine, revu par Dr Dupont en octobre, Dafalgan si besoin"><?= isset($_POST['traitement']) ? htmlspecialchars($_POST['traitement']) : '' ?></textarea>
            </div>
          </div>
          <?php /* .modal-corps se referme ICI, avant les boutons : la
                   modale est une colonne flex dont le corps defile et dont
                   le pied reste visible. Meme decoupe que la modale de
                   Medecins et celle des rendez-vous. */ ?>
          </div>
          <div class="form-boutons">
            <button class="principal" type="submit"><?= $pathologieEnEdition !== null ? 'Enregistrer les modifications' : 'Ajouter' ?></button>
            <?php if ($pathologieEnEdition !== null): ?>
              <?php /* Un lien : il faut aussi retirer "?modifier=" de l'URL,
                       sinon la modale se rouvrirait au rechargement. */ ?>
              <a class="secondaire" href="/pathologies.php?p=<?= $personIdModale ?>">Annuler</a>
            <?php else: ?>
              <button type="button" class="secondaire" data-ferme-modal>Fermer</button>
            <?php endif; ?>
          </div>
        </form>
    </div>
  <?php endif; ?>

  <script src="/assets/admin-ui.js?v=<?= filemtime(__DIR__ . '/assets/admin-ui.js') ?>"></script>
  <script src="/assets/entete.js?v=<?= filemtime(__DIR__ . '/assets/entete.js') ?>"></script>
</body>
</html>
