<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/masook_api.php';

date_default_timezone_set('Asia/Makassar');

const PRESENSI_MESSAGE_DEVICE = 'WhatsApp Bot';
const PRESENSI_MESSAGE_ACCURACY = '10.0';

function pm_table_column_exists(string $table, string $column): bool
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

function pm_first_scalar_by_keys($value, array $keys): string
{
    if (!is_array($value)) {
        return '';
    }

    foreach ($keys as $key) {
        if (isset($value[$key]) && is_scalar($value[$key]) && trim((string) $value[$key]) !== '') {
            return trim((string) $value[$key]);
        }
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            $found = pm_first_scalar_by_keys($child, $keys);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function pm_find_kode_org_from_session(array $session): string
{
    static $hasOrganisasiKodeColumn = null;

    if ($hasOrganisasiKodeColumn === null) {
        $hasOrganisasiKodeColumn = pm_table_column_exists('users', 'organisasi_kode');
    }

    if ($hasOrganisasiKodeColumn && !empty($session['organisasi_kode'])) {
        return (string) $session['organisasi_kode'];
    }

    $loginResponse = json_decode((string) ($session['login_response'] ?? ''), true);
    $kodeOrg = pm_first_scalar_by_keys($loginResponse, [
        'organisasi_kode',
        'kode_organisasi',
        'kodeOrg',
        'kode_org',
        'organisasiKode',
    ]);

    return $kodeOrg !== '' ? $kodeOrg : MASOOK_REFRESH_APP;
}

function pm_clean_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function pm_decode_webhook_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);

    if (is_array($json)) {
        return $json;
    }

    return $_POST;
}

function pm_extract_message(array $payload): array
{
    $message = '';
    $sender = '';
    $username = '';
    $latitude = '';
    $longitude = '';

    $messageKeys = [
        'message',
        'text',
        'body',
        'caption',
    ];
    $senderKeys = [
        'from',
        'sender',
        'phone',
        'remoteJid',
        'participant',
        'wa_id',
    ];

    foreach ($messageKeys as $key) {
        if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
            $message = trim((string) $payload[$key]);
            break;
        }
    }

    foreach ($senderKeys as $key) {
        if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
            $sender = trim((string) $payload[$key]);
            break;
        }
    }

    if (isset($payload['messages'][0]) && is_array($payload['messages'][0])) {
        $item = $payload['messages'][0];
        $message = $message !== '' ? $message : (string) ($item['text']['body'] ?? $item['body'] ?? '');
        $sender = $sender !== '' ? $sender : (string) ($item['from'] ?? $item['sender'] ?? '');
        $latitude = (string) ($item['location']['latitude'] ?? $latitude);
        $longitude = (string) ($item['location']['longitude'] ?? $longitude);
    }

    $whatsappCloudMessage = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
    if (is_array($whatsappCloudMessage)) {
        $message = $message !== '' ? $message : (string) ($whatsappCloudMessage['text']['body'] ?? '');
        $sender = $sender !== '' ? $sender : (string) ($whatsappCloudMessage['from'] ?? '');
        $latitude = (string) ($whatsappCloudMessage['location']['latitude'] ?? $latitude);
        $longitude = (string) ($whatsappCloudMessage['location']['longitude'] ?? $longitude);
    }

    $username = (string) ($payload['username'] ?? '');
    $latitude = (string) ($payload['latitude'] ?? $payload['lat'] ?? $latitude);
    $longitude = (string) ($payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? $longitude);

    return [
        'sender' => pm_clean_phone($sender),
        'message' => trim($message),
        'username' => trim($username),
        'latitude' => trim($latitude),
        'longitude' => trim($longitude),
    ];
}

function pm_parse_key_values(string $message): array
{
    $values = [];
    preg_match_all('/([a-zA-Z_]+)\s*=\s*("[^"]+"|\'[^\']+\'|\S+)/', $message, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $values[strtolower($match[1])] = trim($match[2], '\'"');
    }

    return $values;
}

