# Journal des versions

## v2.0.0 — 2026-07-15

- **Réorganisation des fichiers en sous-dossiers thématiques (changement de
  structure).** Les pages admin, les scripts Cron et les outils
  d'installation/maintenance à usage ponctuel étaient jusqu'ici tous mêlés
  à la racine du site avec les pages publiques — plus difficile à s'y
  retrouver à mesure que le projet grossit. Nouvelle organisation :
  - `admin/` : `login.php`, `logout.php`, `nettoyage.php`, `reglages.php`
    (anciennement `admin_login.php`, `admin_logout.php`,
    `admin_nettoyage.php`, `admin_reglages.php`) ;
  - `cron/` : `backup.php`, `rappels.php` — les deux scripts destinés à
    être appelés périodiquement par un Cron Job Hostinger ;
  - `outils/` : `migrate.php`, `generate_password.php`,
    `import_calendar.php` — outils d'installation ou de maintenance
    ponctuelle.
  Restent à la racine (pages publiques ou fichiers partagés par tous les
  environnements) : `index.php`, `login.php`, `logout.php`, `api.php`,
  `mes_rappels.php`, `config.php`.
- Tous les liens, redirections et références aux assets (CSS, JS) ont été
  convertis en chemins absolus (`/assets/...`, `/admin/...`, etc.) plutôt
  que relatifs, pour fonctionner correctement quelle que soit la
  profondeur du dossier où se trouve la page. `assets/admin.js` a été
  corrigé dans la foulée (l'appel à `api.php` utilisait un chemin relatif
  qui se serait mal résolu une fois `nettoyage.php` déplacé dans `admin/`).
  Tous les fichiers déplacés ont été revérifiés syntaxiquement.
  **Changement cassant** : si vous mettez à jour un site déjà installé,
  voir la section "Mise à jour depuis une version antérieure..." du
  `Guide_installation_hostinger.md` — il faut supprimer les anciens
  fichiers racine, mettre à jour les deux Cron Jobs (nouvelles URLs
  `cron/backup.php` et `cron/rappels.php`) et le favori vers la page
  d'administration (nouvelle adresse `admin/nettoyage.php`).

## v1.16.1 — 2026-07-15

- `mes_rappels.php` : ajout d'une case "Je souhaite recevoir un rappel
  pour mes rendez-vous", indépendante du champ email. Auparavant, la
  seule façon de désactiver ses propres rappels était d'effacer son
  adresse email — désormais on peut couper l'envoi tout en gardant
  l'adresse enregistrée (pratique pour la réactiver plus tard sans avoir
  à la retaper). Réglage `reminder_notify_self_person1`/`_person2`,
  activé par défaut pour ne pas couper les rappels de ceux qui avaient
  déjà renseigné leur adresse avant cette version.

## v1.16.0 — 2026-07-15

