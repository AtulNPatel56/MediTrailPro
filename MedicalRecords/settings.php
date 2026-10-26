<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/documents.php';

const SETTINGS_MIN_PASSWORD = 10;

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$errors = [];
$pageError = null;
$successMessage = null;
$activeForm = '';
$csrfToken = csrfToken();

if (($_GET['status'] ?? '') === 'password') {
    $successMessage = 'Your password was changed.';
}

function exportData(PDO $pdo, int $ownerId, array $user): never
{
    $fetch = static function (string $sql) use ($pdo, $ownerId): array {
        $statement = $pdo->prepare($sql);
        $statement->execute(['owner' => $ownerId]);
        return $statement->fetchAll();
    };

    $data = [
        'exported_at' => gmdate('c'),
        'account' => ['email' => $user['email'], 'display_name' => $user['display_name']],
        'family_members' => $fetch(
            'SELECT id, first_name, last_name, relationship, is_active FROM family_members WHERE owner_user_id = :owner ORDER BY id'
        ),
        'medications' => $fetch(
            'SELECT m.id, m.family_member_id, m.name, m.dose_instructions, m.schedule_time, m.status
             FROM medications m INNER JOIN family_members f ON f.id = m.family_member_id
             WHERE f.owner_user_id = :owner ORDER BY m.id'
        ),
        'appointments' => $fetch(
            'SELECT a.id, a.family_member_id, a.category, a.title, a.provider_name, a.location,
                    a.visit_mode, a.starts_at, a.has_time, a.pre_notes, a.result_notes, a.status
             FROM appointments a INNER JOIN family_members f ON f.id = a.family_member_id
             WHERE f.owner_user_id = :owner ORDER BY a.id'
        ),
        'appointment_attachments' => $fetch(
            'SELECT aa.id, aa.appointment_id, aa.original_name, aa.mime_type, aa.size_bytes, aa.uploaded_at
             FROM appointment_attachments aa
             INNER JOIN appointments a ON a.id = aa.appointment_id
             INNER JOIN family_members f ON f.id = a.family_member_id
             WHERE f.owner_user_id = :owner ORDER BY aa.id'
        ),
        'profile_entries' => $fetch(
            'SELECT p.id, p.family_member_id, p.kind, p.title, p.detail, p.phone
             FROM profile_entries p INNER JOIN family_members f ON f.id = p.family_member_id
             WHERE f.owner_user_id = :owner ORDER BY p.id'
        ),
        'health_readings' => $fetch(
            'SELECT r.id, r.family_member_id, r.metric, r.value_primary, r.value_secondary, r.measured_at, r.note
             FROM health_readings r INNER JOIN family_members f ON f.id = r.family_member_id
             WHERE f.owner_user_id = :owner ORDER BY r.id'
        ),
        'documents' => $fetch(
            'SELECT d.id, d.family_member_id, d.title, d.category, d.original_name, d.mime_type, d.size_bytes, d.uploaded_at
             FROM documents d INNER JOIN family_members f ON f.id = d.family_member_id
             WHERE f.owner_user_id = :owner ORDER BY d.id'
        ),
        'dose_events' => $fetch(
            'SELECT d.medication_id, d.dose_date, d.outcome, d.recorded_at
             FROM dose_events d
             INNER JOIN medications m ON m.id = d.medication_id
             INNER JOIN family_members f ON f.id = m.family_member_id
             WHERE f.owner_user_id = :owner ORDER BY d.dose_date, d.medication_id'
        ),
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="meditrail-export-' . gmdate('Ymd') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $activeForm = is_string($action) ? $action : '';

        if (!validCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif ($action === 'export') {
            audit($ownerId, 'export', 'account', $ownerId);
            exportData($pdo, $ownerId, $currentUser);
        } elseif ($action === 'password' || $action === 'delete') {
            $current = $_POST['current_password'] ?? '';
            $current = is_string($current) ? $current : '';

            $hashQuery = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $hashQuery->execute(['id' => $ownerId]);
            $storedHash = (string) $hashQuery->fetchColumn();

            if (!password_verify($current, $storedHash)) {
                $errors[] = 'Your current password is incorrect.';
            } elseif ($action === 'password') {
                $new = $_POST['new_password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';
                $new = is_string($new) ? $new : '';
                if (mb_strlen($new) < SETTINGS_MIN_PASSWORD) {
                    $errors[] = 'New password must be at least ' . SETTINGS_MIN_PASSWORD . ' characters.';
                } elseif (strlen($new) > 72) {
                    $errors[] = 'New password must be 72 bytes or fewer.';
                } elseif ($new !== $confirm) {
                    $errors[] = 'The new passwords do not match.';
                } elseif (hash_equals($current, $new)) {
                    $errors[] = 'Choose a password different from your current one.';
                } else {
                    $pdo->prepare('UPDATE users SET password_hash = :hash, session_version = session_version + 1, failed_attempts = 0, locked_until = NULL WHERE id = :id')
                        ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $ownerId]);
                    signIn($ownerId);
                    audit($ownerId, 'password_change', 'user', $ownerId);
                    redirect('settings.php?status=password');
                }
            } elseif (($_POST['confirm_text'] ?? '') !== 'DELETE') {
                $errors[] = 'Type DELETE in capital letters to confirm.';
            } else {
                $storedQuery = $pdo->prepare(
                    'SELECT d.stored_name FROM documents d INNER JOIN family_members f ON f.id = d.family_member_id WHERE f.owner_user_id = :owner'
                );
                $storedQuery->execute(['owner' => $ownerId]);
                $storedFiles = $storedQuery->fetchAll(PDO::FETCH_COLUMN);

                $pdo->beginTransaction();
                $params = ['owner' => $ownerId];
                foreach ([
                    'DELETE d FROM dose_events d INNER JOIN medications m ON m.id = d.medication_id INNER JOIN family_members f ON f.id = m.family_member_id WHERE f.owner_user_id = :owner',
                    'DELETE x FROM documents x INNER JOIN family_members f ON f.id = x.family_member_id WHERE f.owner_user_id = :owner',
                    'DELETE p FROM profile_entries p INNER JOIN family_members f ON f.id = p.family_member_id WHERE f.owner_user_id = :owner',
                    'DELETE r FROM health_readings r INNER JOIN family_members f ON f.id = r.family_member_id WHERE f.owner_user_id = :owner',
                    'DELETE m FROM medications m INNER JOIN family_members f ON f.id = m.family_member_id WHERE f.owner_user_id = :owner',
                    'DELETE a FROM appointments a INNER JOIN family_members f ON f.id = a.family_member_id WHERE f.owner_user_id = :owner',
                    'DELETE FROM family_members WHERE owner_user_id = :owner',
                    'DELETE FROM audit_log WHERE user_id = :owner',
                    'DELETE FROM password_resets WHERE user_id = :owner',
                    'DELETE FROM users WHERE id = :owner',
                ] as $sql) {
                    $pdo->prepare($sql)->execute($params);
                }
                $pdo->commit();
                foreach ($storedFiles as $storedFile) {
                    deleteStoredDocument((string) $storedFile);
                }
                signOut();
                redirect('login.php?deleted=1');
            }
        } else {
            http_response_code(400);
            $errors[] = 'That action is not supported.';
        }
    }
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $pageError = 'Settings could not be saved because of a database problem.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Settings | MediTrail Pro</title>
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
            <a class="family-back-link" href="reminders.php"><i class="bi bi-bell"></i> Reminders</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> ACCOUNT</div>
                <h1>Settings &amp; <span>privacy</span></h1>
                <p class="welcome-subtitle">Signed in as <?= escape($currentUser['email']) ?>.</p>
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

        <div class="profile-grid">
            <section class="family-form-card" aria-labelledby="passwordTitle">
                <div class="family-section-heading">
                    <span class="heading-icon"><i class="bi bi-key"></i></span>
                    <div><h2 id="passwordTitle">Change password</h2><p>Use at least <?= SETTINGS_MIN_PASSWORD ?> characters.</p></div>
                </div>
                <form method="post" action="settings.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="password">
                    <div class="mb-3">
                        <label class="form-label" for="pw_current">Current password</label>
                        <input class="form-control" id="pw_current" name="current_password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="pw_new">New password</label>
                        <input class="form-control" id="pw_new" name="new_password" type="password" minlength="<?= SETTINGS_MIN_PASSWORD ?>" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="pw_confirm">Confirm new password</label>
                        <input class="form-control" id="pw_confirm" name="confirm_password" type="password" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary family-submit-button" type="submit"><i class="bi bi-check-lg"></i> Update password</button>
                </form>
            </section>

            <section class="family-form-card" aria-labelledby="exportTitle">
                <div class="family-section-heading">
                    <span class="heading-icon"><i class="bi bi-download"></i></span>
                    <div><h2 id="exportTitle">Download your data</h2><p>A JSON file of everything in your account.</p></div>
                </div>
                <p class="profile-empty">Includes family members, medications, appointments, medical profile entries, health readings and dose history. Uploaded files are not included, only their details; download each file from the Documents page. Your password is never included. Keep the file somewhere safe, since it contains health information.</p>
                <form method="post" action="settings.php">
                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="export">
                    <button class="btn btn-outline-secondary family-submit-button" type="submit"><i class="bi bi-file-earmark-arrow-down"></i> Download JSON</button>
                </form>
            </section>

            <section class="family-form-card danger-zone" aria-labelledby="deleteTitle">
                <div class="family-section-heading">
                    <span class="heading-icon"><i class="bi bi-exclamation-octagon"></i></span>
                    <div><h2 id="deleteTitle">Delete account</h2><p>Permanently removes your account and all its records.</p></div>
                </div>
                <form method="post" action="settings.php" data-confirm="Permanently delete your account and all data? This cannot be undone." novalidate>
                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <div class="mb-3">
                        <label class="form-label" for="del_password">Current password</label>
                        <input class="form-control" id="del_password" name="current_password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="del_confirm">Type DELETE to confirm</label>
                        <input class="form-control" id="del_confirm" name="confirm_text" type="text" autocomplete="off" required>
                    </div>
                    <button class="btn btn-danger family-submit-button" type="submit"><i class="bi bi-trash"></i> Delete my account</button>
                </form>
            </section>
        </div>
    </div>
</main>
</body>
</html>
