<?php
/**
 * En-tête de navigation partagé par toutes les pages familiales (agenda,
 * tâches, médecins, médicaments, pathologies).
 *
 * Avant, chaque page réécrivait son propre en-tête : l'accueil avait sa
 * barre avec les boutons, les autres une barre grise "Retour à l'agenda".
 * Aller de Médicaments à Pathologies obligeait donc à repasser par
 * l'agenda puis à rouvrir un menu, alors que les deux pages sont voisines.
 *
 * Principe retenu : deux étages. Ici, la NAVIGATION (où puis-je aller),
 * identique partout. Dans chaque page ensuite, son titre et ses ACTIONS
 * (Ajouter, Imprimer...) - qui ne sont pas les mêmes d'une page à l'autre
 * et n'ont donc rien à faire dans une barre de navigation.
 *
 * Sur téléphone, les destinations restent dans le menu déroulant comme
 * avant : la place manque pour cinq boutons (voir .nav-destinations et
 * .doublon-nav dans style.css, qui se répondent au même seuil).
 */

/**
 * @param string $pageActive Clé de la page courante : 'agenda', 'taches',
 *        'medecins', 'medicaments' ou 'pathologies'. Sert à marquer la
 *        destination correspondante comme étant la page affichée.
 */
function afficherEnteteNavigation($pageActive = '') {
    $destinations = [
        'agenda' => [
            'url' => '/index.php',
            'libelle' => 'Agenda',
            'icone' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        ],
        'taches' => [
            'url' => '/taches.php',
            'libelle' => 'Tâches',
            'icone' => '<rect x="3" y="5" width="6" height="6" rx="1"/><path d="M3 15h6v6H3z"/><path d="M13 5h8M13 9h8M13 15h8M13 19h8"/>',
        ],
        'medecins' => [
            'url' => '/medecins.php',
            'libelle' => 'Médecins',
            'icone' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        ],
        'medicaments' => [
            'url' => '/medicaments.php',
            'libelle' => 'Médicaments',
            'icone' => '<rect x="3" y="11" width="18" height="7" rx="3.5"/><path d="M8 11v7"/><circle cx="17" cy="6" r="3"/>',
        ],
        'pathologies' => [
            'url' => '/pathologies.php',
            'libelle' => 'Pathologies',
            'icone' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        ],
    ];

    $svg = function ($chemins) {
        return '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round">' . $chemins . '</svg>';
    };
    ?>
  <div class="barre-nav">
    <?php /* Logo plutot que le texte "Agenda médical" : suivi de l'onglet
             "Agenda" puis du titre "Rendez-vous", le mot revenait trois
             fois de suite. C'est la meme icone que le favicon, donc deja
             associee au site dans l'onglet du navigateur. Le nom reste
             lisible par les lecteurs d'ecran et au survol. */ ?>
    <a class="logo-site" href="/index.php" title="Agenda médical — accueil">
      <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="8" fill="#1f9254"/>
        <path d="M16 8v16M8 16h16" stroke="#ffffff" stroke-width="4.5" stroke-linecap="round"/>
      </svg>
      <span class="lecture-ecran">Agenda médical — accueil</span>
    </a>

    <nav class="nav-destinations" aria-label="Navigation principale">
      <?php foreach ($destinations as $cle => $d): ?>
        <?php if ($cle === $pageActive): ?>
          <?php /* Page affichee : marquee comme selectionnee (fond fonce,
                   gras, soulignee) plutot que grisee - un element grise
                   passe pour casse. Le vert reste reserve aux actions
                   principales, il ne doit pas vouloir dire deux choses.
                   <span> et non <a> : inutile de proposer un lien vers la
                   page ou l'on est deja. */ ?>
          <span class="nav-lien actif" aria-current="page"><?= $svg($d['icone']) ?><?= htmlspecialchars($d['libelle']) ?></span>
        <?php else: ?>
          <a class="nav-lien" href="<?= $d['url'] ?>"><?= $svg($d['icone']) ?><?= htmlspecialchars($d['libelle']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="menu-suspendu" id="menuCompte">
      <button class="bouton-compact bouton-menu-suspendu" id="btnMenuCompte" type="button" aria-haspopup="true" aria-expanded="false">
        <?= $svg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>') ?>
        <?= htmlspecialchars(personneSessionActuelle()) ?>
        <svg class="icone icone-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
      </button>
      <div class="menu-deroulant" id="menuCompteListe">
        <?php /* Les memes destinations que la barre, mais visibles
                 uniquement sur telephone (voir .doublon-nav dans le CSS) :
                 la barre ne peut pas y afficher cinq boutons. Il y a donc
                 toujours exactement un chemin vers chaque page. */ ?>
        <?php foreach ($destinations as $cle => $d): ?>
          <?php if ($cle !== $pageActive): ?>
            <a class="doublon-nav" href="<?= $d['url'] ?>"><?= $svg($d['icone']) ?><?= htmlspecialchars($d['libelle']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php /* Le drapeau est_admin, pas le prenom : voir
                 personneConnecteeEstAdmin() dans lib/auth.php. */ ?>
        <?php if (personneConnecteeEstAdmin()): ?>
          <a href="/admin/index.php"><?= $svg('<path d="M12 20a8 8 0 1 0 0-16 8 8 0 0 0 0 16z"/><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>') ?>Administration</a>
        <?php endif; ?>
        <a href="/logout.php" id="lienDeconnexion" class="lien-deconnexion"><?= $svg('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>') ?>Déconnexion</a>
      </div>
    </div>
  </div>
    <?php
}
