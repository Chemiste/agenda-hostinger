<?php
/**
 * Reglages modifiables depuis une page d'administration (table
 * "settings", simple cle/valeur - voir migrations/0007_add_settings.sql),
 * sans avoir a toucher config.php ni redeployer le site. Utilise pour le
 * moment par les reglages des rappels par email (admin/reglages.php).
 */

function getSetting($db, $key, $default = '') {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $valeur = $stmt->fetchColumn();
    return $valeur === false ? $default : $valeur;
}

function setSetting($db, $key, $value) {
    $stmt = $db->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ' .
        'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}
