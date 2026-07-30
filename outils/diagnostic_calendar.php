<?php
/**
 * DIAGNOSTIC TEMPORAIRE : pourquoi la synchro Google Calendar ne cree pas
 * d'evenements, sans aucune erreur dans error_log.
 *
 * La classe CalendarSync (lib/calendar_sync.php) avale les echecs reseau
 * silencieusement : si curl_exec() echoue (DNS, SSL, pare-feu sortant...),
 * le code ne leve aucune exception (donc rien dans error_log) et se
 * contente de retourner une chaine vide. Ce script reproduit les memes
 * appels mais affiche TOUT directement dans la page (statut HTTP, corps de
 * reponse, et surtout le message curl_error(), jamais capture par le code
 * normal), pour identifier la cause exacte.
 *
 * A SUPPRIMER une fois le diagnostic termine (contient des informations
 * techniques sensibles sur la configuration serveur).
 */

require_once __DIR__ . '/../lib/auth.php';
requireAdminLogin();

$config = require __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Diagnostic synchro Google Calendar ===\n\n";

$calendarId = isset($config['google_calendar_id']) ? $config['google_calendar_id'] : '';
$serviceAccountPath = isset($config['google_service_account_path']) ? $config['google_service_account_path'] : '';

echo "google_calendar_id : " . ($calendarId !== '' ? "rempli (" . strlen($calendarId) . " caracteres)" : "VIDE") . "\n";
echo "service_account_path : " . $serviceAccountPath . "\n";
echo "fichier existe : " . (file_exists($serviceAccountPath) ? "oui" : "NON") . "\n\n";

if (!file_exists($serviceAccountPath) || $calendarId === '') {
    echo "ARRET : isEnabled() renverrait false, aucun appel reseau ne serait tente.\n";
    exit;
}

echo "--- Lecture du fichier service-account.json ---\n";
$key = json_decode(file_get_contents($serviceAccountPath), true);
if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
    echo "ECHEC : fichier invalide ou illisible (JSON mal forme, cle privee ou client_email manquant).\n";
    echo "Extrait brut (200 premiers caracteres) : " . substr(file_get_contents($serviceAccountPath), 0, 200) . "\n";
    exit;
}
echo "client_email : " . $key['client_email'] . "\n";
echo "cle privee presente, commence par : " . substr($key['private_key'], 0, 27) . "\n\n";

echo "--- Etape 1 : demande de jeton d'acces (oauth2.googleapis.com) ---\n";

function base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$now = time();
$header = ['alg' => 'RS256', 'typ' => 'JWT'];
$claims = [
    'iss' => $key['client_email'],
    'scope' => 'https://www.googleapis.com/auth/calendar',
    'aud' => 'https://oauth2.googleapis.com/token',
    'iat' => $now,
    'exp' => $now + 3600,
];
$segments = [base64url(json_encode($header)), base64url(json_encode($claims))];
$signingInput = implode('.', $segments);
$signature = '';
$okSign = openssl_sign($signingInput, $signature, $key['private_key'], 'sha256WithRSAEncryption');
echo "Signature JWT (openssl_sign) : " . ($okSign ? "OK" : "ECHEC - cle privee invalide ou extension openssl manquante") . "\n";
if (!$okSign) exit;
$segments[] = base64url($signature);
$jwt = implode('.', $segments);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion' => $jwt,
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
curl_close($ch);

echo "Code HTTP : " . $httpCode . "\n";
echo "curl_errno : " . $curlErrno . "\n";
echo "curl_error : " . ($curlError !== '' ? $curlError : "(aucune)") . "\n";
echo "Reponse brute : " . substr((string) $response, 0, 500) . "\n\n";

$data = json_decode($response, true);
if ($httpCode !== 200 || empty($data['access_token'])) {
    echo "ECHEC a l'obtention du jeton - la synchro s'arrete ici dans le code normal (silencieusement).\n";
    exit;
}
echo "Jeton obtenu avec succes.\n\n";

echo "--- Etape 2 : verification d'acces au calendrier (GET calendars/" . $calendarId . ") ---\n";
$ch2 = curl_init('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId));
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $data['access_token']]);
$response2 = curl_exec($ch2);
$httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$curlError2 = curl_error($ch2);
curl_close($ch2);

echo "Code HTTP : " . $httpCode2 . "\n";
echo "curl_error : " . ($curlError2 !== '' ? $curlError2 : "(aucune)") . "\n";
echo "Reponse brute : " . substr((string) $response2, 0, 500) . "\n\n";

if ($httpCode2 === 200) {
    echo "SUCCES : le compte de service a bien acces au calendrier. La synchro devrait fonctionner.\n";
} elseif ($httpCode2 === 403 || $httpCode2 === 404) {
    echo "PROBLEME DE PARTAGE : le calendrier n'est probablement pas partage avec " . $key['client_email'] . " (ou pas avec les bonnes permissions).\n";
} else {
    echo "Echec inattendu, voir le code et la reponse ci-dessus.\n";
}
