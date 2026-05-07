<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$routes = [
    'history' => 'riwayat_presensi.php',
    'today' => 'riwayat_today.php',
    'status' => 'presensi_status.php',
    'presence' => 'presensi_personal.php',
    'message' => 'presensi_message.php',
    'orgs' => 'organisasi.php',
];

$page = (string) ($_GET['page'] ?? '');
if ($page !== '' && isset($routes[$page])) {
    header('Location: ' . $routes[$page]);
    exit;
}

$session = require_masook_login();

$menus = [
    [
        'title' => 'Presence',
        'description' => 'Buka halaman presensi personal.',
        'icon' => 'bi-fingerprint',
        'href' => 'presensi_personal.php',
        'tone' => 'warning',
    ],
    [
        'title' => 'History Presence',
        'description' => 'Lihat riwayat presensi berdasarkan rentang tanggal.',
        'icon' => 'bi-clock-history',
        'href' => 'riwayat_presensi.php',
        'tone' => 'success',
    ],
    [
        'title' => 'History Today',
        'description' => 'Lihat data presensi khusus hari ini saja.',
        'icon' => 'bi-calendar-day',
        'href' => 'riwayat_today.php',
        'tone' => 'success',
    ],
    [
        'title' => 'Presence Status',
        'description' => 'Ambil response presensi hari ini dari API Koosam.',
        'icon' => 'bi-calendar-check',
        'href' => 'presensi_status.php',
        'tone' => 'primary',
    ],
    [
        'title' => 'Message Presence',
        'description' => 'Kerangka backend presensi lewat WhatsApp bot.',
        'icon' => 'bi-whatsapp',
        'href' => 'presensi_message.php',
        'tone' => 'success',
    ],
    [
        'title' => 'Organizations',
        'description' => 'Cari kode dan nama organisasi dari API Koosam.',
        'icon' => 'bi-buildings',
        'href' => 'organisasi.php',
        'tone' => 'info',
    ],
];

page_start('Dashboard', [
    'active' => 'dashboard',
]);
?>
<section class="row g-3">
    <?php foreach ($menus as $menu): ?>
        <div class="col-12 col-md-6">
            <a class="card dashboard-tile h-100 text-decoration-none text-body" href="<?= e($menu['href']) ?>">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="dashboard-icon rounded-4 bg-<?= e($menu['tone']) ?> bg-opacity-10 text-<?= e($menu['tone']) ?> d-inline-flex align-items-center justify-content-center">
                            <i class="bi <?= e($menu['icon']) ?> fs-4"></i>
                        </div>
                        <div class="min-w-0">
                            <h2 class="h6 mb-1"><?= e($menu['title']) ?></h2>
                            <p class="small text-secondary mb-0"><?= e($menu['description']) ?></p>
                        </div>
                        <i class="bi bi-chevron-right ms-auto text-secondary"></i>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</section>
<?php
page_end();
