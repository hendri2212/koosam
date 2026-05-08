<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const MASOOK_BASE_URL = 'https://api.masook.id';
const MASOOK_APP_VERSION = '1.43.1';
const MASOOK_REFRESH_APP = 'bkpsdm-kotabaru';

function request_api(string $method, string $url, array $headers = [], array $payload = []): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;

        $isJson = false;
        foreach ($headers as $h) {
            if (stripos($h, 'Content-Type:') !== false && stripos($h, 'application/json') !== false) {
                $isJson = true;
                break;
            }
        }

        $options[CURLOPT_POSTFIELDS] = $isJson
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : http_build_query($payload);
    }

    curl_setopt_array($ch, $options);
    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($rawResponse === false) {
        throw new RuntimeException($curlError ?: 'Request gagal tanpa pesan error dari cURL.');
    }

    $body = substr($rawResponse, $headerSize);
    $json = json_decode($body, true);

    return [
        'status_code' => $statusCode,
        'headers' => substr($rawResponse, 0, $headerSize),
        'body' => $json ?? $body,
    ];
}

function masook_common_headers(?string $appVersion = null): array
{
    return [
        'Accept: application/json',
        'User-Agent: okhttp/4.12.0',
        'x-masook-version-android: ' . ($appVersion ?: MASOOK_APP_VERSION),
    ];
}

function jwt_payload(?string $jwt): array
{
    if (!$jwt || substr_count($jwt, '.') < 2) {
        return [];
    }

    $parts = explode('.', $jwt);
    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $decoded = json_decode((string) base64_decode($payload), true);

    return is_array($decoded) ? $decoded : [];
}

function jwt_sub(?string $jwt): string
{
    $payload = jwt_payload($jwt);

    return isset($payload['sub']) ? (string) $payload['sub'] : '';
}

function jwt_expires_at(?string $jwt): ?string
{
    $payload = jwt_payload($jwt);
    if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
        return null;
    }

    return date('Y-m-d H:i:s', (int) $payload['exp']);
}

function seconds_from_now_expires_at($seconds): ?string
{
    if (!is_numeric($seconds)) {
        return null;
    }

    return date('Y-m-d H:i:s', time() + (int) $seconds);
}

function masook_login(string $username, string $password, ?string $appVersion = null): array
{
    return request_api('POST', MASOOK_BASE_URL . '/api/login', array_merge(masook_common_headers($appVersion), [
        'Content-Type: application/x-www-form-urlencoded',
    ]), [
        'username' => $username,
        'password' => $password,
        'kode' => '',
    ]);
}

function masook_refresh_token(string $refreshToken, ?string $appVersion = null): array
{
    $url = MASOOK_BASE_URL . '/api/refresh?' . http_build_query([
        'app' => MASOOK_REFRESH_APP,
    ]);

    return request_api('POST', $url, array_merge(masook_common_headers($appVersion), [
        'Content-Type: application/x-www-form-urlencoded',
    ]), [
        'refresh_token' => $refreshToken,
    ]);
}

