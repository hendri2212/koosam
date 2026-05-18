<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

date_default_timezone_set('Asia/Makassar');

$currentSession = require_masook_login();

// Selalu hari ini, tidak ada filter form
$today = date('Y-m-d');

$result = null;
$error = null;

function rt_is_list_array(array $value): bool
{
    return array_keys($value) === range(0, count($value) - 1);
}

function rt_find_first_row_list($value): array
{
    if (!is_array($value)) {
        return [];
    }

    if (rt_is_list_array($value)) {
        return $value;
    }

    foreach ($value as $child) {
        $rows = rt_find_first_row_list($child);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

function rt_history_items(array $historyBody): array
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
        $rows = rt_find_first_row_list($candidate);
        if ($rows !== []) {
            return array_values(array_filter($rows, 'is_array'));
        }
    }

    return [];
}

function rt_row_value(array $row, array $keys, string $fallback = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return is_scalar($row[$key]) ? (string) $row[$key] : json_encode($row[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    return $fallback;
}

function rt_scan_date(array $row): string
{
    $waktuScan = rt_row_value($row, ['waktu_scan'], '');
    if ($waktuScan === '') {
        return rt_row_value($row, ['tanggal', 'tgl', 'tgl_presensi', 'tanggal_presensi', 'created_at']);
    }

    $timestamp = strtotime($waktuScan);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : $waktuScan;
}

function rt_scan_time(array $row): string
{
    $waktuScan = rt_row_value($row, ['waktu_scan'], '');
    if ($waktuScan === '') {
        return '-';
    }

    $timestamp = strtotime($waktuScan);
    return $timestamp !== false ? date('H:i:s', $timestamp) : $waktuScan;
}

function rt_to_wita(string $timeStr): string
{
    if ($timeStr === '-' || $timeStr === '') {
        return $timeStr;
    }

    $timestamp = strtotime($timeStr);
    return $timestamp !== false ? date('H:i:s', $timestamp + 3600) : $timeStr;
}

function rt_scan_timestamp(array $row): int
{
    $waktuScan = rt_row_value($row, ['waktu_scan'], '');
    $timestamp = $waktuScan !== '' ? strtotime($waktuScan) : false;

    return $timestamp !== false ? (int) $timestamp : 0;
}

function rt_format_tanggal_indonesia(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    return sprintf(
        '%s, %s %s %s',
        $hari[(int) date('w', $timestamp)],
        date('d', $timestamp),
        $bulan[(int) date('n', $timestamp)],
        date('Y', $timestamp)
    );
}

function rt_grouped_history_days(array $items): array
{
    $groups = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $tanggal = rt_scan_date($item);
        if (!isset($groups[$tanggal])) {
            $groups[$tanggal] = [];
        }

        $groups[$tanggal][] = $item;
    }

    foreach ($groups as &$dayItems) {
        usort($dayItems, static fn(array $a, array $b): int => rt_scan_timestamp($a) <=> rt_scan_timestamp($b));
    }
    unset($dayItems);

    krsort($groups);

    return $groups;
}

function rt_badge_class(string $value): string
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

function rt_first_scalar_value_by_keys($value, array $keys): string
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
            $result = rt_first_scalar_value_by_keys($child, $keys);
            if ($result !== '') {
                return $result;
            }
        }
    }

    return '';
}

function rt_history_response_organisasi_id($historyBody): string
{
    return rt_first_scalar_value_by_keys($historyBody, [
        'organisasi_id',
        'id_organisasi',
        'organisasiId',
        'idOrganisasi',
    ]);
}

try {
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
        'tgl_mulai' => $today,
        'tgl_selesai' => $today,
        'is_aktivitas' => '1',
        'page' => '1',
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
        $detectedOrganisasiId = rt_history_response_organisasi_id($history['body']);
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
        'token_expires_at' => $session['expires_at'] ?? null,
        'token_refreshed_before_request' => $wasExpiredBeforeRequest,
        'token_refreshed_after_401' => $history['token_refreshed_after_401'] ?? false,
        'history_url' => $historyUrl,
        'history' => $history,
    ];
} catch (Throwable $throwable) {
    $error = $throwable->getMessage();
}

