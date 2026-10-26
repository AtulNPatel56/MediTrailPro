<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/documents.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$categories = ['Lab report', 'Prescription', 'Imaging', 'Insurance', 'Other'];
$errors = [];
$pageError = null;
$successMessage = null;
$members = [];
$selected = null;
$documents = [];
$form = ['title' => '', 'category' => 'Other'];
$csrfToken = csrfToken();

$statusMessages = ['created' => 'Document uploaded.', 'deleted' => 'Document deleted.'];
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $successMessage = $statusMessages[$status];
}

function redirectToDocuments(int $memberId, string $status): never
{
    redirect('documents.php?member=' . $memberId . '&status=' . rawurlencode($status));
}

function formatBytes(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

function cleanFileName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}._\- ]+/u', '_', $name) ?? '';
    $name = trim($name, " .\t");
    return mb_substr($name !== '' ? $name : 'document', 0, 120);
}

try {
    $pdo = db();

    $memberQuery = $pdo->prepare(
        'SELECT id, first_name, last_name FROM family_members
         WHERE is_active = 1 AND owner_user_id = :owner ORDER BY first_name, last_name'
    );
    $memberQuery->execute(['owner' => $ownerId]);
    $members = $memberQuery->fetchAll();

    $requested = positiveInt($_POST['member_id'] ?? $_GET['member'] ?? null);
    foreach ($members as $member) {
        if ($requested !== false && (int) $member['id'] === $requested) {
            $selected = $member;
        }
    }
    if ($selected === null && $members !== [] && $requested === false) {
        $selected = $members[0];
    }
    if ($selected === null && $requested !== false) {
        http_response_code(404);
        $errors[] = 'That family member could not be found.';
    }

    // An upload larger than post_max_size arrives with an empty $_POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        $errors[] = 'That file is too large. The limit is ' . formatBytes(DOCUMENT_MAX_BYTES) . '.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected !== null) {
        $action = $_POST['action'] ?? '';
        $selectedId = (int) $selected['id'];

        if (!validCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif ($action === 'delete') {
            $documentId = positiveInt($_POST['id'] ?? null);
            if ($documentId === false) {
                http_response_code(400);
                $errors[] = 'The selected document is invalid.';
            } else {
                $lookup = $pdo->prepare(
                    'SELECT d.stored_name FROM documents AS d
                     INNER JOIN family_members AS f ON f.id = d.family_member_id
                     WHERE d.id = :id AND d.family_member_id = :member AND f.owner_user_id = :owner'
                );
                $lookup->execute(['id' => $documentId, 'member' => $selectedId, 'owner' => $ownerId]);
                $storedName = $lookup->fetchColumn();
                if ($storedName !== false) {
                    $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute(['id' => $documentId]);
                    deleteStoredDocument((string) $storedName);
                    audit($ownerId, 'delete', 'document', $documentId);
                }
                redirectToDocuments($selectedId, 'deleted');
            }
        } elseif ($action === 'create') {
            $title = $_POST['title'] ?? '';
            $category = $_POST['category'] ?? '';
            $form['title'] = is_string($title) ? trim($title) : '';
            $form['category'] = is_string($category) && in_array($category, $categories, true) ? $category : 'Other';

            if ($form['title'] === '' || mb_strlen($form['title']) > 140) {
                $errors[] = 'Title is required and must be 140 characters or fewer.';
            }
            if (!is_string($category) || !in_array($category, $categories, true)) {
                $errors[] = 'Choose a valid category.';
            }

            $file = $_FILES['document'] ?? null;
            $detectedType = null;
            if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
                $errors[] = 'Choose a file to upload.';
            } elseif ($file['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Choose a file to upload.';
            } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'That file is too large. The limit is ' . formatBytes(DOCUMENT_MAX_BYTES) . '.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'The upload failed. Please try again.';
            } elseif ($file['size'] > DOCUMENT_MAX_BYTES) {
                $errors[] = 'That file is too large. The limit is ' . formatBytes(DOCUMENT_MAX_BYTES) . '.';
            } elseif ($file['size'] === 0) {
                $errors[] = 'That file is empty.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedType = $finfo->file($file['tmp_name']);
                if (!is_string($detectedType) || !isset(DOCUMENT_TYPES[$detectedType])) {
                    $detectedType = null;
                    $errors[] = 'Only PDF, JPG and PNG files are allowed.';
                }
            }

            if ($errors === [] && $detectedType !== null) {
                if (!is_dir(DOCUMENT_STORAGE_DIR) && !mkdir(DOCUMENT_STORAGE_DIR, 0775, true) && !is_dir(DOCUMENT_STORAGE_DIR)) {
                    throw new RuntimeException('Document storage folder could not be created.');
                }
                $storedName = bin2hex(random_bytes(16)) . '.' . DOCUMENT_TYPES[$detectedType];
                if (!move_uploaded_file($file['tmp_name'], documentPath($storedName))) {
                    throw new RuntimeException('The uploaded file could not be saved.');
                }
                try {
                    $pdo->prepare(
                        'INSERT INTO documents (family_member_id, title, category, original_name, stored_name, mime_type, size_bytes)
                         VALUES (:member, :title, :category, :original, :stored, :mime, :size)'
                    )->execute([
                        'member' => $selectedId,
                        'title' => $form['title'],
                        'category' => $form['category'],
                        'original' => cleanFileName((string) $file['name']),
                        'stored' => $storedName,
                        'mime' => $detectedType,
                        'size' => (int) $file['size'],
                    ]);
                } catch (PDOException $exception) {
                    deleteStoredDocument($storedName);
                    throw $exception;
                }
                audit($ownerId, 'create', 'document', (int) $pdo->lastInsertId());
                redirectToDocuments($selectedId, 'created');
            }
        } else {
            http_response_code(400);
            $errors[] = 'That action is not supported.';
        }
    }

    if ($selected !== null) {
        $listQuery = $pdo->prepare(
            'SELECT id, title, category, original_name, mime_type, size_bytes, uploaded_at FROM documents
             WHERE family_member_id = :member ORDER BY uploaded_at DESC, id DESC LIMIT 100'
        );
        $listQuery->execute(['member' => (int) $selected['id']]);
        $documents = $listQuery->fetchAll();
    }
} catch (PDOException | RuntimeException $exception) {
    http_response_code(500);
    $pageError = 'The documents page could not complete that request. Check MySQL, that migration 006 has been applied, and that the storage folder is writable.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$icons = ['application/pdf' => 'bi-file-earmark-pdf', 'image/jpeg' => 'bi-file-earmark-image', 'image/png' => 'bi-file-earmark-image'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Documents | MediTrail Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
    <script src="assets/js/confirm.js" defer></script>
</head>
<body>
<main class="family-page">
    <header class="family-page-header">
        <a class="brand" href="index.php" aria-label="MediTrail Pro dashboard">
            <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            <span class="brand-name">MediTrail<span>Pro</span></span>
        </a>
        <nav class="d-flex align-items-center gap-3 flex-wrap">
            <a class="family-back-link" href="family_members.php"><i class="bi bi-people"></i> Family</a>
            <a class="family-back-link" href="medical_profile.php"><i class="bi bi-clipboard2-pulse"></i> Profile</a>
            <a class="family-back-link" href="health.php"><i class="bi bi-activity"></i> Health</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> RECORDS</div>
                <h1>Docu<span>ments</span></h1>
                <p class="welcome-subtitle">Keep lab reports, prescriptions and other papers with each family member. PDF, JPG or PNG, up to <?= escape(formatBytes(DOCUMENT_MAX_BYTES)) ?>. Use fictional files only while this is a local starter.</p>
            </div>
        </section>

        <?php if ($successMessage !== null): ?>
            <div class="alert alert-success" role="status" aria-live="polite"><?= escape($successMessage) ?></div>
        <?php endif; ?>
        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert" aria-live="assertive">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?><li><?= escape($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($members === [] && $pageError === null): ?>
            <div class="empty-state family-empty-state">
                <span class="empty-icon"><i class="bi bi-people"></i></span>
                <strong>No family members yet</strong>
                <p>Add a <a href="family_members.php">family member</a> to start storing documents.</p>
            </div>
        <?php elseif ($members !== []): ?>
            <nav class="profile-tabs" aria-label="Choose family member">
                <?php foreach ($members as $member): ?>
                    <?php $isCurrent = $selected !== null && (int) $member['id'] === (int) $selected['id']; ?>
                    <a class="profile-tab<?= $isCurrent ? ' active' : '' ?>" href="documents.php?member=<?= escape($member['id']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                        <span class="avatar avatar-small"><?= escape(strtoupper(mb_substr($member['first_name'], 0, 1))) ?></span>
                        <?= escape($member['first_name'] . ' ' . $member['last_name']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($selected !== null): ?>
            <div class="family-layout">
                <section class="family-form-card" aria-labelledby="uploadTitle">
                    <div class="family-section-heading">
                        <span class="heading-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                        <div><h2 id="uploadTitle">Upload a document</h2><p>For <?= escape($selected['first_name']) ?></p></div>
                    </div>
                    <form method="post" action="documents.php?member=<?= escape($selected['id']) ?>" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="member_id" value="<?= escape($selected['id']) ?>">
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= escape(DOCUMENT_MAX_BYTES) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="title">Title <span class="required-mark">*</span></label>
                            <input class="form-control" id="title" name="title" type="text" maxlength="140" value="<?= escape($form['title']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="category">Category</label>
                            <select class="form-select" id="category" name="category">
                                <?php foreach ($categories as $option): ?>
                                    <option value="<?= escape($option) ?>" <?= $form['category'] === $option ? 'selected' : '' ?>><?= escape($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="document">File <span class="required-mark">*</span></label>
                            <input class="form-control" id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                        </div>
                        <button class="btn btn-primary family-submit-button" type="submit"><i class="bi bi-upload"></i> Upload</button>
                    </form>
                </section>

                <section class="family-list-card" aria-labelledby="documentListTitle">
                    <div class="family-section-heading family-list-heading">
                        <span class="heading-icon family-list-icon"><i class="bi bi-folder2-open"></i></span>
                        <div><h2 id="documentListTitle">Stored documents</h2><p><?= escape(count($documents)) ?> files · newest first</p></div>
                    </div>

                    <?php if ($documents === []): ?>
                        <div class="empty-state family-empty-state">
                            <span class="empty-icon"><i class="bi bi-file-earmark-medical"></i></span>
                            <strong>No documents yet</strong>
                            <p>Upload a file using the form to get started.</p>
                        </div>
                    <?php else: ?>
                        <ul class="profile-list">
                            <?php foreach ($documents as $document): ?>
                                <li>
                                    <div>
                                        <strong><i class="bi <?= escape($icons[$document['mime_type']] ?? 'bi-file-earmark') ?>"></i> <?= escape($document['title']) ?></strong>
                                        <span><?= escape($document['category']) ?> · <?= escape($document['original_name']) ?> · <?= escape(formatBytes((int) $document['size_bytes'])) ?></span>
                                        <span><?= escape((new DateTimeImmutable($document['uploaded_at']))->format('M j, Y · g:i A')) ?></span>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-secondary member-action-button" href="document_download.php?id=<?= escape($document['id']) ?>"><i class="bi bi-download"></i><span>Download</span></a>
                                        <form method="post" action="documents.php?member=<?= escape($selected['id']) ?>" data-confirm="Permanently delete this document?">
                                            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="member_id" value="<?= escape($selected['id']) ?>">
                                            <input type="hidden" name="id" value="<?= escape($document['id']) ?>">
                                            <button class="btn btn-sm btn-outline-danger member-action-button" type="submit" aria-label="Delete <?= escape($document['title']) ?>"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
