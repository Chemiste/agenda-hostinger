var tousLesRdv = [];
var filtreActuel = 'Tous';
var filtreTemps = 'avenir';
var idEnEdition = null;

var MOIS_ABREGES = ['Jan', 'Fev', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Aout', 'Sep', 'Oct', 'Nov', 'Dec'];
function formatDateCompacte(dateStr) {
  var p = dateStr.split('-');
  return { jour: p[2], mois: MOIS_ABREGES[parseInt(p[1], 10) - 1], annee: p[0] };
}

// ---------------------------------------------------------------
// Ouverture / fermeture des modals (formulaire, import .ics)
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
  fermerModal('icsCard');
  viderFormulaire();
});

function appelApi(action, corps) {
  return fetch('api.php?action=' + action, {
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
  fetch('api.php?action=list')
    .then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok || !Array.isArray(data)) {
          throw new Error(data && data.error ? data.error : 'Reponse inattendue du serveur.');
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

  var labelTemps = filtreTemps === 'avenir' ? 'A venir' : (filtreTemps === 'passes' ? 'Passes' : 'Tous');
  document.getElementById('filtreImpression').textContent = filtreActuel + ' — ' + labelTemps;

  if (filtres.length === 0) {
    var messageVide = filtreTemps === 'passes' ? 'Aucun rendez-vous passe.' : 'Aucun rendez-vous a venir.';
    document.getElementById('liste').innerHTML = '<p class="vide">' + messageVide + '</p>';
    document.getElementById('listeCompacte').innerHTML = '<p class="vide">' + messageVide + '</p>';
    return;
  }

  // Chaque jour est regroupe dans un conteneur ".jour-groupe" (titre +
  // ses rendez-vous) pour qu'a l'impression le navigateur garde le titre
  // colle a ses rendez-vous : un "avoid" sur le conteneur entier est bien
  // mieux respecte par les navigateurs qu'un "avoid" pose seulement sur
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
      '<span class="badge ' + classeBadge(r.person) + '">' + r.person + '</span>' +
      '<div class="details">' +
        '<div class="medecin">' + escapeHtml(r.doctor || 'Rendez-vous') + '</div>' +
        (r.department ? '<div class="departement">' + escapeHtml(r.department) + '</div>' : '') +
        (contact ? '<div class="contact">' + contact + '</div>' : '') +
        (r.notes ? '<div class="notes">' + escapeHtml(r.notes) + '</div>' : '') +
      '</div>' +
      '<button class="supprimer" data-id="' + r.id + '" aria-label="Supprimer">✕</button>' +
    '</div>';
  });
  if (dernierJour !== null) html += '</div>';
  document.getElementById('liste').innerHTML = html;
  genererGrilleCompacte(filtres);

  document.querySelectorAll('.rdv').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (e.target.classList.contains('supprimer')) return;
      ouvrirEnEdition(el.dataset.id);
    });
  });
  document.querySelectorAll('.supprimer').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!confirm('Supprimer ce rendez-vous ?')) return;
      appelApi('delete', { id: el.dataset.id }).then(charger).catch(function (err) {
        alert(err.message);
      });
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

// ---------------------------------------------------------------
// Grille compacte (mode d'impression "compact") : meme donnees que la
// liste detaillee, mais rendues sous forme de cartes (date en evidence,
// titre, departement, heure), sans regroupement par jour. Ce conteneur
// reste cache en toute circonstance sauf quand on imprime en mode
// compact (voir bouton "Imprimer (compact)" plus bas et la regle CSS
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

// ---------------------------------------------------------------
// Import de fichiers .ics
// ---------------------------------------------------------------

var evenementsIcsAImporter = [];

document.getElementById('btnImportIcs').addEventListener('click', function () {
  document.getElementById('fichierIcs').click();
});

document.getElementById('fichierIcs').addEventListener('change', function (e) {
  var fichier = e.target.files[0];
  if (!fichier) return;
  var lecteur = new FileReader();
  lecteur.onload = function (evt) {
    try {
      evenementsIcsAImporter = parserIcs(evt.target.result);
      afficherPreviewIcs();
    } catch (err) {
      document.getElementById('erreurIcs').textContent =
        'Impossible de lire ce fichier .ics : ' + err.message;
    }
  };
  lecteur.readAsText(fichier);
  e.target.value = '';
});

