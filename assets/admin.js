// ---------------------------------------------------------------
// JS de la page d'administration (admin_nettoyage.php) : uniquement
// l'import de fichiers .ics (le reste des outils de cette page est
// gere cote serveur via de simples formulaires PHP, pas besoin de JS).
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
  fermerModal('icsCard');
});

function escapeHtml(s) {
  var div = document.createElement('div');
  div.textContent = s || '';
  return div.innerHTML;
}

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
    .then(function (data) {
      fermerModal('icsCard');
      var nb = aImporter.length;
      var conteneur = document.querySelector('.outil');
      var p = document.createElement('p');
      p.className = 'info';
      p.textContent = nb + ' rendez-vous importe(s) avec succes. Retournez a l\'agenda pour les consulter.';
      conteneur.appendChild(p);
      evenementsIcsAImporter = [];
      btn.disabled = false;
      btn.textContent = 'Importer la selection';
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Importer la selection';
      document.getElementById('erreurIcs').textContent = err.message;
    });
});