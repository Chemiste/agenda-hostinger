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
- `family_password_hash` → générez-le en local avec `generate_password.php` (voir ci-dessous)
- laissez `google_calendar_id` vide en local (pas besoin de polluer votre vrai calendrier pendant les tests)

`config.php` est ignoré par Git (`.gitignore`) : chaque environnement (votre machine, le serveur Hostinger) a le sien, jamais partagé ni commité.

## 3. Créer les tables et générer un mot de passe de test

```
php migrate.php
```

Ça crée la table `appointments` (et la table technique `schema_migrations` qui garde la trace de ce qui a été appliqué).

Pour le mot de passe : lancez le serveur de dev (étape suivante), ouvrez `http://localhost:8000/generate_password.php`, générez un hash, collez-le dans `config.php`, puis supprimez le fichier ou laissez-le (en local ce n'est pas grave, mais ne le mettez jamais en prod — il est justement listé pour rappel dans le guide Hostinger).

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
2. Testez en local : `php migrate.php` — il ne joue que les migrations pas encore appliquées.
3. Vérifiez que le site fonctionne toujours avec la nouvelle colonne.
4. Committez le fichier de migration avec le reste du code, mettez à jour `CHANGELOG.md`, taguez la version.

## 7. Déployer une nouvelle version en production

1. Envoyez sur Hostinger uniquement les fichiers qui ont changé (via le Gestionnaire de fichiers ou FTP), y compris les nouveaux fichiers dans `migrations/` s'il y en a.
2. Si de nouvelles migrations existent, appliquez-les sur le serveur :
   - **Avec accès SSH** (si votre plan Hostinger le propose) : connectez-vous et lancez `php migrate.php`.
   - **Sans accès SSH** : ouvrez `https://agenda.hellau.be/migrate.php` dans le navigateur, connectez-vous avec le mot de passe familial, la page liste les migrations en attente, cliquez sur **Lancer les migrations**.
3. Rechargez le site et vérifiez que tout fonctionne.

`config.php` reste propre à chaque environnement : ne le copiez jamais de votre machine vers le serveur (les identifiants de base de données et le mot de passe familial sont différents entre local et production).
