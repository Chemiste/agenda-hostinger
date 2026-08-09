var tousLesRdv = [];
var filtreActuel = 'Tous';
var filtreTemps = 'avenir';
var idEnEdition = null;

var MOIS_ABREGES = ['Jan', 'Fév', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

// Petites icones SVG inline (pas de fichiers a charger, pas de police
// d'icones externe) pour reperer plus vite les infos d'une carte au lieu
// de tout deviner par du texte brut. currentColor pour heriter la
// couleur du texte parent automatiquement.
var ICONES = {
  lieu: '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.5-7-11a7 7 0 0 1 14 0c0 4.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>',
  telephone: '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6.6 10.5c1.4 2.8 3.7 5.1 6.5 6.5l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.5.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.9 21 3 13.1 3 3.7c0-.6.4-1 1-1h3.8c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.5.1.4 0 .8-.2 1L6.6 10.5z"/></svg>',
  route: '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4v6a4 4 0 0 0 4 4h10"/><path d="M15 10l4 4-4 4"/></svg>',
  note: '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4h9l4 4v12H6z"/><path d="M14 4v5h5"/><path d="M9 13h6M9 17h4"/></svg>',
  medecin: '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>',
  accompagnant: '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  crayon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>'
};

// new Date().toISOString() convertit en UTC : pres de minuit, dans un
// fuseau horaire en avance sur UTC (Belgique par ex.), ca peut renvoyer
// la date de la veille. On calcule plutot la date du jour a partir des
// composants locaux, pour que "aujourd'hui" corresponde a ce que la
// personne voit sur son horloge.
function dateLocaleISO(d) {
  var pad = function (n) { return String(n).padStart(2, '0'); };
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}
function formatDateCompacte(dateStr) {
  var p = dateStr.split('-');
  return { jour: p[2], mois: MOIS_ABREGES[parseInt(p[1], 10) - 1], annee: p[0] };
}

// ---------------------------------------------------------------
// Ouverture / fermeture du modal du formulaire de rendez-vous
// (l'import .ics vit desormais dans assets/admin.js, page admin)
// ---------------------------------------------------------------

// Pile plutot qu'un simple id actif : la modale de confirmation
// (dialogueModal, voir plus bas) peut s'ouvrir PAR-DESSUS le formulaire
// (ex. "Supprimer ce rendez-vous ?" depuis le formulaire d'edition) sans
// fermer ce dernier - il faut donc savoir qu'il reste un modal ouvert en
// dessous (fond assombri / defilement bloque tant que la pile n'est pas
// vide) et fermer seulement le plus recent quand on clique l'overlay.
var pileModals = [];

function ouvrirModal(id) {
  pileModals.push(id);
  var modal = document.getElementById(id);
  modal.classList.add('ouvert');
  // Remet le contenu en haut a chaque ouverture : sans ca, un modal deja
  // scrolle (ex. formulaire d'edition qu'on avait fait defiler) rouvre a
  // la meme position, meme pour un usage totalement different (ex.
  // "Annuler" une edition puis "Ajouter" un nouveau rendez-vous).
  var corps = modal.querySelector('.modal-corps');
  if (corps) corps.scrollTop = 0;
  document.getElementById('overlay').classList.add('visible');
  document.body.style.overflow = 'hidden';
}

function fermerModal(id) {
  document.getElementById(id).classList.remove('ouvert');
  pileModals = pileModals.filter(function (m) { return m !== id; });
  if (pileModals.length === 0) {
    document.getElementById('overlay').classList.remove('visible');
    document.body.style.overflow = '';
  }
}

function fermerModalActif() {
  var actif = pileModals[pileModals.length - 1];
  if (!actif) return;
  if (actif === 'formCard') viderFormulaire();
  if (actif === 'dialogueModal' && dialogueResolveEnAttente) dialogueResolveEnAttente();
  fermerModal(actif);
}

document.getElementById('overlay').addEventListener('click', fermerModalActif);

// ---------------------------------------------------------------
// Modale de confirmation/erreur maison, pour remplacer confirm()/alert()
// natifs du navigateur (esthetique incoherente avec le reste du site,
// et pas modifiable). Meme mecanique que le modal du formulaire.
// ---------------------------------------------------------------

var dialogueResolveEnAttente = null;

function ouvrirDialogue(message, boutons) {
  document.getElementById('dialogueMessage').textContent = message;
  var conteneur = document.getElementById('dialogueBoutons');
  conteneur.innerHTML = '';
  boutons.forEach(function (b) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = b.classe;
    btn.textContent = b.texte;
    btn.addEventListener('click', function () {
      dialogueResolveEnAttente = null;
      fermerModal('dialogueModal');
      b.action();
    });
    conteneur.appendChild(btn);
  });
  ouvrirModal('dialogueModal');
}

