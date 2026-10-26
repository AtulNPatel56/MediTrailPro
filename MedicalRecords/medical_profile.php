<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$kinds = [
    'Allergy' => ['icon' => 'bi-exclamation-triangle', 'plural' => 'Allergies', 'title' => 'Allergen', 'detail' => 'Reaction or severity', 'hint' => 'e.g. Penicillin'],
    'Condition' => ['icon' => 'bi-clipboard2-pulse', 'plural' => 'Conditions', 'title' => 'Condition', 'detail' => 'Notes', 'hint' => 'e.g. Asthma'],
    'Emergency contact' => ['icon' => 'bi-telephone-outbound', 'plural' => 'Emergency contacts', 'title' => 'Contact name', 'detail' => 'Relationship', 'hint' => 'e.g. Jordan Sample'],
];

$errors = [];
$pageError = null;
$successMessage = null;
$members = [];
$selected = null;
$entries = array_fill_keys(array_keys($kinds), []);
$form = ['kind' => '', 'title' => '', 'detail' => '', 'phone' => ''];
$csrfToken = csrfToken();

$statusMessages = ['created' => 'Entry added.', 'deleted' => 'Entry removed.'];
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $successMessage = $statusMessages[$status];
}

function redirectToProfile(int $memberId, string $status): never
{
    redirect('medical_profile.php?member=' . $memberId . '&status=' . rawurlencode($status));
}

