<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

start_app_session();

if (isset($_GET['logout'])) {
    logout_masook_session();
}

$next = trim((string) ($_GET['next'] ?? ''));
$allowedNextPages = [
    'index.php',
    'riwayat_presensi.php',
    'presensi_today.php',
    'presensi_personal.php',
    'organisasi.php',
];
if (!in_array($next, $allowedNextPages, true)) {
    $next = 'index.php';
}

if (!isset($_GET['logout']) && $_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['next'])) {
    redirect_if_masook_logged_in();
}

$defaults = [
    'username' => 'yuli.anoor305@gmail.com',
    'password' => '',
    'app_version' => MASOOK_APP_VERSION,
];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $appVersion = trim((string) ($_POST['app_version'] ?? MASOOK_APP_VERSION));

        if ($username === '' || $password === '') {
            throw new RuntimeException('Username dan password wajib diisi.');
        }

        $login = masook_login($username, $password, $appVersion);
        $accessToken = $login['body']['data']['access_token'] ?? null;
        $refreshToken = $login['body']['data']['refresh_token'] ?? null;
        if (!$accessToken) {
            throw new RuntimeException('Login gagal atau access_token tidak ditemukan.');
        }

        $userId = jwt_sub((string) $accessToken);
        save_masook_session(
            $username,
            (string) $accessToken,
            $refreshToken ? (string) $refreshToken : null,
            $userId,
            null,
            $appVersion,
            (int) $login['status_code'],
            is_array($login['body']) ? $login['body'] : ['raw' => $login['body']]
        );

        $_SESSION['masook_username'] = $username;
        $_SESSION['masook_user_id'] = $userId;
        unset($_SESSION['masook_logged_out']);

        header('Location: ' . $next);
        exit;
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

page_start('Login Masook', [
    'show_nav' => false,
    'endpoint' => MASOOK_BASE_URL . '/api/login',
    'container_class' => 'page-shell',
]);
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <?php if (isset($_GET['logout'])): ?>
            <div class="alert alert-info border-0 shadow-sm">
                <i class="bi bi-info-circle-fill me-1"></i>Session lokal sudah keluar.
            </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger border-0 shadow-sm">
                <i class="bi bi-x-circle-fill me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <section class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post" class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="username">Username</label>
                        <input class="form-control" id="username" name="username" type="email" value="<?= e((string) ($_POST['username'] ?? $defaults['username'])) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="password">Password</label>
                        <input class="form-control" id="password" name="password" type="password" value="<?= e((string) ($_POST['password'] ?? $defaults['password'])) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="app_version">Versi Aplikasi</label>
                        <input class="form-control" id="app_version" name="app_version" type="text" value="<?= e((string) ($_POST['app_version'] ?? $defaults['app_version'])) ?>" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success w-100" type="submit">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Login dan Buka Dashboard
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>
<?php
page_end();
