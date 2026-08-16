<?php
/**
 * En-tête partagé par toutes les pages d'administration (admin/*.php) et
 * par les outils de outils/.
 *
 * Même intention que lib/entete.php pour les pages familiales : un seul
 * endroit qui décide à quoi ressemble le haut d'une page, plutôt que
 * onze pages qui le réécrivent chacune un peu différemment.
 *
 * Trois modèles cohabitaient avant :
 *   - une barre SANS titre suivie d'un fil d'Ariane, le vrai titre étant
 *     en fait le <h2> de la première carte (sept pages sur onze n'avaient
 *     donc aucun <h1>) ;
 *   - une barre AVEC titre, sans fil d'Ariane ;
 *   - un « entete-page » avec son propre <h1> à 20px, inventé pour la
 *     seule page des réglages.
 *
 * Un seul modèle désormais, celui du reste du site : un <h1>, un
 * sous-titre facultatif, et à droite les liens de sortie. Le fil d'Ariane
 * disparaît : sur une arborescence à un seul niveau, « Administration /
 * Sauvegardes » ne dit rien de plus que le titre « Sauvegardes » plus le
 * lien « Administration » de la barre.
 */

/**
 * @param string $titre     Titre de la page (le <h1>).
 * @param string $sousTitre Phrase d'explication sous le titre. Peut
 *                          contenir du HTML simple (<code>, <a>...) :
 *                          c'est du texte écrit par nous, pas une saisie.
 * @param bool   $estAccueil true sur l'accueil de l'administration, qui
 *                          n'a pas à proposer un lien vers lui-même.
 */
function afficherEnteteAdmin($titre, $sousTitre = '', $estAccueil = false) {
    ?>
  <div class="barre-admin">
    <h1><?= htmlspecialchars($titre) ?></h1>
    <div class="liens-admin">
      <?php if (!$estAccueil): ?>
        <a href="/admin/index.php">Administration</a>
        <span class="sep">·</span>
      <?php endif; ?>
      <a href="/index.php">Retour à l'agenda</a>
      <?php /* Certains outils (outils/migrate.php, import_calendar.php) ne
               demandent que le mot de passe familial : leur proposer une
               « déconnexion admin » n'aurait aucun sens. */ ?>
    </div>
  </div>
  <?php if ($sousTitre !== ''): ?>
    <p class="sous-titre sous-titre-admin"><?= $sousTitre ?></p>
  <?php endif; ?>
    <?php
}