// Remplace confirm(message) : retourne une Promise<boolean> (true si
// confirme). Fermer via l'overlay ou Echap equivaut a "Annuler".
function confirmerPerso(message) {
  return new Promise(function (resolve) {
    dialogueResolveEnAttente = function () { resolve(false); };
    ouvrirDialogue(message, [
      { texte: 'Annuler', classe: 'secondaire', action: function () { resolve(false); } },
      { texte: 'Supprimer', classe: 'danger', action: function () { resolve(true); } }
    ]);
  });
}

// Remplace alert(message) : retourne une Promise qui se resout quand la
// personne a ferme le message.
function alerterPerso(message) {
  return new Promise(function (resolve) {
    dialogueResolveEnAttente = function () { resolve(); };
    ouvrirDialogue(message, [
      { texte: 'OK', classe: 'principal', action: function () { resolve(); } }
    ]);
  });
}

// Petit message discret en bas de l'ecran (ex: "Rendez-vous enregistré."),
// pour confirmer qu'une action a bien fonctionné sans bloquer l'écran
// comme le fait la modale de dialogue. Disparaît tout seul. "action"
// (optionnel) ajoute un bouton dans le toast (ex: "Annuler" apres une
// suppression) - reste affiché plus longtemps le temps de cliquer dessus.
var toastTimeoutId = null;
function afficherToast(message, action) {
  var toast = document.getElementById('toast');
  toast.innerHTML = '';

  var texte = document.createElement('span');
  texte.textContent = message;
  toast.appendChild(texte);

  if (action) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'toast-action';
    btn.textContent = action.label;
    btn.addEventListener('click', function () {
      if (toastTimeoutId) clearTimeout(toastTimeoutId);
      toast.classList.remove('visible');
      action.onClick();
    });
    toast.appendChild(btn);
  }

  toast.classList.add('visible');
  if (toastTimeoutId) clearTimeout(toastTimeoutId);
  toastTimeoutId = setTimeout(function () {
    toast.classList.remove('visible');
  }, action ? 6000 : 2500);
}

function appelApi(action, corps) {
  return fetch('/api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(corps || {})
  }).then(function (r) {
    return r.json().then(function (data) {
      if (!r.ok) throw new Error(data.error || 'Erreur serveur.');
      return data;
    });
  });
}

function charger() {
  fetch('/api.php?action=list')
    .then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok || !Array.isArray(data)) {
          throw new Error(data && data.error ? data.error : 'Réponse inattendue du serveur.');
        }
        return data;
      });
    })
    .then(function (rdvs) {
      tousLesRdv = rdvs;
      afficherListe();
      construireInfosParMedecin(rdvs);
      remplirListeMedecins();
    })
    .catch(function (err) {
      document.getElementById('liste').innerHTML =
        '<p class="erreur">Impossible de charger les rendez-vous : ' + err.message + '</p>';
    });
}

// Memorise, pour chaque medecin deja rencontre, le departement/adresse/
// telephone/route les plus recents - pour preremplir automatiquement ces
// champs quand on retape un medecin deja connu (surtout utile pour les
// rendez-vous crees a la main de facon reguliere, ex. kine : les
// rendez-vous importes depuis un .ics ont deja leurs infos completes des
// l'import, ce mecanisme ne changera donc rien pour eux).
var infosParMedecin = {};
function construireInfosParMedecin(rdvs) {
  infosParMedecin = {};
  // Trie par date croissante : la derniere iteration (la plus recente)
  // ecrase les precedentes, on garde donc les coordonnees les plus a jour.
  var tries = rdvs.slice().sort(function (a, b) {
    var ca = a.date + ' ' + a.time, cb = b.date + ' ' + b.time;
    return ca < cb ? -1 : ca > cb ? 1 : 0;
  });
  tries.forEach(function (r) {
    var nom = (r.doctor || '').trim();
    if (!nom) return;
    infosParMedecin[nom.toLowerCase()] = {
      nomExact: nom,
      department: r.department || '',
      location: r.location || '',
      phone: r.phone || '',
      route: r.route || ''
    };
  });
}

