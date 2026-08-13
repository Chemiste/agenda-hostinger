var tousLesRdv = [];
var filtreActuel = 'Tous';
var filtreTemps = 'avenir';
var filtreRecherche = '';
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
  question: '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a2.9 2.9 0 1 1 3.8 2.76c-.77.26-1.4.99-1.4 1.81v.43"/><path d="M12 17h.01"/></svg>',
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

// Minuscules + accents retires, pour que "medecin" retrouve "Médecin" et
// que la recherche reste tolerante aux fautes d'accent sur mobile.
function normaliserTexte(s) {
  return (s || '').toString().toLowerCase().normalize('NFD').replace(new RegExp('[\\u0300-\\u036f]', 'g'), '');
}

// Vrai si le rendez-vous r correspond au texte recherche (deja normalise
// dans filtreRecherche), ou si aucune recherche n'est active. Cherche
// dans les champs les plus susceptibles de contenir ce qu'on tape :
// medecin, departement, lieu (adresse ou alias affiche), notes,
// accompagnant et personne.
function correspondRecherche(r) {
  if (filtreRecherche === '') return true;
  var texte = normaliserTexte([
    r.doctor, r.department, r.location, r.location_affichage,
    r.notes, r.accompagnant, r.person, r.questions, r.pathologie_nom
  ].join(' '));
  return texte.indexOf(filtreRecherche) !== -1;
}

