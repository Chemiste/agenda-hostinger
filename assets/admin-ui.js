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
