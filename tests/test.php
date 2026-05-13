<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function make_share(int $docId): string {
    $token = random_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);
    return $token;
}

function http_get(string $path): string {
    $out = @file_get_contents('http://localhost:8000' . $path);
    if ($out === false) {
        throw new RuntimeException("GET {$path} failed — is the dev server running? (docker compose up -d)");
    }
    return $out;
}

function http_post(string $path, array $fields): string {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query($fields),
        'ignore_errors' => true,
    ]]);
    $out = @file_get_contents('http://localhost:8000' . $path, false, $ctx);
    if ($out === false) {
        throw new RuntimeException("POST {$path} failed — is the dev server running?");
    }
    return $out;
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('document with future publish_at returns the not-yet-available page', function () {
    db()->exec("INSERT INTO documents (title, body, created_by, publish_at) VALUES ('Future Doc', 'secret-body-marker', 1, '2099-01-01 00:00:00')");
    $token = make_share((int) db()->lastInsertId());

    $html = http_get('/view.php?token=' . $token);
    assert_true(str_contains($html, "isn't available yet"), 'gate page should render');
    assert_true(!str_contains($html, 'secret-body-marker'), 'body must not leak before publish_at');
});

test('document with past publish_at renders the body', function () {
    db()->exec("INSERT INTO documents (title, body, created_by, publish_at) VALUES ('Past Doc', 'past-body-marker', 1, '2000-01-01 00:00:00')");
    $token = make_share((int) db()->lastInsertId());

    $html = http_get('/view.php?token=' . $token);
    assert_true(str_contains($html, 'past-body-marker'), 'body should be visible after publish_at');
});

test('document with NULL publish_at renders immediately (backward compat)', function () {
    db()->exec("INSERT INTO documents (title, body, created_by) VALUES ('Legacy Doc', 'legacy-body-marker', 1)");
    $token = make_share((int) db()->lastInsertId());

    $html = http_get('/view.php?token=' . $token);
    assert_true(str_contains($html, 'legacy-body-marker'), 'unscheduled doc should render body');
});

test('admin create records publish_at in audit_log details', function () {
    http_post('/admin.php', [
        'title'      => 'Audit Test Doc',
        'body'       => 'b',
        'publish_at' => '2099-06-01T12:00',
    ]);

    $doc = db()->query("SELECT id FROM documents WHERE title = 'Audit Test Doc'")->fetch();
    assert_true($doc !== false, 'doc was not created by admin POST');

    $stmt = db()->prepare("SELECT details FROM audit_log WHERE entity_id = ? AND action = 'create' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$doc['id']]);
    $details = json_decode($stmt->fetchColumn(), true);
    assert_true(isset($details['publish_at']), 'audit_log should record publish_at');
    assert_true(str_starts_with($details['publish_at'], '2099-06-01'), 'publish_at should be the UTC-converted value');
});

test('generated slug has the expected shape', function () {
    $slug = generate_slug('Some Sample Title');
    $ok = preg_match('/^[a-z0-9-]+-[abcdefghjkmnpqrstuvwxyz23456789]{4}$/', $slug);
    assert_true($ok === 1, "unexpected slug shape: {$slug}");
});

test('two documents with identical titles get distinct slugs', function () {
    $a = generate_slug('Duplicate Title');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Duplicate Title', 'a', $a]);
    $b = generate_slug('Duplicate Title');
    assert_true($a !== $b, "expected distinct slugs, got: {$a} / {$b}");
});

test('share.php resolves a document by slug', function () {
    $slug = generate_slug('Resolve By Slug');
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Resolve By Slug', 'b', $slug]);

    $html = http_get('/share.php?doc=' . $slug);
    assert_true(str_contains($html, 'Resolve By Slug'), 'share page should resolve the doc by slug');
});

test('non-ASCII title falls back to a doc- prefixed slug', function () {
    $slug = generate_slug('???');
    $ok = preg_match('/^doc-[abcdefghjkmnpqrstuvwxyz23456789]{4}$/', $slug);
    assert_true($ok === 1, "unexpected fallback slug shape: {$slug}");
});

test('admin search filters documents by title substring', function () {
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Q4 Budget Report', 'a', generate_slug('Q4 Budget Report')]);
    $stmt->execute(['Q1 Planning Doc', 'b', generate_slug('Q1 Planning Doc')]);

    $html = http_get('/admin.php?q=Budget');
    assert_true(str_contains($html, 'Q4 Budget Report'), 'search should include matching doc');
    assert_true(!str_contains($html, 'Q1 Planning Doc'), 'search should exclude non-matching doc');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