try {
    $pdo = db();

    $memberQuery = $pdo->prepare(
        'SELECT id, first_name, last_name, relationship FROM family_members
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected !== null) {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $action = $_POST['action'] ?? '';
        $selectedId = (int) $selected['id'];

        if (!is_string($submittedToken) || !validCsrf($submittedToken)) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif ($action === 'delete') {
            $entryId = positiveInt($_POST['id'] ?? null);
            if ($entryId === false) {
                http_response_code(400);
                $errors[] = 'The selected entry is invalid.';
            } else {
                $statement = $pdo->prepare(
                    'DELETE p FROM profile_entries AS p
                     INNER JOIN family_members AS f ON f.id = p.family_member_id
                     WHERE p.id = :id AND p.family_member_id = :member AND f.owner_user_id = :owner'
                );
                $statement->execute(['id' => $entryId, 'member' => $selectedId, 'owner' => $ownerId]);
                if ($statement->rowCount() > 0) {
                    audit($ownerId, 'delete', 'profile_entry', $entryId);
                }
                redirectToProfile($selectedId, 'deleted');
            }
        } elseif ($action === 'create') {
            foreach (array_keys($form) as $field) {
                $value = $_POST[$field] ?? '';
                $form[$field] = is_string($value) ? trim($value) : '';
            }
            if (!isset($kinds[$form['kind']])) {
                $errors[] = 'Choose a valid entry type.';
            }
            if ($form['title'] === '' || mb_strlen($form['title']) > 120) {
                $errors[] = 'Name is required and must be 120 characters or fewer.';
            }
            if (mb_strlen($form['detail']) > 255) {
                $errors[] = 'Details must be 255 characters or fewer.';
            }
            $isContact = $form['kind'] === 'Emergency contact';
            if ($isContact && preg_match('/^[0-9+()\-\s.]{6,30}$/', $form['phone']) !== 1) {
                $errors[] = 'Enter a valid phone number for the emergency contact.';
            }

            if ($errors === []) {
                $statement = $pdo->prepare(
                    'INSERT INTO profile_entries (family_member_id, kind, title, detail, phone)
                     VALUES (:member, :kind, :title, :detail, :phone)'
                );
                $statement->execute([
                    'member' => $selectedId,
                    'kind' => $form['kind'],
                    'title' => $form['title'],
                    'detail' => $form['detail'],
                    'phone' => $isContact ? $form['phone'] : null,
                ]);
                audit($ownerId, 'create', 'profile_entry', (int) $pdo->lastInsertId());
                redirectToProfile($selectedId, 'created');
            }
        } else {
            http_response_code(400);
            $errors[] = 'That action is not supported.';
        }
    }

    if ($selected !== null) {
        $listQuery = $pdo->prepare(
            'SELECT id, kind, title, detail, phone FROM profile_entries
             WHERE family_member_id = :member ORDER BY title LIMIT 200'
        );
        $listQuery->execute(['member' => (int) $selected['id']]);
        foreach ($listQuery->fetchAll() as $row) {
            $entries[$row['kind']][] = $row;
        }
    }
} catch (PDOException $exception) {
    http_response_code(500);
    $pageError = 'The medical profile page could not access the database. Check the XAMPP MySQL service and that migration 003 has been applied.';
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
    <title>Medical profile | MediTrail Pro</title>
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
            <a class="family-back-link" href="medications.php"><i class="bi bi-capsule"></i> Medications</a>
            <a class="family-back-link" href="appointments.php"><i class="bi bi-calendar2-week"></i> Appointments</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> HEALTH BASICS</div>
                <h1>Medical <span>profile</span></h1>
                <p class="welcome-subtitle">Allergies, conditions and emergency contacts in one place. Information is entered by you and is not medical advice.</p>
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
                <strong>Please check the following:</strong>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($members === [] && $pageError === null): ?>
            <div class="empty-state family-empty-state">
                <span class="empty-icon"><i class="bi bi-people"></i></span>
                <strong>No family members yet</strong>
                <p>Add a <a href="family_members.php">family member</a> to start their medical profile.</p>
            </div>
        <?php elseif ($members !== []): ?>
            <nav class="profile-tabs" aria-label="Choose family member">
                <?php foreach ($members as $member): ?>
                    <?php $isCurrent = $selected !== null && (int) $member['id'] === (int) $selected['id']; ?>
                    <a class="profile-tab<?= $isCurrent ? ' active' : '' ?>" href="medical_profile.php?member=<?= escape($member['id']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                        <span class="avatar avatar-small"><?= escape(strtoupper(mb_substr($member['first_name'], 0, 1))) ?></span>
                        <?= escape($member['first_name'] . ' ' . $member['last_name']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($selected !== null): ?>
            <div class="profile-grid">
                <?php foreach ($kinds as $kind => $meta): ?>
                    <?php $kindId = strtolower(str_replace(' ', '-', $kind)); ?>
                    <section class="family-list-card profile-card" aria-labelledby="heading-<?= escape($kindId) ?>">
                        <div class="family-section-heading family-list-heading">
                            <span class="heading-icon family-list-icon"><i class="bi <?= escape($meta['icon']) ?>"></i></span>
                            <div>
                                <h2 id="heading-<?= escape($kindId) ?>"><?= escape($meta['plural']) ?></h2>
                                <p><?= escape(count($entries[$kind])) ?> recorded</p>
                            </div>
                        </div>

                        <?php if ($entries[$kind] === []): ?>
                            <p class="profile-empty">Nothing recorded yet.</p>
                        <?php else: ?>
                            <ul class="profile-list">
                                <?php foreach ($entries[$kind] as $entry): ?>
                                    <li>
                                        <div>
                                            <strong><?= escape($entry['title']) ?></strong>
                                            <?php if ($entry['detail'] !== ''): ?><span><?= escape($entry['detail']) ?></span><?php endif; ?>
                                            <?php if ($entry['phone'] !== null): ?>
                                                <span><i class="bi bi-telephone"></i> <?= escape($entry['phone']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <form method="post" action="medical_profile.php?member=<?= escape($selected['id']) ?>" data-confirm="Remove this entry?">
                                            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="member_id" value="<?= escape($selected['id']) ?>">
                                            <input type="hidden" name="id" value="<?= escape($entry['id']) ?>">
                                            <button class="btn btn-sm btn-outline-danger member-action-button" type="submit" aria-label="Remove <?= escape($entry['title']) ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <form class="profile-add-form" method="post" action="medical_profile.php?member=<?= escape($selected['id']) ?>" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="member_id" value="<?= escape($selected['id']) ?>">
                            <input type="hidden" name="kind" value="<?= escape($kind) ?>">
                            <?php $prefill = $form['kind'] === $kind; ?>
                            <label class="form-label" for="title-<?= escape($kindId) ?>"><?= escape($meta['title']) ?></label>
                            <input class="form-control mb-2" id="title-<?= escape($kindId) ?>" name="title" type="text" maxlength="120" placeholder="<?= escape($meta['hint']) ?>" value="<?= $prefill ? escape($form['title']) : '' ?>" required>
                            <label class="form-label" for="detail-<?= escape($kindId) ?>"><?= escape($meta['detail']) ?></label>
                            <input class="form-control mb-2" id="detail-<?= escape($kindId) ?>" name="detail" type="text" maxlength="255" value="<?= $prefill ? escape($form['detail']) : '' ?>">
                            <?php if ($kind === 'Emergency contact'): ?>
                                <label class="form-label" for="phone-<?= escape($kindId) ?>">Phone</label>
                                <input class="form-control mb-2" id="phone-<?= escape($kindId) ?>" name="phone" type="tel" maxlength="30" value="<?= $prefill ? escape($form['phone']) : '' ?>" required>
                            <?php endif; ?>
                            <button class="btn btn-primary family-submit-button mt-2" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
                        </form>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
