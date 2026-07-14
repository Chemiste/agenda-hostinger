<?php
/**
 * Connexion a la base de donnees MySQL (PDO).
 */

function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $config = require __DIR__ . '/../config.php';
        $dsn = 'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
