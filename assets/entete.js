// ---------------------------------------------------------------
// Comportements de la barre de navigation partagée (voir lib/entete.php) :
// ouverture/fermeture du menu du compte, et confirmation avant de se
// déconnecter.
//
// Volontairement autonome : ce fichier est chargé par toutes les pages
// familiales (agenda, tâches, médecins, médicaments, pathologies), alors
// que app.js ne l'est que par l'agenda - il y manipule des éléments
// (#liste, le formulaire de rendez-vous...) qui n'existent pas ailleurs.
// Il n'utilise donc rien d'autre que le DOM de l'en-tête.
// ---------------------------------------------------------------

(function () {
  var conteneur = document.getElementById('menuCompte');
  var bouton = document.getElementById('btnMenuCompte');
  if (!conteneur || !bouton) return;

  function fermer() {
    conteneur.classList.remove('ouvert');
    bouton.setAttribute('aria-expanded', 'false');
  }

  bouton.addEventListener('click', function (e) {
    e.stopPropagation();
    var etaitOuvert = conteneur.classList.contains('ouvert');
    fermer();
    if (!etaitOuvert) {
      conteneur.classList.add('ouvert');
      bouton.setAttribute('aria-expanded', 'true');
    }
  });

  document.addEventListener('click', function (e) {
    if (conteneur.classList.contains('ouvert') && !conteneur.contains(e.target)) fermer();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') fermer();
  });

  // Confirmation avant de se déconnecter : le lien voisine avec ceux des
  // autres pages dans le même menu, et un clic de trop renvoie à l'écran
  // de mot de passe - que Michel et Christiane n'ont pas.
  // confirm() natif ici plutôt que la boîte de dialogue maison : celle-ci
  // vit dans app.js et n'existe donc pas sur les autres pages. L'agenda,
  // lui, remplace ce comportement par sa version stylée (voir app.js).
  // Hauteur reelle de la barre de navigation, publiee en variable CSS :
  // la barre du titre (.topbar sur l'agenda) s'y colle juste en dessous.
  // Mesuree plutot que codee en dur, parce que la barre passe sur deux
  // lignes sur les largeurs intermediaires.
  //
  // On mesure la place VERTICALE TOTALE prise dans le flux, marge du bas
  // comprise. C'est ce que la barre du titre doit retrouver une fois
  // collee : tout ecart se voit comme un saut de quelques pixels au
  // premier defilement (trop petit -> saut vers le haut) ou comme un
  // interstice ou le contenu defile (trop grand). .barre-nav n'a
  // aujourd'hui aucune marge du bas, mais on la compte quand meme pour
  // que la mesure reste juste si le CSS change.
  // getBoundingClientRect() plutot que offsetHeight : ce dernier arrondit
  // a l'entier, ce qui laissait un residu de 1px du meme genre.
  var barre = document.querySelector('.barre-nav');
  if (barre) {
    var mesurer = function () {
      var marge = parseFloat(getComputedStyle(barre).marginBottom) || 0;
      var place = barre.getBoundingClientRect().height + marge;
      document.documentElement.style.setProperty('--h-nav', place + 'px');
    };
    mesurer();
    window.addEventListener('resize', mesurer);
  }

  var lienDeconnexion = document.getElementById('lienDeconnexion');
  if (lienDeconnexion && !document.getElementById('dialogueModal')) {
    lienDeconnexion.addEventListener('click', function (e) {
      fermer();
      if (!confirm("Se déconnecter ? Il faudra retaper le mot de passe pour revenir sur l'agenda.")) {
        e.preventDefault();
      }
    });
  }
})();