// Decoupe le texte libre "questions" (une par ligne) en <li> individuels,
// en ignorant les lignes vides - utilise sur la carte (impression detaillee
// et grille compacte, voir genererGrilleCompacte()).
function listeQuestionsHtml(texte) {
  return (texte || '').split(/\r\n|\r|\n/)
    .map(function (l) { return l.trim(); })
    .filter(function (l) { return l !== ''; })
    .map(function (l) { return '<li>' + escapeHtml(l) + '</li>'; })
    .join('');
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
// $texteConfirmation : libelle du bouton qui valide ("Supprimer" par
// defaut). Le nommer d'apres l'action plutot qu'un "Oui" generique evite
// de valider sans avoir lu la question.
function confirmerPerso(message, texteConfirmation) {
  return new Promise(function (resolve) {
    dialogueResolveEnAttente = function () { resolve(false); };
    ouvrirDialogue(message, [
      { texte: 'Annuler', classe: 'secondaire', action: function () { resolve(false); } },
      { texte: texteConfirmation || 'Supprimer', classe: 'danger', action: function () { resolve(true); } }
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
      // Reconstruit la carte medecin->coordonnees a partir de
      // l'historique des rendez-vous, PUIS seulement fusionne le carnet
      // manuel par-dessus (chargerCarnetMedecins()) : dans cet ordre, un
      // medecin du carnet gagne toujours sur une donnee plus ancienne
      // venue d'un rendez-vous passe, et rien n'est perdu si les deux
      // requetes reseau ne reviennent pas dans le meme ordre.
      construireInfosParMedecin(rdvs);
      remplirListeMedecins();
      chargerCarnetMedecins();
      chargerPathologies();
    })
    .catch(function (err) {
      document.getElementById('liste').innerHTML =
        '<p class="erreur">Impossible de charger les rendez-vous : ' + err.message + '</p>';
    });

  chargerTaches();
}

// Taches ouvertes : utilisees a la fois par l'impression
// (genererTachesCompactes), le widget de la colonne de droite sur desktop
// (afficherBarreTaches) et le bandeau compact sur telephone
// (afficherBandeauTaches). Chargees a part de la liste des rendez-vous,
// sans bloquer l'affichage de l'agenda si ca echoue.
var tachesOuvertesActuelles = [];
function chargerTaches() {
  return fetch('/api.php?action=taches')
    .then(function (r) { return r.json(); })
    .then(function (taches) {
      tachesOuvertesActuelles = Array.isArray(taches) ? taches : [];
      rafraichirTachesAffichees();
    })
    .catch(function () { /* widgets/section taches simplement absents */ });
}

// Les taches sans personne (facultative) restent visibles quel que soit
// l'onglet Papa/Maman/Tous choisi au-dessus des rendez-vous (elles ne
// concernent personne en particulier) ; les autres suivent le filtre,
// comme la liste des rendez-vous.
function tachesPourFiltreActuel() {
  return tachesOuvertesActuelles.filter(function (t) {
    return filtreActuel === 'Tous' || t.personne === '' || t.personne === filtreActuel;
  });
}

function rafraichirTachesAffichees() {
  var filtrees = tachesPourFiltreActuel();
  genererTachesCompactes(filtrees);
  afficherBarreTaches(filtrees);
  afficherBandeauTaches(filtrees);
}

// Coche une tache comme faite depuis le widget de l'accueil (barre ou
// bandeau), sans quitter la page - la gestion complete (ajout, date
// cible, suppression) reste sur taches.php.
function cocherTacheDepuisAccueil(id) {
  fetch('/api.php?action=tache_toggle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: id, fait: true })
  })
    .then(function (r) {
      if (!r.ok) throw new Error('Erreur serveur.');
      return chargerTaches();
    })
    .then(function () {
      afficherToast('Tâche terminée.');
    })
    .catch(function () {
      alerterPerso('Impossible de mettre à jour la tâche.');
    });
}

function texteMetaTache(t) {
  var meta = [];
  if (t.personne) meta.push(t.personne);
  if (t.date_cible) {
    var d = formatDateCompacte(t.date_cible);
    meta.push('Pour le ' + d.jour + ' ' + d.mois);
  }
  return meta.join(' · ');
}

function afficherBarreTaches(taches) {
  var conteneur = document.getElementById('barreTaches');
  if (!conteneur) return;
  var entete = '<div class="barre-taches-entete">' +
    '<h2>Tâches' + (taches.length > 0 ? ' <span class="compteur-tab">(' + taches.length + ')</span>' : '') + '</h2>' +
    '<a href="/taches.php">Gérer</a>' +
  '</div>';
  if (taches.length === 0) {
    conteneur.innerHTML = entete + '<p class="vide-taches">Aucune tâche en cours.</p>';
    return;
  }
  conteneur.innerHTML = entete + '<div class="liste-taches-widget">' +
    taches.map(function (t) {
      var meta = texteMetaTache(t);
      return '<label class="item-tache-widget">' +
        '<input type="checkbox" data-id="' + t.id + '">' +
        '<span class="item-tache-contenu">' +
          '<span class="item-tache-texte">' + escapeHtml(t.texte) + '</span>' +
          (meta ? '<span class="item-tache-meta">' + escapeHtml(meta) + '</span>' : '') +
        '</span>' +
      '</label>';
    }).join('') +
  '</div>';
}

function afficherBandeauTaches(taches) {
  var conteneur = document.getElementById('bandeauTaches');
  if (!conteneur) return;
  if (taches.length === 0) {
    conteneur.innerHTML = '';
    return;
  }
  var premiere = taches[0];
  var meta = texteMetaTache(premiere);
  conteneur.innerHTML = '<label class="item-tache-widget">' +
    '<input type="checkbox" data-id="' + premiere.id + '">' +
    '<span class="item-tache-contenu">' +
      '<span class="item-tache-texte">' + escapeHtml(premiere.texte) + '</span>' +
      (meta ? '<span class="item-tache-meta">' + escapeHtml(meta) + '</span>' : '') +
    '</span>' +
  '</label>' +
  '<a href="/taches.php" class="lien-voir-tout-taches">' +
    (taches.length > 1 ? 'Voir tout (' + taches.length + ')' : 'Tâches') +
  '</a>';
}

document.addEventListener('change', function (e) {
  if (!e.target.matches('#barreTaches input[type=checkbox], #bandeauTaches input[type=checkbox]')) return;
  var id = e.target.dataset.id;
  e.target.disabled = true;
  cocherTacheDepuisAccueil(id);
});

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

// Carnet de medecins de reference (voir medecins.php), fusionne dans la
// meme carte infosParMedecin que l'historique des rendez-vous : un
// medecin du carnet apparait donc dans les suggestions et pre-remplit le
// formulaire meme s'il n'a jamais encore eu de rendez-vous. En cas de
// doublon (meme nom), le carnet gagne (voir l'appel apres
// construireInfosParMedecin() dans charger()).
function chargerCarnetMedecins() {
  fetch('/api.php?action=medecins')
    .then(function (r) { return r.json(); })
    .then(function (medecins) {
      if (!Array.isArray(medecins)) return;
      medecins.forEach(function (m) {
        var nom = (m.doctor || '').trim();
        if (!nom) return;
        infosParMedecin[nom.toLowerCase()] = {
          nomExact: nom,
          department: m.department || '',
          location: m.location || '',
          phone: m.phone || '',
          route: m.route || ''
        };
      });
      remplirListeMedecins();
    })
    .catch(function () { /* le carnet manque simplement a l'auto-remplissage */ });
}

// Pathologies suivies (voir pathologies.php), par personne : sert a
// proposer "Pathologie concernee" dans le formulaire de rendez-vous, pour
// pouvoir ensuite retrouver depuis une pathologie les rendez-vous qui s'y
// rapportent ("j'ai un rendez-vous prevu pour mon bras debut octobre").
var pathologiesParPersonne = {};
function chargerPathologies() {
  fetch('/api.php?action=pathologies')
    .then(function (r) { return r.json(); })
    .then(function (parPersonne) {
      if (!parPersonne || typeof parPersonne !== 'object') return;
      pathologiesParPersonne = parPersonne;
      actualiserChoixPathologies();
    })
    .catch(function () { /* le champ reste simplement masque */ });
}

// Remplit le menu "Pathologie concernee" avec les seules pathologies de la
// personne actuellement cochee - et masque tout le champ si elle n'en a
// aucune (inutile de montrer un menu vide). $idPreselectionne permet de
// retrouver le choix enregistre a l'ouverture d'un rendez-vous existant.
function actualiserChoixPathologies(idPreselectionne) {
  var champ = document.getElementById('champPathologie');
  var select = document.getElementById('fPathologie');
  if (!champ || !select) return;

  var personneInput = document.querySelector('.personnes input:checked');
  var personne = personneInput ? personneInput.value : '';
  var liste = (personne && pathologiesParPersonne[personne]) ? pathologiesParPersonne[personne] : [];

  // Conserve le choix en cours si on n'en impose pas un (ex. l'utilisateur
  // change de personne apres avoir choisi une pathologie).
  var valeurVoulue = (idPreselectionne !== undefined && idPreselectionne !== null)
    ? String(idPreselectionne)
    : select.value;

  select.innerHTML = '<option value="0">— Aucune —</option>' +
    liste.map(function (p) {
      return '<option value="' + escapeHtml(p.id) + '">' + escapeHtml(p.nom) + '</option>';
    }).join('');

  // La pathologie retenue n'existe pas pour cette personne : on retombe
  // sur "Aucune" plutot que de garder un lien incoherent.
  select.value = valeurVoulue;
  if (select.selectedIndex === -1) select.value = '0';

  champ.style.display = liste.length ? '' : 'none';
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

// Date en toutes lettres sur chaque carte ("mardi 18 août"), et non plus
// abregee ("mar. 18") : le mois n'est ecrit que dans le titre de groupe
// plus haut, or a l'impression un saut de page peut separer les deux - on
// ne saurait alors plus de quel mois il s'agit. L'annee reste dans le
// titre de groupe (elle est rarement ambigue et alourdirait chaque carte).
function dateCourte(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
}

// "14h05" plutot que "14:05" : ecriture francaise usuelle de l'heure, plus
// naturelle a lire que la notation informatique. Le champ du formulaire
// reste en "HH:MM" (format impose par <input type="time">), c'est juste
// l'affichage qui change.
function heureLisible(heure) {
  return (heure || '').replace(':', 'h');
}

// Lieu et route sur une seule ligne ("Hôpital St Luc · Route 551") plutot
// que deux : seul Saint-Luc utilise cette notion de route (le circuit
// interne à suivre une fois sur place), elle n'a donc de sens qu'accolée
// au lieu - et ça economise une ligne par carte a l'impression.
// Si un rendez-vous n'a qu'une route sans lieu, elle garde sa propre
// ligne avec son icone habituelle.
function lieuEtRouteHtml(r) {
  var lieu = r.location ? (r.location_affichage || r.location) : '';
  if (lieu === '' && !r.route) return '';
  if (!r.route) {
    return '<div class="contact">' + ICONES.lieu + escapeHtml(lieu) + '</div>';
  }
  // Route en tete : voir le mot "Route" suffit a savoir qu'on est a
  // Saint-Luc (seul etablissement qui utilise cette notion), et c'est
  // l'information a suivre une fois sur place.
  return '<div class="contact">' + ICONES.route +
    '<span class="route-en-ligne">' + escapeHtml(r.route) + '</span>' +
    (lieu !== '' ? ' · ' + escapeHtml(lieu) : '') +
    '</div>';
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
    return okPersonne && okTemps && correspondRecherche(r);
  }).sort(function (a, b) {
    var ca = a.date + ' ' + a.time, cb = b.date + ' ' + b.time;
    // "Passés" : le plus recent d'abord (plus utile pour retrouver un
    // rendez-vous recent que de commencer par le plus ancien). Les deux
    // autres vues restent chronologiques (le plus proche en premier).
    if (filtreTemps === 'passes') return ca < cb ? 1 : ca > cb ? -1 : 0;
    return ca < cb ? -1 : ca > cb ? 1 : 0;
  });

  // Compteurs sur les onglets "À venir" et "Passés" : toujours le nombre
  // de rendez-vous correspondant pour le filtre de personne actuel
  // (Tous/Papa/Maman), quel que soit l'onglet temps actuellement selectionne.
  var compteurAvenirEl = document.getElementById('compteurAvenir');
  if (compteurAvenirEl) {
    var nbAvenir = tousLesRdv.filter(function (r) {
      var okPersonne = filtreActuel === 'Tous' || r.person === filtreActuel;
      return okPersonne && correspondRecherche(r) && r.date >= aujourdhui;
    }).length;
    compteurAvenirEl.textContent = nbAvenir > 0 ? '(' + nbAvenir + ')' : '';
  }
  var compteurPassesEl = document.getElementById('compteurPasses');
  if (compteurPassesEl) {
    var nbPasses = tousLesRdv.filter(function (r) {
      var okPersonne = filtreActuel === 'Tous' || r.person === filtreActuel;
      return okPersonne && correspondRecherche(r) && r.date < aujourdhui;
    }).length;
    compteurPassesEl.textContent = nbPasses > 0 ? '(' + nbPasses + ')' : '';
  }
  var compteurTousEl = document.getElementById('compteurTous');
  if (compteurTousEl) {
    var nbTous = tousLesRdv.filter(function (r) {
      return (filtreActuel === 'Tous' || r.person === filtreActuel) && correspondRecherche(r);
    }).length;
    compteurTousEl.textContent = nbTous > 0 ? '(' + nbTous + ')' : '';
  }

  var labelTemps = filtreTemps === 'avenir' ? 'À venir' : (filtreTemps === 'passes' ? 'Passés' : 'Tout l\'historique');
  document.getElementById('filtreImpression').textContent = filtreActuel + ' — ' + labelTemps;

  if (filtres.length === 0) {
    var messageVide = filtreTemps === 'passes' ? 'Aucun rendez-vous passé.' :
      (filtreTemps === 'avenir' ? 'Aucun rendez-vous à venir.' : 'Aucun rendez-vous.');
    if (filtreRecherche !== '') {
      messageVide = 'Aucun rendez-vous ne correspond à la recherche.';
    }
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
        '<span class="rdv-entete-heure">' + dateCourte(r.date) + ' · ' + heureLisible(r.time) + '</span>' +
      '</div>' +
      badgeJour +
      '<div class="rdv-corps">' +
        (t.departement ? '<div class="departement">' + escapeHtml(t.departement) + '</div>' : '') +
        '<div class="medecin">' + ICONES.medecin + escapeHtml(t.medecin) + '</div>' +
        (r.pathologie_nom ? '<div class="pathologie-rdv">' + escapeHtml(r.pathologie_nom) + '</div>' : '') +
        lieuEtRouteHtml(r) +
        (r.phone ? '<div class="contact">' + ICONES.telephone + escapeHtml(r.phone) + '</div>' : '') +
        (r.accompagnant ? '<div class="accompagnant">' + ICONES.accompagnant + escapeHtml(r.accompagnant) + '</div>' : '') +
        (r.notes ? '<div class="notes">' + ICONES.note + escapeHtml(r.notes) + '</div>' : '') +
        (r.questions ? '<div class="questions">' +
          '<div class="questions-titre">' + ICONES.question + 'Questions à poser</div>' +
          '<ul class="questions-liste">' + listeQuestionsHtml(r.questions) + '</ul>' +
        '</div>' : '') +
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
  rafraichirTachesAffichees();
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

// Recherche texte libre (medecin, departement, adresse, notes,
// accompagnant) : filtre en direct, combinee avec les onglets personne
// et temps deja actifs (voir correspondRecherche() dans afficherListe()).
var champRecherche = document.getElementById('champRecherche');
var btnEffacerRecherche = document.getElementById('btnEffacerRecherche');
if (champRecherche && btnEffacerRecherche) {
  champRecherche.addEventListener('input', function () {
    filtreRecherche = normaliserTexte(champRecherche.value.trim());
    btnEffacerRecherche.style.display = champRecherche.value ? '' : 'none';
    afficherListe();
  });
  btnEffacerRecherche.addEventListener('click', function () {
    champRecherche.value = '';
    filtreRecherche = '';
    btnEffacerRecherche.style.display = 'none';
    champRecherche.focus();
    afficherListe();
  });
}

// La recherche est repliee au chargement (voir index.php) : le bouton
// "Rechercher" la deplie, un second clic la referme et efface le filtre en
// cours - sinon on pourrait cacher la barre en laissant une recherche
// active, et la liste paraitrait incomplete sans qu'on comprenne pourquoi.
var btnOuvrirRecherche = document.getElementById('btnOuvrirRecherche');
var rechercheBarre = document.getElementById('rechercheBarre');
if (btnOuvrirRecherche && rechercheBarre && champRecherche) {
  btnOuvrirRecherche.addEventListener('click', function () {
    var ouverte = rechercheBarre.style.display !== 'none';
    if (ouverte) {
      rechercheBarre.style.display = 'none';
      btnOuvrirRecherche.setAttribute('aria-expanded', 'false');
      if (filtreRecherche !== '') {
        champRecherche.value = '';
        filtreRecherche = '';
        btnEffacerRecherche.style.display = 'none';
        afficherListe();
      }
    } else {
      rechercheBarre.style.display = '';
      btnOuvrirRecherche.setAttribute('aria-expanded', 'true');
      champRecherche.focus();
    }
    ajusterHauteurTopbar();
  });
}

// La colonne "Taches" (desktop) doit rester collee juste sous le bandeau
// du haut au scroll (voir --hauteur-topbar dans style.css). Ce bandeau
// est sticky et sa hauteur varie (recherche, onglets...) : on la mesure
// en JS plutot que de deviner un chiffre fixe, sinon le titre "Taches"
// finit cache derriere le bandeau du haut.
function ajusterHauteurTopbar() {
  var topbar = document.querySelector('.topbar');
  if (!topbar) return;
  document.documentElement.style.setProperty('--hauteur-topbar', topbar.offsetHeight + 'px');
}
ajusterHauteurTopbar();
window.addEventListener('resize', ajusterHauteurTopbar);
// La recherche/les onglets ne changent pas la hauteur du bandeau, mais un
// evenement de chargement de police ou une rotation d'ecran peut la
// changer legerement - un reajustement une fois tout charge suffit.
window.addEventListener('load', ajusterHauteurTopbar);

// ---------------------------------------------------------------
// Grille imprimee : mêmes données que la liste détaillée de l'écran, mais
// rendues sous forme de cartes (date en évidence, titre, département,
// heure). Ce conteneur reste caché à l'écran et n'apparait qu'à
// l'impression (voir le bouton "Imprimer" plus bas et la règle CSS
// "body.impression-compacte").
// ---------------------------------------------------------------

function genererGrilleCompacte(filtres) {
  var conteneur = document.getElementById('listeCompacte');
  if (filtres.length === 0) {
    conteneur.innerHTML = '<p class="vide">Aucun rendez-vous.</p>';
    return;
  }
  // Un bloc par mois : titre ("Août 2026") suivi de la grille des cartes
  // de ce mois. Le titre est volontairement HORS de la grille - en tant
  // qu'element de grille, "break-after: avoid" est ignore par les
  // navigateurs et un titre pouvait se retrouver seul en bas d'une page,
  // sans aucun rendez-vous sous lui.
  var mois = [];
  var parCle = {};
  filtres.forEach(function (r) {
    var cle = r.date.slice(0, 7);
    if (!parCle[cle]) {
      parCle[cle] = { libelle: moisAnnee(r.date), rdvs: [] };
      mois.push(parCle[cle]);
    }
    parCle[cle].rdvs.push(r);
  });

  // "break-after: avoid" sur le titre ne suffit pas : les navigateurs le
  // respectent mal quand l'element suivant est une grille, et un mois
  // court se retrouvait quand meme seul en bas de page. On rend donc le
  // bloc entier insecable quand le mois est assez court pour tenir sur une
  // page (MOIS_COURT lignes de cartes ou moins) : il bascule alors en
  // entier sur la page suivante. Au-dela, on laisse le mois se couper
  // normalement, sinon un mois charge laisserait un grand blanc.
  var MOIS_COURT = 6;

  conteneur.innerHTML = mois.map(function (m) {
    var lignes = Math.ceil(m.rdvs.length / 2);
    var classe = 'cc-mois-bloc' + (lignes <= MOIS_COURT ? ' cc-mois-bloc-insecable' : '');
    return '<div class="' + classe + '">' +
      '<div class="cc-mois-titre">' + escapeHtml(m.libelle) + '</div>' +
      '<div class="cc-mois-grille">' + m.rdvs.map(carteCompacteHtml).join('') + '</div>' +
    '</div>';
  }).join('');
}

// Meme regroupement lieu + route que sur les cartes de l'ecran (voir
// lieuEtRouteHtml), pour la grille imprimee.
function lieuEtRouteCompactHtml(r) {
  var lieu = r.location ? (r.location_affichage || r.location) : '';
  if (lieu === '' && !r.route) return '';
  if (!r.route) {
    return '<div class="cc-adresse">' + escapeHtml(lieu) + '</div>';
  }
  return '<div class="cc-adresse">' +
    '<span class="cc-route-en-ligne">' + escapeHtml(r.route) + '</span>' +
    (lieu !== '' ? ' · ' + escapeHtml(lieu) : '') +
    '</div>';
}

function carteCompacteHtml(r) {
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
          '<span class="cc-heure">' + heureLisible(r.time) + '</span>' +
        '</div>' +
        '<div class="cc-contenu">' +
          (t.departement ? '<div class="cc-sous">' + escapeHtml(t.departement) + '</div>' : '') +
          '<div class="cc-titre">' + escapeHtml(t.medecin) + '</div>' +
          lieuEtRouteCompactHtml(r) +
          (r.pathologie_nom ? '<div class="cc-pathologie">' + escapeHtml(r.pathologie_nom) + '</div>' : '') +
          (r.accompagnant ? '<div class="cc-accompagnant">Avec ' + escapeHtml(r.accompagnant) + '</div>' : '') +
          (r.notes ? '<div class="cc-notes">' + escapeHtml(r.notes) + '</div>' : '') +
          (r.questions ? '<div class="cc-questions"><div class="cc-questions-titre">Questions à poser</div><ul class="cc-questions-liste">' + listeQuestionsHtml(r.questions) + '</ul></div>' : '') +
        '</div>' +
      '</div>' +
    '</div>';
}

// Section "Tâches" ajoutee sous la grille a l'impression compacte (voir
// taches.php pour la gestion complete - ceci n'affiche que les taches
// encore ouvertes, en lecture seule, pour avoir la liste sur papier avec
// le reste de l'agenda).
function genererTachesCompactes(taches) {
  var conteneur = document.getElementById('tachesCompacte');
  if (!conteneur) return;
  if (!taches || taches.length === 0) {
    conteneur.innerHTML = '';
    return;
  }
  conteneur.innerHTML = '<h2 class="cc-taches-titre">Tâches</h2>' +
    '<div class="cc-taches-grille">' +
    taches.map(function (t) {
      var cls = t.personne ? classeBadge(t.personne) : 'deux';
      var meta = [];
      if (t.personne) meta.push(escapeHtml(t.personne));
      if (t.date_cible) {
        var d = formatDateCompacte(t.date_cible);
        meta.push('Pour le ' + d.jour + ' ' + d.mois);
      }
      return '<div class="cc-tache cc-tache-' + cls + '">' +
        '<span class="cc-tache-case"></span>' +
        '<div class="cc-tache-contenu">' +
          '<div class="cc-tache-texte">' + escapeHtml(t.texte) + '</div>' +
          (meta.length ? '<div class="cc-tache-meta">' + meta.join(' · ') + '</div>' : '') +
        '</div>' +
      '</div>';
    }).join('') +
    '</div>';
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
  document.getElementById('fQuestions').value = '';
  document.querySelectorAll('.personnes input').forEach(function (r) { r.checked = false; });
  document.querySelectorAll('.personnes label').forEach(function (l) { l.classList.remove('checked'); });
  document.getElementById('erreurForm').textContent = '';
  document.getElementById('btnSupprimer').style.display = 'none';
  idEnEdition = null;
  actualiserBoutonImporterMedecin();
  // Aucune personne cochee a ce stade : le champ se masque de lui-meme et
  // reapparaitra des qu'une personne ayant des pathologies sera choisie.
  actualiserChoixPathologies(0);
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
  document.getElementById('fQuestions').value = r.questions || '';
  selectionnerPersonne(r.person);
  // Apres selectionnerPersonne() : le menu doit d'abord etre rempli avec
  // les pathologies de CETTE personne avant qu'on y resélectionne la
  // valeur enregistree.
  actualiserChoixPathologies(r.pathologie_id || 0);
  // Chaine vide (et non "block") : le bouton reprend le display de sa
  // regle CSS, maintenant qu'il vit dans la barre flex du bas.
  document.getElementById('btnSupprimer').style.display = '';
  actualiserBoutonImporterMedecin();
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
    // Chacun a ses propres pathologies : le menu doit suivre le changement
    // de personne (et disparaitre si la nouvelle n'en a aucune).
    actualiserChoixPathologies();
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
  if (infos) {
    var fDepartement = document.getElementById('fDepartement');
    var fAdresse = document.getElementById('fAdresse');
    var fTelephone = document.getElementById('fTelephone');
    var fRoute = document.getElementById('fRoute');
    if (fDepartement.value === '') fDepartement.value = infos.department;
    if (fAdresse.value === '') fAdresse.value = infos.location;
    if (fTelephone.value === '') fTelephone.value = infos.phone;
    if (fRoute.value === '') fRoute.value = infos.route;
  }
  actualiserBoutonImporterMedecin();
});

// Bouton "Importer ce médecin dans le carnet" (voir medecins.php) :
// reserve a Laurent (verifie aussi cote serveur, voir api.php action
// "importer_medecin"), visible seulement si un nom de medecin est
// renseigne dans le formulaire.
function actualiserBoutonImporterMedecin() {
  var btn = document.getElementById('btnImporterMedecin');
  if (!btn) return;
  var medecinRempli = document.getElementById('fMedecin').value.trim() !== '';
  btn.style.display = (window.PERSONNE_CONNECTEE === 'Laurent' && medecinRempli) ? 'block' : 'none';
}

document.getElementById('btnImporterMedecin').addEventListener('click', function () {
  var personneInput = document.querySelector('.personnes input:checked');
  var doctor = document.getElementById('fMedecin').value.trim();
  if (!personneInput) {
    alerterPerso('Choisissez d\'abord Papa ou Maman.');
    return;
  }
  if (!doctor) return;
  var btn = this;
  btn.disabled = true;
  fetch('/api.php?action=importer_medecin', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      person: personneInput.value,
      doctor: doctor,
      department: document.getElementById('fDepartement').value,
      location: document.getElementById('fAdresse').value,
      phone: document.getElementById('fTelephone').value,
      route: document.getElementById('fRoute').value
    })
  })
    .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
    .then(function (res) {
      if (!res.ok) throw new Error(res.data && res.data.error ? res.data.error : 'Erreur serveur.');
      var messages = {
        cree: 'Médecin ajouté au carnet.',
        mis_a_jour: 'Fiche médecin mise à jour dans le carnet.',
        inchange: 'Déjà à jour dans le carnet.'
      };
      afficherToast(messages[res.data.statut] || 'Carnet mis à jour.');
      chargerCarnetMedecins();
    })
    .catch(function (err) {
      alerterPerso(err.message);
    })
    .finally(function () {
      btn.disabled = false;
    });
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
    notes: r.notes,
    questions: r.questions,
    pathologie_id: r.pathologie_id
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

// Confirmation avant de se deconnecter : le lien voisine avec ceux des
// autres pages dans le meme menu, et un clic de trop renvoie a l'ecran de
// mot de passe - que Michel et Christiane n'ont pas.
var lienDeconnexion = document.getElementById('lienDeconnexion');
if (lienDeconnexion) {
  lienDeconnexion.addEventListener('click', function (e) {
    e.preventDefault();
    fermerMenusSuspendus();
    confirmerPerso(
      'Se déconnecter ? Il faudra retaper le mot de passe pour revenir sur l\'agenda.',
      'Se déconnecter'
    ).then(function (confirme) {
      if (confirme) window.location.href = '/logout.php';
    });
  });
}

document.getElementById('btnImprimer').addEventListener('click', function () {
  fermerMenusSuspendus();
  document.body.classList.add('impression-compacte');

  // Date du jour inscrite sur la feuille : une feuille qui traine sur la
  // table ne dit sinon pas si elle date d'hier ou d'il y a six semaines.
  var champDate = document.getElementById('dateImpression');
  if (champDate) {
    champDate.textContent = 'Imprimé le ' + new Date().toLocaleDateString('fr-FR', {
      day: 'numeric', month: 'long', year: 'numeric'
    });
  }

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
  var questions = document.getElementById('fQuestions').value;
  var pathologieId = document.getElementById('fPathologie').value || '0';

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
    notes: notes,
    questions: questions,
    pathologie_id: pathologieId
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
