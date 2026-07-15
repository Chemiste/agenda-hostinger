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

/**
 * Deuxieme niveau de protection pour les pages d'administration
 * (nettoyage des donnees, import .ics, sauvegardes...), avec un mot de
 * passe distinct du mot de passe familial. Objectif : meme si quelqu'un
 * de la famille tombe sur l'URL d'une page admin, il lui faut un
 * deuxieme mot de passe (connu de vous seul) pour y entrer.
 */

function isAdminLoggedIn() {
    return !empty($_SESSION['admin_logged_in']);
}

function requireAdminLogin() {
    requireLogin();
    if (!isAdminLoggedIn()) {
        header('Location: admin_login.php');
        exit;
    }
}

function attemptAdminLogin($password) {
    $config = require __DIR__ . '/../config.php';
    if (!isset($config['admin_password_hash']) || $config['admin_password_hash'] === 'REMPLACER_PAR_LE_HASH_GENERE') {
        return false;
    }
    if (password_verify($password, $config['admin_password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

function adminLogout() {
    unset($_SESSION['admin_logged_in']);
}