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

    // --- Mot de passe familial (hash bcrypt, genere via generate_password.php) ---
    'family_password_hash' => 'REMPLACER_PAR_LE_HASH_GENERE',

    // --- Synchronisation Google Calendar (facultatif) ---
    'google_calendar_id' => '',
    'google_service_account_path' => __DIR__ . '/service-account.json',

];
