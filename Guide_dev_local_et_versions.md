# Développer en local et gérer les versions

Ce guide couvre trois choses : installer une copie du site sur votre machine (Fedora) pour tester avant de mettre en ligne, suivre les versions avec Git, et appliquer les changements de base de données proprement à chaque mise à jour.

## 1. Installer l'environnement local (Fedora)

```
sudo dnf install php php-cli php-pdo php-mysqlnd mariadb-server
sudo systemctl enable --now mariadb
sudo mysql_secure_installation
```

Créez une base et un utilisateur dédiés au projet :

```
sudo mysql -u root -p
```
```sql
CREATE DATABASE agenda_dev CHARACTER SET utf8mb4;
CREATE USER 'agenda_dev'@'localhost' IDENTIFIED BY 'un_mot_de_passe_local';
GRANT ALL PRIVILEGES ON agenda_dev.* TO 'agenda_dev'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 2. Configurer le projet en local

Dans le dossier du projet :

```
cp config.example.php config.php
```

Éditez `config.php` :
- `db_host` → `localhost`
- `db_name` → `agenda_dev`
- `db_user` → `agenda_dev`
- `db_pass` → `un_mot_de_passe_local`
- `family_password_hash` → générez-le en local avec `outils/generate_password.php` (voir ci-dessous)
- laissez `google_calendar_id` vide en local (pas besoin de polluer votre vrai calendrier pendant les tests)

`config.php` est ignoré par Git (`.gitignore`) : chaque environnement (votre machine, le serveur Hostinger) a le sien, jamais partagé ni commité.

## 3. Créer les tables et générer un mot de passe de test

```
php outils/migrate.php
```

Ça crée la table `appointments` (et la table technique `schema_migrations` qui garde la trace de ce qui a été appliqué).

Pour le mot de passe : lancez le serveur de dev (étape suivante), ouvrez `http://localhost:8000/outils/generate_password.php`, générez un hash, collez-le dans `config.php`, puis supprimez le fichier ou laissez-le (en local ce n'est pas grave, mais ne le mettez jamais en prod — il est justement listé pour rappel dans le guide Hostinger).

## 4. Lancer le site en local

```
php -S localhost:8000
```

Ouvrez `http://localhost:8000` dans votre navigateur. Vous testez ainsi exactement le même code que ce qui sera déployé, mais sur votre propre base de données locale — aucun risque de toucher aux vraies données de vos parents pendant que vous développez.

## 5. Suivre les versions avec Git

Le projet est déjà initialisé (`git init`), avec un `.gitignore` qui exclut `config.php` et `service-account.json`. Workflow recommandé :

```
git add -A
git commit -m "Description du changement"
```

Quand une version est prête à être déployée, taguez-la :

```
git tag -a v1.1.0 -m "Description de la version"
```

Consultez l'historique et les versions avec :

```
git log --oneline
git tag
```

Notez chaque version dans `CHANGELOG.md` (déjà commencé avec `v1.0.0`) : ce que la version change, et si une migration est associée.

## 6. Ajouter un changement de structure de base de données

Dès que vous modifiez la table `appointments` (nouvelle colonne, nouvel index, etc.) :

1. Créez un nouveau fichier dans `migrations/`, numéroté après le dernier existant, par exemple `migrations/0002_ajout_duree.sql` :
   ```sql
   ALTER TABLE appointments ADD COLUMN duree_minutes INT NOT NULL DEFAULT 30;
   ```
2. Testez en local : `php outils/migrate.php` — il ne joue que les migrations pas encore appliquées.
3. Vérifiez que le site fonctionne toujours avec la nouvelle colonne.
4. Committez le fichier de migration avec le reste du code, mettez à jour `CHANGELOG.md`, taguez la version.

## 7. Déployer une nouvelle version en production

1. Envoyez sur Hostinger les fichiers qui ont changé, y compris les nouveaux fichiers dans `migrations/` s'il y en a. Deux façons de faire :
   - **Via le script `deploiement/deployer.php`** (recommandé, voir section 7bis ci-dessous) : détecte tout seul les fichiers modifiés et les envoie par FTP.
   - **À la main** (Gestionnaire de fichiers Hostinger ou un client FTP comme FileZilla) : reprenez la liste des fichiers modifiés depuis le dernier déploiement (`git status`/`git diff --name-only` si tout est commité) et envoyez-les un par un.