function remplirListeMedecins() {
  var noms = Object.keys(infosParMedecin)
    .map(function (cle) { return infosParMedecin[cle].nomExact; })
    .sort(function (a, b) { return a.localeCompare(b, 'fr'); });
  document.getElementById('listeMedecins').innerHTML = noms.map(function (n) {
    return '<option value="' + escapeHtml(n) + '"></option>';
  }).join('');
}

// Regroupement par mois (ex. "Août 2026") au lieu d'un titre par jour :
// avec beaucoup de rendez-vous, un titre par mois donne une vue plus
// synthetique. La date precise de chaque rendez-vous est alors affichee
// directement sur sa carte (voir dateCourte), puisqu'elle n'est plus
// portee par le titre du groupe.
function moisAnnee(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  var texte = d.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
  return texte.charAt(0).toUpperCase() + texte.slice(1);
}

function dateCourte(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' });
}

function classeBadge(personne) {
  if (personne === window.PERSONNE_1) return 'papa';
  if (personne === window.PERSONNE_2) return 'maman';
  return 'deux';
}

// Reutilise pour re-afficher la liste quand on bascule mobile/desktop
// (voir plus bas), la grille de cartes s'adapte deja toute seule en CSS
// mais certains recalculs cote JS en dependent aussi.
var MOBILE_MQ = window.matchMedia('(max-width: 640px)');

function escapeHtml(s) {
  var div = document.createElement('div');
  div.textContent = s || '';
  return div.innerHTML;
}

