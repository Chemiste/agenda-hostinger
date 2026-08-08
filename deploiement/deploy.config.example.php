<?php
/**
 * MODELE de configuration FTP pour deploiement/deployer.php - a copier en
 * "deploy.config.php" puis a remplir avec vos identifiants Hostinger :
 *
 *   cp deploiement/deploy.config.example.php deploiement/deploy.config.php
 *
 * Ce fichier contient un mot de passe : il est volontairement exclu de
 * Git (voir .gitignore) et ne doit jamais etre commite.
 *
 * Les identifiants FTP se trouvent dans hPanel > Fichiers > Comptes FTP.
 */
return [

    'ftp_host' => 'REMPLACER_ftp.votre-domaine.be',
    'ftp_port' => 21,

    // FTPS (chiffre) plutot que FTP en clair - Hostinger le propose,
    // fortement recommande puisque le mot de passe circule sinon en clair
    // sur le reseau a chaque connexion.
    'ftp_ssl' => true,

    'ftp_user' => 'REMPLACER_utilisateur_ftp',
    'ftp_pass' => 'REMPLACER_mot_de_passe_ftp',

    // Chemin distant vers la racine du site sur l'hebergement (souvent
    // "/public_html" ou "/public_html/nom-du-sous-dossier" selon votre
    // configuration Hostinger - verifiez avec votre client FTP habituel
    // avant le premier lancement).
    'ftp_remote_path' => '/public_html',

];
