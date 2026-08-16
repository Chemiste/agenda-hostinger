// ---------------------------------------------------------------
// Comportements génériques partagés par toutes les pages d'administration :
//  - un formulaire avec l'attribut data-confirm="..." demande confirmation
//    avant d'être envoyé (pour les actions groupées difficiles à annuler :
//    corriger des rendez-vous, restaurer une sauvegarde, importer des
//    données de dev...).
//  - au moment de l'envoi, les boutons du formulaire soumis passent en
//    "Un instant…" et se désactivent, pour éviter un double-clic et donner
//    un retour visuel pendant que le serveur traite la demande (recherche
//    sur tous les rendez-vous, synchro Google Calendar ligne par ligne...).
// N'importe quelle page admin peut charger ce script sans configuration :
// il ne fait rien s'il ne trouve pas de formulaire concerné.
// ---------------------------------------------------------------

// ---------------------------------------------------------------
// Formulaires en modale — même présentation que la modale de rendez-vous
// de l'accueil, mais SANS AJAX : le formulaire reste un POST classique
// avec son Post/Redirect/Get. La modale n'est qu'un habillage, ce qui
// évite de réécrire quatre pages en API pour un gain purement visuel.
//
// Pourquoi : sur Médecins, Tâches ou Pathologies, on consulte bien plus
// souvent qu'on n'ajoute. Le formulaire occupait pourtant le premier
// écran, repoussant la liste — ce qu'on vient réellement voir.
//
// Mise en place dans une page : un bouton `data-ouvre-modal="idDeLaModale"`
// et un conteneur `<div class="modal" id="idDeLaModale">`. Rien d'autre.
// La modale s'ouvre toute seule si elle porte `data-ouvrir-au-chargement`
// (mode édition via ?modifier=..., ou réaffichage après une erreur de
// validation) : sans ça, un message d'erreur resterait invisible.
// ---------------------------------------------------------------
(function () {
  var ouverte = null;

  function overlay() {
    var el = document.getElementById('overlay');
    if (!el) {
      // Cree a la volee : toutes les pages n'en ont pas deja un.
      el = document.createElement('div');
      el.className = 'overlay';
      el.id = 'overlay';
      document.body.appendChild(el);
    }
    return el;
  }

  function ouvrir(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('ouvert');
    var corps = modal.querySelector('.modal-corps');
    if (corps) corps.scrollTop = 0;
    overlay().classList.add('visible');
    document.body.style.overflow = 'hidden';
    ouverte = id;
    var premier = modal.querySelector('input:not([type=hidden]), select, textarea');
    if (premier) premier.focus();
  }

  function fermer() {
    if (!ouverte) return;
    var modal = document.getElementById(ouverte);
    if (modal) modal.classList.remove('ouvert');
    overlay().classList.remove('visible');
    document.body.style.overflow = '';
    ouverte = null;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var modales = document.querySelectorAll('.modal[id]');
    if (!modales.length) return;

    document.querySelectorAll('[data-ouvre-modal]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        ouvrir(btn.dataset.ouvreModal);
      });
    });

    document.querySelectorAll('[data-ferme-modal]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        fermer();
      });
    });

    overlay().addEventListener('click', fermer);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') fermer();
    });

    modales.forEach(function (m) {
      if (m.hasAttribute('data-ouvrir-au-chargement')) ouvrir(m.id);
    });
  });
})();

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!confirm(form.dataset.confirm)) {
        e.preventDefault();
      }
    });
  });

  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;

      // AVANT de desactiver quoi que ce soit : un bouton desactive
      // n'envoie plus son couple nom/valeur. Or plusieurs formulaires du
      // site font porter l'action au bouton lui-meme
      // (name="action" value="tester"). Les desactiver ici faisait donc
      // disparaitre le parametre : le serveur recevait un POST sans
      // action, n'executait aucune branche, et reaffichait la page a
      // l'identique. Ni succes, ni erreur - "rien ne se passe".
      //
      // C'est ce qui empechait l'email de test de partir, et aussi
      // l'enregistrement des reglages et la suppression d'une photo.
      // On recopie donc la valeur du bouton clique dans un champ cache,
      // qui survivra a la desactivation.
      var soumetteur = e.submitter;
      if (soumetteur && soumetteur.name) {
        var champCache = document.createElement('input');
        champCache.type = 'hidden';
        champCache.name = soumetteur.name;
        champCache.value = soumetteur.value;
        form.appendChild(champCache);
      }

      form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
        b.disabled = true;
        b.dataset.texteOriginal = b.textContent;
        b.textContent = 'Un instant…';
        // Filet de sécurité : si la page ne se recharge pas (ex: un
        // téléchargement de fichier, qui ne navigue pas ailleurs), on
        // réactive le bouton après quelques secondes plutôt que de le
        // laisser bloqué indéfiniment.
        setTimeout(function () {
          b.disabled = false;
          b.textContent = b.dataset.texteOriginal;
        }, 8000);
      });
    });
  });
});
