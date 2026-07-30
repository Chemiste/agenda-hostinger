<?php
/**
 * DIAGNOSTIC TEMPORAIRE : vide le cache du jeton d'acces Google Calendar
 * (voir lib/calendar_sync.php, getAccessToken()), qui peut contenir un
 * jeton obtenu avec un ancien compte de service (projet Google Cloud
 * supprimu) et rester "valide" jusqu'a expiration (jusqu'a 1h) meme apres
 * avoir remplace service-account.json.
 *
 * A SUPPRIMER une fois utilise.
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();

header('Content-Type: text/plain; charset=utf-8');

$fichier = sys_get_temp_dir() . '/agenda_medical_gcal_token.json';
$existait = file_exists($fichier);

if ($existait) {
    echo "Contenu avant suppression :\n" . file_get_contents($fichier) . "\n\n";
    $ok = @unlink($fichier);
    echo $ok ? "Fichier supprime avec succes.\n" : "ECHEC de la suppression (permissions ?).\n";
} else {
    echo "Aucun fichier de cache trouve a cet emplacement : $fichier\n";
    echo "(Le prochain appel a getAccessToken() en recreera un frais de toute facon.)\n";
}
