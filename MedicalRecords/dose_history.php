<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

const HISTORY_DAYS = 30;

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$rows = [];
$days = [];
$pageError = null;
$overall = ['taken' => 0, 'skipped' => 0, 'missed' => 0];

try {
    $pdo = db();
    $todayKey = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
    $today = new DateTimeImmutable($todayKey);

    // Past days only: today is still in progress, so it is never counted as missed
    for ($i = HISTORY_DAYS; $i >= 1; $i--) {
        $days[] = $today->modify("-$i day")->format('Y-m-d');
    }

    $meds = $pdo->prepare(
        "SELECT m.id, m.name, f.first_name, DATE(m.created_at) AS started
         FROM medications m
         INNER JOIN family_members f ON f.id = m.family_member_id
         WHERE m.status = 'Active' AND f.is_active = 1 AND f.owner_user_id = :owner
         ORDER BY f.first_name, m.name LIMIT 100"
    );
    $meds->execute(['owner' => $ownerId]);
    $medications = $meds->fetchAll();

    $events = [];
    if ($medications !== []) {
        $eventQuery = $pdo->prepare(
            'SELECT d.medication_id, d.dose_date, d.outcome
             FROM dose_events d
             INNER JOIN medications m ON m.id = d.medication_id
             INNER JOIN family_members f ON f.id = m.family_member_id
             WHERE f.owner_user_id = :owner AND d.dose_date >= :from AND d.dose_date < :to'
        );
        $eventQuery->execute(['owner' => $ownerId, 'from' => $days[0], 'to' => $todayKey]);
        foreach ($eventQuery->fetchAll() as $event) {
            $events[(int) $event['medication_id']][$event['dose_date']] = $event['outcome'];
        }
    }

    foreach ($medications as $med) {
        $cells = [];
        $counts = ['taken' => 0, 'skipped' => 0, 'missed' => 0];
        foreach ($days as $day) {
            if ($day < $med['started']) {
                $cells[] = ['day' => $day, 'state' => 'na'];
                continue;
            }
            $outcome = $events[(int) $med['id']][$day] ?? null;
            $state = $outcome === 'Taken' ? 'taken' : ($outcome === 'Skipped' ? 'skipped' : 'missed');
            $counts[$state]++;
            $cells[] = ['day' => $day, 'state' => $state];
        }
        $tracked = array_sum($counts);
        $rows[] = [
            'name' => $med['name'],
            'member' => $med['first_name'],
            'cells' => $cells,
            'counts' => $counts,
            'percent' => $tracked > 0 ? (int) round($counts['taken'] / $tracked * 100) : null,
        ];
        foreach ($counts as $key => $value) {
            $overall[$key] += $value;
        }
    }
} catch (PDOException $exception) {
    http_response_code(500);
    $pageError = 'Dose history could not be loaded because of a database problem.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$overallTracked = array_sum($overall);
$overallPercent = $overallTracked > 0 ? (int) round($overall['taken'] / $overallTracked * 100) : null;
$stateLabels = ['taken' => 'Taken', 'skipped' => 'Skipped', 'missed' => 'Not recorded', 'na' => 'Before this medication was added'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Dose history | MediTrail Pro</title>
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
            <a class="family-back-link" href="reminders.php"><i class="bi bi-bell"></i> Reminders</a>
            <a class="family-back-link" href="medications.php"><i class="bi bi-capsule"></i> Medications</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> LAST <?= HISTORY_DAYS ?> DAYS</div>
                <h1>Dose <span>history</span></h1>
                <p class="welcome-subtitle">How consistently each active medication was recorded as taken. Today is not counted until it has passed.</p>
            </div>
            <?php if ($overallPercent !== null): ?>
                <span class="family-count"><i class="bi bi-graph-up"></i> <?= escape($overallPercent) ?>% overall</span>
            <?php endif; ?>
        </section>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
        <?php endif; ?>

        <section class="family-list-card">
            <?php if ($rows === []): ?>
                <div class="empty-state"><span class="empty-icon"><i class="bi bi-capsule"></i></span><strong>No active medications</strong><p><a href="medications.php">Add a medication</a> and record doses on the Reminders page to build a history.</p></div>
            <?php else: ?>
                <p class="history-legend">
                    <span><i class="history-cell history-taken"></i> Taken</span>
                    <span><i class="history-cell history-skipped"></i> Skipped</span>
                    <span><i class="history-cell history-missed"></i> Not recorded</span>
                    <span><i class="history-cell history-na"></i> Not yet added</span>
                </p>
                <?php foreach ($rows as $row): ?>
                    <div class="history-row">
                        <div class="history-head">
                            <strong><?= escape($row['name']) ?></strong>
                            <span class="text-muted"><?= escape($row['member']) ?></span>
                            <span class="history-percent"><?= $row['percent'] === null ? 'New' : escape($row['percent']) . '%' ?></span>
                        </div>
                        <div class="history-cells" role="img" aria-label="<?= escape($row['name']) ?>: <?= escape($row['counts']['taken']) ?> taken, <?= escape($row['counts']['skipped']) ?> skipped, <?= escape($row['counts']['missed']) ?> not recorded in the last <?= HISTORY_DAYS ?> days">
                            <?php foreach ($row['cells'] as $cell): ?>
                                <i class="history-cell history-<?= escape($cell['state']) ?>" title="<?= escape((new DateTimeImmutable($cell['day']))->format('M j') . ': ' . $stateLabels[$cell['state']]) ?>"></i>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted"><?= escape($row['counts']['taken']) ?> taken · <?= escape($row['counts']['skipped']) ?> skipped · <?= escape($row['counts']['missed']) ?> not recorded</small>
                    </div>
                <?php endforeach; ?>
                <p class="medical-note mt-3"><i class="bi bi-info-circle"></i> This is a personal record for organisation only. Talk to your clinician or pharmacist about any missed doses.</p>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