function save_masook_session(
    string $username,
    string $accessToken,
    ?string $refreshToken,
    ?string $userId,
    ?string $organisasiId,
    ?string $appVersion,
    int $loginStatusCode,
    array $loginBody
): void {
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userStmt = $pdo->prepare(
            "INSERT INTO users
                (username, masook_user_id, organisasi_id)
             VALUES
                (:username, :masook_user_id, :organisasi_id)
             ON DUPLICATE KEY UPDATE
                masook_user_id = VALUES(masook_user_id),
                organisasi_id = COALESCE(VALUES(organisasi_id), organisasi_id)"
        );
        $userStmt->execute([
            'username' => $username,
            'masook_user_id' => $userId ?: null,
            'organisasi_id' => $organisasiId ?: null,
        ]);

        $lookupStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $lookupStmt->execute(['username' => $username]);
        $localUserId = (int) $lookupStmt->fetchColumn();

        if ($localUserId <= 0) {
            throw new RuntimeException('User lokal tidak ditemukan setelah penyimpanan.');
        }

        $sessionStmt = $pdo->prepare(
            "INSERT INTO sessions
            (user_id, access_token, refresh_token, token_type, expires_at, refresh_expires_at, app_version, login_status_code, login_response, last_login_at)
         VALUES
            (:user_id, :access_token, :refresh_token, 'Bearer', :expires_at, :refresh_expires_at, :app_version, :login_status_code, :login_response, NOW())
         ON DUPLICATE KEY UPDATE
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            token_type = VALUES(token_type),
            expires_at = VALUES(expires_at),
            refresh_expires_at = VALUES(refresh_expires_at),
            app_version = VALUES(app_version),
            login_status_code = VALUES(login_status_code),
            login_response = VALUES(login_response),
            last_login_at = NOW()"
        );

        $sessionStmt->execute([
            'user_id' => $localUserId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken ?: null,
            'expires_at' => jwt_expires_at($accessToken),
            'refresh_expires_at' => null,
            'app_version' => $appVersion ?: MASOOK_APP_VERSION,
            'login_status_code' => $loginStatusCode,
            'login_response' => json_encode($loginBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $pdo->commit();
    } catch (Throwable $throwable) {
        $pdo->rollBack();
        throw $throwable;
    }
}

function update_masook_session_tokens(
    int $sessionId,
    string $accessToken,
    ?string $refreshToken,
    ?string $userId,
    int $refreshStatusCode,
    array $refreshBody
): void {
    $stmt = db()->prepare(
        "UPDATE sessions
         SET access_token = :access_token,
             refresh_token = COALESCE(:refresh_token, refresh_token),
             expires_at = :expires_at,
             refresh_status_code = :refresh_status_code,
             refresh_response = :refresh_response,
             last_refresh_at = NOW()
         WHERE id = :id"
    );

    $stmt->execute([
        'id' => $sessionId,
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken ?: null,
        'expires_at' => jwt_expires_at($accessToken),
        'refresh_status_code' => $refreshStatusCode,
        'refresh_response' => json_encode($refreshBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    if ($userId) {
        $userStmt = db()->prepare(
            "UPDATE users
             INNER JOIN sessions ON sessions.user_id = users.id
             SET users.masook_user_id = :masook_user_id
             WHERE sessions.id = :session_id"
        );
        $userStmt->execute([
            'session_id' => $sessionId,
            'masook_user_id' => $userId,
        ]);
    }
}

function db_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function masook_session_select_sql(): string
{
    $latitudeSelect = db_column_exists('users', 'latitude') ? 'users.latitude' : "NULL AS latitude";
    $longitudeSelect = db_column_exists('users', 'longitude') ? 'users.longitude' : "NULL AS longitude";
    $nomorHandphoneSelect = db_column_exists('users', 'nomor_handphone') ? 'users.nomor_handphone' : "NULL AS nomor_handphone";

    return "SELECT
            sessions.id,
            sessions.user_id AS local_user_id,
            users.username,
            users.masook_user_id AS user_id,
            {$nomorHandphoneSelect},
            users.organisasi_id,
            users.organisasi_kode,
            {$latitudeSelect},
            {$longitudeSelect},
            sessions.access_token,
            sessions.refresh_token,
            sessions.token_type,
            sessions.expires_at,
            sessions.refresh_expires_at,
            sessions.app_version,
            sessions.login_status_code,
            sessions.login_response,
            sessions.refresh_status_code,
            sessions.refresh_response,
            sessions.last_refresh_at,
            sessions.last_login_at,
            sessions.created_at,
            sessions.updated_at
        FROM sessions
        INNER JOIN users ON users.id = sessions.user_id";
}

function find_masook_session_by_username(string $username): ?array
{
    $stmt = db()->prepare(masook_session_select_sql() . ' WHERE users.username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $session = $stmt->fetch();

    return $session ?: null;
}

function find_masook_session_by_user_id(string $userId): ?array
{
    $stmt = db()->prepare(masook_session_select_sql() . ' WHERE users.masook_user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $session = $stmt->fetch();

    return $session ?: null;
}

function find_masook_session_by_phone(string $phone): ?array
{
    if (!db_column_exists('users', 'nomor_handphone')) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return null;
    }

    $candidates = [$digits];
    if (str_starts_with($digits, '0')) {
        $candidates[] = '62' . substr($digits, 1);
    } elseif (str_starts_with($digits, '62')) {
        $candidates[] = '0' . substr($digits, 2);
    }

    $candidates = array_values(array_unique($candidates));
    $placeholders = implode(', ', array_fill(0, count($candidates), '?'));
    $stmt = db()->prepare(
        masook_session_select_sql()
        . " WHERE REPLACE(REPLACE(REPLACE(users.nomor_handphone, '+', ''), '-', ''), ' ', '') IN ({$placeholders}) LIMIT 1"
    );
    $stmt->execute($candidates);
    $session = $stmt->fetch();

    return $session ?: null;
}

function find_latest_masook_session(): ?array
{
    $stmt = db()->query(masook_session_select_sql() . ' ORDER BY sessions.last_login_at DESC, sessions.id DESC LIMIT 1');
    $session = $stmt->fetch();

    return $session ?: null;
}

function update_masook_user_organisasi_kode(string $username, string $organisasiKode): void
{
    $stmt = db()->prepare(
        'UPDATE users
         SET organisasi_kode = :organisasi_kode
         WHERE username = :username'
    );
    $stmt->execute([
        'username' => $username,
        'organisasi_kode' => $organisasiKode,
    ]);
}

function update_masook_user_organisasi_id_if_empty(int $localUserId, string $organisasiId): bool
{
    $organisasiId = trim($organisasiId);
    if ($localUserId <= 0 || $organisasiId === '') {
        return false;
    }

    $stmt = db()->prepare(
        "UPDATE users
         SET organisasi_id = :organisasi_id
         WHERE id = :id
           AND (organisasi_id IS NULL OR organisasi_id = '')"
    );
    $stmt->execute([
        'id' => $localUserId,
        'organisasi_id' => $organisasiId,
    ]);

    return $stmt->rowCount() > 0;
}

function update_masook_user_coordinates_if_empty(int $localUserId, string $latitude, string $longitude): bool
{
    $latitude = trim($latitude);
    $longitude = trim($longitude);
    
    if ($localUserId <= 0 || ($latitude === '' && $longitude === '')) {
        return false;
    }

    $stmt = db()->prepare(
        "UPDATE users
         SET latitude = CASE WHEN latitude IS NULL OR latitude = '' THEN :latitude ELSE latitude END,
             longitude = CASE WHEN longitude IS NULL OR longitude = '' THEN :longitude ELSE longitude END
         WHERE id = :id
           AND (latitude IS NULL OR latitude = '' OR longitude IS NULL OR longitude = '')"
    );
    
    $stmt->execute([
        'id' => $localUserId,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);

    return $stmt->rowCount() > 0;
}

function masook_session_is_expired(array $session): bool
{
    if (empty($session['expires_at'])) {
        return false;
    }

    return strtotime((string) $session['expires_at']) <= time() + 60;
}

function refresh_masook_session(array $session): array
{
    if (empty($session['refresh_token'])) {
        throw new RuntimeException('Refresh token tidak ditemukan. Login ulang melalui login.php.');
    }

    $refresh = masook_refresh_token((string) $session['refresh_token'], (string) ($session['app_version'] ?? MASOOK_APP_VERSION));
    $accessToken = $refresh['body']['data']['access_token'] ?? null;

    if (!$accessToken) {
        throw new RuntimeException('Refresh token gagal atau access_token baru tidak ditemukan.');
    }

    $refreshToken = $refresh['body']['data']['refresh_token'] ?? $session['refresh_token'];
    $userId = jwt_sub((string) $accessToken) ?: (string) ($session['user_id'] ?? '');

    update_masook_session_tokens(
        (int) $session['id'],
        (string) $accessToken,
        $refreshToken ? (string) $refreshToken : null,
        $userId,
        (int) $refresh['status_code'],
        is_array($refresh['body']) ? $refresh['body'] : ['raw' => $refresh['body']]
    );

    $updated = find_masook_session_by_username((string) $session['username']);
    if ($updated === null) {
        throw new RuntimeException('Session terbaru tidak ditemukan setelah refresh token.');
    }

    return $updated;
}

function ensure_valid_masook_session(array $session): array
{
    return masook_session_is_expired($session) ? refresh_masook_session($session) : $session;
}

function masook_authorized_request(string $method, string $url, array $session, array $headers = [], array $payload = []): array
{
    $session = ensure_valid_masook_session($session);
    $requestHeaders = array_merge(
        masook_common_headers((string) ($session['app_version'] ?? MASOOK_APP_VERSION)),
        ['Authorization: Bearer ' . (string) $session['access_token']],
        $headers
    );

    $response = request_api($method, $url, $requestHeaders, $payload);
    if ((int) $response['status_code'] !== 401) {
        $response['session'] = $session;
        $response['token_refreshed_after_401'] = false;
        return $response;
    }

    $session = refresh_masook_session($session);
    $requestHeaders = array_merge(
        masook_common_headers((string) ($session['app_version'] ?? MASOOK_APP_VERSION)),
        ['Authorization: Bearer ' . (string) $session['access_token']],
        $headers
    );

    $response = request_api($method, $url, $requestHeaders, $payload);
    $response['session'] = $session;
    $response['token_refreshed_after_401'] = true;

    return $response;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
