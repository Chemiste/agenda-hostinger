# Installer l'agenda médical sur votre hébergement Hostinger

J'ai choisi **PHP + MySQL** : c'est la seule techno garantie de fonctionner sur absolument tous les plans Hostinger (même le moins cher), sans terminal ni installation particulière — tout se fait depuis hPanel. Si vous avez un plan qui supporte Node.js, on pourra migrer plus tard, mais ce n'est pas nécessaire.

Le site utilise :
- un **sous-domaine** (ex : `agenda.hellau.be`)
- une **base de données MySQL** pour stocker les rendez-vous
- **une connexion par compte Google** pour chaque membre de la famille
- en option, une **synchronisation vers Google Calendar** (le calendrier que vous avez déjà créé)

## Étape 1 — Créer le sous-domaine

1. Dans hPanel, allez dans **Domaines > Sous-domaines**.
2. Créez `agenda` sur votre domaine `hellau.be` → cela donnera `agenda.hellau.be`.
3. Notez le dossier associé (souvent `public_html/agenda.hellau.be` ou similaire).
4. Dans **Sites web > SSL**, activez le certificat SSL gratuit pour ce sous-domaine (pour avoir `https://`).

## Étape 2 — Créer la base de données

1. Dans hPanel, allez dans **Bases de données > Bases de données MySQL**.
2. Créez une nouvelle base (notez son nom, un utilisateur et un mot de passe — Hostinger les préfixe souvent par votre identifiant, ex : `u123456789_agenda`).

La table `appointments` sera créée à l'étape 5 via le script `outils/migrate.php`, pas manuellement dans phpMyAdmin — ça garde la base cohérente avec le code à chaque mise à jour (voir `Guide_dev_local_et_versions.md`).

## Étape 3 — Envoyer les fichiers du site

1. Dans hPanel, ouvrez le **Gestionnaire de fichiers** (ou utilisez FTP/FileZilla si vous préférez).
2. Allez dans le dossier du sous-domaine créé à l'étape 1.
3. Envoyez-y **tout le contenu** du dossier `agenda-hostinger` fourni, **en conservant exactement la structure de dossiers** : à la racine `index.php`, `login.php`, `logout.php`, `api.php`, `mes_rappels.php`, `config.example.php`, `.htaccess`, ainsi que les dossiers `migrations/`, `lib/` (avec son propre `.htaccess`), `assets/`, `backups/` (avec son propre `.htaccess`), et `installer.php` à la racine, et les trois dossiers d'outils : `admin/` (index, personnes, import, corriger, sauvegardes, reglages), `cron/` (backup, rappels) et `outils/` (migrate, import_calendar).
4. Ne renvoyez pas votre `config.php` local : créez-en un directement sur le serveur (étape suivante). Chaque environnement a le sien.

## Étape 4 — Configurer `config.php` sur le serveur

1. Dans le Gestionnaire de fichiers, dupliquez `config.example.php`, renommez la copie `config.php`.
2. Modifiez `config.php` et remplacez :
   - `db_host`, `db_name`, `db_user`, `db_pass` par les informations de la base créée à l'étape 2
   - laissez `google_client_id`, `installation_token` et les autres lignes Google pour l'instant

Enregistrez.

## Étape 5 — Définir le jeton d'installation

Il n'y a **aucun mot de passe** sur ce site : tout le monde entre avec son compte Google, y compris pour administrer. Reste à résoudre le tout premier accès — sur une base vide, personne n'est encore inscrit, donc personne ne peut se connecter pour inscrire qui que ce soit.

C'est le rôle de `installer.php`, et du jeton qui l'autorise.

