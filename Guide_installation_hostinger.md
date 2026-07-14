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

La table `appointments` sera créée à l'étape 5 via le script `migrate.php`, pas manuellement dans phpMyAdmin — ça garde la base cohérente avec le code à chaque mise à jour (voir `Guide_dev_local_et_versions.md`).

## Étape 3 — Envoyer les fichiers du site

1. Dans hPanel, ouvrez le **Gestionnaire de fichiers** (ou utilisez FTP/FileZilla si vous préférez).
2. Allez dans le dossier du sous-domaine créé à l'étape 1.
3. Envoyez-y **tout le contenu** du dossier `agenda-hostinger` fourni, en conservant la structure : `index.php`, `login.php`, `logout.php`, `api.php`, `migrate.php`, `config.example.php`, `generate_password.php`, `.htaccess`, le dossier `migrations/`, le dossier `lib/` (avec son propre `.htaccess`), et le dossier `assets/`.
4. Ne renvoyez pas votre `config.php` local : créez-en un directement sur le serveur (étape suivante). Chaque environnement a le sien.

## Étape 4 — Configurer `config.php` sur le serveur

1. Dans le Gestionnaire de fichiers, dupliquez `config.example.php`, renommez la copie `config.php`.
2. Modifiez `config.php` et remplacez :
   - `db_host`, `db_name`, `db_user`, `db_pass` par les informations de la base créée à l'étape 2
   - laissez `family_password_hash` et les lignes Google pour l'instant

Enregistrez.

## Étape 5 — Définir le mot de passe familial

1. Ouvrez `https://agenda.hellau.be/generate_password.php` dans votre navigateur.
2. Saisissez le mot de passe que vous voulez utiliser en famille, cliquez sur **Générer le hash**.
3. Copiez la valeur affichée dans `config.php`, champ `family_password_hash`.
4. **Supprimez le fichier `generate_password.php`** du serveur (via le Gestionnaire de fichiers) — il ne doit pas rester en ligne.

## Étape 6 — Créer les tables

Ouvrez `https://agenda.hellau.be/migrate.php`, connectez-vous avec le mot de passe familial défini à l'étape précédente, puis cliquez sur **Lancer les migrations**. La table `appointments` est créée.

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

## Mettre à jour le site plus tard

Contrairement à la version Google Apps Script, il n'y a pas de « déploiement » à refaire : il suffit de renvoyer le ou les fichiers modifiés dans le même dossier via le Gestionnaire de fichiers ou FTP. Le changement est visible immédiatement. Si la mise à jour ajoute un fichier dans `migrations/`, ouvrez `migrate.php` sur le site pour l'appliquer (voir `Guide_dev_local_et_versions.md` pour le workflow complet : développement local, Git, versions, migrations).

## Sécurité

- `config.php`, tous les fichiers `.json` et `.sql`, ainsi que tout le dossier `lib/` sont bloqués à l'accès direct par le `.htaccess` fourni.
- Le SSL (`https://`) chiffre les échanges entre le navigateur et le serveur.
- Ne partagez jamais `config.php` ni `service-account.json`.
- Pour changer le mot de passe familial plus tard : remettez temporairement `generate_password.php` sur le serveur, générez un nouveau hash, mettez à jour `config.php`, puis supprimez à nouveau `generate_password.php`.
