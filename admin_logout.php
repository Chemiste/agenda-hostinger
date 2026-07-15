<?php
// Referme uniquement la session d'administration (garde la session
// familiale ouverte) : utile si vous partagez l'appareil ensuite.
require_once __DIR__ . '/lib/auth.php';
requireLogin();
adminLogout();
header('Location: index.php');
exit;