# Installer l'agenda médical sur votre hébergement Hostinger

J'ai choisi **PHP + MySQL** : c'est la seule techno garantie de fonctionner sur absolument tous les plans Hostinger (même le moins cher), sans terminal ni installation particulière — tout se fait depuis hPanel. Si vous avez un plan qui supporte Node.js, on pourra migrer plus tard, mais ce n'est pas nécessaire.

Le site utilise :
- un **sous-domaine** (ex : `agenda.hellau.be`)
- une **base de données MySQL** pour stocker les rendez-vous
- **un mot de passe familial partagé** pour se connecter
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
3. Envoyez-y **tout le contenu** du dossier `agenda-hostinger` fourni, **en conservant exactement la structure de dossiers** : à la racine `index.php`, `login.php`, `logout.php`, `api.php`, `mes_rappels.php`, `config.example.php`, `.htaccess`, ainsi que les dossiers `migrations/`, `lib/` (avec son propre `.htaccess`), `assets/`, `backups/` (avec son propre `.htaccess`), et les trois dossiers d'outils : `admin/` (login, logout, index, import, corriger, sauvegardes, reglages), `cron/` (backup, rappels) et `outils/` (migrate, generate_password, import_calendar).
4. Ne renvoyez pas votre `config.php` local : créez-en un directement sur le serveur (étape suivante). Chaque environnement a le sien.

## Étape 4 — Configurer `config.php` sur le serveur

1. Dans le Gestionnaire de fichiers, dupliquez `config.example.php`, renommez la copie `config.php`.
2. Modifiez `config.php` et remplacez :
   - `db_host`, `db_name`, `db_user`, `db_pass` par les informations de la base créée à l'étape 2
   - laissez `family_password_hash` et les lignes Google pour l'instant

Enregistrez.

## Étape 5 — Définir le mot de passe familial

1. Ouvrez `https://agenda.hellau.be/outils/generate_password.php` dans votre navigateur.
2. Saisissez le mot de passe que vous voulez utiliser en famille, cliquez sur **Générer le hash**.
3. Copiez la valeur affichée dans `config.php`, champ `family_password_hash`.
4. **Supprimez le fichier `outils/generate_password.php`** du serveur (via le Gestionnaire de fichiers) — il ne doit pas rester en ligne.

## Étape 6 — Créer les tables

Ouvrez `https://agenda.hellau.be/outils/migrate.php`, connectez-vous avec le mot de passe familial défini à l'étape précédente, puis cliquez sur **Lancer les migrations**. La table `appointments` est créée.

## Étape 7 — Tester

1. Ouvrez `https://agenda.hellau.be`. Vous devez arriver sur l'écran de connexion.
2. Entrez le mot de passe familial.
3. Ajoutez un rendez-vous test, vérifiez qu'il apparaît dans la liste.
4. Dans phpMyAdmin, vérifiez qu'une ligne est bien apparue dans la table `appointments`.
5. Supprimez le rendez-vous test.

À partir de maintenant, vous, Papa et Maman partagez le même lien et le même mot de passe.

## Étape 8 (facultatif) — Synchronisation vers Google Calendar

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

## Étape 9 (facultatif) — Importer une fois les rendez-vous déjà dans Google Calendar

Si vous aviez déjà des rendez-vous dans le calendrier Google créé pour vos parents, vous pouvez les récupérer en une fois dans le site (nécessite d'avoir fait l'étape 8 juste avant, pour que le site puisse lire ce calendrier).

1. Ouvrez `https://agenda.hellau.be/outils/import_calendar.php`, connectez-vous.
2. Choisissez une période (du / au), cliquez sur **Charger les évènements de cette période**.
3. Une liste s'affiche : décochez ceux à ne pas importer, choisissez la bonne personne (Papa ou Maman) pour chaque ligne — un rendez-vous ne concerne jamais qu'une seule personne, même si le calendrier d'origine ne le précisait pas.
4. Cliquez sur **Importer la sélection**.

Chaque rendez-vous importé reste lié à son évènement Google Calendar d'origine : si vous le modifiez ensuite depuis le site, l'évènement existant sera mis à jour (pas de doublon créé). Relancer cet import par erreur ne duplique rien non plus — les évènements déjà importés sont automatiquement ignorés.

**Une fois l'import fait et vérifié, supprimez `outils/import_calendar.php` du serveur** (comme `outils/generate_password.php`) : il n'a plus de raison de rester en ligne.

## Protéger les outils d'administration par un second mot de passe

Le site a une zone d'administration (`admin/index.php`), organisée en trois groupes : **Rendez-vous** (import `.ics`, correction de rendez-vous existants), **Sauvegardes** (restauration) et **Notifications** (réglages des rappels). Pour que le reste de la famille n'y ait pas accès même s'il tombe sur l'adresse, cette zone est protégée par un **second mot de passe**, différent du mot de passe familial.

