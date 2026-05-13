<?php

function run_migrations(): void {
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            name TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    $dir = __DIR__ . '/../migrations';
    $files = glob($dir . '/*.sql') ?: [];
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration {$name} is empty or unreadable.");
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
            $stmt->execute([$name]);
            $pdo->commit();
            echo "Applied migration: {$name}\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
        }
    }
}
