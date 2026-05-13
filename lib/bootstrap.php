<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function now_utc(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function format_display(string $utc): string {
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    $dt = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $dt->format('M j, Y g:i A T');
}

function to_utc(string $local): string {
    $dt = new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get()));
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function slugify(string $title): string {
    $s = strtolower($title);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if (strlen($s) > 50) {
        $s = rtrim(substr($s, 0, 50), '-');
    }
    return $s;
}

function random_slug_suffix(int $len = 4): string {
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $n = strlen($chars);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $n - 1)];
    }
    return $out;
}

function generate_slug(string $title): string {
    $base = slugify($title);
    if ($base === '') {
        $base = 'doc';
    }
    $stmt = db()->prepare('SELECT 1 FROM documents WHERE slug = ? LIMIT 1');
    for ($i = 0; $i < 5; $i++) {
        $candidate = $base . '-' . random_slug_suffix(4);
        $stmt->execute([$candidate]);
        if ($stmt->fetchColumn() === false) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not generate a unique slug after 5 attempts.');
}