1. Remettez temporairement `outils/generate_password.php` sur le serveur si vous l'aviez déjà supprimé.
2. Ouvrez `https://agenda.hellau.be/outils/generate_password.php`, saisissez le mot de passe d'administration de votre choix (gardez-le pour vous), cliquez sur **Générer le hash**.
3. Copiez la valeur générée dans `config.php`, champ `admin_password_hash`.
4. Supprimez à nouveau `outils/generate_password.php` du serveur si vous n'en avez plus besoin.

Il n'y a plus de lien visible vers `admin/index.php` dans l'agenda : gardez cette adresse en favori pour y accéder directement. Même en la connaissant, l'accès reste bloqué tant que le mot de passe d'administration n'est pas défini dans `config.php`.

## Sauvegardes automatiques

En cas de suppression accidentelle d'un rendez-vous, une sauvegarde automatique quotidienne permet de le retrouver et de le restaurer depuis `admin/sauvegardes.php` (accessible aussi depuis la carte "Sauvegardes" de l'accueil admin).

1. Dans `config.php`, remplacez `backup_token` par une chaîne aléatoire longue (par exemple générée sur [1password.com/password-generator](https://1password.com/password-generator) ou similaire) — ce n'est pas un mot de passe à retenir, juste une clé secrète dans une URL.
2. Dans hPanel, allez dans **Avancé > Cron Jobs** (ou **Tâches Cron**).
3. Créez une nouvelle tâche :
   - Fréquence : une fois par jour (par exemple à 3h du matin).
   - Type de commande / URL : `https://agenda.hellau.be/cron/backup.php?token=VOTRE_JETON` (remplacez `VOTRE_JETON` par la valeur mise dans `config.php`). Si hPanel demande une commande shell plutôt qu'une URL, utilisez `wget -q -O /dev/null "https://agenda.hellau.be/cron/backup.php?token=VOTRE_JETON"` (ou `curl` si disponible).
4. Enregistrez. Le lendemain, vérifiez dans `admin/sauvegardes.php` qu'une sauvegarde datée est bien apparue dans le menu déroulant.

Chaque sauvegarde est un export complet des rendez-vous à cet instant, conservé 60 jours puis supprimé automatiquement. Le dossier `backups/` est bloqué à l'accès direct par son propre `.htaccess` : seule la page d'administration (avec son mot de passe) peut les consulter.

Si vous n'avez pas de Cron Jobs sur votre plan Hostinger, vous pouvez toujours déclencher une sauvegarde manuellement en visitant l'URL `cron/backup.php?token=...` vous-même de temps en temps — ce n'est juste plus automatique.

**Important : mettez à jour l'URL du Cron Job existant.** Si vous aviez déjà configuré ce Cron Job avant cette mise à jour (structure de fichiers réorganisée), modifiez son URL dans hPanel pour utiliser le nouveau chemin `cron/backup.php` (au lieu de `backup.php`), sinon la sauvegarde automatique cessera de fonctionner.

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
   - Type de commande / URL : `https://agenda.hellau.be/cron/rappels.php?token=VOTRE_JETON` (remplacez `VOTRE_JETON` par la valeur mise dans `config.php`). Si hPanel demande une commande shell plutôt qu'une URL, utilisez `wget -q -O /dev/null "https://agenda.hellau.be/cron/rappels.php?token=VOTRE_JETON"` (ou `curl` si disponible).
6. Enregistrez. Chaque rendez-vous ne reçoit qu'un seul rappel, quelle que soit la fréquence du Cron Job — pas de risque de doublon même en appelant `cron/rappels.php` très souvent.

**Important : mettez à jour l'URL du Cron Job existant.** Si vous aviez déjà configuré ce Cron Job avant cette mise à jour (structure de fichiers réorganisée), modifiez son URL dans hPanel pour utiliser le nouveau chemin `cron/rappels.php` (au lieu de `rappels.php`), sinon les rappels cesseront de partir.

### Étape 3 — Chaque parent règle ses propres préférences

Contrairement au reste (réservé à l'administration), les adresses email de vos parents et leurs préférences ne se configurent PAS dans `admin/reglages.php` : chacun les gère lui-même, avec le mot de passe familial habituel (pas besoin du mot de passe admin), depuis le lien **"Rappels par email"** en haut de l'agenda (`mes_rappels.php`).

Sur cette page, chaque personne (identifiée par son prénom, ex. "Michel" / "Christiane") peut :
- renseigner sa propre adresse email (vide = aucun rappel pour elle) ;
- cocher **"Recevoir aussi les rappels des rendez-vous de [l'autre]"** si elle veut être prévenue des deux agendas plutôt que du sien seulement ;
- s'envoyer un email de test pour vérifier que ça arrive bien.

Exemple concret : si Michel coche la case pour recevoir aussi les rendez-vous de Christiane, mais que Christiane ne coche pas la case équivalente, alors Michel reçoit un rappel pour tous les rendez-vous (les siens et ceux de Christiane), tandis que Christiane ne reçoit un rappel que pour les siens. Chacun règle ça independamment, à tout moment, sans avoir besoin de vous (Chem) ni du mot de passe admin.

## Remplacer "Papa" et "Maman" par d'autres noms

1. Dans `config.php`, modifiez les deux lignes :
   ```php
   'personne_1' => 'Papa',
   'personne_2' => 'Maman',
   ```
   par les noms de votre choix (prénoms réels, par exemple).
2. **Les rendez-vous déjà enregistrés gardent les anciens noms en base** (ils ne se renomment pas tout seuls). Pour les mettre à jour, exécutez dans phpMyAdmin (ou via `mysql`) :
   ```sql
   UPDATE appointments SET person = 'NouveauNom1' WHERE person = 'Papa';
   UPDATE appointments SET person = 'NouveauNom2' WHERE person = 'Maman';
   ```
   (adaptez avec vos vrais anciens/nouveaux noms). Sans ça, ces anciens rendez-vous n'apparaîtront plus dans les onglets Papa/Maman renommés (ils resteront visibles dans "Tous").
3. Rechargez la page : onglets, formulaire, badges et description Google Calendar utilisent maintenant les nouveaux noms partout, sans avoir touché au code.

## Mettre à jour le site plus tard

Contrairement à la version Google Apps Script, il n'y a pas de « déploiement » à refaire : il suffit de renvoyer le ou les fichiers modifiés dans le même dossier via le Gestionnaire de fichiers ou FTP. Le changement est visible immédiatement. Si la mise à jour ajoute un fichier dans `migrations/`, ouvrez `outils/migrate.php` sur le site pour l'appliquer (voir `Guide_dev_local_et_versions.md` pour le workflow complet : développement local, Git, versions, migrations).

## Sécurité

- `config.php`, tous les fichiers `.json` et `.sql`, ainsi que tout le dossier `lib/` sont bloqués à l'accès direct par le `.htaccess` fourni.
- Le dossier `backups/` a son propre `.htaccess` qui bloque tout accès direct aux sauvegardes.
- La zone d'administration (`admin/index.php` et ses sous-pages : import `.ics`, correction de rendez-vous, sauvegardes) est protégée par un second mot de passe distinct du mot de passe familial (voir « Protéger les outils d'administration » plus haut), et n'a pas de lien visible depuis l'agenda.
- Le SSL (`https://`) chiffre les échanges entre le navigateur et le serveur.
- Ne partagez jamais `config.php` ni `service-account.json`.
- Pour changer le mot de passe familial ou le mot de passe d'administration plus tard : remettez temporairement `outils/generate_password.php` sur le serveur, générez un nouveau hash, mettez à jour `config.php` (`family_password_hash` ou `admin_password_hash`), puis supprimez à nouveau `outils/generate_password.php`.

## Mise à jour depuis une version antérieure à la réorganisation des fichiers (v2.0.0)

À partir de la version 2.0.0, les pages d'administration, les scripts Cron et les outils d'installation sont rangés dans des sous-dossiers (`admin/`, `cron/`, `outils/`) plutôt qu'à la racine du site. Si vous mettez à jour un site déjà installé avec l'ancienne structure (fichiers `admin_nettoyage.php`, `backup.php`, `rappels.php`, `migrate.php`, etc. directement à la racine), il faut, en plus de renvoyer les nouveaux fichiers :

1. **Supprimer les anciens fichiers racine** devenus obsolètes : `admin_login.php`, `admin_logout.php`, `admin_nettoyage.php`, `admin_reglages.php`, `backup.php`, `rappels.php`, `migrate.php`, `generate_password.php`, `import_calendar.php` (s'il est encore présent). Les laisser en place ne casse rien techniquement, mais ce sont des doublons obsolètes qu'il vaut mieux retirer.
2. **Mettre à jour les deux Cron Jobs** dans hPanel (Avancé > Cron Jobs) pour pointer vers les nouvelles URLs `cron/backup.php?token=...` et `cron/rappels.php?token=...` (voir les sections correspondantes ci-dessus).
3. **Mettre à jour votre favori/marque-page** vers la page d'administration : la nouvelle adresse est `https://agenda.hellau.be/admin/index.php`.

## Mise à jour depuis une version antérieure à la refonte de l'admin (v2.1.0)

À partir de la version 2.1.0, `admin/nettoyage.php` (qui empilait 5 outils sur une seule page) est remplacé par un accueil admin (`admin/index.php`) avec des cartes groupées par thème, et 3 sous-pages dédiées : `admin/import.php` (import `.ics`), `admin/corriger.php` (les 3 outils de correction, présentés en onglets) et `admin/sauvegardes.php` (restauration). Si vous mettez à jour un site déjà installé :

1. **Supprimez l'ancien fichier `admin/nettoyage.php`** du serveur, devenu obsolète.
2. **Mettez à jour votre favori/marque-page** : la nouvelle adresse est `https://agenda.hellau.be/admin/index.php` (au lieu de `admin/nettoyage.php`).