function parserIcs(texte) {
  var lignesBrutes = texte.split(/\r\n|\n|\r/);
  var lignes = [];
  lignesBrutes.forEach(function (l) {
    if ((l.charAt(0) === ' ' || l.charAt(0) === '\t') && lignes.length > 0) {
      lignes[lignes.length - 1] += l.slice(1);
    } else {
      lignes.push(l);
    }
  });

  var evenements = [];
  var courant = null;

  lignes.forEach(function (ligne) {
    if (ligne === 'BEGIN:VEVENT') {
      courant = {};
      return;
    }
    if (ligne === 'END:VEVENT') {
      if (courant && courant.dtstart) evenements.push(convertirEvenement_(courant));
      courant = null;
      return;
    }
    if (!courant) return;

    var sep = ligne.indexOf(':');
    if (sep === -1) return;
    var cle = ligne.slice(0, sep);
    var valeur = ligne.slice(sep + 1);
    var cleBase = cle.split(';')[0];

    if (cleBase === 'SUMMARY') courant.summary = valeur.replace(/\\,/g, ',').replace(/\\n/gi, ' ');
    if (cleBase === 'LOCATION') courant.location = valeur.replace(/\\,/g, ',');
    if (cleBase === 'DESCRIPTION') courant.description = valeur.replace(/\\,/g, ',').replace(/\\n/gi, ' ');
    if (cleBase === 'DTSTART') {
      courant.dtstart = valeur;
      courant.dtstartToutelaJournee = cle.indexOf('VALUE=DATE') !== -1 && cle.indexOf('DATE-TIME') === -1;
      courant.dtstartUtc = ligne.indexOf('Z', sep) !== -1 && valeur.slice(-1) === 'Z';
    }
  });

  return evenements;
}

function convertirEvenement_(e) {
  var v = e.dtstart;
  var toutelaJournee = e.dtstartToutelaJournee || v.length <= 8;
  var annee = v.slice(0, 4), mois = v.slice(4, 6), jour = v.slice(6, 8);
  var date = annee + '-' + mois + '-' + jour;
  var heure = '09:00';

  if (!toutelaJournee && v.length >= 15) {
    var h = v.slice(9, 11), m = v.slice(11, 13), s = v.slice(13, 15);
    if (e.dtstartUtc) {
      var dUtc = new Date(Date.UTC(+annee, +mois - 1, +jour, +h, +m, +s || 0));
      date = dUtc.getFullYear() + '-' + pad2_(dUtc.getMonth() + 1) + '-' + pad2_(dUtc.getDate());
      heure = pad2_(dUtc.getHours()) + ':' + pad2_(dUtc.getMinutes());
    } else {
      heure = h + ':' + m;
    }
  }

  return {
    summary: e.summary || 'Rendez-vous',
    location: e.location || '',
    description: e.description || '',
    date: date,
    time: heure,
    toutelaJournee: toutelaJournee
  };
}

function pad2_(n) { return (n < 10 ? '0' : '') + n; }

function afficherPreviewIcs() {
  document.getElementById('erreurIcs').textContent = '';
  var conteneur = document.getElementById('listeIcs');

  if (evenementsIcsAImporter.length === 0) {
    conteneur.innerHTML = '<p class="vide">Aucun rendez-vous trouve dans ce fichier.</p>';
  } else {
    conteneur.innerHTML = evenementsIcsAImporter.map(function (e, i) {
      return '<div class="ics-ligne">' +
        '<input type="checkbox" checked data-idx="' + i + '" class="ics-check">' +
        '<div class="ics-details">' +
          '<div class="ics-titre">' + escapeHtml(e.summary) + '</div>' +
          (e.location ? '<div class="ics-adresse">' + escapeHtml(e.location) + '</div>' : '') +
          '<div class="ics-date">' + e.date + ' a ' + e.time + (e.toutelaJournee ? ' (toute la journee, heure a verifier)' : '') + '</div>' +
        '</div>' +
        '<select class="ics-personne" data-idx="' + i + '">' +
          '<option value="' + escapeHtml(window.PERSONNE_1) + '">' + escapeHtml(window.PERSONNE_1) + '</option>' +
          '<option value="' + escapeHtml(window.PERSONNE_2) + '">' + escapeHtml(window.PERSONNE_2) + '</option>' +
        '</select>' +
      '</div>';
    }).join('');
  }

  ouvrirModal('icsCard');
}

document.getElementById('btnAnnulerIcs').addEventListener('click', function () {
  fermerModal('icsCard');
  evenementsIcsAImporter = [];
});

document.getElementById('btnImporterSelection').addEventListener('click', function () {
  var aImporter = [];
  document.querySelectorAll('.ics-check:checked').forEach(function (c) {
    var idx = +c.dataset.idx;
    var e = evenementsIcsAImporter[idx];
    var select = document.querySelector('.ics-personne[data-idx="' + idx + '"]');
    aImporter.push({
      date: e.date,
      time: e.time,
      person: select.value,
      doctor: e.summary,
      location: e.location || '',
      notes: e.description || ''
    });
  });

  if (aImporter.length === 0) {
    document.getElementById('erreurIcs').textContent = 'Selectionnez au moins un rendez-vous.';
    return;
  }

  var btn = document.getElementById('btnImporterSelection');
  btn.disabled = true;
  btn.textContent = 'Import en cours…';

  appelApi('bulk_add', { appointments: aImporter })
    .then(function () {
      btn.disabled = false;
      btn.textContent = 'Importer la selection';
      fermerModal('icsCard');
      evenementsIcsAImporter = [];
      charger();
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Importer la selection';
      document.getElementById('erreurIcs').textContent = err.message;
    });
});

charger();