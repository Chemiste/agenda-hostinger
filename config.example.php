<?php
/**
 * MODELE de configuration - a copier en "config.php" puis a remplir.
 *
 *   cp config.example.php config.php
 *
 * config.php contient des secrets (mots de passe, cles) : il est
 * volontairement exclu de Git (voir .gitignore) et ne doit jamais etre
 * commite. Chaque environnement (votre machine en local, le serveur
 * Hostinger en production) a son propre config.php avec ses propres
 * identifiants.
 */
return [

    // --- Base de donnees MySQL/MariaDB ---
    'db_host' => 'localhost',
    'db_name' => 'REMPLACER_nom_de_la_base',
    'db_user' => 'REMPLACER_utilisateur',
    'db_pass' => 'REMPLACER_mot_de_passe_base',

    // --- Mot de passe familial (hash bcrypt, genere via outils/generate_password.php) ---
    'family_password_hash' => 'REMPLACER_PAR_LE_HASH_GENERE',

    // --- Mot de passe d'administration (nettoyage, import .ics, sauvegardes) ---
    // Second mot de passe, DIFFERENT du mot de passe familial, connu de
    // vous seul. Meme genere via outils/generate_password.php (ouvrez la page
    // une deuxieme fois avec un autre mot de passe).
    'admin_password_hash' => 'REMPLACER_PAR_LE_HASH_GENERE',

    // --- Jeton de sauvegarde automatique (voir cron/backup.php) ---
    // Chaine aleatoire longue de votre choix, utilisee dans l'URL du
    // Cron Job Hostinger pour declencher la sauvegarde sans mot de passe
    // interactif. Ex : genere avec `openssl rand -hex 20` ou un
    // generateur de mots de passe en ligne.
    'backup_token' => 'REMPLACER_PAR_UNE_CHAINE_ALEATOIRE',

    // --- Jeton des rappels par email (voir cron/rappels.php) ---
    // Meme principe que backup_token : chaine aleatoire longue utilisee
    // dans l'URL du Cron Job Hostinger qui declenche l'envoi des rappels.
    // Les reglages (active/desactive, delai, destinataires, expediteur) se
    // configurent depuis admin/reglages.php, pas ici.
    'reminder_token' => 'REMPLACER_PAR_UNE_CHAINE_ALEATOIRE',

    // --- Serveur SMTP pour l'envoi des rappels par email (facultatif mais recommande) ---
    // Si 'smtp_host' est laisse vide, l'envoi se rabat sur la fonction
    // mail() native de PHP (aucune config necessaire, mais les emails
    // atterrissent plus facilement en indesirables). En renseignant ces
    // champs, les emails sont envoyes via une vraie boite mail
    // authentifiee (SPF/DKIM alignes), ce qui ameliore nettement la
    // delivrabilite. Voir Guide_installation_hostinger.md, section
    // "Rappels par email", pour la marche a suivre complete.
    'smtp_host' => '',                  // ex : 'smtp.hostinger.com'
    'smtp_port' => 587,                 // 587 pour 'tls' (STARTTLS), 465 pour 'ssl'
    'smtp_securite' => 'tls',           // 'tls' ou 'ssl'
    'smtp_utilisateur' => '',           // ex : 'agenda@votre-domaine.be' (adresse complete de la boite)
    'smtp_mot_de_passe' => '',          // mot de passe de cette boite mail (pas votre mot de passe hPanel)

    // --- LES PERSONNES SE GERENT DANS LE SITE, PLUS ICI ---------------
    //
    // Depuis la migration 0021, les personnes vivent dans la table
    // "persons" et se gerent depuis /admin/personnes.php : ajouter
    // quelqu'un, le renommer ou le desactiver ne demande plus de toucher
    // a ce fichier ni de redeployer. Renommer y est sans danger, toutes
    // ses donnees suivent (ce n'etait PAS le cas avant : changer un nom
    // ici faisait disparaitre ses medicaments et ses pathologies de
    // l'ecran, en silence).
    //
    // Les trois cles ci-dessous ne servent plus qu'a AMORCER une
    // installation neuve, tant que la table est vide : l'ecran
    // "Qui etes-vous ?" retombe dessus, et le bouton "Reprendre depuis
    // config.php" de /admin/personnes.php les recopie en base. Une fois
    // les personnes creees, elles ne sont plus lues.
    //
    //   personne_1, personne_2 : les PATIENTS (on suit leurs rendez-vous,
    //                            leurs medicaments, leurs pathologies).
    //   membres_famille        : qui peut se connecter. Facultatif - sans
    //                            elle, la liste par defaut ci-dessous
    //                            s'applique.
    'personne_1' => 'Papa',
    'personne_2' => 'Maman',
    // 'membres_famille' => ['Michel', 'Christiane', 'Helene', 'Laurent'],

    // --- Synchronisation Google Calendar (facultatif) ---
    'google_calendar_id' => '',
    'google_service_account_path' => __DIR__ . '/service-account.json',

];
