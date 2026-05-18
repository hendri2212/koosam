<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$currentSession = require_masook_login();

$defaults = [
    'tgl_mulai' => date('Y-m-01'),
    'tgl_selesai' => date('Y-m-d'),
];

$result = null;
$error = null;

function is_list_array(array $value): bool
{
    return array_keys($value) === range(0, count($value) - 1);
}

function find_first_row_list($value): array
{
    if (!is_array($value)) {
        return [];
    }

    if (is_list_array($value)) {
        return $value;
    }

    foreach ($value as $child) {
        $rows = find_first_row_list($child);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

function history_items(array $historyBody): array
{
    $candidates = [
        $historyBody['data']['data'] ?? null,
        $historyBody['data']['items'] ?? null,
        $historyBody['data']['riwayat'] ?? null,
        $historyBody['data']['presensi'] ?? null,
        $historyBody['data'] ?? null,
        $historyBody['rows'] ?? null,
        $historyBody['result'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $rows = find_first_row_list($candidate);
        if ($rows !== []) {
            return array_values(array_filter($rows, 'is_array'));
        }
    }

    return [];
}

function row_value(array $row, array $keys, string $fallback = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return is_scalar($row[$key]) ? (string) $row[$key] : json_encode($row[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    return $fallback;
}

function scan_date(array $row): string
{
    $waktuScan = row_value($row, ['waktu_scan'], '');
    if ($waktuScan === '') {
        return row_value($row, ['tanggal', 'tgl', 'tgl_presensi', 'tanggal_presensi', 'created_at']);
    }

    $timestamp = strtotime($waktuScan);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : $waktuScan;
}

function scan_time(array $row): string
{
    $waktuScan = row_value($row, ['waktu_scan'], '');
    if ($waktuScan === '') {
        return '-';
    }

    $timestamp = strtotime($waktuScan);
    return $timestamp !== false ? date('H:i:s', $timestamp) : $waktuScan;
}

function to_wita(string $timeStr): string
{
    if ($timeStr === '-' || $timeStr === '') {
        return $timeStr;
    }

    $timestamp = strtotime($timeStr);
    return $timestamp !== false ? date('H:i:s', $timestamp + 3600) : $timeStr;
}

function scan_timestamp(array $row): int
{
    $waktuScan = row_value($row, ['waktu_scan'], '');
    $timestamp = $waktuScan !== '' ? strtotime($waktuScan) : false;

    return $timestamp !== false ? (int) $timestamp : 0;
}

function format_tanggal_indonesia(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $hari = [
        'Minggu',
        'Senin',
        'Selasa',
        'Rabu',
        'Kamis',
        'Jumat',
        'Sabtu',
    ];
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    return sprintf(
        '%s, %s %s %s',
        $hari[(int) date('w', $timestamp)],
        date('d', $timestamp),
        $bulan[(int) date('n', $timestamp)],
        date('Y', $timestamp)
    );
}

function grouped_history_days(array $items): array
{
    $groups = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $tanggal = scan_date($item);
        if (!isset($groups[$tanggal])) {
            $groups[$tanggal] = [];
        }

        $groups[$tanggal][] = $item;
    }

    foreach ($groups as &$dayItems) {
        usort($dayItems, static fn(array $a, array $b): int => scan_timestamp($a) <=> scan_timestamp($b));
    }
    unset($dayItems);

    krsort($groups);

    return $groups;
}

function badge_class(string $value): string
{
    $normalized = strtolower($value);

    if (str_contains($normalized, 'hadir') || str_contains($normalized, 'masuk') || str_contains($normalized, 'success') || str_contains($normalized, 'tepat')) {
        return 'text-bg-success';
    }

    if (str_contains($normalized, 'terlambat') || str_contains($normalized, 'telat') || str_contains($normalized, 'pending')) {
        return 'text-bg-warning';
    }

    if (str_contains($normalized, 'alpha') || str_contains($normalized, 'tidak') || str_contains($normalized, 'gagal')) {
        return 'text-bg-danger';
    }

    return 'text-bg-secondary';
}

function response_meta(?array $result, string $key): string
{
    return $result !== null && isset($result[$key]) && $result[$key] !== ''
        ? (string) $result[$key]
        : '-';
}

function first_scalar_value_by_keys($value, array $keys): string
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
            $result = first_scalar_value_by_keys($child, $keys);
            if ($result !== '') {
                return $result;
            }
        }
    }

    return '';
}

function history_response_organisasi_id($historyBody): string
{
    return first_scalar_value_by_keys($historyBody, [
        'organisasi_id',
        'id_organisasi',
        'organisasiId',
        'idOrganisasi',
    ]);
}

function history_pagination(array $historyBody): array
{
    $data = $historyBody['data'] ?? [];
    if (!is_array($data)) {
        $data = [];
    }

    $currentPage = isset($data['current_page']) && is_numeric($data['current_page']) ? (int) $data['current_page'] : 1;
    $perPage = isset($data['per_page']) && is_numeric($data['per_page']) ? (int) $data['per_page'] : 0;
    $from = isset($data['from']) && is_numeric($data['from']) ? (int) $data['from'] : 0;
    $to = isset($data['to']) && is_numeric($data['to']) ? (int) $data['to'] : 0;

    return [
        'current_page' => max(1, $currentPage),
        'prev_page' => !empty($data['prev_page_url']) ? max(1, $currentPage - 1) : null,
        'next_page' => !empty($data['next_page_url']) ? $currentPage + 1 : null,
        'per_page' => $perPage,
        'from' => $from,
        'to' => $to,
    ];
}

$shouldFetchHistory = true;

if ($shouldFetchHistory) {
    try {
        $tglMulai = trim((string) ($_POST['tgl_mulai'] ?? $defaults['tgl_mulai']));
        $tglSelesai = trim((string) ($_POST['tgl_selesai'] ?? $defaults['tgl_selesai']));
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $isAktivitas = '1';
        $session = $currentSession;

        if ($session === null) {
            throw new RuntimeException('Token belum ada di database. Login terlebih dahulu melalui login.php.');
        }

        $originalSessionId = $session['id'] ?? null;
        $wasExpiredBeforeRequest = masook_session_is_expired($session);
        $session = ensure_valid_masook_session($session);

        $token = (string) $session['access_token'];
        $userId = (string) ($session['user_id'] ?? '');
        $organisasiId = (string) ($session['organisasi_id'] ?? '');

        if ($userId === '') {
            $userId = jwt_sub($token);
        }

        if ($userId === '') {
            throw new RuntimeException('User ID tidak ditemukan di database/token. Login ulang atau isi User ID manual.');
        }

        $_SESSION['masook_username'] = (string) $session['username'];
        $_SESSION['masook_user_id'] = $userId;

        $query = array_filter([
            'organisasi_id' => $organisasiId,
            'isPaginate' => 'true',
            'format' => 'mobile',
            'tgl_mulai' => $tglMulai,
            'tgl_selesai' => $tglSelesai,
            'is_aktivitas' => $isAktivitas,
            'page' => (string) $page,
        ], static fn($value) => $value !== '');

        $historyUrl = MASOOK_BASE_URL . '/api/presensi/riwayat/' . rawurlencode($userId);
        if ($query) {
            $historyUrl .= '?' . http_build_query($query);
        }

        $history = masook_authorized_request('GET', $historyUrl, $session);
        $session = $history['session'] ?? $session;
        unset($history['session']);

        $detectedOrganisasiId = '';
        $organisasiIdUpdated = false;
        $sessionOrganisasiIdWasEmpty = trim((string) ($session['organisasi_id'] ?? '')) === '';

        if ($sessionOrganisasiIdWasEmpty && is_array($history['body'] ?? null)) {
            $detectedOrganisasiId = history_response_organisasi_id($history['body']);
            if ($detectedOrganisasiId !== '') {
                $organisasiIdUpdated = update_masook_user_organisasi_id_if_empty(
                    (int) ($session['local_user_id'] ?? 0),
                    $detectedOrganisasiId
                );

                if ($organisasiIdUpdated) {
                    $updatedSession = find_masook_session_by_username((string) $session['username']);
                    if ($updatedSession !== null) {
                        $session = $updatedSession;
                        $currentSession = $updatedSession;
                        sync_masook_session_to_php($updatedSession);
                    }
                }

                if ($organisasiId === '') {
                    $organisasiId = $detectedOrganisasiId;
                }
            }
        }

        $result = [
            'auth_source' => 'database',
            'session_id' => $session['id'] ?? $originalSessionId,
            'username' => $session['username'] ?? null,
            'user_id' => $userId,
            'organisasi_id' => $organisasiId,
            'organisasi_id_from_response' => $detectedOrganisasiId,
            'organisasi_id_saved_to_database' => $organisasiIdUpdated,
            'token_expires_at' => $session['expires_at'] ?? null,
            'token_refreshed_before_request' => $wasExpiredBeforeRequest,
            'token_refreshed_after_401' => $history['token_refreshed_after_401'] ?? false,
            'history_url' => $historyUrl,
            'history' => $history,
        ];
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}
if ($result !== null && is_array($result['history']['body'] ?? null)) {
    $items = history_items($result['history']['body']);
    $pagination = history_pagination($result['history']['body']);

    // Otomatis update koordinat jika di database masih kosong
    $sessionCoordsEmpty = trim((string) ($session['latitude'] ?? '')) === '' || trim((string) ($session['longitude'] ?? '')) === '';
    if ($sessionCoordsEmpty && !empty($items)) {
        $firstItem = $items[0];
        $detectedLat = row_value($firstItem, ['latitude', 'lat'], '');
        $detectedLng = row_value($firstItem, ['longitude', 'lng', 'lon'], '');

        if ($detectedLat !== '' && $detectedLng !== '') {
            $coordsUpdated = update_masook_user_coordinates_if_empty(
                (int) ($session['local_user_id'] ?? 0),
                $detectedLat,
                $detectedLng
            );

            if ($coordsUpdated) {
                $updatedSession = find_masook_session_by_username((string) $session['username']);
                if ($updatedSession !== null) {
                    $session = $updatedSession;
                    $currentSession = $updatedSession;
                    sync_masook_session_to_php($updatedSession);
                }
            }
        }
    }
} else {
    $items = [];
    $pagination = [
        'current_page' => 1,
        'prev_page' => null,
        'next_page' => null,
        'per_page' => 0,
        'from' => 0,
        'to' => 0,
    ];
}

$historyStatusCode = $result !== null ? (int) ($result['history']['status_code'] ?? 0) : 0;
$groupedDays = grouped_history_days($items);
page_start('Riwayat Presensi', [
    'active' => 'history',
    'endpoint' => MASOOK_BASE_URL . '/api/presensi/riwayat/{user_id}',
]);
?>
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form id="historyFilterForm" method="post" class="row g-2 align-items-end flex-nowrap">
                    <input id="history_page" name="page" type="hidden" value="<?= e((string) ($_POST['page'] ?? '1')) ?>">
                    <div class="col">
                        <label class="form-label fw-semibold" for="tgl_mulai">Tanggal Mulai</label>
                        <input class="form-control" id="tgl_mulai" name="tgl_mulai" type="date" value="<?= e((string) ($_POST['tgl_mulai'] ?? $defaults['tgl_mulai'])) ?>">
                    </div>
                    <div class="col">
                        <label class="form-label fw-semibold" for="tgl_selesai">Tanggal Selesai</label>
                        <input class="form-control" id="tgl_selesai" name="tgl_selesai" type="date" value="<?= e((string) ($_POST['tgl_selesai'] ?? $defaults['tgl_selesai'])) ?>">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-success px-3" type="submit" title="Cari riwayat" aria-label="Cari riwayat" onclick="document.getElementById('history_page').value='1'">
                            <i class="bi bi-search"></i>
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
            <section class="card table-card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 pt-3 pb-0">
                    <div class="d-flex flex-column flex-md-row gap-2 justify-content-between">
                        <div>
                            <h2 class="h5 mb-1">Daftar Riwayat</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($groupedDays === []): ?>
                        <div class="text-center py-5">
                            <div class="display-6 text-secondary mb-2"><i class="bi bi-inbox"></i></div>
                            <div class="fw-semibold">Belum ada item riwayat yang bisa ditampilkan.</div>
                        </div>
                    <?php else: ?>
                        <div class="d-grid gap-3">
                            <?php foreach ($groupedDays as $tanggal => $dayItems): ?>
                                <?php
                                    $masukItem = null;
                                    $pulangItem = null;
                                    foreach ($dayItems as $item) {
                                        $tipe = (string) row_value($item, ['tipe', 'type', 'jenis'], '');
                                        if ($tipe === '1') $masukItem = $item;
                                        elseif ($tipe === '2') $pulangItem = $item;
                                    }
                                    if ($masukItem === null && $pulangItem === null) {
                                        $masukItem = $dayItems[0];
                                        $pulangItem = count($dayItems) > 1 ? $dayItems[count($dayItems) - 1] : null;
                                    }
                                    
                                    $firstItem = $masukItem ?? $pulangItem ?? $dayItems[0];
                                    $lastItem = $pulangItem ?? $masukItem ?? $dayItems[count($dayItems) - 1];

                                    $jamMasuk = $masukItem !== null
                                        ? to_wita(row_value($masukItem, ['jam_masuk', 'waktu_masuk', 'masuk', 'check_in', 'presensi_masuk'], scan_time($masukItem)))
                                        : '-';
                                    $jamPulang = $pulangItem !== null
                                        ? to_wita(row_value($pulangItem, ['jam_pulang', 'waktu_pulang', 'pulang', 'check_out', 'presensi_pulang'], scan_time($pulangItem)))
                                        : '-';
                                    $label = row_value($firstItem, ['label', 'status', 'status_presensi', 'status_kehadiran', 'jenis', 'tipe'], 'Terekam');
                                    if ($label === '1') $label = 'Masuk';
                                    elseif ($label === '2') $label = 'Pulang';
                                    $latitude = row_value($firstItem, ['latitude', 'lat']);
                                    $longitude = row_value($firstItem, ['longitude', 'lng', 'lon']);
                                    $namaPerangkat = row_value($firstItem, ['nama_perangkat', 'device_name', 'perangkat']);
                                    $namaLokasi = row_value($firstItem, ['nama_lokasi', 'lokasi', 'location_name']);
                                    $keterangan = row_value($lastItem, ['keterangan', 'catatan', 'nama_aktivitas', 'aktivitas']);
                                ?>
                                <article class="card mobile-card border-0">
                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                                            <div>
                                                <div class="text-secondary small">Tanggal</div>
                                                <h3 class="h6 mb-0"><?= e(format_tanggal_indonesia((string) $tanggal)) ?></h3>
                                            </div>
                                            <span class="badge <?= e(badge_class($label)) ?>"><?= e($label) ?></span>
                                        </div>

                                        <div class="d-grid gap-2 small">
                                            <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                                <span class="text-secondary"><i class="bi bi-box-arrow-in-right me-1"></i>Masuk</span>
                                                <span class="fw-semibold mono-small"><?= e($jamMasuk) ?></span>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                                <span class="text-secondary"><i class="bi bi-box-arrow-left me-1"></i>Pulang</span>
                                                <span class="fw-semibold mono-small"><?= e($jamPulang) ?></span>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                                <span class="text-secondary"><i class="bi bi-geo-alt me-1"></i>Koordinat</span>
                                                <span class="fw-semibold mono-small text-end"><?= e($latitude) ?>, <?= e($longitude) ?></span>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                                <span class="text-secondary"><i class="bi bi-phone me-1"></i>Perangkat</span>
                                                <span class="fw-semibold text-end"><?= e($namaPerangkat) ?></span>
                                            </div>
                                            <?php if ($namaLokasi !== '-'): ?>
                                            <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                                <span class="text-secondary"><i class="bi bi-building me-1"></i>Lokasi</span>
                                                <span class="fw-semibold text-end"><?= e($namaLokasi) ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($keterangan !== '-'): ?>
                                            <div class="small text-secondary mt-2 pt-2 border-top">
                                                <i class="bi bi-chat-left-text me-1"></i><?= e($keterangan) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($pagination['prev_page'] !== null || $pagination['next_page'] !== null || $pagination['from'] > 0): ?>
                    <div class="card-footer bg-white border-0 pt-0">
                        <div class="d-flex flex-column flex-sm-row gap-2 align-items-center justify-content-between">
                            <div class="small text-secondary">
                                Halaman <?= e((string) $pagination['current_page']) ?>
                                <?php if ($pagination['from'] > 0): ?>
                                    · Data <?= e((string) (int) ceil($pagination['from'] / 2)) ?>-<?= e((string) (int) ceil($pagination['to'] / 2)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="btn-group w-100 w-sm-auto" role="group" aria-label="Pagination riwayat">
                                <button
                                    class="btn btn-outline-secondary"
                                    type="submit"
                                    form="historyFilterForm"
                                    onclick="document.getElementById('history_page').value='<?= e((string) ($pagination['prev_page'] ?? $pagination['current_page'])) ?>'"
                                    <?= $pagination['prev_page'] === null ? 'disabled' : '' ?>
                                >
                                    <i class="bi bi-chevron-left me-1"></i>Prev
                                </button>
                                <button
                                    class="btn btn-outline-secondary"
                                    type="submit"
                                    form="historyFilterForm"
                                    onclick="document.getElementById('history_page').value='<?= e((string) ($pagination['next_page'] ?? $pagination['current_page'])) ?>'"
                                    <?= $pagination['next_page'] === null ? 'disabled' : '' ?>
                                >
                                    Next<i class="bi bi-chevron-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
            <script>
                console.log(<?= json_encode($result['history']['body'] ?? null) ?>);
            </script>
        <?php endif; ?>
<?php
page_end();