function echapperRegex(s) {
  return (s || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// Construit le titre affiché à l'écran et à l'impression : le département
// (s'il existe) sur sa propre ligne, puis le médecin/consultation sur la
// ligne suivante — certains départements ont un nom long, les mettre sur
// une seule ligne avec " - " les faisait déborder ou passer à la ligne
// n'importe où. On retire au passage un éventuel résidu "pour <Prénom>"
// laissé par d'anciens imports .ics dans le champ médecin/consultation :
// la personne concernée est de toute façon déjà indiquée par ailleurs
// (badge/bandeau coloré), pas la peine de le répéter dans le titre.
// Purement un effet d'affichage : ni le champ "doctor" en base ni la
// synchro Google Calendar ne sont modifiés.
function titreAffichage(r) {
  var doc = r.doctor || 'Rendez-vous';
  var noms = [window.PERSONNE_1, window.PERSONNE_2].filter(Boolean).map(echapperRegex).join('|');
  if (noms) {
    var re = new RegExp('\\s*[-,]?\\s*\\bpour\\s+(' + noms + ')\\b\\.?', 'gi');
    doc = doc.replace(re, '').replace(/\s{2,}/g, ' ').replace(/^[\s,-]+|[\s,-]+$/g, '').trim();
  }
  if (!doc) doc = 'Rendez-vous';
  return { departement: r.department || '', medecin: doc };
}

function afficherListe() {
  var aujourdhui = dateLocaleISO(new Date());
  var filtres = tousLesRdv.filter(function (r) {
    var okPersonne = filtreActuel === 'Tous' || r.person === filtreActuel;
    var okTemps = filtreTemps === 'tous' ||
      (filtreTemps === 'avenir' ? r.date >= aujourdhui : r.date < aujourdhui);
    return okPersonne && okTemps;
  }).sort(function (a, b) {
    var ca = a.date + ' ' + a.time, cb = b.date + ' ' + b.time;
    // "Passés" : le plus recent d'abord (plus utile pour retrouver un
    // rendez-vous recent que de commencer par le plus ancien). Les deux
    // autres vues restent chronologiques (le plus proche en premier).
    if (filtreTemps === 'passes') return ca < cb ? 1 : ca > cb ? -1 : 0;
    return ca < cb ? -1 : ca > cb ? 1 : 0;
  });

  var labelTemps = filtreTemps === 'avenir' ? 'À venir' : (filtreTemps === 'passes' ? 'Passés' : 'Tout l\'historique');
  document.getElementById('filtreImpression').textContent = filtreActuel + ' — ' + labelTemps;

  if (filtres.length === 0) {
    var messageVide = filtreTemps === 'passes' ? 'Aucun rendez-vous passé.' :
      (filtreTemps === 'avenir' ? 'Aucun rendez-vous à venir.' : 'Aucun rendez-vous.');
    var iconeVide = '<svg class="vide-icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>';
    document.getElementById('liste').innerHTML = '<div class="vide">' + iconeVide + '<p>' + messageVide + '</p></div>';
    document.getElementById('listeCompacte').innerHTML = '<p class="vide">' + messageVide + '</p>';
    return;
  }

  // Chaque mois est regroupé dans un conteneur ".jour-groupe" (titre du
  // mois + ses rendez-vous) pour qu'à l'impression le navigateur garde le
  // titre collé à ses rendez-vous : un "avoid" sur le conteneur entier est
  // bien mieux respecté par les navigateurs qu'un "avoid" posé seulement
  // sur le titre (qui laissait parfois le titre seul en bas d'une page).
  var demain = dateLocaleISO(new Date(Date.now() + 24 * 60 * 60 * 1000));

  var html = '';
  var dernierMois = null;
  filtres.forEach(function (r) {
    var moisCourant = r.date.slice(0, 7);
    if (moisCourant !== dernierMois) {
      if (dernierMois !== null) html += '</div>';
      html += '<div class="jour-groupe"><p class="jour-titre">' + moisAnnee(r.date) + '</p>';
      dernierMois = moisCourant;
    }
    var t = titreAffichage(r);
    var cls = classeBadge(r.person);
    // Repere visuel perdu depuis le passage au regroupement par mois (on
    // ne voit plus tout de suite "c'est aujourd'hui" comme avec l'ancien
    // regroupement par jour) : on le remet sous forme d'etiquette sur la
    // carte concernee.
    var badgeJour = '';
    if (r.date === aujourdhui) badgeJour = '<span class="badge-jour badge-aujourdhui">Aujourd\'hui</span>';
    else if (r.date === demain) badgeJour = '<span class="badge-jour badge-demain">Demain</span>';
    html += '<div class="rdv" data-id="' + r.id + '" tabindex="0" role="button" aria-label="Modifier ce rendez-vous">' +
      '<div class="rdv-entete rdv-' + cls + '">' +
        '<span class="rdv-entete-nom">' + escapeHtml(r.person) + '</span>' +
        '<span class="rdv-entete-heure">' + dateCourte(r.date) + ' · ' + r.time + '</span>' +
      '</div>' +
      badgeJour +
      '<div class="rdv-corps">' +
        (t.departement ? '<div class="departement">' + escapeHtml(t.departement) + '</div>' : '') +
        '<div class="medecin">' + ICONES.medecin + escapeHtml(t.medecin) + '</div>' +
        (r.location ? '<div class="contact">' + ICONES.lieu + escapeHtml(r.location_affichage || r.location) + '</div>' : '') +
        (r.phone ? '<div class="contact">' + ICONES.telephone + escapeHtml(r.phone) + '</div>' : '') +
        (r.route ? '<div class="route">' + ICONES.route + escapeHtml(r.route) + '</div>' : '') +
        (r.accompagnant ? '<div class="accompagnant">' + ICONES.accompagnant + escapeHtml(r.accompagnant) + '</div>' : '') +
        (r.notes ? '<div class="notes">' + ICONES.note + escapeHtml(r.notes) + '</div>' : '') +
      '</div>' +
      '<span class="rdv-modifier">' + ICONES.crayon + '</span>' +
    '</div>';
  });
  if (dernierMois !== null) html += '</div>';
  document.getElementById('liste').innerHTML = html;
  genererGrilleCompacte(filtres);

  // La suppression se fait desormais depuis le formulaire d'edition (bouton
  // "Supprimer ce rendez-vous" en bas du formulaire), pas directement dans
  // la liste : c'est une action rare, pas la peine de l'exposer partout.
  document.querySelectorAll('.rdv').forEach(function (el) {
    el.addEventListener('click', function () {
      ouvrirEnEdition(el.dataset.id);
    });
    // tabindex="0" + role="button" sur la carte (voir plus haut) : Entree
    // et Espace doivent l'activer comme un clic, pour rester utilisable
    // au clavier.
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        ouvrirEnEdition(el.dataset.id);
      }
    });
  });
}

