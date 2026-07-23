<?php
/**
 * fcm_send.php — Firebase Cloud Messaging (HTTP v1) sender for BharatGPS TaskManager.
 *
 * Reads the service-account key from public_html/fcm-key.json (one level above /api).
 * Exposes fcm_send_to_user($pdo, $userId, $title, $body, $data) which looks up the
 * user's stored fcm_token and sends a notification. Safe no-op if key/token missing.
 *
 * Uses only cURL + openssl (available on Hostinger PHP). No Composer / SDK needed.
 */

if (!function_exists('fcm_key_path')) {
    function fcm_key_path() {
        // /api/index.php -> ../fcm-key.json (public_html/fcm-key.json)
        return __DIR__ . '/../fcm-key.json';
    }
}

if (!function_exists('fcm_base64url')) {
    function fcm_base64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/**
 * Get an OAuth2 access token for FCM using the service-account key (JWT bearer flow).
 * Caches the token in a temp file until ~5 min before expiry to avoid re-signing each call.
 */
if (!function_exists('fcm_get_access_token')) {
    function fcm_get_access_token() {
        $keyPath = fcm_key_path();
        if (!is_readable($keyPath)) return null;
        $key = json_decode(file_get_contents($keyPath), true);
        if (!$key || empty($key['client_email']) || empty($key['private_key'])) return null;

        // Simple file cache
        $cacheFile = sys_get_temp_dir() . '/fcm_tok_' . md5($key['client_email']) . '.json';
        if (is_readable($cacheFile)) {
            $c = json_decode(file_get_contents($cacheFile), true);
            if ($c && !empty($c['access_token']) && !empty($c['exp']) && $c['exp'] > time() + 300) {
                return $c['access_token'];
            }
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim  = [
            'iss'   => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $jwtUnsigned = fcm_base64url(json_encode($header)) . '.' . fcm_base64url(json_encode($claim));
        $signature = '';
        if (!openssl_sign($jwtUnsigned, $signature, $key['private_key'], 'SHA256')) return null;
        $jwt = $jwtUnsigned . '.' . fcm_base64url($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!$resp) return null;
        $tok = json_decode($resp, true);
        if (empty($tok['access_token'])) return null;

        @file_put_contents($cacheFile, json_encode([
            'access_token' => $tok['access_token'],
            'exp'          => $now + intval($tok['expires_in'] ?? 3600),
        ]));
        return $tok['access_token'];
    }
}

/**
 * Send a push to a raw device token. Returns true on success.
 */
if (!function_exists('fcm_send_to_token')) {
    function fcm_send_to_token($fcmToken, $title, $body, $data = []) {
        if (!$fcmToken) return false;
        $keyPath = fcm_key_path();
        if (!is_readable($keyPath)) return false;
        $key = json_decode(file_get_contents($keyPath), true);
        $projectId = $key['project_id'] ?? null;
        if (!$projectId) return false;

        $accessToken = fcm_get_access_token();
        if (!$accessToken) return false;

        // All data values must be strings for FCM
        $strData = [];
        foreach ($data as $k => $v) { $strData[(string)$k] = (string)$v; }

        $message = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => (string)$title,
                    'body'  => (string)$body,
                ],
                'data' => $strData,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'bgps_tasks',
                        'sound'      => 'default',
                    ],
                ],
            ],
        ];

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($message),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $GLOBALS['_fcm_last_response'] = ['code'=>$code, 'body'=>substr((string)$resp,0,500)];
        if ($code < 200 || $code >= 300) { error_log('FCM send failed ('.$code.'): '.substr((string)$resp,0,300)); }
        return $code >= 200 && $code < 300;
    }
}

/**
 * Look up a user's stored fcm_token and send. Safe no-op if user has no token.
 * Never throws — wrap everything so a notification failure can't break the main API action.
 */
if (!function_exists('fcm_send_to_user')) {
    function fcm_send_to_user($pdo, $userId, $title, $body, $data = []) {
        try {
            if (!$userId) return false;
            // Store EVERY notification so the technician can re-read it later in Notification History,
            // even if the pop-up was missed or truncated on screen.
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS notification_log (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
                    title VARCHAR(200) DEFAULT NULL, body TEXT DEFAULT NULL,
                    type VARCHAR(50) DEFAULT NULL, task_id VARCHAR(50) DEFAULT NULL,
                    url VARCHAR(200) DEFAULT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_created (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->prepare("INSERT INTO notification_log (user_id,title,body,type,task_id,url) VALUES (?,?,?,?,?,?)")
                    ->execute([$userId, (string)$title, (string)$body,
                        (string)($data['type'] ?? ''), (string)($data['task_id'] ?? ''), (string)($data['url'] ?? '')]);
            } catch (Exception $e) { /* logging must never block the push */ }
            $st = $pdo->prepare("SELECT fcm_token FROM users WHERE id=? AND is_active=1");
            $st->execute([$userId]);
            $row = $st->fetch();
            $token = $row['fcm_token'] ?? null;
            if (!$token) return false;
            return fcm_send_to_token($token, $title, $body, $data);
        } catch (Exception $e) {
            return false;
        }
    }
}