2. Si de nouvelles migrations existent, appliquez-les sur le serveur :
   - **Avec accès SSH** (si votre plan Hostinger le propose) : connectez-vous et lancez `php outils/migrate.php`.
   - **Sans accès SSH** : ouvrez `https://agenda.hellau.be/outils/migrate.php` dans le navigateur, connectez-vous avec le mot de passe **d'administration**, la page liste les migrations en attente, cliquez sur **Lancer les migrations**.
3. Rechargez le site et vérifiez que tout fonctionne.

`config.php` reste propre à chaque environnement : ne le copiez jamais de votre machine vers le serveur (les identifiants de base de données et le mot de passe familial sont différents entre local et production).

## 7bis. Déploiement automatique par FTP (`deploiement/deployer.php`)

Script à lancer manuellement depuis votre machine (jamais depuis le serveur) : il compare chaque fichier local au fichier réellement présent sur le serveur (via FTP, contenu octet par octet — pas une date), et n'envoie que ceux qui diffèrent ou sont absents. Les fichiers sensibles (`config.php`, `service-account.json`...) et tout ce que vous listez vous-même dans `deploiement/exclusions.txt` sont systématiquement exclus.

**Configuration, une seule fois :**
```
cp deploiement/deploy.config.example.php deploiement/deploy.config.php
```
Remplissez `deploiement/deploy.config.php` avec vos identifiants FTP Hostinger (hPanel > Fichiers > Comptes FTP) et le chemin distant vers la racine du site (`ftp_remote_path`, souvent `/public_html`). Ce fichier contient un mot de passe : il est exclu de Git, ne le committez jamais.

**Utilisation :**
```
php deploiement/deployer.php            # compare au FTP, televerse ce qui differe (ou est absent), apres confirmation
php deploiement/deployer.php --test      # meme comparaison, affiche juste la liste sans rien televerser
php deploiement/deployer.php --tout      # televerse TOUT le depot (fichiers non exclus), sans comparer au FTP
```
`--testftp` reste accepté comme synonyme de `--test`, par habitude.

Le script se connecte toujours au FTP (y compris en `--test`) pour comparer le contenu réel de chaque fichier candidat à sa copie en ligne, affiche la liste de ce qui diffère ou est absent, puis demande une confirmation (`o`/N) avant d'envoyer quoi que ce soit — sauf en `--test`, qui n'envoie jamais rien. Comme la comparaison est toujours la même (le contenu réel sur le serveur), le mode normal et `--test` donnent toujours des résultats cohérents entre eux.

Si `php deploiement/deployer.php` répond que l'extension `ftp` n'est pas installée : `sudo dnf install php-ftp`.

Pour exclure un fichier ou dossier supplémentaire, ajoutez une ligne dans `deploiement/exclusions.txt` (une ligne se terminant par `/` exclut tout un dossier, sinon c'est un nom de fichier ou un motif avec `*`).

## 8. Récupérer les vraies données de production en local

Pour tester avec des données réalistes plutôt qu'une base de dev vide ou remplie de faux rendez-vous :

1. Sur le site en production, allez dans **Administration > Données de développement > Exporter les données** (`admin/exporter_donnees.php`). Ça télécharge un fichier `agenda-export-AAAA-MM-JJ-HHMM.json` contenant tous les rendez-vous actuels.
2. En local, allez dans **Administration > Données de développement > Importer un export** (`outils/importer_donnees_dev.php`) et sélectionnez ce fichier.
3. Confirmez : ça **remplace entièrement** le contenu de la base de dev (pas une fusion) par celui de l'export.

Cet outil d'import refuse de s'exécuter si `config.php` ne pointe pas explicitement vers une base nommée `agenda_dev` — impossible de l'utiliser par erreur en production et d'effacer les vraies données. Le lien "Importer un export" n'apparaît d'ailleurs même pas dans le menu Administration tant que ce n'est pas le cas.

Les identifiants d'événements Google Calendar ne sont jamais importés (ils appartiennent au calendrier de production) : la base de dev n'a donc aucun lien avec le vrai calendrier après un import, ce qui est le comportement voulu.
