var tousLesRdv = [];
var filtreActuel = 'Tous';
var filtreTemps = 'avenir';
var idEnEdition = null;

var MOIS_ABREGES = ['Jan', 'Fév', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
function formatDateCompacte(dateStr) {
  var p = dateStr.split('-');
  return { jour: p[2], mois: MOIS_ABREGES[parseInt(p[1], 10) - 1], annee: p[0] };
}

// ---------------------------------------------------------------
// Ouverture / fermeture du modal du formulaire de rendez-vous
// (l'import .ics vit desormais dans assets/admin.js, page admin)
// ---------------------------------------------------------------

function ouvrirModal(id) {
  document.getElementById(id).classList.add('ouvert');
  document.getElementById('overlay').classList.add('visible');
  document.body.style.overflow = 'hidden';
}

function fermerModal(id) {
  document.getElementById(id).classList.remove('ouvert');
  document.getElementById('overlay').classList.remove('visible');
  document.body.style.overflow = '';
}

document.getElementById('overlay').addEventListener('click', function () {
  fermerModal('formCard');
  viderFormulaire();
});

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
    })
    .catch(function (err) {
      document.getElementById('liste').innerHTML =
        '<p class="erreur">Impossible de charger les rendez-vous : ' + err.message + '</p>';
    });
}