function pm_parse_command(array $incoming): array
{
    $message = trim($incoming['message']);
    $keyValues = pm_parse_key_values($message);
    $tokens = preg_split('/\s+/', $message) ?: [];
    $command = strtoupper((string) ($tokens[0] ?? ''));

    $email = '';
    foreach ($tokens as $token) {
        if (filter_var($token, FILTER_VALIDATE_EMAIL)) {
            $email = $token;
            break;
        }
    }

    preg_match_all('/-?\d+(?:\.\d+)?/', $message, $numbers);
    $numberValues = $numbers[0] ?? [];

    return [
        'sender' => (string) ($incoming['sender'] ?? ''),
        'command' => $command,
        'username' => (string) ($keyValues['username'] ?? $keyValues['email'] ?? $incoming['username'] ?? $email),
        'user_id' => (string) ($keyValues['user_id'] ?? $keyValues['userid'] ?? ''),
        'kode_org' => (string) ($keyValues['kode_org'] ?? $keyValues['kodeorg'] ?? $keyValues['kode'] ?? ''),
        'latitude' => (string) ($keyValues['latitude'] ?? $keyValues['lat'] ?? $incoming['latitude'] ?? ($numberValues[0] ?? '')),
        'longitude' => (string) ($keyValues['longitude'] ?? $keyValues['lng'] ?? $keyValues['lon'] ?? $incoming['longitude'] ?? ($numberValues[1] ?? '')),
        'akurasi' => (string) ($keyValues['akurasi'] ?? $keyValues['accuracy'] ?? PRESENSI_MESSAGE_ACCURACY),
        'nama_perangkat' => (string) ($keyValues['device'] ?? $keyValues['nama_perangkat'] ?? PRESENSI_MESSAGE_DEVICE),
        'percobaan_ke' => (string) ($keyValues['percobaan_ke'] ?? $keyValues['percobaan'] ?? '1'),
    ];
}

function pm_find_session(array $command): ?array
{
    if (!empty($command['sender'])) {
        $session = find_masook_session_by_phone((string) $command['sender']);
        if ($session !== null) {
            return $session;
        }
    }

    if ($command['user_id'] !== '') {
        return find_masook_session_by_user_id($command['user_id']);
    }

    if ($command['username'] !== '') {
        return find_masook_session_by_username($command['username']);
    }

    return null;
}

function pm_help_reply(): string
{
    return "Format presensi:\n"
        . "ABSEN\n\n"
        . "Alternatif:\n"
        . "PRESENSI\n\n"
        . "Nomor WhatsApp pengirim harus tersimpan di database. Data username, kode_org, latitude, dan longitude akan diambil dari tabel users.";
}

function pm_send_presensi(array $command): array
{
    if (!in_array($command['command'], ['ABSEN', 'PRESENSI'], true)) {
        return [
            'ok' => false,
            'reply_text' => pm_help_reply(),
        ];
    }

    $session = pm_find_session($command);
    if ($session === null) {
        throw new RuntimeException('Session user tidak ditemukan. Pastikan nomor WhatsApp pengirim sudah tersimpan di users.nomor_handphone dan user sudah pernah login agar token tersedia.');
    }

    $session = ensure_valid_masook_session($session);
    $userId = (string) ($session['user_id'] ?? '');
    if ($userId === '') {
        $userId = jwt_sub((string) $session['access_token']);
    }

    if ($userId === '') {
        throw new RuntimeException('User ID tidak ditemukan di database/token.');
    }

    $kodeOrg = trim($command['kode_org']);
    if ($kodeOrg === '') {
        $kodeOrg = pm_find_kode_org_from_session($session);
    }

    if ($kodeOrg === '') {
        throw new RuntimeException('kode_org tidak ditemukan. Sertakan kode_org=... di pesan.');
    }

    $latitude = trim($command['latitude']) ?: trim((string) ($session['latitude'] ?? ''));
    $longitude = trim($command['longitude']) ?: trim((string) ($session['longitude'] ?? ''));
    if ($latitude === '' || $longitude === '') {
        throw new RuntimeException('Latitude dan longitude belum ada di database users. Lengkapi kolom latitude dan longitude untuk nomor ini.');
    }

    $orgUserUrl = MASOOK_BASE_URL . '/api/orgs/' . rawurlencode($kodeOrg) . '/user';
    $orgUser = masook_authorized_request('GET', $orgUserUrl, $session);
    $session = $orgUser['session'] ?? $session;
    unset($orgUser['session']);

    if ((int) ($orgUser['status_code'] ?? 0) >= 400) {
        throw new RuntimeException('kode_org tidak valid untuk user ini.');
    }

    update_masook_user_organisasi_kode((string) $session['username'], $kodeOrg);

    $payload = [
        'user_id' => $userId,
        'waktu_scan' => date('Y-m-d H:i:s'),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'akurasi' => trim($command['akurasi']) ?: PRESENSI_MESSAGE_ACCURACY,
        'nama_perangkat' => trim($command['nama_perangkat']) ?: PRESENSI_MESSAGE_DEVICE,
        'percobaan_ke' => trim($command['percobaan_ke']) ?: '1',
    ];

    $presensiUrl = MASOOK_BASE_URL . '/api/orgs/' . rawurlencode($kodeOrg) . '/presensi/personal';
    $presensi = masook_authorized_request('POST', $presensiUrl, $session, [
        'Content-Type: application/x-www-form-urlencoded',
    ], $payload);
    unset($presensi['session']);

    $statusCode = (int) ($presensi['status_code'] ?? 0);
    $body = is_array($presensi['body'] ?? null) ? $presensi['body'] : [];
    $message = pm_first_scalar_by_keys($body, ['message', 'label', 'status', 'keterangan']);
    $reply = $statusCode >= 200 && $statusCode < 300
        ? 'Presensi berhasil dikirim.'
        : 'Presensi dikirim, tapi API mengembalikan status ' . $statusCode . '.';

    if ($message !== '') {
        $reply .= "\n" . $message;
    }

    return [
        'ok' => $statusCode >= 200 && $statusCode < 300,
        'reply_text' => $reply,
        'session_id' => $session['id'] ?? null,
        'username' => $session['username'] ?? null,
        'nomor_handphone' => $session['nomor_handphone'] ?? null,
        'user_id' => $userId,
        'kode_org' => $kodeOrg,
        'org_user_url' => $orgUserUrl,
        'presensi_url' => $presensiUrl,
        'sent_payload' => $payload,
        'org_user' => $orgUser,
        'presensi' => $presensi,
    ];
}

