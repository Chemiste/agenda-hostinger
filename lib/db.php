<?php
/**
 * Connexion a la base de donnees MySQL (PDO), et fuseau horaire de
 * l'application.
 */

/**
 * Fuseau horaire de reference : celui de Michel et Christiane.
 *
 * Il est fixe ici plutot que laisse au reglage de l'hebergeur, pour deux
 * raisons. D'abord parce que le serveur etait livre en UTC : taches.php
 * comparait un date('Y-m-d') UTC a la date cible d'une tache, et entre
 * minuit et 2h du matin (heure d'ete) PHP croyait qu'on etait encore la
 * veille. Ensuite parce qu'un reglage d'hebergeur se perd - a un
 * changement d'offre, a une remise a zero, a un demenagement - alors que
 * cette ligne suit le code.
 *
 * C'est lib/db.php qui la porte parce que c'est le seul fichier inclus
 * par TOUS les points d'entree qui manipulent des dates (les 11 verifies
 * un par un : taches, historique, les deux crons, les pages admin et les
 * outils). Un fichier d'amorcage dedie aurait demande de modifier chaque
 * page pour un unique appel.
 *
 * Ceci ne regle que PHP : MySQL a sa propre horloge, alignee sur celle-ci
 * juste en dessous dans getDb(). Les deux doivent rester d'accord, sinon
 * les rappels repartent de travers (voir le commentaire de SET time_zone).
 */
date_default_timezone_set('Europe/Brussels');

function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $config = require __DIR__ . '/../config.php';
        $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // MySQL est regle sur SYSTEM chez Hostinger, c'est-a-dire UTC :
        // NOW() renvoyait deux heures de moins que l'heure belge en ete.
        // Ce n'etait pas cosmetique - cron/rappels.php compare des heures
        // de rendez-vous notees en heure locale a NOW(), donc un rappel
        // regle sur 24h partait en fait 22h avant.
        //
        // On envoie un DECALAGE ("+02:00") et non un nom de fuseau
        // ("Europe/Brussels") : les noms exigent que les tables
        // mysql.time_zone_* soient chargees, ce que les hebergements
        // mutualises ne font generalement pas. Le decalage est recalcule a
        // chaque connexion depuis le fuseau de PHP, donc il suit le
        // passage a l'heure d'hiver sans rien avoir a changer.
        $decalage = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        $pdo->exec("SET time_zone = '" . $decalage . "'");
    }
    return $pdo;
}