function choisirTab(tab) {
  document.querySelectorAll('.tab').forEach(function (t) {
    t.classList.remove('active');
    t.setAttribute('aria-selected', 'false');
  });
  tab.classList.add('active');
  tab.setAttribute('aria-selected', 'true');
  filtreActuel = tab.dataset.filtre;
  afficherListe();
}

function choisirTabTemps(tab) {
  document.querySelectorAll('.tab-temps').forEach(function (t) {
    t.classList.remove('active');
    t.setAttribute('aria-selected', 'false');
  });
  tab.classList.add('active');
  tab.setAttribute('aria-selected', 'true');
  filtreTemps = tab.dataset.temps;
  afficherListe();
}

document.getElementById('tabs').addEventListener('click', function (e) {
  var tab = e.target.closest('.tab');
  if (tab) choisirTab(tab);
});
document.getElementById('tabs').addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  var tab = e.target.closest('.tab');
  if (!tab) return;
  e.preventDefault();
  choisirTab(tab);
});

document.getElementById('tabsTemps').addEventListener('click', function (e) {
  var tab = e.target.closest('.tab-temps');
  if (tab) choisirTabTemps(tab);
});
document.getElementById('tabsTemps').addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  var tab = e.target.closest('.tab-temps');
  if (!tab) return;
  e.preventDefault();
  choisirTabTemps(tab);
});

// Reaffiche la liste si on passe le seuil mobile/large (ex: rotation de
// l'ecran du telephone), pour que le badge affiche le bon format de nom.
MOBILE_MQ.addEventListener('change', function () {
  if (tousLesRdv.length > 0) afficherListe();
});

// ---------------------------------------------------------------
// Grille compacte (mode d'impression "compact") : mêmes données que la
// liste détaillée, mais rendues sous forme de cartes (date en évidence,
// titre, département, heure), sans regroupement par jour. Ce conteneur
// reste caché en toute circonstance sauf quand on imprime en mode
// compact (voir bouton "Imprimer (compact)" plus bas et la règle CSS
// "body.impression-compacte").
// ---------------------------------------------------------------

