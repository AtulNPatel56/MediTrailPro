<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$errors = [];
$pageError = null;
$successMessage = null;
$doses = [];
$csrfToken = csrfToken();

$statusMessages = ['Taken' => 'Dose marked as taken.', 'Skipped' => 'Dose marked as skipped.', 'Undone' => 'Dose status cleared.'];
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $successMessage = $statusMessages[$status];
}

try {
    $pdo = db();

    // Use the database clock for "today" so dates and times agree with stored schedules
    $clock = $pdo->query('SELECT CURDATE() AS today, CURTIME() AS now_time')->fetch();
    $today = (string) $clock['today'];
    $nowTime = (string) $clock['now_time'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $medicationId = positiveInt($_POST['medication_id'] ?? null);

        if (!validCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif ($medicationId === false || !in_array($action, ['Taken', 'Skipped', 'Undone'], true)) {
            http_response_code(400);
            $errors[] = 'That request is not valid.';
        } else {
            $owned = $pdo->prepare(
                "SELECT m.id FROM medications AS m
                 INNER JOIN family_members AS f ON f.id = m.family_member_id
                 WHERE m.id = :id AND f.owner_user_id = :owner AND m.status = 'Active' LIMIT 1"
            );
            $owned->execute(['id' => $medicationId, 'owner' => $ownerId]);
            if ($owned->fetchColumn() === false) {
                http_response_code(404);
                $errors[] = 'That medication could not be found.';
            } elseif ($action === 'Undone') {
                $pdo->beginTransaction();
                $previous = $pdo->prepare('SELECT outcome FROM dose_events WHERE medication_id = :id AND dose_date = :day FOR UPDATE');
                $previous->execute(['id' => $medicationId, 'day' => $today]);
                $before = $previous->fetchColumn();
                $pdo->prepare('DELETE FROM dose_events WHERE medication_id = :id AND dose_date = :day')
                    ->execute(['id' => $medicationId, 'day' => $today]);
                if ($before === 'Taken') {
                    $pdo->prepare('UPDATE medications SET supply_count = supply_count + 1 WHERE id = :id AND supply_count IS NOT NULL')
                        ->execute(['id' => $medicationId]);
                }
                $pdo->commit();
                audit($ownerId, 'dose_undo', 'medication', $medicationId);
                redirect('reminders.php?status=Undone');
            } else {
                $pdo->beginTransaction();
                $previous = $pdo->prepare('SELECT outcome FROM dose_events WHERE medication_id = :id AND dose_date = :day FOR UPDATE');
                $previous->execute(['id' => $medicationId, 'day' => $today]);
                $before = $previous->fetchColumn();
                $pdo->prepare(
                    'INSERT INTO dose_events (medication_id, dose_date, outcome) VALUES (:id, :day, :outcome)
                     ON DUPLICATE KEY UPDATE outcome = VALUES(outcome), recorded_at = CURRENT_TIMESTAMP'
                )->execute(['id' => $medicationId, 'day' => $today, 'outcome' => $action]);
                // The supply, when tracked, only changes when a dose moves into or out of "Taken"
                if ($action === 'Taken' && $before !== 'Taken') {
                    $pdo->prepare('UPDATE medications SET supply_count = GREATEST(supply_count, 1) - 1 WHERE id = :id AND supply_count IS NOT NULL')
                        ->execute(['id' => $medicationId]);
                } elseif ($action !== 'Taken' && $before === 'Taken') {
                    $pdo->prepare('UPDATE medications SET supply_count = supply_count + 1 WHERE id = :id AND supply_count IS NOT NULL')
                        ->execute(['id' => $medicationId]);
                }
                $pdo->commit();
                audit($ownerId, 'dose_' . strtolower($action), 'medication', $medicationId);
                redirect('reminders.php?status=' . $action);
            }
        }
    }

    $query = $pdo->prepare(
        "SELECT m.id, m.name, m.dose_instructions, m.schedule_time, m.supply_count, f.first_name, f.last_name, d.outcome
         FROM medications AS m
         INNER JOIN family_members AS f ON f.id = m.family_member_id
         LEFT JOIN dose_events AS d ON d.medication_id = m.id AND d.dose_date = :day
         WHERE m.status = 'Active' AND f.is_active = 1 AND f.owner_user_id = :owner
         ORDER BY m.schedule_time IS NULL, m.schedule_time, m.name
         LIMIT 100"
    );
    $query->execute(['day' => $today, 'owner' => $ownerId]);
    $doses = $query->fetchAll();
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $pageError = 'The reminders page could not access the database. Check MySQL and that migrations 004 and 010 have been applied.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$counts = ['Taken' => 0, 'Skipped' => 0, 'Pending' => 0];
foreach ($doses as &$dose) {
    if ($dose['outcome'] !== null) {
        $dose['state'] = $dose['outcome'];
        $counts[$dose['outcome']]++;
    } else {
        $dose['state'] = ($dose['schedule_time'] !== null && $dose['schedule_time'] < ($nowTime ?? '00:00:00')) ? 'Overdue' : 'Due';
        $counts['Pending']++;
    }
}
unset($dose);

$badgeClasses = ['Taken' => 'text-bg-success', 'Skipped' => 'text-bg-secondary', 'Overdue' => 'text-bg-danger', 'Due' => 'text-bg-info'];
$total = count($doses);
$percent = $total > 0 ? (int) round(($counts['Taken'] / $total) * 100) : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Reminders | MediTrail Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
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
            <a class="family-back-link" href="medical_profile.php"><i class="bi bi-clipboard2-pulse"></i> Profile</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> TODAY<?= isset($today) ? ' · ' . escape(strtoupper((new DateTimeImmutable($today))->format('l, M j'))) : '' ?></div>
                <h1>Today's <span>doses</span></h1>
                <p class="welcome-subtitle">Record what was taken from the schedules you entered. This app does not give medical advice or dosing guidance.</p>
            </div>
            <span class="family-count"><i class="bi bi-check2-circle"></i> <?= escape($counts['Taken']) ?> of <?= escape($total) ?> taken</span>
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

        <?php if ($total > 0): ?>
            <div class="dose-progress" role="progressbar" aria-label="Doses taken today" aria-valuenow="<?= escape($percent) ?>" aria-valuemin="0" aria-valuemax="100">
                <span style="width: <?= escape($percent) ?>%"></span>
            </div>
        <?php endif; ?>

        <section class="family-list-card" aria-labelledby="doseListTitle">
            <div class="family-section-heading family-list-heading">
                <span class="heading-icon family-list-icon"><i class="bi bi-bell"></i></span>
                <div>
                    <h2 id="doseListTitle">Scheduled for today</h2>
                    <p><?= escape($counts['Pending']) ?> pending · <?= escape($counts['Skipped']) ?> skipped</p>
                </div>
            </div>

            <?php if ($doses === []): ?>
                <div class="empty-state family-empty-state">
                    <span class="empty-icon"><i class="bi bi-capsule"></i></span>
                    <strong>No active medications</strong>
                    <p>Add one on the <a href="medications.php">Medications</a> page to track daily doses.</p>
                </div>
            <?php else: ?>
                <div class="family-member-list">
                    <?php foreach ($doses as $dose): ?>
                        <article class="family-member-row<?= $dose['state'] === 'Skipped' ? ' is-archived' : '' ?>">
                            <div class="date-tile">
                                <?php if ($dose['schedule_time'] !== null): ?>
                                    <?php $time = new DateTimeImmutable($dose['schedule_time']); ?>
                                    <span><?= escape($time->format('A')) ?></span><strong><?= escape($time->format('g:i')) ?></strong>
                                <?php else: ?>
                                    <span>ANY</span><strong>--</strong>
                                <?php endif; ?>
                            </div>
                            <div class="family-member-details">
                                <strong><?= escape($dose['name']) ?> <span class="badge <?= escape($badgeClasses[$dose['state']]) ?>"><?= escape($dose['state']) ?></span></strong>
                                <span><?= escape($dose['first_name'] . ' ' . $dose['last_name']) ?><?= $dose['dose_instructions'] !== '' ? ' · ' . escape($dose['dose_instructions']) : '' ?></span>
                                <?php if ($dose['supply_count'] !== null && (int) $dose['supply_count'] <= 7): ?>
                                    <span><span class="due-badge<?= (int) $dose['supply_count'] === 0 ? ' due-badge-today' : '' ?>"><?= (int) $dose['supply_count'] === 0 ? 'Out of supply' : 'Only ' . escape((int) $dose['supply_count']) . ' left' ?></span> <a href="medications.php?edit=<?= escape((int) $dose['id']) ?>">Refill</a></span>
                                <?php endif; ?>
                            </div>
                            <div class="family-member-actions">
                                <?php if ($dose['outcome'] === null): ?>
                                    <form method="post" action="reminders.php">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="medication_id" value="<?= escape($dose['id']) ?>">
                                        <button class="btn btn-sm btn-success member-action-button" type="submit" name="action" value="Taken"><i class="bi bi-check-lg"></i><span>Taken</span></button>
                                        <button class="btn btn-sm btn-outline-secondary member-action-button" type="submit" name="action" value="Skipped"><i class="bi bi-skip-forward"></i><span>Skip</span></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="reminders.php">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="medication_id" value="<?= escape($dose['id']) ?>">
                                        <button class="btn btn-sm btn-outline-secondary member-action-button" type="submit" name="action" value="Undone"><i class="bi bi-arrow-counterclockwise"></i><span>Undo</span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
