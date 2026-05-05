<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$currentSession = require_masook_login();
$orgsUrl = MASOOK_BASE_URL . '/api/orgs';
$query = trim((string) ($_GET['q'] ?? ''));
$result = null;
$error = null;

function is_list_array_orgs(array $value): bool
{
    return array_keys($value) === range(0, count($value) - 1);
}

function find_org_rows($value): array
{
    if (!is_array($value)) {
        return [];
    }

    if (is_list_array_orgs($value)) {
        return array_values(array_filter($value, 'is_array'));
    }

    foreach (['data', 'items', 'organisasi', 'orgs', 'rows', 'result'] as $key) {
        if (isset($value[$key])) {
            $rows = find_org_rows($value[$key]);
            if ($rows !== []) {
                return $rows;
            }
        }
    }

    foreach ($value as $child) {
        $rows = find_org_rows($child);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

function org_value(array $row, array $keys, string $fallback = '-'): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return is_scalar($row[$key]) ? (string) $row[$key] : json_encode($row[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    return $fallback;
}

function org_matches(array $row, string $query): bool
{
    if ($query === '') {
        return true;
    }

    $haystack = strtolower(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return str_contains($haystack, strtolower($query));
}

try {
    $orgs = masook_authorized_request('GET', $orgsUrl, $currentSession);
    $session = $orgs['session'] ?? $currentSession;
    unset($orgs['session']);

    sync_masook_session_to_php($session);

    $result = [
        'auth_source' => 'database',
        'session_id' => $session['id'] ?? null,
        'username' => $session['username'] ?? null,
        'token_expires_at' => $session['expires_at'] ?? null,
        'orgs_url' => $orgsUrl,
        'orgs' => $orgs,
    ];
} catch (Throwable $throwable) {
    $error = $throwable->getMessage();
}

$body = is_array($result['orgs']['body'] ?? null) ? $result['orgs']['body'] : [];
$rows = find_org_rows($body);
$filteredRows = array_values(array_filter($rows, static fn(array $row): bool => org_matches($row, $query)));
$statusCode = $result !== null ? (int) ($result['orgs']['status_code'] ?? 0) : 0;

page_start('Data Organisasi', [
    'active' => 'orgs',
    'endpoint' => $orgsUrl,
]);
?>
<section class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-lg-9">
                <label class="form-label fw-semibold" for="q">Cari Organisasi</label>
                <input class="form-control" id="q" name="q" type="search" value="<?= e($query) ?>" placeholder="Cari nama, kode, ID, atau kata lain dari response">
            </div>
            <div class="col-12 col-lg-3">
                <button class="btn btn-success w-100" type="submit">
                    <i class="bi bi-search me-1"></i>Cari
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
        <div class="card-body">
            <?php if ($filteredRows === []): ?>
                <div class="text-center py-5">
                    <div class="display-6 text-secondary mb-2"><i class="bi bi-inbox"></i></div>
                    <div class="fw-semibold">Tidak ada organisasi yang cocok.</div>
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($filteredRows as $row): ?>
                        <?php
                            $id = org_value($row, ['organisasi_id', 'id', 'id_organisasi']);
                            $kode = org_value($row, ['kode_organisasi', 'organisasi_kode', 'kode_org', 'kodeOrg', 'kode', 'no_reg']);
                            $nama = org_value($row, ['nama', 'nama_organisasi', 'organisasi', 'name', 'label']);
                            $email = org_value($row, ['email'], '');
                            $telepon = org_value($row, ['no_telp', 'telepon', 'phone'], '');
                            $kontak = trim($email . ($email !== '' && $telepon !== '' ? ' / ' : '') . $telepon) ?: '-';
                            $awal = org_value($row, ['tgl_berlaku_awal'], '');
                            $akhir = org_value($row, ['tgl_berlaku_akhir'], '');
                            $masaBerlaku = trim($awal . ($awal !== '' && $akhir !== '' ? ' - ' : '') . $akhir) ?: '-';
                            $isDisabled = strtolower(org_value($row, ['is_disabled'], 'false')) === 'true';
                            $isLangganan = strtolower(org_value($row, ['is_langganan'], 'false')) === 'true';
                            $status = $isDisabled ? 'Nonaktif' : ($isLangganan ? 'Aktif' : 'Terdaftar');
                            $statusClass = $isDisabled ? 'text-bg-secondary' : 'text-bg-success';
                        ?>
                        <article class="card mobile-card border-0">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                                    <div class="min-w-0">
                                        <div class="text-secondary small mono-small"><?= e($kode) ?></div>
                                        <h2 class="h6 mb-0"><?= e($nama) ?></h2>
                                    </div>
                                    <span class="badge <?= e($statusClass) ?>"><?= e($status) ?></span>
                                </div>

                                <div class="d-grid gap-2 small mb-3">
                                    <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                        <span class="text-secondary"><i class="bi bi-hash me-1"></i>ID</span>
                                        <span class="fw-semibold mono-small"><?= e($id) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                        <span class="text-secondary"><i class="bi bi-telephone me-1"></i>Kontak</span>
                                        <span class="fw-semibold text-end"><?= e($kontak) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-top">
                                        <span class="text-secondary"><i class="bi bi-calendar-range me-1"></i>Berlaku</span>
                                        <span class="fw-semibold mono-small text-end"><?= e($masaBerlaku) ?></span>
                                    </div>
                                </div>

                                <a class="btn btn-success w-100" href="presensi_personal.php?kode_org=<?= e(rawurlencode($kode)) ?>">
                                    <i class="bi bi-fingerprint me-1"></i>Pakai Organisasi
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php
page_end();
