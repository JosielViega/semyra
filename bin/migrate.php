<?php

declare(strict_types=1);

use App\Core\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$config = require $root . '/config/database.php';

try {
    $pdo = (new Database($config))->connection();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations ('
        . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
        . 'migration VARCHAR(255) NOT NULL UNIQUE, '
        . 'executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $executed = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob($root . '/database/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    $pending = array_filter($files, static fn (string $file): bool => !in_array(basename($file), $executed, true));

    foreach ($pending as $file) {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Migration is empty or unreadable: ' . basename($file));
        }

        $pdo->exec($sql);
        $statement = $pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
        $statement->execute(['migration' => basename($file)]);
        fwrite(STDOUT, 'Migrated: ' . basename($file) . PHP_EOL);
    }

    if ($pending === []) {
        fwrite(STDOUT, 'No pending migrations.' . PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
