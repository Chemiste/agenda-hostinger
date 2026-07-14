<?php
/**
 * Gestion de la connexion (un seul mot de passe familial partage).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return !empty($_SESSION['logged_in']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function attemptLogin($password) {
    $config = require __DIR__ . '/../config.php';
    if (!isset($config['family_password_hash']) || $config['family_password_hash'] === 'REMPLACER_PAR_LE_HASH_GENERE') {
        return false;
    }
    if (password_verify($password, $config['family_password_hash'])) {
        $_SESSION['logged_in'] = true;
        return true;
    }
    return false;
}

function logout() {
    $_SESSION = [];
    session_destroy();
}