- **Rappels par email : préférences par personne, gérées par chacun.**
  Le champ admin unique "email des parents" est remplacé par une nouvelle
  page **`mes_rappels.php`**, accessible avec le mot de passe familial
  (pas besoin du mot de passe admin) et reliée par un lien "Rappels par
  email" en haut de l'agenda. Chaque parent y renseigne sa propre adresse
  email et peut cocher "Recevoir aussi les rappels des rendez-vous de
  [l'autre]" pour être prévenu des deux agendas plutôt que du sien
  seulement — chacun règle ça indépendamment, à tout moment. Chem reste
  destinataire fixe de tous les rappels (réglage inchangé dans
  `admin_reglages.php`, qui ne gère plus que les réglages techniques :
  activer/désactiver, délai, adresse d'expédition, adresse de Chem).
  `rappels.php` calcule maintenant les destinataires rendez-vous par
  rendez-vous plutôt qu'une liste unique pour tous les envois. Les anciens
  réglages ne sont pas perdus : `mes_rappels.php` les reprend comme valeur
  de départ la première fois qu'on l'ouvre.

## v1.15.0 — 2026-07-15

- **Rappels par email : envoi via SMTP authentifié (recommandé).** La
  fonction `mail()` native de PHP fonctionne mais atterrit très souvent en
  indésirables, même avec un domaine correctement configuré (SPF/DKIM),
  car l'email n'est pas authentifié comme venant réellement de la boîte
  d'expédition — c'est un comportement documenté par Hostinger, qui
  recommande officiellement de passer par SMTP. `lib/mailer.php` propose
  maintenant les deux : si un serveur SMTP est renseigné dans `config.php`
  (`smtp_host`, `smtp_port`, `smtp_securite`, `smtp_utilisateur`,
  `smtp_mot_de_passe`), les rappels sont envoyés via une connexion SMTP
  authentifiée à une vraie boîte mail (nettement plus fiable) ; sinon,
  repli automatique sur `mail()` comme avant. Le client SMTP est écrit à
  la main (aucune dépendance externe, même principe que la synchro Google
  Calendar). `admin_reglages.php` affiche désormais laquelle des deux
  méthodes est active. Voir `Guide_installation_hostinger.md`, section
  "Rappels par email", pour la marche à suivre.

## v1.14.1 — 2026-07-15

- Rappels par email : le bouton "Envoyer un email de test" affichait un
  message générique en cas d'échec ("L'envoi a échoué"), impossible à
  diagnostiquer. `lib/mailer.php` capture maintenant le message
  d'avertissement PHP réel émis par `mail()` et l'affiche directement sur
  la page de réglages (`admin_reglages.php`).

## v1.14.0 — 2026-07-15

- **Rappels par email.** Un email peut désormais être envoyé avant chaque
  rendez-vous à venir, à vous (Chem) et/ou à vos parents. Réglable
  entièrement depuis une nouvelle page d'administration
  `admin_reglages.php` (lien "Réglages" dans `admin_nettoyage.php`) : 
  activer/désactiver, délai unique en heures avant le rendez-vous (le
  même délai s'applique à tous les rendez-vous), adresses email des
  destinataires, adresse d'expédition, et un bouton "Envoyer un email de
  test" pour vérifier que ça fonctionne avant de compter dessus.
  L'envoi effectif est fait par un nouveau script `rappels.php`, protégé
  par jeton (`reminder_token` dans `config.php`, même principe que
  `backup.php`) et déclenché périodiquement par un Cron Job Hostinger
  (voir le guide d'installation). Chaque rendez-vous ne reçoit qu'un
  seul rappel (colonne `reminder_sent_at`), y compris si `rappels.php`
  est appelé très souvent ; le rappel est automatiquement réarmé si la
  date ou l'heure du rendez-vous est modifiée par la suite. Nouvelles
  tables/colonnes : `settings` (réglages génériques clé/valeur,
  réutilisable pour de futurs réglages sans toucher à `config.php`) et
  `appointments.reminder_sent_at` (migrations 0007 et 0008).

## v1.13.2 — 2026-07-15

- Sur telephone (largeur d'ecran <= 640px), le badge de la personne dans
  la liste n'affiche que les 4 premieres lettres du prenom (ex. "Mich"
  au lieu de "Michel") pour gagner de la place. Le nom complet reste
  affiche sur les ecrans plus larges (tablette, ordinateur) et a
  l'impression. Se met a jour automatiquement si on tourne l'ecran.

## v1.13.1 — 2026-07-15

- Le bouton de suppression d'un rendez-vous n'est plus sur chaque ligne
  de la liste principale (action rare, trop exposée) : il est maintenant
  en bas du formulaire d'édition ("Supprimer ce rendez-vous"), visible
  uniquement quand on modifie un rendez-vous existant (pas à la création).

## v1.13.0 — 2026-07-15

- Correction des accents manquants dans tous les textes affichés du site
  (pages, boutons, messages d'erreur, titres) : "Agenda medical" →
  "Agenda médical", "Deconnexion" → "Déconnexion", "Charger les
  evenements de cette periode" → "Charger les événements de cette
  période", etc. Concerne `index.php`, `login.php`, `admin_login.php`,
  `generate_password.php`, `migrate.php`, `import_calendar.php`,
  `admin_nettoyage.php`, `backup.php`, `api.php`, `assets/app.js` et
  `assets/admin.js`. Les commentaires de code ont aussi été corrigés au
  passage.

## v1.12.0 — 2026-07-15

- **Deuxieme mot de passe pour l'administration.** `admin_nettoyage.php`
  (et toute la zone d'administration) demande desormais, en plus du mot
  de passe familial, un second mot de passe distinct
  (`admin_password_hash` dans `config.php`, nouvelles pages
  `admin_login.php` / `admin_logout.php`). Le lien vers cette page a ete
  retire de l'agenda principal : elle n'est plus accessible qu'en
  connaissant directement son adresse.
- **Import .ics deplace dans l'administration.** Le bouton "Importer un
  fichier .ics" et sa fenetre de previsualisation ne sont plus sur la
  page principale (`index.php`) : ils vivent maintenant dans
  `admin_nettoyage.php`, avec le reste des outils de maintenance. Le
  code JS correspondant est passe de `assets/app.js` a un nouveau
  fichier `assets/admin.js`.
- **Sauvegardes automatiques et restauration.** Nouveau script
  `backup.php`, destine a etre appele chaque jour par un Cron Job
  Hostinger (voir le guide d'installation) : il exporte tous les
  rendez-vous dans un fichier JSON horodate (dossier `backups/`,
  proteges par leur propre `.htaccess`, conserves 60 jours). Nouvelle
  section "Sauvegardes" dans `admin_nettoyage.php` : choisissez une
  sauvegarde, les rendez-vous qui y figurent mais qui ont disparu de
  l'agenda actuel (ex : suppression accidentelle) sont proposes a la
  restauration, avec recreation de l'evenement Google Calendar si la
  synchro est active.
- Migration/config : nouvelles cles `admin_password_hash` et
  `backup_token` dans `config.php` (voir `config.example.php` et le
  guide d'installation, section "Protéger les outils d'administration"
  et "Sauvegardes automatiques").

## v1.11.3 — 2026-07-15

- Impression compacte : les adresses tres longues sont maintenant limitees
  a 2 lignes avec "..." au lieu de s'etaler et de trop agrandir la carte.

## v1.11.2 — 2026-07-15

- L'impression compacte (mode grille) affiche desormais aussi l'adresse
  du rendez-vous, sous le departement.

## v1.11.1 — 2026-07-15

- Correctif : le champ Telephone n'heritait pas du style des autres champs
  (police/taille par defaut du navigateur), ce qui le faisait paraitre
  plus petit que le champ Route juste a cote. Harmonise.

## v1.11.0 — 2026-07-15

- Nouveau champ **Route** (facultatif) : le circuit / numero interne
  utilise par certains hopitaux (ex. "Route 555"), separe du reste du
  texte. Affiche avec l'adresse et le telephone sur la meme ligne
  ("Adresse · Telephone · Route") dans la liste, l'impression detaillee
  et l'impression compacte.
- Vers Google Calendar : la route part dans la description, sur sa
  propre ligne.
- `admin_nettoyage.php` : l'outil d'extraction automatique du telephone
  detecte desormais aussi la route quand elle est presente ("Le lieu du
  rendez-vous : Route NNN Tel : ..."), et la range dans le nouveau champ
  au lieu de la perdre. Detecte egalement une mention "Route NNN" isolee
  (sans telephone a cote). L'outil generique "Retirer un texte" propose
  aussi desormais "Route" comme destination possible.
- Migration associee : `migrations/0006_add_route.sql`.


## v1.10.0 — 2026-07-15

- `admin_nettoyage.php` : nouvel outil **"Raccourcir les noms complets"**.
  Detecte automatiquement "pour Michel Louis" / "pour Christiane Monique"
  (prenom configure + nom de famille colle par certains imports) dans le
  champ "Medecin / consultation" et le raccourcit en "pour Michel" /
  "pour Christiane" — la personne est de toute facon deja indiquee par le
  badge colore, inutile de repeter le nom complet dans le titre.
  Fonctionne sans rien taper (se base sur `personne_1`/`personne_2` dans
  `config.php`), avec apercu avant application comme les autres outils.


## v1.9.0 — 2026-07-15

- Nouveaux onglets **A venir / Passes / Tous** (sous les onglets
  Michel/Christiane) : filtre les rendez-vous par periode, a l'ecran
  comme a l'impression. "A venir" est selectionne par defaut. Le titre
  imprime indique maintenant les deux filtres actifs (ex. "Christiane —
  Passes").
- Nouveau bouton **"Imprimer (compact)"** a cote du bouton "Imprimer"
  existant : imprime une grille de petites cartes (date en evidence,
  titre, departement, heure) plutot que la liste detaillee — tient
  beaucoup plus de rendez-vous par page. Le bouton "Imprimer" normal
  continue de produire la vue detaillee habituelle.


## v1.8.7 — 2026-07-15

- Correctif : le `break-after: avoid` du v1.8.6 (titre de journee colle au
  rendez-vous suivant) n'etait pas respecte de maniere fiable par les
  navigateurs — le titre pouvait quand meme rester seul en bas de page.
  Chaque jour (titre + tous ses rendez-vous) est desormais regroupe dans
  un seul conteneur, avec un `break-inside: avoid` sur ce conteneur
  entier : cette approche est beaucoup mieux respectee a l'impression. Si
  un jour a vraiment trop de rendez-vous pour tenir sur une page, il
  passera entierement a la page suivante plutot que d'etre coupe au
  milieu.


## v1.8.6 — 2026-07-15

- Correctif d'impression : un titre de journee ("JEUDI 5 NOVEMBRE 2026")
  pouvait se retrouver seul en bas d'une page, separe des rendez-vous de
  ce jour qui commencaient sur la page suivante (sans plus savoir de
  quel jour il s'agissait). Le titre reste desormais toujours colle au
  rendez-vous qui le suit ; s'ils ne tiennent pas ensemble sur la page en
  cours, les deux passent a la page suivante. Renforce aussi la regle qui
  empeche un rendez-vous d'etre coupe en deux entre deux pages (ajout de
  la propriete equivalente `page-break-inside` pour une meilleure
  compatibilite entre navigateurs).


## v1.8.5 — 2026-07-15

- Correctif : `min-width` ne fixe qu'un plancher, pas une largeur fixe —
  "Christiane" (plus long) continuait a etre plus large que "Michel"
  malgre le correctif precedent, donc les colonnes restaient decalees.
  L'etiquette a maintenant une vraie largeur fixe (`width: 13ch`, qui
  s'adapte a la taille de police a l'ecran comme a l'impression), avec
  troncature "..." en secours pour des noms tres longs.


## v1.8.4 — 2026-07-15

- Correctif : la largeur fixe de l'etiquette "Michel"/"Christiane" ajoutee
  en v1.8.3 ne s'appliquait pas (un `<span>` est un element "inline", qui
  ignore `min-width` par defaut). Ajout de `display: inline-block` pour
  que la largeur fixe fonctionne reellement, y compris a l'impression.


## v1.8.3 — 2026-07-15

- L'etiquette "Michel"/"Christiane" a maintenant une largeur fixe (au lieu
  de s'ajuster a la longueur du nom) : le medecin, le departement,
  l'adresse et les notes commencent desormais tous a la meme position,
  sur toutes les lignes, a l'ecran comme a l'impression.


## v1.8.2 — 2026-07-15

- Retrait de la ligne "Imprime le [date]" en haut de la page imprimee
  (ne restent que le titre "Rendez-vous medicaux — [filtre]").
- Note : le bandeau gris avec le titre de la page et l'URL (ex.
  "Agenda medical" / "http://.../index.php") vient du navigateur
  lui-meme (en-tetes/pieds de page d'impression), pas du site — ca se
  desactive dans la boite de dialogue d'impression du navigateur (ex.
  Chrome : "Plus de parametres" -> decocher "En-tetes et pieds de
  page"), et ce reglage est ensuite memorise par le navigateur.


## v1.8.1 — 2026-07-15

- Date et Heure sont maintenant cote a cote dans le formulaire de
  rendez-vous (au lieu de deux lignes empilees) : gagne de la place
  verticale, moins besoin de defiler pour voir le reste du formulaire.


## v1.8.0 — 2026-07-15

- Nouveau champ **Telephone** (facultatif), separe du reste du texte,
  affiche avec l'adresse sur une seule ligne "Adresse · Telephone" dans
  la liste et l'impression (pour gagner de la place).
- Vers Google Calendar : le telephone part dans la description (pas de
  champ natif "telephone" dans Calendar, contrairement au lieu), sur sa
  propre ligne ("Tel : ...").
- `admin_nettoyage.php` : outil generalise, avec deux sections.
  - "Extraction automatique du telephone" : detecte tout seul les
    numeros de telephone colles au texte, y compris le format complet
    "Le lieu du rendez-vous : Route NNN Tel.: NN NNN NN NN" utilise par
    certains hopitaux (retire toute la mention, y compris "Route NNN",
    et ne garde que le numero de telephone).
  - "Retirer un texte" (ex-outil d'extraction d'adresse) : on choisit
    maintenant ou ranger le texte trouve — champ Adresse, champ
    Telephone, ou nulle part si c'est juste une mention a supprimer.
- Impression corrigee et resserree :
  - Correctif important : les notes longues etaient tronquees avec des
    "..." a l'impression (la regle qui coupe le texte dans la liste a
    l'ecran s'appliquait aussi au papier). Le texte est desormais
    complet et lisible a l'impression.
  - Mise en page resserree (marges de page, espacements, tailles de
    police) pour tenir davantage de rendez-vous par page.
- Migration associee : `migrations/0005_add_phone.sql`.


## v1.7.2 — 2026-07-15

- Correctif d'affichage : dans le formulaire de rendez-vous, les boutons
  "Enregistrer" / "Annuler" restent desormais toujours visibles en bas du
  modal (au lieu de defiler avec les champs). Seul le contenu du
  formulaire defile si besoin ; les boutons sont fixes, separes par une
  ligne de separation. Meme correction sur la fenetre d'import .ics.


## v1.7.1 — 2026-07-15

- Le champ Notes du formulaire devient une zone de texte multi-lignes
  (au lieu d'une seule ligne) : plus lisible pour les notes longues
  (ex. adresses ou instructions collees depuis un email de convocation).


## v1.7.0 — 2026-07-15

- Nouvel outil d'administration `admin_nettoyage.php` (protege par le meme
  mot de passe familial) pour corriger en masse les rendez-vous deja
  enregistres dont le champ "Medecin / consultation" ou "Notes" contient
  encore une adresse collee au reste du texte (heritage des imports
  d'avant la v1.6.0).
- Fonctionnement : on colle le texte exact de l'adresse (un par ligne),
  le site cherche ce texte dans tous les rendez-vous, affiche un apercu
  avec le passage surligne, et sur confirmation retire le texte du champ
  ou il se trouvait, nettoie la ponctuation residuelle (tirets, virgules,
  espaces en trop) et le range dans le champ Adresse (sans ecraser un
  champ Adresse deja rempli). Une liste de suggestions (textes contenant
  un code postal) aide a reperer quoi copier-coller.
- Les rendez-vous deja synchronises avec Google Calendar sont mis a jour
  automatiquement (l'adresse part dans le champ Lieu natif).
- Lien discret vers cet outil ajoute en bas de la page principale (masque
  a l'impression). Contrairement a `import_calendar.php`, cet outil n'est
  pas a usage unique et peut rester sur le serveur.


## v1.6.0 — 2026-07-15

- Ajout d'un champ **Adresse** (facultatif), separe du champ "Medecin /
  consultation" — corrige les rendez-vous importes (.ics ou Google
  Calendar) ou l'adresse de l'hopital se retrouvait concatenee au nom du
  medecin (ex. "Dr Martin — Avenue Hippocrate, 10, 1200 Bruxelles").
- Affichee dans la liste entre le departement et les notes.
- Envoyee vers Google Calendar dans le champ **Lieu** natif de l'evenement
  (et non plus dans la description) : la description continue de
  contenir uniquement `departement\nnotes`.
- L'import de fichiers .ics et l'import ponctuel (`import_calendar.php`)
  remplissent desormais automatiquement ce champ separement, au lieu de
  le coller au nom du medecin.
- Migration associee : `migrations/0004_add_location.sql`.


## v1.5.0 — 2026-07-14

- Suppression de l'option "Les deux" : un rendez-vous medical ne concerne
  desormais jamais qu'une seule personne (Papa ou Maman). L'onglet "Tous"
  reste disponible pour voir les rendez-vous des deux en meme temps.
- `config.php` n'a plus que `personne_1` et `personne_2` (la cle
  `personne_les_deux` est retiree).
- Les anciens rendez-vous enregistres avec "Les deux" restent visibles
  dans l'onglet "Tous" mais ne seront plus proposes a la creation ; les
  rouvrir en edition demande de choisir explicitement Papa ou Maman.


## v1.4.0 — 2026-07-14

- Refonte visuelle complete : palette de couleurs affinee, ombres et
  transitions douces, cartes de rendez-vous animees a l'apparition,
  squelette de chargement anime.
- Le formulaire d'ajout et l'import .ics deviennent des modals : dialogue
  centre sur ordinateur, "bottom sheet" glissant depuis le bas sur mobile.
- En-tete (titre + onglets) collant en haut de page pendant le defilement.
- Bouton d'ajout flottant (FAB) sur smartphone.
- Verrouillage du defilement de la page pendant qu'un modal est ouvert.
- La vue impression reste inchangee (modals/FAB masques a l'impression).


## v1.3.0 — 2026-07-14

- Les noms "Papa"/"Maman"/"Les deux" sont desormais configurables via
  `config.php` (`personne_1`, `personne_2`, `personne_les_deux`) au lieu
  d'etre codes en dur dans 5 fichiers differents.
- Le prefixe dans Google Calendar devient generique (`[Nom] `), construit
  directement a partir de la personne — fonctionne avec n'importe quel nom
  sans modification de code.
- `import_calendar.php` reconnait desormais n'importe quel prefixe
  `[Quelque chose]` dans les titres (au lieu de seulement Papa/Maman).
- Migration `0003_widen_person.sql` : la colonne `person` passe de
  VARCHAR(20) a VARCHAR(50) pour supporter des noms plus longs.
- Voir le guide Hostinger, section "Remplacer Papa et Maman par d'autres
  noms", pour la procedure complete (y compris la mise a jour des
  rendez-vous deja enregistres).


## v1.2.0 — 2026-07-14

- Ajout du champ **Département** (facultatif), affiché avant les notes dans
  la liste et dans le formulaire.
- Dans Google Calendar, la description devient `département\nnotes` (saut
  de ligne) quand un département est renseigné.
- Migration associée : `migrations/0002_add_department.sql`.


## v1.1.0 — 2026-07-14

- Ajout de `import_calendar.php` : import ponctuel des rendez-vous déjà
  présents dans le Google Calendar existant (aperçu, choix Papa/Maman/Les
  deux par ligne, liaison avec `calendar_event_id` pour éviter les doublons
  si l'import est relancé ou si l'évènement est modifié ensuite).
- Ajout de `CalendarSync::listEvents()` dans `lib/calendar_sync.php`.


## v1.0.1 — 2026-07-14

- Correctif : `migrate.php` plantait avec "There is no active transaction" sur
  MySQL/MariaDB. En cause : un `CREATE TABLE` declenche un commit implicite
  cote serveur, donc le `commit()` PDO explicite qui suivait echouait. Les
  migrations ne sont plus enveloppees dans une transaction PDO.


Format : chaque version correspond a un tag Git (`v1.0.0`, `v1.1.0`, ...).
Quand une version change le schema de la base, un fichier est ajoute dans
`migrations/` et doit etre execute via `migrate.php` (voir le guide).

## v1.0.0 — 2026-07-14

Version initiale.

- Connexion par mot de passe familial partage
- Liste des rendez-vous avec onglets Papa / Maman / Tous
- Ajout, modification, suppression de rendez-vous
- Import de fichiers .ics
- Synchronisation a sens unique vers Google Calendar (compte de service, facultatif)
- Impression d'une vue filtree et lisible

Migration associee : `migrations/0001_init.sql` (table `appointments`).