$items = [];
if ($result !== null && is_array($result['history']['body'] ?? null)) {
    $items = rt_history_items($result['history']['body']);
    // Filter hanya data hari ini
    $items = array_values(array_filter($items, static function (array $row) use ($today): bool {
        return rt_scan_date($row) === $today;
    }));
}

$groupedDays = rt_grouped_history_days($items);

page_start('Absen Hari Ini', [
    'active' => 'today',
    'endpoint' => MASOOK_BASE_URL . '/api/presensi/riwayat/{user_id}',
]);
?>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger border-0 shadow-sm" role="alert">
                <i class="bi bi-x-circle-fill me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <section class="card table-card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 pt-3 pb-0">
                <div class="d-flex flex-column flex-md-row gap-2 justify-content-between">
                    <div>
                        <h2 class="h5 mb-1">Absen Hari Ini</h2>
                        <div class="text-secondary small"><?= e(rt_format_tanggal_indonesia($today)) ?></div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if ($groupedDays === []): ?>
                    <div class="text-center py-5">
                        <div class="display-6 text-secondary mb-2"><i class="bi bi-inbox"></i></div>
                        <div class="fw-semibold">Belum ada presensi hari ini.</div>
                    </div>
                <?php else: ?>
                    <div class="d-grid gap-3">
                        <?php foreach ($groupedDays as $tanggal => $dayItems): ?>
                            <?php
                                $masukItem = null;
                                $pulangItem = null;
                                foreach ($dayItems as $item) {
                                    $tipe = (string) rt_row_value($item, ['tipe', 'type', 'jenis'], '');
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
                                    ? rt_to_wita(rt_row_value($masukItem, ['jam_masuk', 'waktu_masuk', 'masuk', 'check_in', 'presensi_masuk'], rt_scan_time($masukItem)))
                                    : '-';
                                $jamPulang = $pulangItem !== null
                                    ? rt_to_wita(rt_row_value($pulangItem, ['jam_pulang', 'waktu_pulang', 'pulang', 'check_out', 'presensi_pulang'], rt_scan_time($pulangItem)))
                                    : '-';
                                $label = rt_row_value($firstItem, ['label', 'status', 'status_presensi', 'status_kehadiran', 'jenis', 'tipe'], 'Terekam');
                                if ($label === '1') $label = 'Masuk';
                                elseif ($label === '2') $label = 'Pulang';
                                $latitude = rt_row_value($firstItem, ['latitude', 'lat']);
                                $longitude = rt_row_value($firstItem, ['longitude', 'lng', 'lon']);
                                $namaPerangkat = rt_row_value($firstItem, ['nama_perangkat', 'device_name', 'perangkat']);
                                $namaLokasi = rt_row_value($firstItem, ['nama_lokasi', 'lokasi', 'location_name']);
                                $keterangan = rt_row_value($lastItem, ['keterangan', 'catatan', 'nama_aktivitas', 'aktivitas']);
                            ?>
                            <article class="card mobile-card border-0">
                                <div class="card-body p-3">
                                    <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                                        <div>
                                            <div class="text-secondary small">Tanggal</div>
                                            <h3 class="h6 mb-0"><?= e(rt_format_tanggal_indonesia((string) $tanggal)) ?></h3>
                                        </div>
                                        <span class="badge <?= e(rt_badge_class($label)) ?>"><?= e($label) ?></span>
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
        </section>
        <?php if ($result !== null): ?>
            <script>
                console.log(<?= json_encode($result['history']['body'] ?? null) ?>);
            </script>
        <?php endif; ?>
<?php
page_end();
