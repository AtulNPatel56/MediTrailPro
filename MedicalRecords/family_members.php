<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

function redirectToFamilyMembers(string $status): never
{
    redirect('family_members.php?status=' . rawurlencode($status));
}

$relationships = ['Self', 'Partner', 'Child', 'Parent', 'Sibling', 'Other'];
$form = [
    'first_name' => '',
    'last_name' => '',
    'relationship' => '',
];
$errors = [];
$pageError = null;
$successMessage = null;
$editingMember = null;
$familyMembers = [];
$activeCount = 0;
$pdo = null;

$csrfToken = csrfToken();

$statusMessages = [
    'created' => 'Family member added.',
    'updated' => 'Family member updated.',
    'archived' => 'Family member archived. Their associated records have been preserved.',
    'restored' => 'Family member restored.',
    'deleted' => 'Family member permanently deleted.',
];
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $successMessage = $statusMessages[$status];
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } else {
            $action = $_POST['action'] ?? '';
            if (!is_string($action)) {
                $errors[] = 'Choose a valid action and try again.';
            } elseif (in_array($action, ['create', 'update'], true)) {
                foreach (['first_name', 'last_name', 'relationship'] as $field) {
                    $value = $_POST[$field] ?? '';
                    $form[$field] = is_string($value) ? trim($value) : '';
                }

                if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 80) {
                    $errors[] = 'First name is required and must be 80 characters or fewer.';
                }
                if ($form['last_name'] === '' || mb_strlen($form['last_name']) > 80) {
                    $errors[] = 'Last name is required and must be 80 characters or fewer.';
                }
                if (!in_array($form['relationship'], $relationships, true)) {
                    $errors[] = 'Choose a relationship from the list.';
                }

                $memberId = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($action === 'update' && $memberId === false) {
                    $errors[] = 'The selected family member is invalid.';
                }

                if ($form['relationship'] === 'Self') {
                    $selfQuery = $pdo->prepare(
                        'SELECT id FROM family_members WHERE relationship = :relationship AND owner_user_id = :owner'
                        . ($action === 'update' ? ' AND id <> :id' : '')
                        . ' LIMIT 1'
                    );
                    $selfParameters = ['relationship' => 'Self', 'owner' => $ownerId];
                    if ($action === 'update') {
                        $selfParameters['id'] = $memberId;
                    }
                    $selfQuery->execute($selfParameters);
                    if ($selfQuery->fetchColumn() !== false) {
                        $errors[] = 'A self profile already exists. Edit that profile instead.';
                    }
                }

                if ($errors === []) {
                    if ($action === 'create') {
                        $statement = $pdo->prepare(
                            'INSERT INTO family_members (owner_user_id, first_name, last_name, relationship)
                             VALUES (:owner, :first_name, :last_name, :relationship)'
                        );
                        $statement->execute($form + ['owner' => $ownerId]);
                        audit($ownerId, 'create', 'family_member', (int) $pdo->lastInsertId());
                        redirectToFamilyMembers('created');
                    }

                    $exists = $pdo->prepare('SELECT id FROM family_members WHERE id = :id AND owner_user_id = :owner LIMIT 1');
                    $exists->execute(['id' => $memberId, 'owner' => $ownerId]);
                    if ($exists->fetchColumn() === false) {
                        http_response_code(404);
                        $errors[] = 'That family member could not be found.';
                    } else {
                        $statement = $pdo->prepare(
                            'UPDATE family_members
                             SET first_name = :first_name, last_name = :last_name, relationship = :relationship
                             WHERE id = :id AND owner_user_id = :owner'
                        );
                        $statement->execute([
                            'first_name' => $form['first_name'],
                            'last_name' => $form['last_name'],
                            'relationship' => $form['relationship'],
                            'id' => $memberId,
                            'owner' => $ownerId,
                        ]);
                        audit($ownerId, 'update', 'family_member', $memberId);
                        redirectToFamilyMembers('updated');
                    }
                }

                if ($action === 'update' && $memberId !== false) {
                    $editingMember = ['id' => $memberId, ...$form];
                }
            } elseif (in_array($action, ['archive', 'restore', 'delete'], true)) {
                $memberId = filter_var(
                    $_POST['id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($memberId === false) {
                    $errors[] = 'The selected family member is invalid.';
                } else {
                    if ($action === 'delete') {
                        $linkedRecords = $pdo->prepare(
                            'SELECT
                                EXISTS(SELECT 1 FROM medications WHERE family_member_id = :medication_member_id)
                                OR EXISTS(SELECT 1 FROM appointments WHERE family_member_id = :appointment_member_id)
                                OR EXISTS(SELECT 1 FROM profile_entries WHERE family_member_id = :profile_member_id)
                                OR EXISTS(SELECT 1 FROM health_readings WHERE family_member_id = :reading_member_id)
                                OR EXISTS(SELECT 1 FROM documents WHERE family_member_id = :document_member_id)'
                        );
                        $linkedRecords->execute([
                            'medication_member_id' => $memberId,
                            'appointment_member_id' => $memberId,
                            'profile_member_id' => $memberId,
                            'reading_member_id' => $memberId,
                            'document_member_id' => $memberId,
                        ]);
                        if ((bool) $linkedRecords->fetchColumn()) {
                            $errors[] = 'This profile has associated records and cannot be permanently deleted. Archive it instead.';
                        } else {
                            try {
                                $statement = $pdo->prepare('DELETE FROM family_members WHERE id = :id AND owner_user_id = :owner');
                                $statement->execute(['id' => $memberId, 'owner' => $ownerId]);
                            } catch (PDOException $exception) {
                                if ($exception->getCode() !== '23000') {
                                    throw $exception;
                                }
                                $errors[] = 'This profile has associated records and cannot be permanently deleted. Archive it instead.';
                            }

                            if ($errors === []) {
                                if ($statement->rowCount() === 0) {
                                    http_response_code(404);
                                    $errors[] = 'That family member could not be found.';
                                } else {
                                    audit($ownerId, 'delete', 'family_member', $memberId);
                                    redirectToFamilyMembers('deleted');
                                }
                            }
                        }
                    } else {
                        $statement = $pdo->prepare(
                            'UPDATE family_members SET is_active = :is_active WHERE id = :id AND owner_user_id = :owner'
                        );
                        $statement->execute([
                            'is_active' => $action === 'archive' ? 0 : 1,
                            'id' => $memberId,
                            'owner' => $ownerId,
                        ]);
                        if ($statement->rowCount() === 0) {
                            $exists = $pdo->prepare('SELECT id FROM family_members WHERE id = :id AND owner_user_id = :owner LIMIT 1');
                            $exists->execute(['id' => $memberId, 'owner' => $ownerId]);
                            if ($exists->fetchColumn() === false) {
                                http_response_code(404);
                                $errors[] = 'That family member could not be found.';
                            } else {
                                audit($ownerId, $action, 'family_member', $memberId);
                                redirectToFamilyMembers($action === 'archive' ? 'archived' : 'restored');
                            }
                        } else {
                            audit($ownerId, $action, 'family_member', $memberId);
                            redirectToFamilyMembers($action === 'archive' ? 'archived' : 'restored');
                        }
                    }
                }
            } else {
                $errors[] = 'Choose a valid action and try again.';
            }
        }
    } elseif (isset($_GET['edit'])) {
        $memberId = filter_var(
            $_GET['edit'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($memberId === false) {
            http_response_code(400);
            $errors[] = 'The selected family member is invalid.';
        } else {
            $statement = $pdo->prepare(
                'SELECT id, first_name, last_name, relationship
                 FROM family_members WHERE id = :id AND owner_user_id = :owner LIMIT 1'
            );
            $statement->execute(['id' => $memberId, 'owner' => $ownerId]);
            $editingMember = $statement->fetch() ?: null;
            if ($editingMember === null) {
                http_response_code(404);
                $errors[] = 'That family member could not be found.';
            } else {
                $form = [
                    'first_name' => $editingMember['first_name'],
                    'last_name' => $editingMember['last_name'],
                    'relationship' => $editingMember['relationship'],
                ];
            }
        }
    }

    $listQuery = $pdo->prepare(
        'SELECT id, first_name, last_name, relationship, is_active
         FROM family_members
         WHERE owner_user_id = :owner
         ORDER BY is_active DESC, first_name, last_name
         LIMIT 100'
    );
    $listQuery->execute(['owner' => $ownerId]);
    $familyMembers = $listQuery->fetchAll();
    $countQuery = $pdo->prepare('SELECT COUNT(*) FROM family_members WHERE is_active = 1 AND owner_user_id = :owner');
    $countQuery->execute(['owner' => $ownerId]);
    $activeCount = (int) $countQuery->fetchColumn();
} catch (PDOException $exception) {
    http_response_code(500);
    $pageError = 'The family member page could not access the database. Check the XAMPP MySQL service and database setup.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$isEditing = $editingMember !== null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Family Members | MediTrail Pro</title>
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
        <nav class="d-flex align-items-center gap-3">
            <a class="family-back-link" href="appointments.php"><i class="bi bi-calendar2-week"></i> Appointments</a>
            <a class="family-back-link" href="medications.php"><i class="bi bi-capsule"></i> Medications</a>
            <a class="family-back-link" href="medical_profile.php"><i class="bi bi-clipboard2-pulse"></i> Profile</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> YOUR HOUSEHOLD</div>
                <h1>Family <span>members</span></h1>
                <p class="welcome-subtitle">Manage the profiles that keep your family's health information organized.</p>
            </div>
            <span class="family-count"><i class="bi bi-people"></i> <?= escape($activeCount) ?> active</span>
        </section>

        <div class="alert alert-info family-demo-notice" role="note">
            <i class="bi bi-info-circle"></i>
            Your records are private to your account. Use fictional data only; archiving preserves linked records.
        </div>

        <?php if ($successMessage !== null): ?>
            <div class="alert alert-success" role="status" aria-live="polite"><?= escape($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert" aria-live="assertive">
                <strong>Please check the following:</strong>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="family-layout">
            <section class="family-form-card" aria-labelledby="familyFormTitle">
                <div class="family-section-heading">
                    <span class="heading-icon family-heading-icon"><i class="bi bi-person-plus"></i></span>
                    <div>
                        <h2 id="familyFormTitle"><?= $isEditing ? 'Edit family member' : 'Add a family member' ?></h2>
                        <p><?= $isEditing ? 'Update this profile’s information.' : 'Create a profile for your household.' ?></p>
                    </div>
                </div>

                <form method="post" action="family_members.php<?= $isEditing ? '?edit=' . escape($editingMember['id']) : '' ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="id" value="<?= escape($editingMember['id']) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label" for="first_name">First name <span class="required-mark">*</span></label>
                        <input class="form-control" id="first_name" name="first_name" type="text" maxlength="80" autocomplete="given-name" value="<?= escape($form['first_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="last_name">Last name <span class="required-mark">*</span></label>
                        <input class="form-control" id="last_name" name="last_name" type="text" maxlength="80" autocomplete="family-name" value="<?= escape($form['last_name']) ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="relationship">Relationship <span class="required-mark">*</span></label>
                        <select class="form-select" id="relationship" name="relationship" required>
                            <option value="">Choose a relationship</option>
                            <?php foreach ($relationships as $relationship): ?>
                                <option value="<?= escape($relationship) ?>" <?= $form['relationship'] === $relationship ? 'selected' : '' ?>><?= escape($relationship) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="family-form-actions">
                        <button class="btn btn-primary family-submit-button" type="submit">
                            <i class="bi bi-<?= $isEditing ? 'check-lg' : 'plus-lg' ?>"></i>
                            <?= $isEditing ? 'Save changes' : 'Add member' ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a class="btn btn-light family-cancel-button" href="family_members.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="family-list-card" aria-labelledby="familyListTitle">
                <div class="family-section-heading family-list-heading">
                    <span class="heading-icon family-list-icon"><i class="bi bi-people"></i></span>
                    <div>
                        <h2 id="familyListTitle">Your profiles</h2>
                        <p>Active and archived family members</p>
                    </div>
                </div>

                <?php if ($familyMembers === []): ?>
                    <div class="empty-state family-empty-state">
                        <span class="empty-icon"><i class="bi bi-people"></i></span>
                        <strong>No family profiles yet</strong>
                        <p>Add a family member using the form to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="family-member-list">
                        <?php foreach ($familyMembers as $member): ?>
                            <?php
                            $isActive = (bool) $member['is_active'];
                            $initial = mb_strtoupper(mb_substr($member['first_name'], 0, 1));
                            ?>
                            <article class="family-member-row<?= $isActive ? '' : ' is-archived' ?>">
                                <span class="family-member-avatar"><?= escape($initial) ?></span>
                                <div class="family-member-details">
                                    <strong><?= escape($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                                    <span><?= escape($member['relationship']) ?> · <?= $isActive ? 'Active' : 'Archived' ?></span>
                                </div>
                                <div class="family-member-actions">
                                    <a class="btn btn-sm btn-outline-secondary member-action-button" href="family_members.php?edit=<?= escape($member['id']) ?>" aria-label="Edit <?= escape($member['first_name'] . ' ' . $member['last_name']) ?>">
                                        <i class="bi bi-pencil"></i><span>Edit</span>
                                    </a>
                                    <form method="post" action="family_members.php" data-confirm="<?= $isActive ? 'Archive this family member? Their associated records will be preserved.' : 'Restore this family member?' ?>">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="<?= $isActive ? 'archive' : 'restore' ?>">
                                        <input type="hidden" name="id" value="<?= escape($member['id']) ?>">
                                        <button class="btn btn-sm <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?> member-action-button" type="submit">
                                            <i class="bi bi-<?= $isActive ? 'archive' : 'arrow-counterclockwise' ?>"></i>
                                            <span><?= $isActive ? 'Archive' : 'Restore' ?></span>
                                        </button>
                                    </form>
                                    <form method="post" action="family_members.php" data-confirm="Permanently delete this profile? This cannot be undone.">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= escape($member['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger member-action-button" type="submit" aria-label="Permanently delete <?= escape($member['first_name'] . ' ' . $member['last_name']) ?>">
                                            <i class="bi bi-trash"></i><span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($familyMembers) === 100): ?>
                        <p class="family-list-limit">Showing the first 100 profiles.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
</body>
</html>
