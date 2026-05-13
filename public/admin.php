<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publish_at_local = trim($_POST['publish_at'] ?? '');
    $publish_at = $publish_at_local === '' ? null : to_utc($publish_at_local);

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $slug = generate_slug($title);
        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, publish_at, slug)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$title, $body, $staff['id'], $publish_at, $slug]);
        $docId = (int) db()->lastInsertId();

        $details = ['title' => $title, 'slug' => $slug];
        if ($publish_at !== null) {
            $details['publish_at'] = $publish_at;
        }
        audit_log('create', 'document', $docId, $details);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%' . $q . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at <span class="hint">(optional &mdash; leave blank to publish immediately)</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search by title…">
        <button type="submit" class="btn">Search</button>
        <?php if ($q !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </form>
    <?php if (empty($docs)): ?>
        <p class="empty"><?= $q !== '' ? 'No documents match "' . h($q) . '".' : 'No documents yet.' ?></p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id"><?= h($d['slug'] ?? '#' . (int) $d['id']) ?></td>
                        <td>
                            <?= h($d['title']) ?>
                            <?php if (!empty($d['publish_at']) && $d['publish_at'] > now_utc()): ?>
                                <span class="badge badge-scheduled">Scheduled for <?= h(format_display($d['publish_at'])) ?></span>
                            <?php endif ?>
                        </td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><a href="/share.php?doc=<?= h($d['slug'] ?? (string) (int) $d['id']) ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