function joursDepuis(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function classeBadge(personne) {
  if (personne === window.PERSONNE_1) return 'papa';
  if (personne === window.PERSONNE_2) return 'maman';
  return 'deux';
}

// Sur petit ecran (telephone), le badge n'affiche que les 4 premieres
// lettres du prenom (ex. "Mich" au lieu de "Michel") pour gagner de la
// place dans la liste. Sur ecran plus large, le nom complet reste affiche.
var MOBILE_MQ = window.matchMedia('(max-width: 640px)');
function nomBadge(personne) {
  return MOBILE_MQ.matches ? personne.slice(0, 4) : personne;
}

function escapeHtml(s) {
  var div = document.createElement('div');
  div.textContent = s || '';
  return div.innerHTML;
}

function afficherListe() {
  var aujourdhui = new Date().toISOString().slice(0, 10);
  var filtres = tousLesRdv.filter(function (r) {
    var okPersonne = filtreActuel === 'Tous' || r.person === filtreActuel;
    var okTemps = filtreTemps === 'tous' ||
      (filtreTemps === 'avenir' ? r.date >= aujourdhui : r.date < aujourdhui);
    return okPersonne && okTemps;
  }).sort(function (a, b) {
    var ca = a.date + ' ' + a.time, cb = b.date + ' ' + b.time;
    return ca < cb ? -1 : ca > cb ? 1 : 0;
  });

  var labelTemps = filtreTemps === 'avenir' ? 'À venir' : (filtreTemps === 'passes' ? 'Passés' : 'Tous');
  document.getElementById('filtreImpression').textContent = filtreActuel + ' — ' + labelTemps;

  if (filtres.length === 0) {
    var messageVide = filtreTemps === 'passes' ? 'Aucun rendez-vous passé.' : 'Aucun rendez-vous à venir.';
    document.getElementById('liste').innerHTML = '<p class="vide">' + messageVide + '</p>';
    document.getElementById('listeCompacte').innerHTML = '<p class="vide">' + messageVide + '</p>';
    return;
  }

  // Chaque jour est regroupé dans un conteneur ".jour-groupe" (titre +
  // ses rendez-vous) pour qu'à l'impression le navigateur garde le titre
  // collé à ses rendez-vous : un "avoid" sur le conteneur entier est bien
  // mieux respecté par les navigateurs qu'un "avoid" posé seulement sur
  // le titre (qui laissait parfois le titre seul en bas d'une page).
  var html = '';
  var dernierJour = null;
  filtres.forEach(function (r) {
    if (r.date !== dernierJour) {
      if (dernierJour !== null) html += '</div>';
      html += '<div class="jour-groupe"><p class="jour-titre">' + joursDepuis(r.date) + '</p>';
      dernierJour = r.date;
    }
    var contact = [r.location, r.phone, r.route].filter(function (v) { return v; }).map(escapeHtml).join(' · ');
    html += '<div class="rdv" data-id="' + r.id + '">' +
      '<div class="heure">' + r.time + '</div>' +
      '<span class="badge ' + classeBadge(r.person) + '">' + escapeHtml(nomBadge(r.person)) + '</span>' +
      '<div class="details">' +
        '<div class="medecin">' + escapeHtml(r.doctor || 'Rendez-vous') + '</div>' +
        (r.department ? '<div class="departement">' + escapeHtml(r.department) + '</div>' : '') +
        (contact ? '<div class="contact">' + contact + '</div>' : '') +
        (r.notes ? '<div class="notes">' + escapeHtml(r.notes) + '</div>' : '') +
      '</div>' +
    '</div>';
  });
  if (dernierJour !== null) html += '</div>';
  document.getElementById('liste').innerHTML = html;
  genererGrilleCompacte(filtres);

  // La suppression se fait desormais depuis le formulaire d'edition (bouton
  // "Supprimer ce rendez-vous" en bas du formulaire), pas directement dans
  // la liste : c'est une action rare, pas la peine de l'exposer partout.
  document.querySelectorAll('.rdv').forEach(function (el) {
    el.addEventListener('click', function () {
      ouvrirEnEdition(el.dataset.id);
    });
  });
}

document.getElementById('tabs').addEventListener('click', function (e) {
  var tab = e.target.closest('.tab');
  if (!tab) return;
  document.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
  tab.classList.add('active');
  filtreActuel = tab.dataset.filtre;
  afficherListe();
});

document.getElementById('tabsTemps').addEventListener('click', function (e) {
  var tab = e.target.closest('.tab-temps');
  if (!tab) return;
  document.querySelectorAll('.tab-temps').forEach(function (t) { t.classList.remove('active'); });
  tab.classList.add('active');
  filtreTemps = tab.dataset.temps;
  afficherListe();
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
    return '<div class="carte-compacte">' +
      '<div class="cc-date cc-' + cls + '">' +
        '<span class="cc-jour">' + d.jour + '</span>' +
        '<span class="cc-mois">' + d.mois + '</span>' +
        '<span class="cc-annee">' + d.annee + '</span>' +
      '</div>' +
      '<div class="cc-contenu">' +
        '<div class="cc-titre">' + escapeHtml(r.doctor || 'Rendez-vous') + '</div>' +
        (r.department ? '<div class="cc-sous">' + escapeHtml(r.department) + '</div>' : '') +
        (r.location ? '<div class="cc-adresse">' + escapeHtml(r.location) + '</div>' : '') +
        '<div class="cc-bas"><span class="cc-personne cc-' + cls + '">' + escapeHtml(r.person) + '</span> · ' + r.time + (r.route ? ' · ' + escapeHtml(r.route) : '') + '</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

function viderFormulaire() {
  document.getElementById('fDate').value = '';
  document.getElementById('fHeure').value = '';
  document.getElementById('fMedecin').value = '';
  document.getElementById('fDepartement').value = '';
  document.getElementById('fAdresse').value = '';
  document.getElementById('fTelephone').value = '';
  document.getElementById('fRoute').value = '';
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
  document.getElementById('fMedecin').value = r.doctor || '';
  document.getElementById('fDepartement').value = r.department || '';
  document.getElementById('fAdresse').value = r.location || '';
  document.getElementById('fTelephone').value = r.phone || '';
  document.getElementById('fRoute').value = r.route || '';
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
  var today = new Date().toISOString().slice(0, 10);
  document.getElementById('fDate').value = today;
  ouvrirModal('formCard');
}

document.getElementById('btnAjouter').addEventListener('click', ouvrirFormulaireAjout);
document.getElementById('btnAjouterMobile').addEventListener('click', ouvrirFormulaireAjout);

document.getElementById('btnAnnuler').addEventListener('click', function () {
  fermerModal('formCard');
  viderFormulaire();
});

document.getElementById('btnSupprimer').addEventListener('click', function () {
  if (!idEnEdition) return;
  if (!confirm('Supprimer ce rendez-vous ?')) return;
  var id = idEnEdition;
  appelApi('delete', { id: id })
    .then(function () {
      fermerModal('formCard');
      viderFormulaire();
      charger();
    })
    .catch(function (err) {
      alert(err.message);
    });
});

document.getElementById('btnImprimer').addEventListener('click', function () {
  document.body.classList.remove('impression-compacte');
  window.print();
});

document.getElementById('btnImprimerCompact').addEventListener('click', function () {
  document.body.classList.add('impression-compacte');
  window.print();
});

window.addEventListener('afterprint', function () {
  document.body.classList.remove('impression-compacte');
});

document.getElementById('btnEnregistrer').addEventListener('click', function () {
  var date = document.getElementById('fDate').value;
  var heure = document.getElementById('fHeure').value;
  var personneInput = document.querySelector('.personnes input:checked');
  var medecin = document.getElementById('fMedecin').value;
  var departement = document.getElementById('fDepartement').value;
  var adresse = document.getElementById('fAdresse').value;
  var telephone = document.getElementById('fTelephone').value;
  var route = document.getElementById('fRoute').value;
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
    person: personneInput.value,
    doctor: medecin,
    department: departement,
    location: adresse,
    phone: telephone,
    route: route,
    notes: notes
  };

  var btn = document.getElementById('btnEnregistrer');
  btn.disabled = true;
  btn.textContent = 'Enregistrement…';

  var action = idEnEdition ? 'update' : 'add';
  appelApi(action, appt)
    .then(function () {
      btn.disabled = false;
      btn.textContent = 'Enregistrer';
      fermerModal('formCard');
      viderFormulaire();
      charger();
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Enregistrer';
      document.getElementById('erreurForm').textContent = err.message;
    });
});

charger();
