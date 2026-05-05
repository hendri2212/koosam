<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function db_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException('Nama database tidak valid.');
    }

    return '`' . $identifier . '`';
}

function server_db(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_CHARSET
    );

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

$statements = [];
$error = null;

try {
    $pdo = server_db();
    $database = db_identifier(DB_NAME);

    $queries = [
        "DROP DATABASE IF EXISTS {$database}",
        "CREATE DATABASE {$database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "USE {$database}",
        "CREATE TABLE `users` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(190) NOT NULL,
            `masook_user_id` VARCHAR(100) DEFAULT NULL,
            `nomor_handphone` VARCHAR(30) DEFAULT NULL,
            `organisasi_id` VARCHAR(100) DEFAULT NULL,
            `organisasi_kode` VARCHAR(100) DEFAULT NULL,
            `latitude` VARCHAR(50) DEFAULT NULL,
            `longitude` VARCHAR(50) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_users_username` (`username`),
            UNIQUE KEY `uniq_users_masook_user_id` (`masook_user_id`),
            KEY `idx_users_nomor_handphone` (`nomor_handphone`),
            KEY `idx_users_organisasi_id` (`organisasi_id`),
            KEY `idx_users_organisasi_kode` (`organisasi_kode`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE `sessions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `access_token` TEXT NOT NULL,
            `refresh_token` TEXT DEFAULT NULL,
            `token_type` VARCHAR(30) NOT NULL DEFAULT 'Bearer',
            `expires_at` DATETIME DEFAULT NULL,
            `refresh_expires_at` DATETIME DEFAULT NULL,
            `app_version` VARCHAR(30) DEFAULT NULL,
            `login_status_code` SMALLINT UNSIGNED DEFAULT NULL,
            `login_response` JSON DEFAULT NULL,
            `refresh_status_code` SMALLINT UNSIGNED DEFAULT NULL,
            `refresh_response` JSON DEFAULT NULL,
            `last_refresh_at` DATETIME DEFAULT NULL,
            `last_login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_sessions_user_id` (`user_id`),
            KEY `idx_sessions_expires_at` (`expires_at`),
            KEY `idx_sessions_refresh_expires_at` (`refresh_expires_at`),
            CONSTRAINT `fk_sessions_user_id`
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
        $statements[] = strtok($query, "\n");
    }
} catch (Throwable $throwable) {
    $error = $throwable->getMessage();
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Database Koosam</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f6f8fb;
            color: #172033;
        }

        main {
            max-width: 860px;
            margin: 48px auto;
            padding: 0 20px;
        }

        .panel {
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

        p {
            margin: 0 0 18px;
            color: #536174;
        }

        .status {
            display: inline-block;
            margin-bottom: 18px;
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 700;
        }

        .success {
            background: #e8f7ef;
            color: #116b3b;
        }

        .error {
            background: #fdeaea;
            color: #9f1d1d;
        }

        ol {
            margin: 0;
            padding-left: 22px;
        }

        li {
            margin: 8px 0;
            font-family: Consolas, monospace;
            font-size: 13px;
        }

        a {
            color: #1464c0;
            font-weight: 700;
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Create Database Koosam</h1>
        <p>Script ini memakai koneksi dari <code>config/database.php</code>, drop database jika ada, lalu membuat ulang semua tabel.</p>

        <?php if ($error !== null): ?>
            <div class="status error">Gagal</div>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <div class="status success">Selesai</div>
            <p>Database <code><?= htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8') ?></code> sudah dibuat ulang.</p>
            <ol>
                <?php foreach ($statements as $statement): ?>
                    <li><?= htmlspecialchars($statement, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ol>
            <p style="margin-top:18px;"><a href="seed_users.php">Lanjut seed users</a></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
