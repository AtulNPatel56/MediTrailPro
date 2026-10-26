<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$byDay = [];
$pageError = null;
$todayKey = '';

try {
    $pdo = db();
    // The database clock is used everywhere else for "today", so the calendar agrees with it
    $todayKey = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();

    $param = $_GET['month'] ?? '';
    $first = is_string($param) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $param) === 1
        ? new DateTimeImmutable($param . '-01')
        : new DateTimeImmutable(substr($todayKey, 0, 7) . '-01');
    if ((int) $first->format('Y') < 2000 || (int) $first->format('Y') > 2100) {
        $first = new DateTimeImmutable(substr($todayKey, 0, 7) . '-01');
    }
    $next = $first->modify('+1 month');

    $query = $pdo->prepare(
        'SELECT a.id, a.title, a.provider_name, a.starts_at, a.has_time, a.status, f.first_name
         FROM appointments a
         INNER JOIN family_members f ON f.id = a.family_member_id
         WHERE f.owner_user_id = :owner AND a.starts_at >= :from AND a.starts_at < :to
         ORDER BY a.starts_at LIMIT 500'
    );
    $query->execute(['owner' => $ownerId, 'from' => $first->format('Y-m-d'), 'to' => $next->format('Y-m-d')]);
    foreach ($query->fetchAll() as $row) {
        $byDay[substr((string) $row['starts_at'], 0, 10)][] = $row;
    }
} catch (PDOException $exception) {
    http_response_code(500);
    $first = new DateTimeImmutable('first day of this month');
    $pageError = 'The calendar could not be loaded because of a database problem.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$prev = $first->modify('-1 month');
$next = $first->modify('+1 month');
$daysInMonth = (int) $first->format('t');
$leading = (int) $first->format('N') - 1;
$cells = (int) (ceil(($leading + $daysInMonth) / 7) * 7);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Calendar | MediTrail Pro</title>
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
            <a class="family-back-link" href="appointments.php"><i class="bi bi-list-ul"></i> Appointment list</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> SCHEDULE</div>
                <h1>Family <span>calendar</span></h1>
                <p class="welcome-subtitle">All appointments for your family, by month.</p>
            </div>
        </section>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
        <?php endif; ?>

        <section class="family-list-card">
            <div class="calendar-nav">
                <a class="btn btn-outline-secondary btn-sm" href="calendar.php?month=<?= escape($prev->format('Y-m')) ?>" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                <h2><?= escape($first->format('F Y')) ?></h2>
                <a class="btn btn-outline-secondary btn-sm" href="calendar.php?month=<?= escape($next->format('Y-m')) ?>" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
                <a class="btn btn-outline-secondary btn-sm ms-auto" href="calendar.php">Today</a>
                <a class="btn btn-primary btn-sm" href="appointments.php"><i class="bi bi-plus-lg"></i> Add</a>
            </div>

            <div class="calendar-grid" role="grid" aria-label="<?= escape($first->format('F Y')) ?> appointments">
                <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName): ?>
                    <div class="calendar-dow" role="columnheader"><?= $dayName ?></div>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < $cells; $i++): ?>
                    <?php
                    $day = $i - $leading + 1;
                    if ($day < 1 || $day > $daysInMonth) {
                        echo '<div class="calendar-cell calendar-empty" role="gridcell"></div>';
                        continue;
                    }
                    $key = $first->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                    $items = $byDay[$key] ?? [];
                    ?>
                    <div class="calendar-cell<?= $key === $todayKey ? ' calendar-today' : '' ?>" role="gridcell">
                        <span class="calendar-day"><?= $day ?></span>
                        <?php foreach (array_slice($items, 0, 3) as $item): ?>
                            <a class="calendar-event<?= $item['status'] === 'Cancelled' ? ' calendar-cancelled' : '' ?><?= $item['status'] === 'Completed' ? ' calendar-done' : '' ?>"
                               href="appointments.php?edit=<?= escape((int) $item['id']) ?>"
                               title="<?= escape($item['title'] . ' – ' . $item['provider_name'] . ' (' . $item['first_name'] . ', ' . $item['status'] . ')') ?>">
                                <?= (int) $item['has_time'] === 1 ? escape((new DateTimeImmutable($item['starts_at']))->format('g:ia')) . ' ' : '' ?><?= escape($item['first_name']) ?>: <?= escape($item['title']) ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($items) > 3): ?>
                            <span class="calendar-more">+<?= count($items) - 3 ?> more</span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <p class="calendar-legend"><span class="calendar-event calendar-done">Completed</span> <span class="calendar-event calendar-cancelled">Cancelled</span> <span class="calendar-event">Scheduled</span></p>
        </section>
    </div>
</main>
</body>
</html>
