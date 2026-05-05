<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$seedUsers = [
    [
        'id' => 1,
        'username' => 'yuli.anoor305@gmail.com',
        'masook_user_id' => '963262',
        'nomor_handphone' => '085746080544',
        'organisasi_id' => '1073',
        'organisasi_kode' => 'ORG-NMTUVO',
        'latitude' => '-3.2497189',
        'longitude' => '116.2159197',
        'created_at' => '2026-05-04 18:40:09',
        'updated_at' => '2026-05-04 20:33:21',
    ],
];

$inserted = 0;
$updated = 0;
$error = null;

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO users
            (id, username, masook_user_id, nomor_handphone, organisasi_id, organisasi_kode, latitude, longitude, created_at, updated_at)
         VALUES
            (:id, :username, :masook_user_id, :nomor_handphone, :organisasi_id, :organisasi_kode, :latitude, :longitude, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            masook_user_id = VALUES(masook_user_id),
            nomor_handphone = VALUES(nomor_handphone),
            organisasi_id = VALUES(organisasi_id),
            organisasi_kode = VALUES(organisasi_kode),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            created_at = VALUES(created_at),
            updated_at = VALUES(updated_at)"
    );

    foreach ($seedUsers as $user) {
        $stmt->execute($user);

        if ($stmt->rowCount() === 1) {
            $inserted++;
        } else {
            $updated++;
        }
    }

    $pdo->commit();
    $pdo->exec('ALTER TABLE users AUTO_INCREMENT = 2');
} catch (Throwable $throwable) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $throwable->getMessage();
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seed Users Koosam</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f6f8fb;
            color: #172033;
        }

        main {
            max-width: 760px;
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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }

        th,
        td {
            border-bottom: 1px solid #dfe5ee;
            padding: 10px 8px;
            text-align: left;
            font-size: 14px;
        }

        code {
            font-family: Consolas, monospace;
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
        <h1>Seed Users Koosam</h1>
        <p>Seeder ini mengisi tabel <code>users</code> dari data awal di <code>users.sql</code>.</p>

        <?php if ($error !== null): ?>
            <div class="status error">Gagal</div>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <div class="status success">Selesai</div>
            <p><?= $inserted ?> user ditambahkan, <?= $updated ?> user diperbarui.</p>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>No. HP</th>
                        <th>User ID</th>
                        <th>Organisasi</th>
                        <th>Kode</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seedUsers as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $user['id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['nomor_handphone'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['masook_user_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['organisasi_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['organisasi_kode'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['latitude'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($user['longitude'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:18px;"><a href="../login.php">Lanjut ke login.php</a></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
