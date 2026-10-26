<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/documents.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

function redirectToAppointments(string $status): never
{
    redirect('appointments.php?status=' . rawurlencode($status));
}

$statuses = ['Scheduled', 'Completed', 'Cancelled'];
$categories = ['Other', 'Family Dr', 'Dentist', 'Specialist', 'Optometrist', 'Lab Test', 'X-Ray / Imaging', 'Hospital Visit', 'Vaccination', 'Surgery', 'Physiotherapy'];
$visitModes = ['In Person', 'Virtual', 'Other'];
$form = [
    'family_member_id' => '',
    'category' => 'Specialist',
    'title' => '',
    'provider_name' => '',
    'location' => '',
    'visit_mode' => 'In Person',
    'starts_date' => '',
    'starts_time' => '',
    'pre_notes' => '',
    'result_notes' => '',
    'status' => 'Scheduled',
];
$errors = [];
$pageError = null;
$successMessage = null;
$editingAppointment = null;
$appointments = [];
$attachments = [];
$members = [];
$pdo = null;

$csrfToken = csrfToken();

$statusMessages = [
    'created' => 'Appointment added.',
    'updated' => 'Appointment updated.',
    'deleted' => 'Appointment deleted.',
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        $errors[] = 'The submitted form is too large. Upload no more than five files of 5 MB each.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $action = $_POST['action'] ?? '';

        if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif (!is_string($action) || !in_array($action, ['create', 'update', 'delete'], true)) {
            $errors[] = 'Choose a valid action and try again.';
        } else {
            $appointmentId = positiveInt($_POST['id'] ?? null);

            if ($action === 'delete') {
                if ($appointmentId === false) {
                    $errors[] = 'The selected appointment is invalid.';
                } else {
                    $attachmentQuery = $pdo->prepare(
                        'SELECT aa.stored_name FROM appointment_attachments AS aa
                         INNER JOIN appointments AS a ON a.id = aa.appointment_id
                         INNER JOIN family_members AS f ON f.id = a.family_member_id
                         WHERE a.id = :id AND f.owner_user_id = :owner'
                    );
                    $attachmentQuery->execute(['id' => $appointmentId, 'owner' => $ownerId]);
                    $storedAttachments = $attachmentQuery->fetchAll(PDO::FETCH_COLUMN);
                    $statement = $pdo->prepare(
                        'DELETE a FROM appointments AS a
                         INNER JOIN family_members AS f ON f.id = a.family_member_id
                         WHERE a.id = :id AND f.owner_user_id = :owner'
                    );
                    $statement->execute(['id' => $appointmentId, 'owner' => $ownerId]);
                    if ($statement->rowCount() === 0) {
                        http_response_code(404);
                        $errors[] = 'That appointment could not be found.';
                    } else {
                        foreach ($storedAttachments as $storedName) {
                            deleteStoredDocument((string) $storedName);
                        }
                        audit($ownerId, 'delete', 'appointment', $appointmentId);
                        redirectToAppointments('deleted');
                    }
                }
            } else {
                foreach (array_keys($form) as $field) {
                    $value = $_POST[$field] ?? '';
                    $form[$field] = is_string($value) ? trim($value) : '';
                }

                $memberId = positiveInt($form['family_member_id']);
                $appointmentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['starts_date']);
                $dateValid = $appointmentDate !== false && $appointmentDate->format('Y-m-d') === $form['starts_date'];
                $appointmentTime = $form['starts_time'] === ''
                    ? null
                    : DateTimeImmutable::createFromFormat('!H:i', $form['starts_time']);
                $timeValid = $form['starts_time'] === '' || (
                    $appointmentTime !== false && $appointmentTime->format('H:i') === $form['starts_time']
                );

                if ($action === 'update' && $appointmentId === false) {
                    $errors[] = 'The selected appointment is invalid.';
                }
                if ($memberId === false) {
                    $errors[] = 'Choose a family member.';
                } elseif (!in_array($memberId, $activeMemberIds, true)) {
                    // Allow keeping an archived member on an existing appointment
                    $current = null;
                    if ($action === 'update' && $appointmentId !== false) {
                        $lookup = $pdo->prepare(
                            'SELECT a.family_member_id FROM appointments AS a
                             INNER JOIN family_members AS f ON f.id = a.family_member_id
                             WHERE a.id = :id AND f.owner_user_id = :owner'
                        );
                        $lookup->execute(['id' => $appointmentId, 'owner' => $ownerId]);
                        $current = $lookup->fetchColumn();
                    }
                    if ($current === false || $current === null || (int) $current !== $memberId) {
                        $errors[] = 'Choose an active family member.';
                    }
                }
                if ($form['title'] === '' || mb_strlen($form['title']) > 140) {
                    $errors[] = 'Appointment title is required and must be 140 characters or fewer.';
                }
                if (!in_array($form['category'], $categories, true)) {
                    $errors[] = 'Choose a valid appointment category.';
                }
                if (mb_strlen($form['provider_name']) > 120) {
                    $errors[] = 'Provider must be 120 characters or fewer.';
                }
                if (mb_strlen($form['location']) > 160) {
                    $errors[] = 'Location must be 160 characters or fewer.';
                }
                if (!in_array($form['visit_mode'], $visitModes, true)) {
                    $errors[] = 'Choose a valid visit mode.';
                }
                if (!$dateValid || !$timeValid) {
                    $errors[] = 'Enter a valid appointment date and optional time.';
                }
                if (mb_strlen($form['pre_notes']) > 5000 || mb_strlen($form['result_notes']) > 5000) {
                    $errors[] = 'Appointment notes must be 5,000 characters or fewer.';
                }
                if (!in_array($form['status'], $statuses, true)) {
                    $errors[] = 'Choose a valid status.';
                }

                $uploadFiles = [];
                $fileInput = $_FILES['attachments'] ?? null;
                if ($fileInput !== null) {
                    if (!is_array($fileInput) || !isset($fileInput['name'], $fileInput['error'], $fileInput['tmp_name'], $fileInput['size']) || !is_array($fileInput['name'])) {
                        $errors[] = 'The selected attachment could not be processed.';
                    } else {
                        $fileCount = count($fileInput['name']);
                        if ($fileCount > 5) {
                            $errors[] = 'Upload no more than five attachments at a time.';
                        } else {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            for ($index = 0; $index < $fileCount; $index++) {
                                $uploadError = $fileInput['error'][$index] ?? UPLOAD_ERR_NO_FILE;
                                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                                    continue;
                                }
                                if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                                    $errors[] = 'Each attachment must be 5 MB or smaller.';
                                    continue;
                                }
                                $tmpName = $fileInput['tmp_name'][$index] ?? '';
                                $fileSize = $fileInput['size'][$index] ?? 0;
                                if ($uploadError !== UPLOAD_ERR_OK || !is_string($tmpName) || !is_uploaded_file($tmpName)) {
                                    $errors[] = 'An attachment upload failed. Please try again.';
                                    continue;
                                }
                                if (!is_int($fileSize) || $fileSize < 1 || $fileSize > DOCUMENT_MAX_BYTES) {
                                    $errors[] = 'Each attachment must be non-empty and 5 MB or smaller.';
                                    continue;
                                }
                                $mimeType = $finfo->file($tmpName);
                                if (!is_string($mimeType) || !isset(DOCUMENT_TYPES[$mimeType])) {
                                    $errors[] = 'Attachments must be PDF, JPG or PNG files.';
                                    continue;
                                }
                                $originalName = basename(str_replace('\\', '/', (string) ($fileInput['name'][$index] ?? 'attachment')));
                                $originalName = preg_replace('/[^\p{L}\p{N}._\- ]+/u', '_', $originalName) ?? '';
                                $uploadFiles[] = [
                                    'tmp_name' => $tmpName,
                                    'original_name' => mb_substr(trim($originalName, " .\t") ?: 'attachment', 0, 190),
                                    'mime_type' => $mimeType,
                                    'size_bytes' => $fileSize,
                                ];
                            }
                        }
                    }
                }

                if ($errors === []) {
                    $startsAt = $form['starts_date'] . ' ' . ($form['starts_time'] !== '' ? $form['starts_time'] : '00:00') . ':00';
                    $values = [
                        'family_member_id' => $memberId,
                        'category' => $form['category'],
                        'title' => $form['title'],
                        'provider_name' => $form['provider_name'],
                        'location' => $form['location'],
                        'visit_mode' => $form['visit_mode'],
                        'starts_at' => $startsAt,
                        'has_time' => $form['starts_time'] !== '' ? 1 : 0,
                        'pre_notes' => $form['pre_notes'],
                        'result_notes' => $form['result_notes'],
                        'status' => $form['status'],
                    ];

                    if ($action === 'update') {
                        $exists = $pdo->prepare(
                            'SELECT a.id FROM appointments AS a
                             INNER JOIN family_members AS f ON f.id = a.family_member_id
                             WHERE a.id = :id AND f.owner_user_id = :owner LIMIT 1'
                        );
                        $exists->execute(['id' => $appointmentId, 'owner' => $ownerId]);
                        if ($exists->fetchColumn() === false) {
                            http_response_code(404);
                            $errors[] = 'That appointment could not be found.';
                        }
                    }

                    if ($errors === []) {
                        $storedNames = [];
                        try {
                            if ($uploadFiles !== [] && !is_dir(DOCUMENT_STORAGE_DIR) && !mkdir(DOCUMENT_STORAGE_DIR, 0775, true) && !is_dir(DOCUMENT_STORAGE_DIR)) {
                                throw new RuntimeException('Attachment storage folder could not be created.');
                            }
                            $pdo->beginTransaction();
                            if ($action === 'create') {
                                $statement = $pdo->prepare(
                                    'INSERT INTO appointments
                                        (family_member_id, category, title, provider_name, location, visit_mode, starts_at, has_time, pre_notes, result_notes, status)
                                     VALUES
                                        (:family_member_id, :category, :title, :provider_name, :location, :visit_mode, :starts_at, :has_time, :pre_notes, :result_notes, :status)'
                                );
                                $statement->execute($values);
                                $targetAppointmentId = (int) $pdo->lastInsertId();
                            } else {
                                $statement = $pdo->prepare(
                                    'UPDATE appointments
                                     SET family_member_id = :family_member_id, category = :category, title = :title,
                                         provider_name = :provider_name, location = :location, visit_mode = :visit_mode,
                                         starts_at = :starts_at, has_time = :has_time, pre_notes = :pre_notes,
                                         result_notes = :result_notes, status = :status
                                     WHERE id = :id'
                                );
                                $statement->execute($values + ['id' => $appointmentId]);
                                $targetAppointmentId = $appointmentId;
                            }

                            foreach ($uploadFiles as $upload) {
                                $storedName = bin2hex(random_bytes(16)) . '.' . DOCUMENT_TYPES[$upload['mime_type']];
                                if (!move_uploaded_file($upload['tmp_name'], documentPath($storedName))) {
                                    throw new RuntimeException('An attachment could not be saved.');
                                }
                                $storedNames[] = $storedName;
                                $pdo->prepare(
                                    'INSERT INTO appointment_attachments
                                        (appointment_id, original_name, stored_name, mime_type, size_bytes)
                                     VALUES (:appointment, :original, :stored, :mime, :size)'
                                )->execute([
                                    'appointment' => $targetAppointmentId,
                                    'original' => $upload['original_name'],
                                    'stored' => $storedName,
                                    'mime' => $upload['mime_type'],
                                    'size' => $upload['size_bytes'],
                                ]);
                            }
                            $pdo->commit();
                        } catch (RuntimeException $exception) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            foreach ($storedNames as $storedName) {
                                deleteStoredDocument($storedName);
                            }
                            $errors[] = 'An attachment could not be saved. Please try again.';
                        } catch (PDOException $exception) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            foreach ($storedNames as $storedName) {
                                deleteStoredDocument($storedName);
                            }
                            throw $exception;
                        }

                        if ($errors === []) {
                            audit($ownerId, $action === 'create' ? 'create' : 'update', 'appointment', $targetAppointmentId);
                            redirectToAppointments($action === 'create' ? 'created' : 'updated');
                        }
                    }
                }

                if ($action === 'update' && $appointmentId !== false) {
                    $editingAppointment = ['id' => $appointmentId];
                }
            }
        }
    } elseif (isset($_GET['edit'])) {
        $appointmentId = positiveInt($_GET['edit']);
        if ($appointmentId === false) {
            http_response_code(400);
            $errors[] = 'The selected appointment is invalid.';
        } else {
            $statement = $pdo->prepare(
                'SELECT a.id, a.family_member_id, a.category, a.title, a.provider_name,
                        a.location, a.visit_mode, a.starts_at, a.has_time, a.pre_notes,
                        a.result_notes, a.status
                 FROM appointments AS a
                 INNER JOIN family_members AS f ON f.id = a.family_member_id
                 WHERE a.id = :id AND f.owner_user_id = :owner LIMIT 1'
            );
            $statement->execute(['id' => $appointmentId, 'owner' => $ownerId]);
            $editingAppointment = $statement->fetch() ?: null;
            if ($editingAppointment === null) {
                http_response_code(404);
                $errors[] = 'That appointment could not be found.';
            } else {
                $form = [
                    'family_member_id' => (string) $editingAppointment['family_member_id'],
                    'category' => $editingAppointment['category'],
                    'title' => $editingAppointment['title'],
                    'provider_name' => $editingAppointment['provider_name'],
                    'location' => $editingAppointment['location'],
                    'visit_mode' => $editingAppointment['visit_mode'],
                    'starts_date' => (new DateTimeImmutable($editingAppointment['starts_at']))->format('Y-m-d'),
                    'starts_time' => (int) $editingAppointment['has_time'] === 1
                        ? (new DateTimeImmutable($editingAppointment['starts_at']))->format('H:i')
                        : '',
                    'pre_notes' => $editingAppointment['pre_notes'] ?? '',
                    'result_notes' => $editingAppointment['result_notes'] ?? '',
                    'status' => $editingAppointment['status'],
                ];
            }
        }
    }

    // Archived member being edited stays selectable on the form
    if ($editingAppointment !== null && $form['family_member_id'] !== '' && !in_array((int) $form['family_member_id'], $activeMemberIds, true)) {
        $archived = $pdo->prepare('SELECT id, first_name, last_name FROM family_members WHERE id = :id AND owner_user_id = :owner');
        $archived->execute(['id' => (int) $form['family_member_id'], 'owner' => $ownerId]);
        if ($row = $archived->fetch()) {
            $row['first_name'] .= ' (archived)';
            $members[] = $row;
        }
    }

    if ($editingAppointment !== null && isset($editingAppointment['id'])) {
        $attachmentQuery = $pdo->prepare(
            'SELECT id, original_name, mime_type, size_bytes FROM appointment_attachments
             WHERE appointment_id = :appointment ORDER BY uploaded_at, id'
        );
        $attachmentQuery->execute(['appointment' => (int) $editingAppointment['id']]);
        $attachments = $attachmentQuery->fetchAll();
    }

    $listQuery = $pdo->prepare(
        'SELECT a.id, a.category, a.title, a.provider_name, a.location, a.starts_at, a.has_time,
                a.status, f.first_name, f.last_name
         FROM appointments AS a
         INNER JOIN family_members AS f ON f.id = a.family_member_id
         WHERE f.owner_user_id = :owner
         ORDER BY a.starts_at DESC
         LIMIT 100'
    );
    $listQuery->execute(['owner' => $ownerId]);
    $appointments = $listQuery->fetchAll();
} catch (PDOException $exception) {
    http_response_code(500);
    $pageError = 'The appointments page could not access the database. Check the XAMPP MySQL service and database setup.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$isEditing = $editingAppointment !== null && isset($editingAppointment['id']);
$badgeClasses = ['Scheduled' => 'text-bg-info', 'Completed' => 'text-bg-success', 'Cancelled' => 'text-bg-secondary'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Appointments | MediTrail Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
    <script src="assets/js/confirm.js" defer></script>
    <script src="assets/js/appointment-files.js" defer></script>
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
            <a class="family-back-link" href="calendar.php"><i class="bi bi-calendar3"></i> Calendar</a>
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
                <h1>Appoint<span>ments</span></h1>
                <p class="welcome-subtitle">Keep track of visits for each family member.</p>
            </div>
            <span class="family-count"><i class="bi bi-calendar2-week"></i> <?= escape(count($appointments)) ?> total</span>
        </section>

        <div class="alert alert-info family-demo-notice" role="note">
            <i class="bi bi-info-circle"></i>
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
            <section class="family-form-card" aria-labelledby="appointmentFormTitle">
                <div class="family-section-heading">
                    <span class="heading-icon appointment-heading-icon"><i class="bi bi-calendar-plus"></i></span>
                    <div>
                        <h2 id="appointmentFormTitle"><?= $isEditing ? 'Edit appointment' : 'Add an appointment' ?></h2>
                        <p><?= $isEditing ? 'Update the appointment details.' : 'Schedule a visit for a family member.' ?></p>
                    </div>
                </div>

                <?php if ($members === []): ?>
                    <div class="alert alert-warning" role="alert">
                        Add an active <a href="family_members.php">family member</a> before scheduling appointments.
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" action="appointments.php<?= $isEditing ? '?edit=' . escape($editingAppointment['id']) : '' ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= DOCUMENT_MAX_BYTES ?>">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="id" value="<?= escape($editingAppointment['id']) ?>">
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

                    <fieldset class="appointment-fieldset mb-3">
                        <legend class="form-label">Event type</legend>
                        <div class="appointment-choice-list">
                            <?php foreach ($categories as $category): ?>
                                <label class="appointment-choice">
                                    <input type="radio" name="category" value="<?= escape($category) ?>" <?= $form['category'] === $category ? 'checked' : '' ?>>
                                    <span><?= escape($category) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="mb-3">
                        <label class="form-label" for="title">Appointment title <span class="required-mark">*</span></label>
                        <input class="form-control" id="title" name="title" type="text" maxlength="140" value="<?= escape($form['title']) ?>" required>
                    </div>

                    <fieldset class="appointment-fieldset mb-3">
                        <legend class="form-label">Visit type</legend>
                        <div class="appointment-choice-list appointment-mode-list">
                            <?php foreach ($visitModes as $mode): ?>
                                <label class="appointment-choice">
                                    <input type="radio" name="visit_mode" value="<?= escape($mode) ?>" <?= $form['visit_mode'] === $mode ? 'checked' : '' ?>>
                                    <span><?= escape($mode) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="mb-3">
                        <label class="form-label" for="starts_date">Date <span class="required-mark">*</span></label>
                        <input class="form-control" id="starts_date" name="starts_date" type="date" value="<?= escape($form['starts_date']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="starts_time">Time <span class="text-muted">(optional)</span></label>
                        <input class="form-control" id="starts_time" name="starts_time" type="time" value="<?= escape($form['starts_time']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="provider_name">Provider / doctor</label>
                        <input class="form-control" id="provider_name" name="provider_name" type="text" maxlength="120" value="<?= escape($form['provider_name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="location">Clinic / location</label>
                        <input class="form-control" id="location" name="location" type="text" maxlength="160" value="<?= escape($form['location']) ?>">
                    </div>

                    <div class="appointment-notes-card mb-3">
                        <label class="form-label" for="pre_notes">Pre-appointment notes</label>
                        <textarea class="form-control" id="pre_notes" name="pre_notes" maxlength="5000" rows="3" placeholder="Questions or information to remember"><?= escape($form['pre_notes']) ?></textarea>
                        <div class="appointment-upload-actions" aria-label="Attach a photo or file">
                            <label class="appointment-upload-button">
                                <i class="bi bi-camera"></i> Camera
                                <input type="file" name="attachments[]" accept="image/jpeg,image/png" capture="environment" multiple>
                                <span class="appointment-file-selection" aria-live="polite"></span>
                            </label>
                            <label class="appointment-upload-button">
                                <i class="bi bi-images"></i> Photos
                                <input type="file" name="attachments[]" accept="image/jpeg,image/png" multiple>
                                <span class="appointment-file-selection" aria-live="polite"></span>
                            </label>
                            <label class="appointment-upload-button">
                                <i class="bi bi-file-earmark-arrow-up"></i> Files
                                <input type="file" name="attachments[]" accept="application/pdf,image/jpeg,image/png" multiple>
                                <span class="appointment-file-selection" aria-live="polite"></span>
                            </label>
                        </div>
                    </div>

                    <div class="appointment-notes-card mb-3">
                        <label class="form-label" for="result_notes">After-appointment results / notes</label>
                        <textarea class="form-control" id="result_notes" name="result_notes" maxlength="5000" rows="3" placeholder="Record results or follow-up details"><?= escape($form['result_notes']) ?></textarea>
                    </div>

                    <?php if ($attachments !== []): ?>
                        <div class="mb-3">
                            <span class="form-label d-block">Attached files</span>
                            <ul class="appointment-attachment-list">
                                <?php foreach ($attachments as $attachment): ?>
                                    <li>
                                        <a href="appointment_download.php?id=<?= escape($attachment['id']) ?>">
                                            <i class="bi bi-paperclip"></i> <?= escape($attachment['original_name']) ?>
                                        </a>
                                        <small><?= escape(number_format((int) $attachment['size_bytes'] / 1024)) ?> KB</small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <p class="appointment-upload-help">Attach up to five PDF, JPG or PNG files (5 MB each). Uploading an image saves it with this appointment; details are not automatically read from it.</p>

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
                            <?= $isEditing ? 'Save changes' : 'Add appointment' ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a class="btn btn-light family-cancel-button" href="appointments.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="family-list-card" aria-labelledby="appointmentListTitle">
                <div class="family-section-heading family-list-heading">
                    <span class="heading-icon family-list-icon"><i class="bi bi-calendar2-week"></i></span>
                    <div>
                        <h2 id="appointmentListTitle">All appointments</h2>
                        <p>Most recent first</p>
                    </div>
                </div>

                <?php if ($appointments === []): ?>
                    <div class="empty-state family-empty-state">
                        <span class="empty-icon"><i class="bi bi-calendar2-week"></i></span>
                        <strong>No appointments yet</strong>
                        <p>Add an appointment using the form to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="family-member-list">
                        <?php foreach ($appointments as $appointment): ?>
                            <?php
                            $date = new DateTimeImmutable($appointment['starts_at']);
                            $fullName = $appointment['first_name'] . ' ' . $appointment['last_name'];
                            ?>
                            <article class="family-member-row<?= $appointment['status'] === 'Cancelled' ? ' is-archived' : '' ?>">
                                <div class="date-tile"><span><?= escape(strtoupper($date->format('M'))) ?></span><strong><?= escape($date->format('d')) ?></strong></div>
                                <div class="family-member-details">
                                    <strong><?= escape($appointment['title']) ?></strong>
                                    <span><?= escape($appointment['category']) ?> · <?= escape($appointment['provider_name'] !== '' ? $appointment['provider_name'] : 'Provider not added') ?></span>
                                    <span><?= escape($date->format('M j, Y')) ?><?= (int) $appointment['has_time'] === 1 ? ' · ' . escape($date->format('g:i A')) : '' ?>
                                        <span class="badge <?= escape($badgeClasses[$appointment['status']] ?? 'text-bg-light') ?> ms-1"><?= escape($appointment['status']) ?></span>
                                    </span>
                                    <?php if ($appointment['location'] !== ''): ?>
                                        <small><?= escape($appointment['location']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="family-member-actions">
                                    <a class="btn btn-sm btn-outline-secondary member-action-button" href="appointments.php?edit=<?= escape($appointment['id']) ?>" aria-label="Edit <?= escape($appointment['title']) ?>">
                                        <i class="bi bi-pencil"></i><span>Edit</span>
                                    </a>
                                    <form method="post" action="appointments.php" data-confirm="Delete this appointment? This cannot be undone.">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= escape($appointment['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger member-action-button" type="submit" aria-label="Delete <?= escape($appointment['title']) ?>">
                                            <i class="bi bi-trash"></i><span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($appointments) === 100): ?>
                        <p class="family-list-limit">Showing the 100 most recent appointments.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
</body>
</html>
