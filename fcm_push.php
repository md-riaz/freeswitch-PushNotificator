<?php

// show errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('SERVICE_ACCOUNT_KEY',  __DIR__ . '/service-account.json');
define('TOKEN_CACHE_FILE',  __DIR__ . '/fcm_access_token.json');
define('FCM_SCOPES', 'https://www.googleapis.com/auth/firebase.messaging');
define('LOG_FILE', __DIR__ . '/fcm_log.txt'); // New log file constant

// APNs authentication configuration for VoIP pushes
define('APNS_AUTH_KEY', __DIR__ . '/AuthKey.p8'); // Path to .p8 key file
define('APNS_KEY_ID', 'YOUR_KEY_ID'); // Apple key ID
define('APNS_TEAM_ID', 'YOUR_TEAM_ID'); // Apple developer team ID
define('APNS_BUNDLE_ID', 'com.example.app.voip'); // VoIP bundle identifier
define('APNS_HOST', 'api.push.apple.com'); // Use api.development.push.apple.com for sandbox

/**
 * Get Firebase project ID and credentials from JSON.
 */
function getFirebaseCredentials()
{
    if (!file_exists(SERVICE_ACCOUNT_KEY)) {
        throw new Exception('Service account JSON file not found.');
    }

    $json = json_decode(file_get_contents(SERVICE_ACCOUNT_KEY), true);

    if (empty($json['client_email']) || empty($json['private_key']) || empty($json['project_id'])) {
        throw new Exception('Invalid service account JSON.');
    }

    return [
        'client_email' => $json['client_email'],
        'private_key' => $json['private_key'],
        'project_id' => $json['project_id']
    ];
}

/**
 * Get or generate a valid access token.
 */
function getAccessToken($credentials)
{
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cached = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if (!empty($cached['access_token']) && $cached['expires_at'] > time()) {
            return $cached['access_token'];
        }
    }

    $jwtHeader = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwtPayload = base64UrlEncode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => FCM_SCOPES,
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => time() + 3600,
        'iat' => time()
    ]));

    $signature = '';
    openssl_sign("$jwtHeader.$jwtPayload", $signature, $credentials['private_key'], 'sha256');
    $jwt = "$jwtHeader.$jwtPayload." . base64UrlEncode($signature);

    $response = httpPost('https://oauth2.googleapis.com/token', json_encode([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]), ['Content-Type: application/json']);

    if (empty($response['access_token'])) {
        throw new Exception('Failed to obtain access token.');
    }

    file_put_contents(TOKEN_CACHE_FILE, json_encode([
        'access_token' => $response['access_token'],
        'expires_at' => time() + 3500
    ]));

    return $response['access_token'];
}

/**
 * Parse the device token from the argument.
 */
function parseDeviceToken($input)
{
    if (!empty($input['token'])) {
        return $input['token'];
    }
    throw new Exception('Device token not found in the request.');
}

/**
 * Send FCM push notification using JSON payload.
 */
function sendPushNotification($token, $deviceToken, $projectId, $data = [])
{
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'data' => $data,
            'android' => [
                'priority' => 'high'
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10'
                ],
                'payload' => [
                    'aps' => [
                        'content-available' => 1
                    ]
                ]
            ]
        ]
    ];

    $response = httpPost(
        "https://fcm.googleapis.com/v1/projects/$projectId/messages:send",
        json_encode($payload),
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]
    );

    if (empty($response['name'])) {
        throw new Exception(empty($response) ? 'Failed to send push notification.' : json_encode($response));
    }

    logMessage("Notification sent successfully. Response ID: {$response['name']}"); // Log success
    echo "Notification sent successfully. Response ID: {$response['name']}\n";
}

/**
 * Generate JWT for APNs authentication.
 */
function getApnsJwt()
{
    if (!file_exists(APNS_AUTH_KEY)) {
        throw new Exception('APNs auth key file not found.');
    }

    $header = base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => APNS_KEY_ID]));
    $claims = base64UrlEncode(json_encode(['iss' => APNS_TEAM_ID, 'iat' => time()]));

    $signature = '';
    $keyContent = file_get_contents(APNS_AUTH_KEY);
    $privateKey = openssl_pkey_get_private($keyContent);
    if (!$privateKey) {
        throw new Exception('Failed to parse APNs private key. Ensure it is a valid, unencrypted .p8 file.');
    }
    openssl_sign("$header.$claims", $signature, $privateKey, 'sha256');

    return "$header.$claims." . base64UrlEncode($signature);
}

/**
 * Send VoIP push notification directly via APNs.
 */
function sendVoipPushNotification($deviceToken, $data = [])
{
    $jwt = getApnsJwt();

    // VoIP pushes don't require the aps dictionary when using the
    // apns-push-type header, so send data directly as the payload.
    $payload = $data;

    $ch = curl_init("https://" . APNS_HOST . "/3/device/{$deviceToken}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'authorization: bearer ' . $jwt,
        'apns-topic: ' . APNS_BUNDLE_ID,
        'apns-push-type: voip',
        'apns-priority: 10',
        'content-type: application/json'
    ]);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception('Curl Error: ' . curl_error($ch));
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200) {
        throw new Exception('APNs Error: ' . $result);
    }

    logMessage("VoIP notification sent successfully. Response: {$result}");
    echo "VoIP notification sent successfully.\n";
}

/**
 * Helper function to make HTTP POST requests.
 */
function httpPost($url, $data, $headers)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('Curl Error: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception('HTTP request failed with status ' . $httpCode);
    }

    return json_decode($result, true);
}

/**
 * Base64 URL Encode.
 */
function base64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Log messages with a timestamp.
 */
function logMessage($message)
{
    $timestamp = (new DateTime('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "$timestamp - $message\n", FILE_APPEND);
}

// Main Execution
try {
    $inputData = $_REQUEST;

    if (empty($inputData)) {
        throw new Exception('No input provided.');
    }

    $deviceToken = parseDeviceToken($inputData);

    $dataPayload = [
        'type' => $inputData['type'] ?? '',
        'call_id' => $inputData['aleg_uuid'] ?? '',
        'sip_call_id' => $inputData['x_call_id'] ?? '',
        'app_id' => $inputData['app_id'] ?? '',
        'user' => $inputData['user'] ?? '',
        'realm' => $inputData['realm'] ?? '',
        'platform' => $inputData['platform'] ?? '',
        'cid_name' => $inputData['cid_name'] ?? '',
        'cid_number' => $inputData['cid_number'] ?? '',
        'payload' => json_decode($inputData['payload'] ?? 'null', true)
    ];

    $type = strtolower($inputData['type'] ?? '');
    $platform = strtolower($inputData['platform'] ?? $inputData['pn-platform'] ?? '');

    if ($type === 'voip' && $platform === 'ios') {
        sendVoipPushNotification($deviceToken, $dataPayload);
    } else {
        $credentials = getFirebaseCredentials();
        $accessToken = getAccessToken($credentials);
        sendPushNotification($accessToken, $deviceToken, $credentials['project_id'], $dataPayload);
    }

    logMessage("Push notification sent to device token: $deviceToken with data: " . json_encode($inputData)); // Log success

} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage()); // Log error
    echo "Error: " . $e->getMessage() . "\n";
}
