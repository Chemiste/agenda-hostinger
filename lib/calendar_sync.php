<?php
/**
 * Synchronisation vers Google Calendar (sens unique : site -> Calendar),
 * via un compte de service Google (pas de dependance Composer, juste
 * les extensions PHP openssl + curl qui sont activees par defaut sur
 * la quasi-totalite des hebergements, dont Hostinger).
 *
 * Voir le guide d'installation pour creer le compte de service et
 * partager votre calendrier avec lui.
 */

class CalendarSync {

    private $serviceAccountPath;
    private $calendarId;
    private $tokenCacheFile;

    public function __construct($serviceAccountPath, $calendarId) {
        $this->serviceAccountPath = $serviceAccountPath;
        $this->calendarId = $calendarId;
        $this->tokenCacheFile = sys_get_temp_dir() . '/agenda_medical_gcal_token.json';
    }

    public function isEnabled() {
        return $this->calendarId !== '' && $this->serviceAccountPath && file_exists($this->serviceAccountPath);
    }

    public function createEvent($appt) {
        if (!$this->isEnabled()) return '';
        try {
            $result = $this->request('POST', '/events', $this->buildEventPayload($appt));
            if ($result['code'] >= 200 && $result['code'] < 300 && !empty($result['body']['id'])) {
                return $result['body']['id'];
            }
        } catch (Exception $e) {
            error_log('CalendarSync createEvent: ' . $e->getMessage());
        }
        return '';
    }

    public function updateEvent($eventId, $appt) {
        if (!$this->isEnabled()) return $eventId;
        if (!$eventId) return $this->createEvent($appt);
        try {
            $result = $this->request('PATCH', '/events/' . rawurlencode($eventId), $this->buildEventPayload($appt));
            if ($result['code'] >= 200 && $result['code'] < 300) {
                return $eventId;
            }
            if ($result['code'] === 404 || $result['code'] === 410) {
                return $this->createEvent($appt);
            }
        } catch (Exception $e) {
            error_log('CalendarSync updateEvent: ' . $e->getMessage());
        }
        return $eventId;
    }

    public function deleteEvent($eventId) {
        if (!$this->isEnabled() || !$eventId) return;
        try {
            $this->request('DELETE', '/events/' . rawurlencode($eventId));
        } catch (Exception $e) {
            error_log('CalendarSync deleteEvent: ' . $e->getMessage());
        }
    }

    /**
     * Liste les evenements existants du calendrier entre deux dates
     * (format ISO 8601, ex : '2026-01-01T00:00:00Z'). Utilise pour
     * l'import ponctuel des rendez-vous deja presents dans le calendrier
     * (voir import_calendar.php). Les evenements recurrents sont
     * "deplies" en occurrences individuelles (singleEvents=true).
     */
    public function listEvents($timeMin, $timeMax) {
        if (!$this->isEnabled()) return [];

        $tousLesEvenements = [];
        $pageToken = null;

        do {
            $params = [
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $result = $this->request('GET', '/events?' . http_build_query($params));
            if ($result['code'] < 200 || $result['code'] >= 300) {
                throw new Exception('Impossible de lire le calendrier (code ' . $result['code'] . ').');
            }

            $body = $result['body'];
            foreach (($body['items'] ?? []) as $item) {
                $tousLesEvenements[] = $item;
            }
            $pageToken = $body['nextPageToken'] ?? null;
        } while ($pageToken);

        return $tousLesEvenements;
    }

    private function eventPrefix($person) {
        $map = ['Papa' => '[Papa] ', 'Maman' => '[Maman] ', 'Les deux' => '[Papa & Maman] '];
        return isset($map[$person]) ? $map[$person] : '';
    }

    private function buildEventPayload($appt) {
        $start = $appt['date'] . 'T' . $appt['time'] . ':00';
        $endDt = new DateTime($start);
        $endDt->modify('+30 minutes');

        return [
            'summary' => $this->eventPrefix($appt['person']) . (!empty($appt['doctor']) ? $appt['doctor'] : 'Rendez-vous'),
            'location' => !empty($appt['doctor']) ? $appt['doctor'] : '',
            'description' => $this->buildDescription($appt),
            'start' => ['dateTime' => $start, 'timeZone' => 'Europe/Paris'],
            'end' => ['dateTime' => $endDt->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Paris'],
        ];
    }

    // Le departement (s'il y en a un) est place devant la note, separe par
    // un saut de ligne. Ex : departement="Cardiologie", notes="Bonjour"
    // -> description = "Cardiologie\nBonjour".
    private function buildDescription($appt) {
        $departement = !empty($appt['department']) ? $appt['department'] : '';
        $notes = !empty($appt['notes']) ? $appt['notes'] : '';

        if ($departement !== '' && $notes !== '') {
            return $departement . "\n" . $notes;
        }
        return $departement . $notes;
    }

    private function base64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function getAccessToken() {
        if (file_exists($this->tokenCacheFile)) {
            $cached = json_decode(file_get_contents($this->tokenCacheFile), true);
            if ($cached && !empty($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
                return $cached['access_token'];
            }
        }

        $key = json_decode(file_get_contents($this->serviceAccountPath), true);
        if (!$key || empty($key['private_key']) || empty($key['client_email'])) {
            throw new Exception('Fichier service-account.json invalide.');
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

        $segments = [
            $this->base64url(json_encode($header)),
            $this->base64url(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $key['private_key'], 'sha256WithRSAEncryption');
        if (!$ok) {
            throw new Exception('Impossible de signer le jeton JWT.');
        }
        $segments[] = $this->base64url($signature);
        $jwt = implode('.', $segments);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            throw new Exception('Authentification Google Calendar refusee : ' . $response);
        }

        @file_put_contents($this->tokenCacheFile, json_encode([
            'access_token' => $data['access_token'],
            'expires_at' => $now + (isset($data['expires_in']) ? $data['expires_in'] : 3600),
        ]));

        return $data['access_token'];
    }

    private function request($method, $path, $body = null) {
        $token = $this->getAccessToken();
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($this->calendarId) . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => json_decode($response, true)];
    }
}