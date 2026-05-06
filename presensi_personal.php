<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

date_default_timezone_set('Asia/Makassar');

const PRESENSI_DEVICE = 'Xiaomi-M2103K19PG';
const PRESENSI_ACCURACY = '10.0';

$currentSession = require_masook_login();

$result = null;
$error = null;

function table_column_exists(string $table, string $column): bool
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

function first_scalar_by_keys($value, array $keys): string
{
    if (!is_array($value)) {
        return '';
    }

    foreach ($keys as $key) {
        if (isset($value[$key]) && is_scalar($value[$key]) && (string) $value[$key] !== '') {
            return (string) $value[$key];
        }
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            $found = first_scalar_by_keys($child, $keys);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function find_kode_org_from_session(array $session): string
{
    static $hasOrganisasiKodeColumn = null;

    if ($hasOrganisasiKodeColumn === null) {
        $hasOrganisasiKodeColumn = table_column_exists('users', 'organisasi_kode');
    }

    if ($hasOrganisasiKodeColumn && !empty($session['organisasi_kode'])) {
        return (string) $session['organisasi_kode'];
    }

    $loginResponse = json_decode((string) ($session['login_response'] ?? ''), true);
    $kodeOrg = first_scalar_by_keys($loginResponse, [
        'organisasi_kode',
        'kode_organisasi',
        'kodeOrg',
        'kode_org',
        'organisasiKode',
    ]);

    if ($kodeOrg !== '') {
        return $kodeOrg;
    }

    return MASOOK_REFRESH_APP;
}

function response_pick($body, array $keys, string $fallback = '-'): string
{
    if (!is_array($body)) {
        return $fallback;
    }

    foreach ($keys as $key) {
        if (isset($body[$key]) && $body[$key] !== '') {
            return is_scalar($body[$key]) ? (string) $body[$key] : json_encode($body[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    foreach (['data', 'result', 'presensi'] as $container) {
        if (isset($body[$container]) && is_array($body[$container])) {
            $found = response_pick($body[$container], $keys, '');
            if ($found !== '') {
                return $found;
            }
        }
    }

    return $fallback;
}

$defaults = [
    'username' => (string) ($currentSession['username'] ?? ''),
    'user_id' => (string) ($currentSession['user_id'] ?? ''),
    'kode_org' => (string) ($_GET['kode_org'] ?? ($currentSession !== null ? find_kode_org_from_session($currentSession) : '')),
    'akurasi' => PRESENSI_ACCURACY,
    'nama_perangkat' => PRESENSI_DEVICE,
    'percobaan_ke' => '1',
    'kode' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($currentSession === null) {
            throw new RuntimeException('Token belum ada di database. Login terlebih dahulu melalui login.php.');
        }

        $session = ensure_valid_masook_session($currentSession);
        $userId = (string) ($session['user_id'] ?? '');
        $kodeOrg = trim((string) ($_POST['kode_org'] ?? ''));
        $latitude = trim((string) ($session['latitude'] ?? ''));
        $longitude = trim((string) ($session['longitude'] ?? ''));
        $waktuScan = date('Y-m-d H:i:s');

        if ($userId === '') {
            $userId = jwt_sub((string) $session['access_token']);
        }

        if ($userId === '') {
            throw new RuntimeException('User ID tidak ditemukan di database/token. Login ulang melalui login.php.');
        }

        if ($kodeOrg === '') {
            $kodeOrg = find_kode_org_from_session($session);
        }

        if ($kodeOrg === '') {
            throw new RuntimeException('kodeOrg tidak ditemukan di database. Isi kolom kodeOrg atau simpan organisasi_kode di tabel users.');
        }

        if ($latitude === '' || $longitude === '') {
            throw new RuntimeException('Latitude dan longitude belum ada di database. Lengkapi field latitude dan longitude di tabel users.');
        }

        $orgUserUrl = MASOOK_BASE_URL . '/api/orgs/' . rawurlencode($kodeOrg) . '/user';
        $orgUser = masook_authorized_request('GET', $orgUserUrl, $session);
        $session = $orgUser['session'] ?? $session;
        unset($orgUser['session']);

        if ((int) ($orgUser['status_code'] ?? 0) >= 400) {
            $result = [
                'auth_source' => 'database',
                'session_id' => $session['id'] ?? null,
                'username' => $session['username'] ?? null,
                'user_id' => $userId,
                'kode_org' => $kodeOrg,
                'token_expires_at' => $session['expires_at'] ?? null,
                'org_user_url' => $orgUserUrl,
                'org_user' => $orgUser,
            ];

            throw new RuntimeException('kodeOrg tidak valid untuk endpoint organisasi. Cek response GET /api/orgs/{kodeOrg}/user di bawah, lalu gunakan kode organisasi yang benar.');
        }

        update_masook_user_organisasi_kode((string) $session['username'], $kodeOrg);
        $session['organisasi_kode'] = $kodeOrg;

        $payload = [
            'user_id' => $userId,
            'waktu_scan' => $waktuScan,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'akurasi' => trim((string) ($_POST['akurasi'] ?? PRESENSI_ACCURACY)),
            'nama_perangkat' => trim((string) ($_POST['nama_perangkat'] ?? PRESENSI_DEVICE)),
            'percobaan_ke' => trim((string) ($_POST['percobaan_ke'] ?? '1')),
        ];

        $kode = trim((string) ($_POST['kode'] ?? ''));
        if ($kode !== '') {
            $payload['kode'] = $kode;
        }

        $presensiUrl = MASOOK_BASE_URL . '/api/orgs/' . rawurlencode($kodeOrg) . '/presensi/personal';
        $presensi = masook_authorized_request('POST', $presensiUrl, $session, [
            'Content-Type: application/x-www-form-urlencoded',
        ], $payload);
        $session = $presensi['session'] ?? $session;
        unset($presensi['session']);

        $_SESSION['masook_username'] = (string) $session['username'];
        $_SESSION['masook_user_id'] = $userId;

        $result = [
            'auth_source' => 'database',
            'session_id' => $session['id'] ?? null,
            'username' => $session['username'] ?? null,
            'user_id' => $userId,
            'kode_org' => $kodeOrg,
            'token_expires_at' => $session['expires_at'] ?? null,
            'org_user_url' => $orgUserUrl,
            'org_user' => $orgUser,
            'token_refreshed_after_401' => $presensi['token_refreshed_after_401'] ?? false,
            'presensi_url' => $presensiUrl,
            'sent_payload' => $payload,
            'presensi' => $presensi,
        ];
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$responseBlock = $result['presensi'] ?? ($result['org_user'] ?? null);
$body = is_array($responseBlock['body'] ?? null) ? $responseBlock['body'] : [];
$statusCode = $result !== null ? (int) ($responseBlock['status_code'] ?? 0) : 0;
$pageFooterScripts = <<<'HTML'
<script>
    function updateCurrentTime() {
        const target = document.getElementById('currentTime');
        if (!target) {
            return;
        }

        const now = new Date();
        target.textContent = now.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        }).replace(/\./g, ':');
    }

    updateCurrentTime();
    setInterval(updateCurrentTime, 1000);
</script>
HTML;
page_start('Presensi Personal', [
    'active' => 'presence',
    'endpoint' => MASOOK_BASE_URL . '/api/orgs/{kodeOrg}/presensi/personal',
]);
?>
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="kode_org" value="<?= e((string) ($_POST['kode_org'] ?? $defaults['kode_org'])) ?>">
                    <div class="col-12">
                        <div id="currentTime" class="presence-clock fw-semibold mono-small text-center text-nowrap">--:--:--</div>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label fw-semibold" for="percobaan_ke">Percobaan</label>
                        <input class="form-control" id="percobaan_ke" name="percobaan_ke" type="number" min="1" value="<?= e((string) ($_POST['percobaan_ke'] ?? $defaults['percobaan_ke'])) ?>">
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label fw-semibold" for="akurasi">Akurasi</label>
                        <input class="form-control" id="akurasi" name="akurasi" type="text" value="<?= e((string) ($_POST['akurasi'] ?? $defaults['akurasi'])) ?>" required>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label fw-semibold" for="kode">Kode</label>
                        <input class="form-control" id="kode" name="kode" type="text" value="<?= e((string) ($_POST['kode'] ?? $defaults['kode'])) ?>" placeholder="Opsional">
                    </div>
                    <div class="col-12 col-lg-8">
                        <label class="form-label fw-semibold" for="nama_perangkat">Nama Perangkat</label>
                        <input class="form-control" id="nama_perangkat" name="nama_perangkat" type="text" value="<?= e((string) ($_POST['nama_perangkat'] ?? $defaults['nama_perangkat'])) ?>" required>
                    </div>
                    <div class="col-12 col-lg-4">
                        <button class="btn btn-success w-100" type="submit">
                            <i class="bi bi-fingerprint me-1"></i>Kirim Presensi
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger border-0 shadow-sm" role="alert">
                <i class="bi bi-x-circle-fill me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <section class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="card metric border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-secondary small">Status API</div>
                            <div class="display-6 fw-semibold"><?= e((string) $statusCode) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card metric border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-secondary small">Waktu Scan Terkirim</div>
                            <div class="h5 fw-semibold mb-0"><?= e((string) ($result['sent_payload']['waktu_scan'] ?? '-')) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card metric border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-secondary small">Label Response</div>
                            <div class="h5 fw-semibold mb-0"><?= e(response_pick($body, ['label', 'status', 'message'])) ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-3">
                    <h2 class="h5 mb-0">Ringkasan Request</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">URL Validasi Organisasi</div>
                                <div class="fw-semibold mono-small text-break"><?= e((string) ($result['org_user_url'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">URL Presensi</div>
                                <div class="fw-semibold mono-small text-break"><?= e((string) ($result['presensi_url'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">kodeOrg</div>
                                <div class="fw-semibold mono-small"><?= e((string) $result['kode_org']) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Latitude</div>
                                <div class="fw-semibold mono-small"><?= e((string) ($result['sent_payload']['latitude'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Longitude</div>
                                <div class="fw-semibold mono-small"><?= e((string) ($result['sent_payload']['longitude'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Perangkat</div>
                                <div class="fw-semibold"><?= e((string) ($result['sent_payload']['nama_perangkat'] ?? '-')) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="accordion mb-4" id="debugAccordion">
                <div class="accordion-item border-0 shadow-sm">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#rawResponse" aria-expanded="true" aria-controls="rawResponse">
                            Request dan Response Mentah
                        </button>
                    </h2>
                    <div id="rawResponse" class="accordion-collapse collapse show" data-bs-parent="#debugAccordion">
                        <div class="accordion-body">
                            <pre class="bg-dark text-white rounded p-3 mb-0"><?= e(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
<?php
page_end();
