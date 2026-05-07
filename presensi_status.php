<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$currentSession = require_masook_login();

$result = null;
$error = null;
$todayEndpoint = MASOOK_BASE_URL . '/api/presensi/today';
$todayUrl = $todayEndpoint;

function response_value($body, array $keys, string $fallback = '-'): string
{
    if (!is_array($body)) {
        return $fallback;
    }

    foreach ($keys as $key) {
        if (isset($body[$key]) && $body[$key] !== '') {
            return is_scalar($body[$key]) ? (string) $body[$key] : json_encode($body[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    foreach (['data', 'result', 'presensi'] as $containerKey) {
        if (isset($body[$containerKey]) && is_array($body[$containerKey])) {
            $value = response_value($body[$containerKey], $keys, '');
            if ($value !== '') {
                return $value;
            }
        }
    }

    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($currentSession === null) {
            throw new RuntimeException('Token belum ada di database. Login terlebih dahulu melalui login.php.');
        }

        $session = ensure_valid_masook_session($currentSession);
        $userId = (string) ($session['user_id'] ?? '');
        $organisasiId = (string) ($session['organisasi_id'] ?? '');
        $organisasiKode = (string) ($session['organisasi_kode'] ?? '');

        if ($userId === '') {
            $userId = jwt_sub((string) $session['access_token']);
        }

        if ($userId === '') {
            throw new RuntimeException('User ID tidak ditemukan di database/token. Login ulang melalui login.php.');
        }

        if ($organisasiId === '') {
            throw new RuntimeException('organisasi_id belum ada di database. Login ulang atau lengkapi organisasi_id di tabel users.');
        }

        $query = array_filter([
            'user_id' => $userId,
            'organisasi_id' => $organisasiId,
            'kode_org' => $organisasiKode,
            'kode_organisasi' => $organisasiKode,
        ], static fn($value) => $value !== '');
        $todayUrl = $todayEndpoint . '?' . http_build_query($query);

        $today = masook_authorized_request('GET', $todayUrl, $session);
        $session = $today['session'] ?? $session;
        unset($today['session']);

        $_SESSION['masook_username'] = (string) $session['username'];
        $_SESSION['masook_user_id'] = $userId;

        $result = [
            'auth_source' => 'database',
            'session_id' => $session['id'] ?? null,
            'username' => $session['username'] ?? null,
            'user_id' => $userId,
            'organisasi_id' => $organisasiId,
            'organisasi_kode' => $organisasiKode,
            'token_expires_at' => $session['expires_at'] ?? null,
            'token_refreshed_after_401' => $today['token_refreshed_after_401'] ?? false,
            'today_url' => $todayUrl,
            'today' => $today,
        ];
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$body = is_array($result['today']['body'] ?? null) ? $result['today']['body'] : [];
$statusCode = $result !== null ? (int) ($result['today']['status_code'] ?? 0) : 0;
page_start('Presensi Hari Ini', [
    'active' => 'today',
    'endpoint' => $todayUrl,
]);
?>
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-column flex-md-row gap-3 justify-content-between align-items-md-center">
                <div>
                    <h2 class="h5 mb-1">Ambil Data Presensi Hari Ini</h2>
                    <div class="text-secondary small">Request memakai token dari tabel <span class="mono-small">sessions</span> dan data user dari tabel <span class="mono-small">users</span>.</div>
                </div>
                <form method="post">
                    <button class="btn btn-success" type="submit">
                        <i class="bi bi-arrow-repeat me-1"></i>Ambil Response Today
                    </button>
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
                            <div class="text-secondary small">Label</div>
                            <div class="h5 fw-semibold mb-0"><?= e(response_value($body, ['label', 'status', 'status_presensi'])) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card metric border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-secondary small">Waktu Scan</div>
                            <div class="h5 fw-semibold mb-0"><?= e(response_value($body, ['waktu_scan', 'jam_masuk', 'waktu_masuk', 'created_at'])) ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-3">
                    <h2 class="h5 mb-0">Ringkasan Response</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Username</div>
                                <div class="fw-semibold"><?= e((string) ($result['username'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">User ID</div>
                                <div class="fw-semibold mono-small"><?= e((string) ($result['user_id'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Kode Organisasi</div>
                                <div class="fw-semibold mono-small"><?= e((string) ($result['organisasi_kode'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Organisasi ID</div>
                                <div class="fw-semibold mono-small"><?= e((string) ($result['organisasi_id'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Latitude</div>
                                <div class="fw-semibold mono-small"><?= e(response_value($body, ['latitude', 'lat'])) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="text-secondary small">Longitude</div>
                                <div class="fw-semibold mono-small"><?= e(response_value($body, ['longitude', 'lng', 'lon'])) ?></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="border rounded p-3">
                                <div class="text-secondary small">Perangkat</div>
                                <div class="fw-semibold"><?= e(response_value($body, ['nama_perangkat', 'device_name', 'perangkat'])) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="accordion mb-4" id="debugAccordion">
                <div class="accordion-item border-0 shadow-sm">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#rawResponse" aria-expanded="true" aria-controls="rawResponse">
                            Response Mentah API
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