function pm_json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $payload = pm_decode_webhook_payload();
        $incoming = pm_extract_message($payload);
        $command = pm_parse_command($incoming);
        $result = pm_send_presensi($command);

        pm_json_response([
            'ok' => $result['ok'],
            'reply_text' => $result['reply_text'],
            'incoming' => $incoming,
            'command' => $command,
            'result' => $result,
        ], $result['ok'] ? 200 : 422);
    } catch (Throwable $throwable) {
        pm_json_response([
            'ok' => false,
            'reply_text' => $throwable->getMessage(),
            'error' => $throwable->getMessage(),
        ], 422);
    }
    exit;
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Presensi Message Backend</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f6f8fb;
            color: #172033;
        }

        main {
            max-width: 920px;
            margin: 48px auto;
            padding: 0 20px;
        }

        section {
            background: #fff;
            border: 1px solid #dfe5ee;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(23, 32, 51, 0.08);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        h2 {
            margin-top: 24px;
            font-size: 18px;
        }

        p,
        li {
            color: #536174;
            line-height: 1.5;
        }

        pre {
            overflow: auto;
            padding: 14px;
            border-radius: 6px;
            background: #172033;
            color: #f8fafc;
        }

        code {
            font-family: Consolas, monospace;
        }
    </style>
</head>
<body>
<main>
    <section>
        <h1>Presensi Message Backend</h1>
        <p>Endpoint kerangka untuk webhook WhatsApp bot. Nanti URL bot bisa diarahkan ke <code>presensi_message.php</code> dengan method <code>POST</code>.</p>

        <h2>Format Pesan</h2>
        <pre>ABSEN</pre>
        <pre>PRESENSI</pre>
        <p>Backend mendeteksi nomor WhatsApp pengirim, lalu mengambil <code>username</code>, <code>masook_user_id</code>, <code>organisasi_kode</code>, <code>latitude</code>, dan <code>longitude</code> dari tabel <code>users</code>. Parameter opsional untuk testing: <code>kode_org=ORG-XXXX</code>, <code>lat=-3.327</code>, <code>lon=116.162</code>, <code>akurasi=10</code>, <code>percobaan=1</code>.</p>

        <h2>Contoh Payload POST</h2>
        <pre>{
  "from": "6285746080544",
  "message": "ABSEN"
}</pre>

        <h2>Response</h2>
        <p>Backend mengembalikan JSON dengan <code>reply_text</code>. Nanti adapter bot WhatsApp cukup mengambil field itu untuk dikirim balik ke user.</p>
    </section>
</main>
</body>
</html>
