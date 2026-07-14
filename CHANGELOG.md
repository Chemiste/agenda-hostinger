# Journal des versions

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