1. Dans `config.php`, remplissez `installation_token` avec une chaîne aléatoire longue (par exemple le résultat de `openssl rand -hex 20`, ou n'importe quel générateur de mot de passe).
2. Gardez-la sous la main : vous la saisirez une fois, à l'étape 7.

> **Pourquoi un jeton et pas rien du tout.** Entre le moment où le site répond et celui où vous lancez l'installation, il y a une fenêtre pendant laquelle le premier venu qui trouve l'adresse deviendrait administrateur — et repartirait avec le dossier médical de vos parents. Le jeton ferme cette fenêtre, sans rien ajouter à votre travail puisque vous êtes déjà dans `config.php`.
>
> Dès qu'un administrateur existe, `installer.php` refuse de tourner. Vous pouvez laisser le jeton dans `config.php`, il n'a plus aucun effet.

## Étape 5 bis — Autoriser la connexion par compte Google

Chaque membre de la famille entre avec son compte Google. Aucun mot de passe à retenir, et surtout : le site n'a plus à croire sur parole qui prétend être qui.

Tout se passe dans la section **Google Auth Platform** de la console Google Cloud. Attention : ce n'est plus sous « APIs et services → Écran de consentement OAuth », Google a réorganisé ces pages. Les liens directs ci-dessous évitent de chercher dans les menus.

1. **Identité de l'application** — [console.cloud.google.com/auth/branding](https://console.developers.google.com/auth/branding)
   Sélectionnez votre projet (le même que pour la synchronisation Calendar, si vous l'avez déjà créée), puis remplissez le nom de l'application, votre adresse de contact et le domaine autorisé (`hellau.be`). C'est ce nom que verront Michel et Christiane sur l'écran de Google : mettez quelque chose qu'ils reconnaissent, par exemple « Agenda médical ».

2. **Type d'utilisateurs** — [console.cloud.google.com/auth/audience](https://console.developers.google.com/auth/audience)
   Choisissez **Externe**. « Interne » n'existe que pour les organisations Google Workspace et n'est de toute façon pas proposé avec un compte Gmail personnel.

   **Ne remplissez pas la liste d'utilisateurs de test, elle est inutile ici.** Google prévoit une exception documentée : une application qui utilise Sign in with Google et ne demande que le nom, l'adresse et le profil (`openid`, `email`, `profile` — notre cas) n'a pas besoin d'utilisateurs de test. Personne ne voit d'écran « application non vérifiée » et aucune autorisation n'expire au bout de sept jours. Le mode **Test** peut donc rester en place indéfiniment.

   > **Le contrôle d'accès est entièrement de votre côté.** Google laissera n'importe quel compte arriver jusqu'à la page de connexion du site ; c'est le champ adresse de `/admin/personnes.php` qui accepte ou refuse. Un inconnu qui trouverait l'adresse du site verrait le bouton, se connecterait à son compte, et serait renvoyé avec « Ce compte Google n'a pas accès à l'agenda ».

3. **Les données demandées** — [console.cloud.google.com/auth/scopes](https://console.developers.google.com/auth/scopes)
   Rien à ajouter. Les autorisations par défaut (`email`, `profile`, `openid`) suffisent : le site a seulement besoin de savoir qui vous êtes, il ne lit ni vos mails ni vos contacts.

4. **Créer l'identifiant** — [console.cloud.google.com/auth/clients](https://console.developers.google.com/auth/clients)
   Cliquez sur **Créer un client**, type d'application **Application Web**. Dans **Origines JavaScript autorisées**, ajoutez :
   - `https://agenda.hellau.be`
   - `http://localhost` **et** `http://localhost:8081` si vous développez en local (Google demande les deux)

   N'ajoutez **aucune** URI de redirection : le site utilise le mode « callback JavaScript », qui n'en a pas besoin. Une origine, c'est le protocole et le nom d'hôte, sans chemin ni barre oblique finale.

5. Copiez l'**ID client** (il se termine par `.apps.googleusercontent.com`) dans `config.php`, champ `google_client_id`.

> **Ce n'est pas le compte de service de Calendar.** Les deux vivent dans le même projet mais ne sont pas interchangeables : le compte de service permet au *site* de parler à Google, l'ID client permet aux *personnes* de prouver qui elles sont.

## Étape 6 — Installer

Ouvrez `https://agenda.hellau.be/installer.php`.

1. Saisissez le **jeton d'installation** de l'étape 5. Les tables sont créées dans la foulée (toutes les migrations sont appliquées).
2. Cliquez sur **Se connecter avec Google** et choisissez **votre** compte. Il devient le premier administrateur.

Vous êtes ensuite redirigé vers la page de connexion habituelle.

## Étape 7 — Créer les autres personnes

Connectez-vous, puis ouvrez **Administration → Les personnes** (`/admin/personnes.php`) et ajoutez chaque membre de la famille :

- **Patient** — on suit sa santé : elle a un onglet dans l'agenda, un plan de médicaments, des pathologies, un carnet de médecins.
- **Se connecte** — elle est autorisée à entrer sur le site, avec l'adresse Google indiquée en face.
- **Administre** — elle peut modifier les pathologies et le plan de médicaments, et atteindre l'administration.

Renseignez l'**adresse du compte Google** de chaque personne qui se connecte. Sans elle, personne ne peut entrer : se connecter avec un compte Google valide ne donne aucun droit tant que l'adresse n'a pas été inscrite ici.

La colonne « Compte Google » affiche « en attente de 1re connexion » jusqu'à ce que la personne se soit connectée au moins une fois. À ce moment-là, le site mémorise l'identifiant interne du compte (que Google ne réattribue jamais) et n'utilise plus l'adresse pour l'identifier — une adresse peut changer de propriétaire, cet identifiant non. Si vous corrigez l'adresse plus tard, le rattachement est défait et se refera à la connexion suivante.

## Étape 8 — Tester

1. Ouvrez `https://agenda.hellau.be`. Vous devez arriver sur l'écran de connexion.
2. Cliquez sur **Se connecter avec Google** et choisissez votre compte.
3. Ajoutez un rendez-vous test, vérifiez qu'il apparaît dans la liste.
4. Dans phpMyAdmin, vérifiez qu'une ligne est bien apparue dans la table `appointments`.
5. Supprimez le rendez-vous test.

À partir de maintenant, vous, Papa et Maman partagez le même lien — chacun entre avec son propre compte Google.

## Étape 9 (facultatif) — Synchronisation vers Google Calendar

Cette étape est plus technique ; elle n'est pas indispensable pour que le site fonctionne.

1. Allez sur [console.cloud.google.com](https://console.cloud.google.com), créez un nouveau projet (gratuit).
2. **APIs et services > Bibliothèque** : cherchez et activez **Google Calendar API**.
3. **APIs et services > Identifiants > Créer des identifiants > Compte de service**. Donnez-lui un nom (ex : `agenda-medical`), validez sans rôle particulier.
4. Ouvrez ce compte de service > onglet **Clés > Ajouter une clé > Créer une clé > JSON**. Un fichier `.json` se télécharge.
5. Renommez ce fichier `service-account.json` et envoyez-le sur votre hébergement, dans le même dossier que les autres fichiers du site (il est déjà protégé contre le téléchargement direct par le `.htaccess` fourni).
6. Ouvrez ce fichier JSON (avec un éditeur de texte) et repérez la valeur `client_email` — une adresse du type `agenda-medical@votre-projet.iam.gserviceaccount.com`.
7. Sur [calendar.google.com](https://calendar.google.com), dans les paramètres du calendrier de vos parents (survolez-le > trois points > **Paramètres et partage**), section **Partager avec des personnes**, ajoutez cette adresse avec la permission **Apporter des modifications aux événements**.
8. Toujours dans ces paramètres, section **Intégrer l'agenda**, copiez l'**ID de l'agenda**.
9. Dans `config.php`, remplissez `google_calendar_id` avec cet ID.
10. Testez : ajoutez un rendez-vous sur le site, vérifiez qu'il apparaît dans Google Calendar avec le préfixe `[Papa]`, `[Maman]` ou `[Papa & Maman]`.

C'est une synchronisation à sens unique (site → Calendar) : modifier un événement directement dans Google Calendar ne le modifiera pas sur le site.

## Étape 10 (facultatif) — Importer une fois les rendez-vous déjà dans Google Calendar

Si vous aviez déjà des rendez-vous dans le calendrier Google créé pour vos parents, vous pouvez les récupérer en une fois dans le site (nécessite d'avoir fait l'étape 9 juste avant, pour que le site puisse lire ce calendrier).

1. Ouvrez `https://agenda.hellau.be/outils/import_calendar.php`, connecté avec un compte marqué **Administre**.
2. Choisissez une période (du / au), cliquez sur **Charger les évènements de cette période**.
3. Une liste s'affiche : décochez ceux à ne pas importer, choisissez la bonne personne (Papa ou Maman) pour chaque ligne — un rendez-vous ne concerne jamais qu'une seule personne, même si le calendrier d'origine ne le précisait pas.
4. Cliquez sur **Importer la sélection**.

Chaque rendez-vous importé reste lié à son évènement Google Calendar d'origine : si vous le modifiez ensuite depuis le site, l'évènement existant sera mis à jour (pas de doublon créé). Relancer cet import par erreur ne duplique rien non plus — les évènements déjà importés sont automatiquement ignorés.

**Une fois l'import fait et vérifié, supprimez `outils/import_calendar.php` du serveur** : il n'a plus de raison de rester en ligne.

## Qui peut administrer

Le site a une zone d'administration (`admin/index.php`), organisée en trois groupes : **Rendez-vous** (import `.ics`, correction de rendez-vous existants), **Sauvegardes** (restauration) et **Notifications** (réglages des rappels).

Elle est réservée aux personnes portant le drapeau **Administre** dans `/admin/personnes.php`. Il n'y a pas de mot de passe supplémentaire : votre compte Google vous identifie déjà, et exiger un second secret n'aurait rien protégé de plus — modifier une pathologie, geste bien plus lourd de conséquences, ne demande de toute façon que ce même drapeau.

Un membre de la famille sans le drapeau qui tomberait sur l'adresse est renvoyé vers l'agenda.

Il n'y a pas de lien visible vers `admin/index.php` pour les autres : il n'apparaît dans le menu que pour les personnes qui administrent.

## Sauvegardes automatiques

En cas de suppression accidentelle d'un rendez-vous, une sauvegarde automatique quotidienne permet de le retrouver et de le restaurer depuis `admin/sauvegardes.php` (accessible aussi depuis la carte "Sauvegardes" de l'accueil admin).

1. Dans `config.php`, remplacez `backup_token` par une chaîne aléatoire longue (par exemple générée sur [1password.com/password-generator](https://1password.com/password-generator) ou similaire) — ce n'est pas un mot de passe à retenir, juste une clé secrète dans une URL.
2. Dans hPanel, allez dans **Avancé > Cron Jobs** (ou **Tâches Cron**).
3. Créez une nouvelle tâche :
   - Fréquence : une fois par jour (par exemple à 3h du matin).
   - Type de commande / URL : type **"Personnalisé"**, commande `bash /chemin/complet/vers/le/site/cron/backup.sh` (remplacez le chemin par celui réel sur votre hébergement — voir l'encadré ci-dessous). C'est la méthode recommandée : `cron/backup.sh` va lui-même chercher le jeton dans `config.php` et lancer `wget` proprement, sans risque que hPanel dénature les guillemets d'une commande tapée directement. Alternative plus simple mais plus fragile : coller directement `wget -q -O /dev/null "https://agenda.hellau.be/cron/backup.php?token=VOTRE_JETON"` dans le champ commande (remplacez `VOTRE_JETON` par la valeur mise dans `config.php`) — certains hébergeurs (dont Hostinger, par moments) n'aiment pas les guillemets tapés en direct dans ce champ et la commande échoue silencieusement.
4. Enregistrez. Le lendemain, vérifiez dans `admin/sauvegardes.php` qu'une sauvegarde datée est bien apparue dans le menu déroulant.

> **Chemin complet du site sur le serveur.** Pour le trouver : dans hPanel, commencez à créer une tâche Cron de type **"PHP"** (sans l'enregistrer) — le champ affiche un chemin pré-rempli du style `/home/uXXXXXXXX/...`, qui donne votre dossier personnel. Combinez-le avec l'arborescence visible dans un client FTP (ou le Gestionnaire de fichiers de hPanel) pour reconstituer le chemin complet jusqu'au dossier `cron/` du site — par exemple, si le site est installé dans un sous-dossier `agenda/` de votre domaine principal (`domains/hellau.be/public_html/agenda/`, plutôt que dans un domaine additionnel séparé), le chemin complet est `/home/uXXXXXXXX/domains/hellau.be/public_html/agenda/cron/backup.sh`.
>
> **Si ça ne se déclenche toujours pas automatiquement** (mais que visiter l'URL à la main fonctionne) : dans la liste des tâches Cron, cliquez sur **"Afficher le résultat"** pour voir la sortie de la dernière exécution — une sortie vide ne veut pas forcément dire un échec, `wget -q` ne log rien par défaut en cas de succès. Vérifiez aussi qu'aucun espace ou caractère invisible ne s'est glissé dans le chemin lors du copier-coller.

Chaque sauvegarde est un export complet des **rendez-vous**, des **médicaments** et des **pathologies** à cet instant — un fichier JSON par table (`appointments-…json`, `medicaments-…json`, `medicament_moments-…json`, `medicament_prises-…json`, `pathologies-…json`), conservé 60 jours puis supprimé automatiquement. Le dossier `backups/` est bloqué à l'accès direct par son propre `.htaccess` : seule la page d'administration peut les consulter.

Seuls les rendez-vous ont un écran de restauration (`admin/sauvegardes.php`). Pour les médicaments et les pathologies, la sauvegarde sert de filet : en cas de problème, le fichier JSON permet de reconstituer les données à la main, ce qui est bien plus rapide que de tout retrouver de mémoire.

Si vous n'avez pas de Cron Jobs sur votre plan Hostinger, vous pouvez toujours déclencher une sauvegarde manuellement en visitant l'URL `cron/backup.php?token=...` vous-même de temps en temps — ce n'est juste plus automatique.

**Important : mettez à jour l'URL du Cron Job existant.** Si vous aviez déjà configuré ce Cron Job avant cette mise à jour (structure de fichiers réorganisée), modifiez son URL dans hPanel pour utiliser le nouveau chemin `cron/backup.php` (au lieu de `backup.php`), sinon la sauvegarde automatique cessera de fonctionner.

### Copie hors du serveur

Tout ce qui précède protège de la fausse manœuvre, **pas de la perte du serveur** : le dossier `backups/` est sur le disque de la machine qu'il est censé protéger. Un compte résilié, un dossier vidé par erreur en FTP, un incident chez l'hébergeur, et les sauvegardes disparaissent avec les données.

Le site en envoie donc **une copie par email, une fois par semaine**, à l'adresse réglée dans `admin/reglages.php` (« Ton adresse email »). Rien à configurer : le Cron Job de sauvegarde s'en charge au passage, une fois tous les 7 jours. La pièce jointe est un `.zip` des fichiers JSON (ou les JSON un par un si l'extension ZIP n'est pas disponible sur le serveur).

Pour restaurer depuis un de ces emails : décompressez la pièce jointe, déposez les `.json` dans le dossier `backups/` du site, puis ouvrez `admin/restaurer_tout.php` et choisissez cette date.

Depuis `admin/sauvegardes.php`, un bloc « Copie hors du serveur » indique la date du dernier envoi et permet à tout moment de **télécharger** la dernière sauvegarde ou de **se l'envoyer par email** immédiatement. Un envoi manuel repousse d'autant l'envoi automatique.

En complément (pas à la place) : selon votre plan Hostinger, hPanel propose peut-être ses propres sauvegardes automatiques de tout le compte (**Fichiers > Sauvegardes**). Ça vaut le coup de vérifier et de l'activer si disponible — c'est un filet de sécurité supplémentaire au niveau de l'hébergement, indépendant de celui-ci.

## Rappels par email

Un email peut être envoyé avant chaque rendez-vous à venir (à vous et/ou à vos parents), avec un délai unique réglable depuis `admin/reglages.php` — pas besoin de toucher à `config.php` ni de renvoyer de fichiers pour changer le délai ou les adresses.

### Étape 1 — Configurer l'envoi SMTP (fortement recommandé)

Par défaut, sans configuration supplémentaire, les rappels utilisent la fonction `mail()` native de PHP. **Elle fonctionne, mais atterrit très souvent dans les indésirables** : Hostinger déconseille officiellement cette méthode, car l'email envoyé de cette façon n'est pas authentifié comme venant réellement de votre boîte mail (SPF/DKIM non alignés), même si votre domaine est par ailleurs bien configuré.

La solution recommandée est de faire passer l'envoi par une vraie boîte mail, avec son mot de passe :

1. Créez (si ce n'est pas déjà fait) une boîte mail sur votre domaine dans **hPanel > Emails > Créer un compte email** (par exemple `agenda@hellau.be`), et notez son mot de passe.
2. Dans `config.php`, renseignez :
   ```php
   'smtp_host' => 'smtp.hostinger.com',
   'smtp_port' => 587,
   'smtp_securite' => 'tls',
   'smtp_utilisateur' => 'agenda@hellau.be',   // l'adresse complète de la boîte créée à l'étape 1
   'smtp_mot_de_passe' => 'MOT_DE_PASSE_DE_LA_BOITE',
   ```
   (Port 587 + `'tls'`, ou en variante port 465 + `'ssl'`.) Le mot de passe SMTP est celui de la boîte mail elle-même, pas votre mot de passe de connexion à hPanel.
3. Dans `admin/reglages.php`, réglez **Adresse d'expédition (From)** sur cette même adresse (`agenda@hellau.be`), puis cliquez sur **Envoyer un email de test** : si les identifiants SMTP sont corrects, l'email part réellement authentifié et arrive bien plus fiablement en boîte de réception principale.

Si vous laissez `smtp_host` vide, le site continue de fonctionner en se rabattant automatiquement sur `mail()` — mais surveillez alors le dossier indésirables.

### Étape 2 — Réglages techniques (administration)

1. Dans `config.php`, remplacez `reminder_token` par une chaîne aléatoire longue (même principe que `backup_token`).
2. Ouvrez `https://agenda.hellau.be/admin/reglages.php` (carte "Notifications" sur l'accueil admin, `admin/index.php`), cochez **Activer les rappels par email**, réglez le délai (en heures) et renseignez ton adresse email (Chem) — tu reçois un rappel pour tous les rendez-vous, quels que soient les choix de tes parents.
3. Cliquez sur **Envoyer un email de test** pour vérifier que l'envoi fonctionne bien (et pensez à regarder le dossier des indésirables/spam la première fois, surtout si vous n'avez pas configuré le SMTP ci-dessus). Le message d'erreur affiché en cas d'échec indique la cause précise (identifiants SMTP incorrects, serveur inaccessible, etc.).
4. Cliquez sur **Enregistrer les réglages**.
5. Dans hPanel, allez dans **Avancé > Cron Jobs** (ou **Tâches Cron**) et créez une nouvelle tâche :
   - Fréquence : toutes les 15 à 30 minutes (plus fréquent que les sauvegardes, pour que les rappels partent à l'heure).
   - Type de commande / URL : type **"Personnalisé"**, commande `bash /chemin/complet/vers/le/site/cron/rappels.sh` (même principe que pour la sauvegarde ci-dessus — voir l'encadré sur le chemin complet dans la section précédente). Alternative plus fragile : coller directement `wget -q -O /dev/null "https://agenda.hellau.be/cron/rappels.php?token=VOTRE_JETON"` dans le champ commande.
6. Enregistrez. Chaque rendez-vous ne reçoit qu'un seul rappel, quelle que soit la fréquence du Cron Job — pas de risque de doublon même en appelant `cron/rappels.php` très souvent.

**Important : mettez à jour l'URL du Cron Job existant.** Si vous aviez déjà configuré ce Cron Job avant cette mise à jour (structure de fichiers réorganisée), modifiez son URL dans hPanel pour utiliser le nouveau chemin `cron/rappels.php` (au lieu de `rappels.php`), sinon les rappels cesseront de partir.

### Étape 3 — Chaque parent règle ses propres préférences

Contrairement au reste (réservé à l'administration), les adresses email de vos parents et leurs préférences ne se configurent PAS dans `admin/reglages.php` : chacun les gère lui-même, une fois connecté avec son compte Google (pas besoin du mot de passe admin), depuis le lien **"Rappels par email"** en haut de l'agenda (`mes_rappels.php`).

Sur cette page, chaque personne (identifiée par son prénom, ex. "Michel" / "Christiane") peut :
- renseigner sa propre adresse email (vide = aucun rappel pour elle) ;
- cocher **"Recevoir aussi les rappels des rendez-vous de [l'autre]"** si elle veut être prévenue des deux agendas plutôt que du sien seulement ;
- s'envoyer un email de test pour vérifier que ça arrive bien.

Exemple concret : si Michel coche la case pour recevoir aussi les rendez-vous de Christiane, mais que Christiane ne coche pas la case équivalente, alors Michel reçoit un rappel pour tous les rendez-vous (les siens et ceux de Christiane), tandis que Christiane ne reçoit un rappel que pour les siens. Chacun règle ça independamment, à tout moment, sans avoir besoin de vous (Chem) ni du mot de passe admin.

## Gérer les personnes (renommer, ajouter, retirer)

Tout se passe dans **Administration → Personnes** (`/admin/personnes.php`). Aucune modification de `config.php`, aucun redéploiement.

Chaque personne porte deux cases :

- **Patient** — on suit sa santé : elle a un onglet dans l'agenda, un plan de médicaments, des pathologies, un carnet de médecins.
- **Se connecte** — elle est autorisée à entrer sur le site, avec l'adresse Google indiquée en face.

Michel et Christiane ont les deux, Hélène et Laurent seulement la seconde.

**Renommer est sans danger** : le nom n'est stocké qu'à un seul endroit et toutes les données pointent dessus par un identifiant. Le changement se voit immédiatement partout, y compris sur les rendez-vous passés, sans que personne ait à se reconnecter.

> Ce n'était pas le cas avant la version qui a introduit la table `persons` : le nom était recopié dans six tables, et le modifier dans `config.php` faisait disparaître les médicaments et les pathologies de la personne — sans message d'erreur.

**Pour retirer quelqu'un**, préférez **Désactiver** à **Supprimer** dès qu'il a des données : il disparaît des listes et des onglets, mais les rendez-vous passés et le journal d'activité gardent un nom lisible. La suppression définitive n'est proposée que si plus rien n'y est rattaché.

`config.php` ne contient plus aucun nom de personne : tout est en base.
3. Rechargez la page : onglets, formulaire, badges et description Google Calendar utilisent maintenant les nouveaux noms partout, sans avoir touché au code.

## Mettre à jour le site plus tard

Contrairement à la version Google Apps Script, il n'y a pas de « déploiement » à refaire : il suffit de renvoyer le ou les fichiers modifiés dans le même dossier via le Gestionnaire de fichiers ou FTP. Le changement est visible immédiatement. Si la mise à jour ajoute un fichier dans `migrations/`, ouvrez `outils/migrate.php` sur le site pour l'appliquer (voir `Guide_dev_local_et_versions.md` pour le workflow complet : développement local, Git, versions, migrations).

## Sécurité

- `config.php`, tous les fichiers `.json` et `.sql`, ainsi que tout le dossier `lib/` sont bloqués à l'accès direct par le `.htaccess` fourni.
- Le dossier `backups/` a son propre `.htaccess` qui bloque tout accès direct aux sauvegardes.
- La zone d'administration (`admin/index.php` et ses sous-pages : import `.ics`, correction de rendez-vous, sauvegardes) est réservée aux personnes portant le drapeau **Administre** (voir « Qui peut administrer » plus haut).
- Le SSL (`https://`) chiffre les échanges entre le navigateur et le serveur.
- Ne partagez jamais `config.php` ni `service-account.json`.
- Pour donner ou retirer les droits d'administration : cochez ou décochez **Administre** dans `/admin/personnes.php`. L'effet est immédiat au chargement de page suivant, sans reconnexion.
- Pour retirer l'accès à quelqu'un : effacez son adresse Google dans `admin/personnes.php`, ou désactivez la personne. L'effet est immédiat à la prochaine connexion.

## Mise à jour depuis une version antérieure à la réorganisation des fichiers (v2.0.0)

À partir de la version 2.0.0, les pages d'administration, les scripts Cron et les outils d'installation sont rangés dans des sous-dossiers (`admin/`, `cron/`, `outils/`) plutôt qu'à la racine du site. Si vous mettez à jour un site déjà installé avec l'ancienne structure (fichiers `admin_nettoyage.php`, `backup.php`, `rappels.php`, `migrate.php`, etc. directement à la racine), il faut, en plus de renvoyer les nouveaux fichiers :

1. **Supprimer les anciens fichiers racine** devenus obsolètes : `admin_login.php`, `admin_logout.php`, `admin_nettoyage.php`, `admin_reglages.php`, `backup.php`, `rappels.php`, `migrate.php`, `import_calendar.php` (s'il est encore présent). Les laisser en place ne casse rien techniquement, mais ce sont des doublons obsolètes qu'il vaut mieux retirer.
2. **Mettre à jour les deux Cron Jobs** dans hPanel (Avancé > Cron Jobs) pour pointer vers les nouvelles URLs `cron/backup.php?token=...` et `cron/rappels.php?token=...` (voir les sections correspondantes ci-dessus).
3. **Mettre à jour votre favori/marque-page** vers la page d'administration : la nouvelle adresse est `https://agenda.hellau.be/admin/index.php`.

## Mise à jour depuis une version antérieure à la refonte de l'admin (v2.1.0)

À partir de la version 2.1.0, `admin/nettoyage.php` (qui empilait 5 outils sur une seule page) est remplacé par un accueil admin (`admin/index.php`) avec des cartes groupées par thème, et 3 sous-pages dédiées : `admin/import.php` (import `.ics`), `admin/corriger.php` (les 3 outils de correction, présentés en onglets) et `admin/sauvegardes.php` (restauration). Si vous mettez à jour un site déjà installé :

1. **Supprimez l'ancien fichier `admin/nettoyage.php`** du serveur, devenu obsolète.
2. **Mettez à jour votre favori/marque-page** : la nouvelle adresse est `https://agenda.hellau.be/admin/index.php` (au lieu de `admin/nettoyage.php`).
