<?php
declare(strict_types=1);

require_once __DIR__ . '/masook_api.php';

function page_start(string $title, array $options = []): void
{
    $subtitle = (string) ($options['subtitle'] ?? 'Koosam Application');
    $endpoint = (string) ($options['endpoint'] ?? '');
    $containerClass = (string) ($options['container_class'] ?? 'page-shell');
    $showNav = (bool) ($options['show_nav'] ?? true);
    $extraCss = (string) ($options['extra_css'] ?? '');
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: #eef3f8;
            color: #172033;
        }
        .page-shell {
            max-width: 560px;
        }
        .top-band {
            background: #123a63;
            border-bottom: 1px solid rgba(255, 255, 255, .14);
            border-radius: 0 0 22px 22px;
            box-shadow: 0 14px 32px rgba(15, 23, 42, .18);
        }
        .metric {
            border-left: 4px solid #16a34a;
        }
        .table-card {
            border-radius: 8px;
        }
        .table thead th {
            color: #475569;
            font-size: .78rem;
            letter-spacing: .02em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .mono-small {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .82rem;
        }
        .text-truncate-fixed {
            max-width: 220px;
        }
        pre {
            max-height: 460px;
        }
        .app-title {
            font-size: 1.38rem;
            line-height: 1.2;
            letter-spacing: 0;
        }
        .app-subtitle {
            color: rgba(255, 255, 255, .68);
        }
        .mobile-card {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, .08);
        }
        .dashboard-tile {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
            transition: transform .14s ease, box-shadow .14s ease;
        }
        .dashboard-tile:active {
            transform: scale(.99);
        }
        .dashboard-icon {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
        }
        .presence-clock {
            font-size: clamp(3.6rem, 18vw, 7rem);
            line-height: .95;
            letter-spacing: 0;
        }
        @media (min-width: 768px) {
            .page-shell {
                max-width: 960px;
            }
            .app-title {
                font-size: 1.65rem;
            }
        }
        @media (min-width: 1200px) {
            .page-shell {
                max-width: 1120px;
            }
        }
        <?= $extraCss ?>
    </style>
</head>
<body>
    <header class="top-band text-white">
        <div class="container <?= e($containerClass) ?> py-4">
            <div class="d-flex flex-row gap-3 align-items-center justify-content-between">
                <div class="min-w-0">
                    <div class="app-subtitle small mb-1"><?= e($subtitle) ?></div>
                    <h1 class="app-title fw-semibold mb-2"><?= e($title) ?></h1>
                </div>
                <?php if (!empty($options['header_actions'])): ?>
                    <div class="d-flex gap-2 flex-shrink-0"><?= $options['header_actions'] ?></div>
                <?php elseif ($showNav && ($options['show_logout'] ?? true) === true): ?>
                    <div class="d-flex justify-content-end flex-shrink-0">
                        <a class="btn btn-light btn-sm" href="login.php?logout=1">
                            <i class="bi bi-box-arrow-right me-1"></i>Logout
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main class="container <?= e($containerClass) ?> py-4">
    <?php
}

function page_end(): void
{
    global $pageFooterScripts;
    ?>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!empty($pageFooterScripts)): ?>
        <?= $pageFooterScripts ?>
    <?php endif; ?>
</body>
</html>
    <?php
}