function genererGrilleCompacte(filtres) {
  var conteneur = document.getElementById('listeCompacte');
  if (filtres.length === 0) {
    conteneur.innerHTML = '<p class="vide">Aucun rendez-vous.</p>';
    return;
  }
  conteneur.innerHTML = filtres.map(function (r) {
    var d = formatDateCompacte(r.date);
    var cls = classeBadge(r.person);
    var t = titreAffichage(r);
    return '<div class="carte-compacte">' +
      '<div class="cc-entete cc-' + cls + '">' +
        '<span class="cc-entete-nom">' + escapeHtml(r.person) + '</span>' +
      '</div>' +
      '<div class="cc-corps">' +
        '<div class="cc-date cc-' + cls + '">' +
          '<span class="cc-jour">' + d.jour + '</span>' +
          '<span class="cc-mois">' + d.mois + '</span>' +
          '<span class="cc-annee">' + d.annee + '</span>' +
          '<span class="cc-heure">' + r.time + '</span>' +
        '</div>' +
        '<div class="cc-contenu">' +
          (t.departement ? '<div class="cc-sous">' + escapeHtml(t.departement) + '</div>' : '') +
          '<div class="cc-titre">' + escapeHtml(t.medecin) + '</div>' +
          (r.location ? '<div class="cc-adresse">' + escapeHtml(r.location_affichage || r.location) + '</div>' : '') +
          (r.route ? '<div class="cc-route">' + escapeHtml(r.route) + '</div>' : '') +
          (r.accompagnant ? '<div class="cc-accompagnant">Avec ' + escapeHtml(r.accompagnant) + '</div>' : '') +
        '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

function viderFormulaire() {
  document.getElementById('fDate').value = '';
  document.getElementById('fHeure').value = '';
  document.getElementById('fDuree').value = '30';
  document.getElementById('fMedecin').value = '';
  document.getElementById('fDepartement').value = '';
  document.getElementById('fAdresse').value = '';
  document.getElementById('fTelephone').value = '';
  document.getElementById('fRoute').value = '';
  document.getElementById('fAccompagnant').value = '';
  document.getElementById('fNotes').value = '';
  document.querySelectorAll('.personnes input').forEach(function (r) { r.checked = false; });
  document.querySelectorAll('.personnes label').forEach(function (l) { l.classList.remove('checked'); });
  document.getElementById('erreurForm').textContent = '';
  document.getElementById('btnSupprimer').style.display = 'none';
  idEnEdition = null;
}

function ouvrirEnEdition(id) {
  var r = tousLesRdv.find(function (x) { return String(x.id) === String(id); });
  if (!r) return;
  idEnEdition = r.id;
  document.getElementById('fDate').value = r.date;
  document.getElementById('fHeure').value = r.time;
  document.getElementById('fDuree').value = r.duration || 30;
  document.getElementById('fMedecin').value = r.doctor || '';
  document.getElementById('fDepartement').value = r.department || '';
  document.getElementById('fAdresse').value = r.location || '';
  document.getElementById('fTelephone').value = r.phone || '';
  document.getElementById('fRoute').value = r.route || '';
  document.getElementById('fAccompagnant').value = r.accompagnant || '';
  document.getElementById('fNotes').value = r.notes || '';
  selectionnerPersonne(r.person);
  document.getElementById('btnSupprimer').style.display = 'block';
  ouvrirModal('formCard');
}

function selectionnerPersonne(nom) {
  document.querySelectorAll('.personnes label').forEach(function (l) { l.classList.remove('checked'); });
  var input = document.querySelector('.personnes input[value="' + nom + '"]');
  if (input) {
    input.checked = true;
    input.nextElementSibling.classList.add('checked');
  }
}

document.querySelectorAll('.personnes input').forEach(function (input) {
  input.addEventListener('change', function () {
    document.querySelectorAll('.personnes label').forEach(function (l) { l.classList.remove('checked'); });
    input.nextElementSibling.classList.add('checked');
  });
});

function ouvrirFormulaireAjout() {
  viderFormulaire();
  var today = dateLocaleISO(new Date());
  document.getElementById('fDate').value = today;
  ouvrirModal('formCard');
}

document.getElementById('btnAjouter').addEventListener('click', ouvrirFormulaireAjout);
document.getElementById('btnAjouterMobile').addEventListener('click', ouvrirFormulaireAjout);

// Prerempli departement/adresse/telephone/route quand le medecin tape (ou
// choisi dans la liste suggeree) correspond exactement a un medecin deja
// vu - seulement les champs encore vides, pour ne jamais ecraser une
// valeur deja saisie ou importee.
document.getElementById('fMedecin').addEventListener('input', function () {
  var infos = infosParMedecin[this.value.trim().toLowerCase()];
  if (!infos) return;
  var fDepartement = document.getElementById('fDepartement');
  var fAdresse = document.getElementById('fAdresse');
  var fTelephone = document.getElementById('fTelephone');
  var fRoute = document.getElementById('fRoute');
  if (fDepartement.value === '') fDepartement.value = infos.department;
  if (fAdresse.value === '') fAdresse.value = infos.location;
  if (fTelephone.value === '') fTelephone.value = infos.phone;
  if (fRoute.value === '') fRoute.value = infos.route;
});

document.getElementById('btnAnnuler').addEventListener('click', function () {
  fermerModal('formCard');
  viderFormulaire();
});

document.getElementById('btnSupprimer').addEventListener('click', function () {
  if (!idEnEdition) return;
  confirmerPerso('Supprimer ce rendez-vous ?').then(function (confirme) {
    if (!confirme) return;
    var id = idEnEdition;
    // Capture des infos avant suppression, pour pouvoir les proposer au
    // "Annuler" du toast juste apres (recree un rendez-vous identique -
    // nouvel id et nouvel evenement Calendar, comme une restauration
    // depuis une sauvegarde : l'ancien evenement est deja supprime).
    var rdvSupprime = tousLesRdv.find(function (r) { return String(r.id) === String(id); });
    appelApi('delete', { id: id })
      .then(function () {
        fermerModal('formCard');
        viderFormulaire();
        charger();
        afficherToast('Rendez-vous supprimé.', rdvSupprime ? {
          label: 'Annuler',
          onClick: function () { restaurerRdv(rdvSupprime); }
        } : null);
      })
      .catch(function (err) {
        alerterPerso(err.message);
      });
  });
});

function restaurerRdv(r) {
  appelApi('add', {
    date: r.date,
    time: r.time,
    duration: r.duration,
    person: r.person,
    doctor: r.doctor,
    department: r.department,
    location: r.location,
    phone: r.phone,
    route: r.route,
    accompagnant: r.accompagnant,
    notes: r.notes
  })
    .then(function () {
      charger();
      afficherToast('Rendez-vous restauré.');
    })
    .catch(function (err) {
      alerterPerso(err.message);
    });
}

// Mecanique generique de menu deroulant (bouton -> liste d'options) :
// utilisee pour le menu "Imprimer" (Normal/Compact) et le menu de compte
// (Administration/Historique/Rappels/Deconnexion), pour ne pas dupliquer
// la meme logique d'ouverture/fermeture a chaque nouveau menu de ce genre.
var menusSuspendus = [];
function initMenuSuspendu(idConteneur, idBouton) {
  var conteneur = document.getElementById(idConteneur);
  var bouton = document.getElementById(idBouton);
  if (!conteneur || !bouton) return;
  var entree = { conteneur: conteneur, bouton: bouton };
  menusSuspendus.push(entree);
  bouton.addEventListener('click', function (e) {
    e.stopPropagation();
    var etaitOuvert = conteneur.classList.contains('ouvert');
    fermerMenusSuspendus();
    if (!etaitOuvert) {
      conteneur.classList.add('ouvert');
      bouton.setAttribute('aria-expanded', 'true');
    }
  });
}
function fermerMenusSuspendus() {
  menusSuspendus.forEach(function (m) {
    m.conteneur.classList.remove('ouvert');
    m.bouton.setAttribute('aria-expanded', 'false');
  });
}
document.addEventListener('click', function (e) {
  var unOuvertHorsClic = menusSuspendus.some(function (m) {
    return m.conteneur.classList.contains('ouvert') && !m.conteneur.contains(e.target);
  });
  if (unOuvertHorsClic) fermerMenusSuspendus();
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    fermerMenusSuspendus();
    fermerModalActif();
  }
});

initMenuSuspendu('menuCompte', 'btnMenuCompte');
initMenuSuspendu('menuImpression', 'btnMenuImprimer');

document.getElementById('btnImprimer').addEventListener('click', function () {
  fermerMenusSuspendus();
  document.body.classList.remove('impression-compacte');
  window.print();
});

document.getElementById('btnImprimerCompact').addEventListener('click', function () {
  fermerMenusSuspendus();
  document.body.classList.add('impression-compacte');

  // On ajoute le délai ici pour Android
  setTimeout(function() {
    window.print();
  }, 300);
});


document.getElementById('btnEnregistrer').addEventListener('click', function () {
  var date = document.getElementById('fDate').value;
  var heure = document.getElementById('fHeure').value;
  var duree = parseInt(document.getElementById('fDuree').value, 10) || 30;
  var personneInput = document.querySelector('.personnes input:checked');
  var medecin = document.getElementById('fMedecin').value;
  var departement = document.getElementById('fDepartement').value;
  var adresse = document.getElementById('fAdresse').value;
  var telephone = document.getElementById('fTelephone').value;
  var route = document.getElementById('fRoute').value;
  var accompagnant = document.getElementById('fAccompagnant').value;
  var notes = document.getElementById('fNotes').value;

  if (!date || !heure || !personneInput) {
    document.getElementById('erreurForm').textContent =
      "Merci de remplir la date, l'heure et de choisir pour qui.";
    return;
  }

  var appt = {
    id: idEnEdition,
    date: date,
    time: heure,
    duration: duree,
    person: personneInput.value,
    doctor: medecin,
    department: departement,
    location: adresse,
    phone: telephone,
    route: route,
    accompagnant: accompagnant,
    notes: notes
  };

  var btn = document.getElementById('btnEnregistrer');
  btn.disabled = true;
  btn.textContent = 'Enregistrement…';

  var action = idEnEdition ? 'update' : 'add';
  var messageConfirmation = idEnEdition ? 'Rendez-vous modifié.' : 'Rendez-vous ajouté.';
  appelApi(action, appt)
    .then(function () {
      btn.disabled = false;
      btn.textContent = 'Enregistrer';
      fermerModal('formCard');
      viderFormulaire();
      charger();
      afficherToast(messageConfirmation);
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Enregistrer';
      document.getElementById('erreurForm').textContent = err.message;
    });
});

charger();
