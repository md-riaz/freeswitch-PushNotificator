<?php

// show errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('SERVICE_ACCOUNT_KEY',  __DIR__ . '/service-account.json');
define('TOKEN_CACHE_FILE',  __DIR__ . '/fcm_access_token.json');
define('FCM_SCOPES', 'https://www.googleapis.com/auth/firebase.messaging');
define('LOG_FILE', __DIR__ . '/fcm_log.txt'); // New log file constant

/**
 * Get Firebase project ID and credentials from JSON.
 */
function getFirebaseCredentials()
{
    echo SERVICE_ACCOUNT_KEY;

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
function parseDeviceTokens($input)
{
    if (!empty($input['token'])) {
        return $input['token'];
    }
    throw new Exception('Device token not found in the input string.');
}

/**
 * Send FCM push notification using JSON payload.
 */
function sendPushNotification($token, $deviceToken, $projectId, $title, $body, $data = [])
{
    $payload = [
        'message' => [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $body
            ],
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
                        'alert' => [
                            'title' => $title,
                            'body' => $body
                        ],
                        'sound' => 'default'
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
 * Helper function to make HTTP POST requests.
 */
function httpPost($url, $data, $headers)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception('Curl Error: ' . curl_error($ch));
    }

    curl_close($ch);

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

    $credentials = getFirebaseCredentials();
    $accessToken = getAccessToken($credentials);
    $deviceToken = parseDeviceTokens($inputData);
    

    sendPushNotification(
        $accessToken,
        $deviceToken,
        $credentials['project_id'],
        !empty($inputData['cid_name']) ? $inputData['cid_name'] : 'Incoming Call',
        !empty($inputData['cid_number']) ? $inputData['cid_number'] : 'You have an incoming call.',
        [
            'type' => 'incoming_call',
            'call_id' => $inputData['aleg_uuid'],
            'app_id' => $inputData['app_id'],
            'user' => $inputData['user'],
            'realm' => $inputData['realm'],
            'platform' => $inputData['platform'],
            'payload' => $inputData['payload']
        ]
    );

    logMessage("Push notification sent to device token: $deviceToken with data: ".json_encode($inputData)); // Log success

} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage()); // Log error
    echo "Error: " . $e->getMessage() . "\n";
}
