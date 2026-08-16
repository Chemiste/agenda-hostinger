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

    // --- Quel environnement ce fichier decrit-il ? 'dev' ou 'prod' ---
    // Sert de garde-fou (voir lib/db.php) : un config.php declarant 'prod'
    // refuse de demarrer sur une machine de developpement. Sans lui, une
    // copie malencontreuse de config_prod.php par-dessus config.php fait
    // travailler le site local dans les VRAIES donnees, sans rien signaler.
    // C'est deja arrive.
    'environnement' => 'prod',

    // --- Base de donnees MySQL/MariaDB ---
    'db_host' => 'localhost',
    'db_name' => 'REMPLACER_nom_de_la_base',
    'db_user' => 'REMPLACER_utilisateur',
    'db_pass' => 'REMPLACER_mot_de_passe_base',

    // --- Connexion des membres de la famille : compte Google ---
    // Identifiant client OAuth 2.0 de type "Application Web", cree dans la
    // console Google Cloud (voir Guide_installation_hostinger.md, section
    // "Connexion par compte Google"). Ce n'est PAS le compte de service
    // utilise pour Calendar : ce sont deux identifiants differents, dans le
    // meme projet.
    //
    // Il n'y a plus de mot de passe familial : il donnait l'acces sans
    // donner l'identite, et l'ecran "Qui etes-vous ?" qui le suivait
    // croyait sur parole le nom clique. Chaque personne est reliee a son
    // compte Google depuis /admin/personnes.php.
    'google_client_id' => 'REMPLACER_PAR_L_IDENTIFIANT_CLIENT.apps.googleusercontent.com',

    // --- Jeton d'installation (voir installer.php) ---
    // Chaine aleatoire de votre choix, utilisee UNE SEULE FOIS : elle
    // autorise installer.php a creer les tables et a designer le premier
    // administrateur, a partir du compte Google qui s'y connecte.
    //
    // Elle protege la fenetre entre le moment ou le site repond et celui ou
    // vous lancez l'installation : sans jeton, le premier venu qui trouve
    // l'adresse deviendrait administrateur, et repartirait avec l'agenda
    // medical de vos parents.
    //
    // Des qu'un administrateur existe, installer.php refuse de tourner et
    // ce jeton n'a plus aucun effet - vous pouvez le laisser ou l'effacer.
    // Ex : genere avec `openssl rand -hex 20`.
    //
    // Il n'y a AUCUN mot de passe sur ce site : tout le monde entre par son
    // compte Google, y compris pour administrer (drapeau "Administre" dans
    // /admin/personnes.php).
    'installation_token' => 'REMPLACER_PAR_UNE_CHAINE_ALEATOIRE',

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

    // --- LES PERSONNES NE SONT PLUS ICI -------------------------------
    // Elles vivent dans la table "persons" et se gerent depuis
    // /admin/personnes.php : ajouter quelqu'un, le renommer ou le
    // desactiver ne demande ni de toucher a ce fichier ni de redeployer.
    // Renommer y est sans danger, toutes ses donnees suivent.
    // (Sur une installation neuve : creez-les depuis l'administration
    // AVANT de vous identifier - /admin/ ne demande que les deux mots de
    // passe, pas l'ecran "Qui etes-vous ?".)

    // --- Synchronisation Google Calendar (facultatif) ---
    'google_calendar_id' => '',
    'google_service_account_path' => __DIR__ . '/service-account.json',

];
