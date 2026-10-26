<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

function redirectToMedications(string $status): never
{
    redirect('medications.php?status=' . rawurlencode($status));
}

$statuses = ['Active', 'Paused', 'Completed'];
$form = [
    'family_member_id' => '',
    'name' => '',
    'dose_instructions' => '',
    'schedule_time' => '',
    'status' => 'Active',
    'supply_count' => '',
];
$errors = [];
$pageError = null;
$successMessage = null;
$editingMedication = null;
$medications = [];
$members = [];
$activeMemberIds = [];
$pdo = null;

$csrfToken = csrfToken();

$statusMessages = [
    'created' => 'Medication added.',
    'updated' => 'Medication updated.',
    'deleted' => 'Medication deleted.',
    'refilled' => 'Supply updated.',
];
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $successMessage = $statusMessages[$status];
}

try {
    $pdo = db();

    $memberQuery = $pdo->prepare(
        'SELECT id, first_name, last_name FROM family_members
         WHERE is_active = 1 AND owner_user_id = :owner ORDER BY first_name, last_name'
    );
    $memberQuery->execute(['owner' => $ownerId]);
    $members = $memberQuery->fetchAll();
    $activeMemberIds = array_map(static fn (array $m): int => (int) $m['id'], $members);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $action = $_POST['action'] ?? '';

        if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif (!is_string($action) || !in_array($action, ['create', 'update', 'delete', 'refill'], true)) {
            $errors[] = 'Choose a valid action and try again.';
        } else {
            $medicationId = positiveInt($_POST['id'] ?? null);

            if ($action === 'delete') {
                if ($medicationId === false) {
                    $errors[] = 'The selected medication is invalid.';
                } else {
                    $statement = $pdo->prepare(
                        'DELETE m FROM medications AS m
                         INNER JOIN family_members AS f ON f.id = m.family_member_id
                         WHERE m.id = :id AND f.owner_user_id = :owner'
                    );
                    $statement->execute(['id' => $medicationId, 'owner' => $ownerId]);
                    if ($statement->rowCount() === 0) {
                        http_response_code(404);
                        $errors[] = 'That medication could not be found.';
                    } else {
                        audit($ownerId, 'delete', 'medication', $medicationId);
                        redirectToMedications('deleted');
                    }
                }
            } elseif ($action === 'refill') {
                $added = positiveInt($_POST['add_doses'] ?? null);
                if ($medicationId === false || $added === false || $added > 9999) {
                    $errors[] = 'Enter a whole number of doses between 1 and 9999.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE medications AS m
                         INNER JOIN family_members AS f ON f.id = m.family_member_id
                         SET m.supply_count = LEAST(COALESCE(m.supply_count, 0) + :added, 99999)
                         WHERE m.id = :id AND f.owner_user_id = :owner'
                    );
                    $statement->execute(['added' => $added, 'id' => $medicationId, 'owner' => $ownerId]);
                    $exists = $pdo->prepare(
                        'SELECT COUNT(*) FROM medications m INNER JOIN family_members f ON f.id = m.family_member_id
                         WHERE m.id = :id AND f.owner_user_id = :owner'
                    );
                    $exists->execute(['id' => $medicationId, 'owner' => $ownerId]);
                    if ((int) $exists->fetchColumn() === 0) {
                        http_response_code(404);
                        $errors[] = 'That medication could not be found.';
                    } else {
                        audit($ownerId, 'refill', 'medication', $medicationId);
                        redirectToMedications('refilled');
                    }
                }
            } else {
                foreach (array_keys($form) as $field) {
                    $value = $_POST[$field] ?? '';
                    $form[$field] = is_string($value) ? trim($value) : '';
                }

                $memberId = positiveInt($form['family_member_id']);
                $scheduleTime = null;
                if ($form['schedule_time'] !== '') {
                    $parsed = DateTimeImmutable::createFromFormat('!H:i', $form['schedule_time']);
                    if ($parsed !== false && $parsed->format('H:i') === $form['schedule_time']) {
                        $scheduleTime = $parsed->format('H:i:s');
                    } else {
                        $errors[] = 'Enter a valid time or leave it blank.';
                    }
                }

                if ($action === 'update' && $medicationId === false) {
                    $errors[] = 'The selected medication is invalid.';
                }
                if ($memberId === false) {
                    $errors[] = 'Choose a family member.';
                } elseif (!in_array($memberId, $activeMemberIds, true)) {
                    // An existing record may keep its archived member
                    $current = false;
                    if ($action === 'update' && $medicationId !== false) {
                        $lookup = $pdo->prepare(
                            'SELECT m.family_member_id FROM medications AS m
                             INNER JOIN family_members AS f ON f.id = m.family_member_id
                             WHERE m.id = :id AND f.owner_user_id = :owner'
                        );
                        $lookup->execute(['id' => $medicationId, 'owner' => $ownerId]);
                        $current = $lookup->fetchColumn();
                    }
                    if ($current === false || (int) $current !== $memberId) {
                        $errors[] = 'Choose an active family member.';
                    }
                }
                if ($form['name'] === '' || mb_strlen($form['name']) > 120) {
                    $errors[] = 'Medication name is required and must be 120 characters or fewer.';
                }
                if ($form['dose_instructions'] === '' || mb_strlen($form['dose_instructions']) > 180) {
                    $errors[] = 'Instructions are required and must be 180 characters or fewer.';
                }
                if (!in_array($form['status'], $statuses, true)) {
                    $errors[] = 'Choose a valid status.';
                }
                $supplyCount = null;
                if ($form['supply_count'] !== '') {
                    if (preg_match('/^\d{1,5}$/', $form['supply_count']) === 1) {
                        $supplyCount = (int) $form['supply_count'];
                    } else {
                        $errors[] = 'Doses on hand must be a whole number (0 to 99999) or left blank.';
                    }
                }

                if ($errors === []) {
                    $values = [
                        'family_member_id' => $memberId,
                        'name' => $form['name'],
                        'dose_instructions' => $form['dose_instructions'],
                        'schedule_time' => $scheduleTime,
                        'status' => $form['status'],
                        'supply_count' => $supplyCount,
                    ];

                    if ($action === 'create') {
                        $statement = $pdo->prepare(
                            'INSERT INTO medications (family_member_id, name, dose_instructions, schedule_time, status, supply_count)
                             VALUES (:family_member_id, :name, :dose_instructions, :schedule_time, :status, :supply_count)'
                        );
                        $statement->execute($values);
                        audit($ownerId, 'create', 'medication', (int) $pdo->lastInsertId());
                        redirectToMedications('created');
                    }

                    $exists = $pdo->prepare(
                        'SELECT m.id FROM medications AS m
                         INNER JOIN family_members AS f ON f.id = m.family_member_id
                         WHERE m.id = :id AND f.owner_user_id = :owner LIMIT 1'
                    );
                    $exists->execute(['id' => $medicationId, 'owner' => $ownerId]);
                    if ($exists->fetchColumn() === false) {
                        http_response_code(404);
                        $errors[] = 'That medication could not be found.';
                    } else {
                        $statement = $pdo->prepare(
                            'UPDATE medications
                             SET family_member_id = :family_member_id, name = :name,
                                 dose_instructions = :dose_instructions,
                                 schedule_time = :schedule_time, status = :status, supply_count = :supply_count
                             WHERE id = :id'
                        );
                        $statement->execute($values + ['id' => $medicationId]);
                        audit($ownerId, 'update', 'medication', $medicationId);
                        redirectToMedications('updated');
                    }
                }

                if ($action === 'update' && $medicationId !== false) {
                    $editingMedication = ['id' => $medicationId];
                }
            }
        }
    } elseif (isset($_GET['edit'])) {
        $medicationId = positiveInt($_GET['edit']);
        if ($medicationId === false) {
            http_response_code(400);
            $errors[] = 'The selected medication is invalid.';
        } else {
            $statement = $pdo->prepare(
                'SELECT m.id, m.family_member_id, m.name, m.dose_instructions, m.schedule_time, m.status, m.supply_count
                 FROM medications AS m
                 INNER JOIN family_members AS f ON f.id = m.family_member_id
                 WHERE m.id = :id AND f.owner_user_id = :owner LIMIT 1'
            );
            $statement->execute(['id' => $medicationId, 'owner' => $ownerId]);
            $editingMedication = $statement->fetch() ?: null;
            if ($editingMedication === null) {
                http_response_code(404);
                $errors[] = 'That medication could not be found.';
            } else {
                $form = [
                    'family_member_id' => (string) $editingMedication['family_member_id'],
                    'name' => $editingMedication['name'],
                    'dose_instructions' => $editingMedication['dose_instructions'],
                    'schedule_time' => $editingMedication['schedule_time'] === null
                        ? ''
                        : substr($editingMedication['schedule_time'], 0, 5),
                    'status' => $editingMedication['status'],
                    'supply_count' => $editingMedication['supply_count'] === null ? '' : (string) $editingMedication['supply_count'],
                ];
            }
        }
    }

    // Keep an archived member selectable while editing their record
    if ($editingMedication !== null && $form['family_member_id'] !== '' && !in_array((int) $form['family_member_id'], $activeMemberIds, true)) {
        $archived = $pdo->prepare('SELECT id, first_name, last_name         FROM family_members WHERE id = :id AND owner_user_id = :owner');
                $archived->execute(['id' => (int) $form['family_member_id'], 'owner' => $ownerId]);
        if ($row = $archived->fetch()) {
            $row['first_name'] .= ' (archived)';
            $members[] = $row;
        }
    }

    $listQuery = $pdo->prepare(
        'SELECT m.id, m.name, m.dose_instructions, m.schedule_time, m.status, m.supply_count, f.first_name, f.last_name
         FROM medications AS m
         INNER JOIN family_members AS f ON f.id = m.family_member_id
         WHERE f.owner_user_id = :owner
         ORDER BY FIELD(m.status, \'Active\', \'Paused\', \'Completed\'), m.schedule_time, m.name
         LIMIT 100'
    );
    $listQuery->execute(['owner' => $ownerId]);
    $medications = $listQuery->fetchAll();
} catch (PDOException $exception) {
    http_response_code(500);
    $pageError = 'The medications page could not access the database. Check the XAMPP MySQL service and database setup.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$isEditing = $editingMedication !== null && isset($editingMedication['id']);
$badgeClasses = ['Active' => 'text-bg-success', 'Paused' => 'text-bg-warning', 'Completed' => 'text-bg-secondary'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Medications | MediTrail Pro</title>
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
            <a class="family-back-link" href="family_members.php"><i class="bi bi-people"></i> Family members</a>
            <a class="family-back-link" href="appointments.php"><i class="bi bi-calendar2-week"></i> Appointments</a>
            <a class="family-back-link" href="medical_profile.php"><i class="bi bi-clipboard2-pulse"></i> Profile</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> YOUR HOUSEHOLD</div>
                <h1>Medi<span>cations</span></h1>
                <p class="welcome-subtitle">Record the medications each family member takes.</p>
            </div>
            <span class="family-count"><i class="bi bi-capsule"></i> <?= escape(count($medications)) ?> total</span>
        </section>

        <div class="alert alert-info family-demo-notice" role="note">
            <i class="bi bi-info-circle"></i>
            Enter instructions exactly as given by a clinician or pharmacist. This app does not provide medical advice.
            Your records are private to your account. Use fictional data only.
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
            <section class="family-form-card" aria-labelledby="medicationFormTitle">
                <div class="family-section-heading">
                    <span class="heading-icon medication-heading-icon"><i class="bi bi-capsule"></i></span>
                    <div>
                        <h2 id="medicationFormTitle"><?= $isEditing ? 'Edit medication' : 'Add a medication' ?></h2>
                        <p><?= $isEditing ? 'Update the medication details.' : 'Add a medication for a family member.' ?></p>
                    </div>
                </div>

                <?php if ($members === []): ?>
                    <div class="alert alert-warning" role="alert">
                        Add an active <a href="family_members.php">family member</a> before adding medications.
                    </div>
                <?php endif; ?>

                <form method="post" action="medications.php<?= $isEditing ? '?edit=' . escape($editingMedication['id']) : '' ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="id" value="<?= escape($editingMedication['id']) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label" for="family_member_id">Family member <span class="required-mark">*</span></label>
                        <select class="form-select" id="family_member_id" name="family_member_id" required>
                            <option value="">Choose a family member</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?= escape($member['id']) ?>" <?= $form['family_member_id'] === (string) $member['id'] ? 'selected' : '' ?>><?= escape($member['first_name'] . ' ' . $member['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="name">Medication name <span class="required-mark">*</span></label>
                        <input class="form-control" id="name" name="name" type="text" maxlength="120" value="<?= escape($form['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="dose_instructions">Instructions <span class="required-mark">*</span></label>
                        <input class="form-control" id="dose_instructions" name="dose_instructions" type="text" maxlength="180" aria-describedby="doseHelp" value="<?= escape($form['dose_instructions']) ?>" required>
                        <div class="form-text" id="doseHelp">As prescribed by the clinician or pharmacist.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="schedule_time">Time of day</label>
                        <input class="form-control" id="schedule_time" name="schedule_time" type="time" value="<?= escape($form['schedule_time']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="supply_count">Doses on hand</label>
                        <input class="form-control" id="supply_count" name="supply_count" type="text" inputmode="numeric" maxlength="5" aria-describedby="supplyHelp" value="<?= escape($form['supply_count']) ?>">
                        <div class="form-text" id="supplyHelp">Optional. Counts down each time you mark a dose as taken, and warns when 7 or fewer remain.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="status">Status <span class="required-mark">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <?php foreach ($statuses as $option): ?>
                                <option value="<?= escape($option) ?>" <?= $form['status'] === $option ? 'selected' : '' ?>><?= escape($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="family-form-actions">
                        <button class="btn btn-primary family-submit-button" type="submit" <?= $members === [] ? 'disabled' : '' ?>>
                            <i class="bi bi-<?= $isEditing ? 'check-lg' : 'plus-lg' ?>"></i>
                            <?= $isEditing ? 'Save changes' : 'Add medication' ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a class="btn btn-light family-cancel-button" href="medications.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="family-list-card" aria-labelledby="medicationListTitle">
                <div class="family-section-heading family-list-heading">
                    <span class="heading-icon family-list-icon"><i class="bi bi-capsule"></i></span>
                    <div>
                        <h2 id="medicationListTitle">All medications</h2>
                        <p>Active medications first</p>
                    </div>
                </div>

                <?php if ($medications === []): ?>
                    <div class="empty-state family-empty-state">
                        <span class="empty-icon"><i class="bi bi-capsule"></i></span>
                        <strong>No medications yet</strong>
                        <p>Add a medication using the form to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="family-member-list">
                        <?php foreach ($medications as $medication): ?>
                            <?php
                            $fullName = $medication['first_name'] . ' ' . $medication['last_name'];
                            $time = $medication['schedule_time'] === null
                                ? 'No time set'
                                : (new DateTimeImmutable($medication['schedule_time']))->format('g:i A');
                            ?>
                            <article class="family-member-row<?= $medication['status'] === 'Completed' ? ' is-archived' : '' ?>">
                                <span class="record-icon"><i class="bi bi-capsule"></i></span>
                                <div class="family-member-details">
                                    <strong><?= escape($medication['name']) ?></strong>
                                    <span><?= escape($fullName) ?> · <?= escape($medication['dose_instructions']) ?></span>
                                    <span><i class="bi bi-clock"></i> <?= escape($time) ?>
                                        <span class="badge <?= escape($badgeClasses[$medication['status']] ?? 'text-bg-light') ?> ms-1"><?= escape($medication['status']) ?></span>
                                    </span>
                                    <?php if ($medication['supply_count'] !== null): ?>
                                        <?php $left = (int) $medication['supply_count']; ?>
                                        <span><i class="bi bi-box-seam"></i> <?= escape($left) ?> dose<?= $left === 1 ? '' : 's' ?> on hand
                                            <?php if ($medication['status'] === 'Active' && $left === 0): ?><span class="due-badge due-badge-today">Out of supply</span>
                                            <?php elseif ($medication['status'] === 'Active' && $left <= 7): ?><span class="due-badge">Running low</span><?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    <form class="refill-form" method="post" action="medications.php">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="refill">
                                        <input type="hidden" name="id" value="<?= escape($medication['id']) ?>">
                                        <label class="visually-hidden" for="add_doses_<?= escape($medication['id']) ?>">Doses to add for <?= escape($medication['name']) ?></label>
                                        <input class="form-control form-control-sm" id="add_doses_<?= escape($medication['id']) ?>" name="add_doses" type="text" inputmode="numeric" maxlength="4" placeholder="30" required>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-plus-lg"></i> Refill</button>
                                    </form>
                                </div>
                                <div class="family-member-actions">
                                    <a class="btn btn-sm btn-outline-secondary member-action-button" href="medications.php?edit=<?= escape($medication['id']) ?>" aria-label="Edit <?= escape($medication['name']) ?>">
                                        <i class="bi bi-pencil"></i><span>Edit</span>
                                    </a>
                                    <form method="post" action="medications.php" data-confirm="Delete this medication? This cannot be undone.">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= escape($medication['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger member-action-button" type="submit" aria-label="Delete <?= escape($medication['name']) ?>">
                                            <i class="bi bi-trash"></i><span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($medications) === 100): ?>
                        <p class="family-list-limit">Showing the first 100 medications.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
</body>
</html>
