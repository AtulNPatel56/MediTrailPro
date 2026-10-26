<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];
$userInitial = strtoupper(mb_substr((string) $currentUser['display_name'], 0, 1));

function displayTime(?string $dateTime): string
{
    if ($dateTime === null) {
        return 'Time not set';
    }

    return (new DateTimeImmutable($dateTime))->format('g:i A');
}

$databaseError = null;
$stats = [
    'family_members' => 0,
    'active_medications' => 0,
    'upcoming_appointments' => 0,
];
$medications = [];
$appointments = [];
$doses = ['total' => 0, 'taken' => 0, 'skipped' => 0];
$lowSupply = [];
$readings = [];
$recentRecords = 0;

try {
    $pdo = db();
    $owner = ['owner' => $ownerId];

    $dose = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(d.outcome = 'Taken'), 0) AS taken,
                COALESCE(SUM(d.outcome = 'Skipped'), 0) AS skipped
         FROM medications m
         INNER JOIN family_members f ON f.id = m.family_member_id
         LEFT JOIN dose_events d ON d.medication_id = m.id AND d.dose_date = CURDATE()
         WHERE m.status = 'Active' AND f.is_active = 1 AND f.owner_user_id = :owner"
    );
    $dose->execute($owner);
    $doses = array_map('intval', $dose->fetch());

    $low = $pdo->prepare(
        "SELECT m.id, m.name, m.supply_count, f.first_name
         FROM medications m
         INNER JOIN family_members f ON f.id = m.family_member_id
         WHERE m.status = 'Active' AND f.is_active = 1 AND f.owner_user_id = :owner
           AND m.supply_count IS NOT NULL AND m.supply_count <= 7
         ORDER BY m.supply_count, m.name LIMIT 5"
    );
    $low->execute($owner);
    $lowSupply = $low->fetchAll();

    $count = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM documents x INNER JOIN family_members f ON f.id = x.family_member_id
             WHERE f.owner_user_id = :owner1 AND x.uploaded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
          + (SELECT COUNT(*) FROM health_readings r INNER JOIN family_members f ON f.id = r.family_member_id
             WHERE f.owner_user_id = :owner2 AND r.measured_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))"
    );
    $count->execute(['owner1' => $ownerId, 'owner2' => $ownerId]);
    $recentRecords = (int) $count->fetchColumn();

    $latest = $pdo->prepare(
        "SELECT r.metric, r.value_primary, r.value_secondary, r.measured_at, f.first_name, f.last_name
         FROM health_readings r
         INNER JOIN family_members f ON f.id = r.family_member_id
         WHERE f.owner_user_id = :owner AND f.is_active = 1
           AND r.id = (SELECT r2.id FROM health_readings r2
                       WHERE r2.family_member_id = r.family_member_id AND r2.metric = r.metric
                       ORDER BY r2.measured_at DESC, r2.id DESC LIMIT 1)
         ORDER BY r.measured_at DESC
         LIMIT 6"
    );
    $latest->execute($owner);
    $readings = $latest->fetchAll();

    $count = $pdo->prepare('SELECT COUNT(*) FROM family_members WHERE is_active = 1 AND owner_user_id = :owner');
    $count->execute($owner);
    $stats['family_members'] = (int) $count->fetchColumn();

    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM medications m INNER JOIN family_members f ON f.id = m.family_member_id
         WHERE m.status = 'Active' AND f.owner_user_id = :owner"
    );
    $count->execute($owner);
    $stats['active_medications'] = (int) $count->fetchColumn();

    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM appointments a INNER JOIN family_members f ON f.id = a.family_member_id
         WHERE (a.starts_at >= NOW() OR (a.has_time = 0 AND DATE(a.starts_at) >= CURDATE()))
           AND a.status <> 'Cancelled' AND f.owner_user_id = :owner"
    );
    $count->execute($owner);
    $stats['upcoming_appointments'] = (int) $count->fetchColumn();

    $list = $pdo->prepare(
        "SELECT m.name, m.dose_instructions, m.schedule_time, f.first_name, f.last_name
         FROM medications AS m
         INNER JOIN family_members AS f ON f.id = m.family_member_id
         WHERE m.status = 'Active' AND f.is_active = 1 AND f.owner_user_id = :owner
         ORDER BY m.schedule_time, m.name
         LIMIT 5"
    );
    $list->execute($owner);
    $medications = $list->fetchAll();

    $list = $pdo->prepare(
        "SELECT a.title, a.provider_name, a.starts_at, a.has_time, f.first_name, f.last_name,
                DATEDIFF(DATE(a.starts_at), CURDATE()) AS days_away
         FROM appointments AS a
         INNER JOIN family_members AS f ON f.id = a.family_member_id
         WHERE (a.starts_at >= NOW() OR (a.has_time = 0 AND DATE(a.starts_at) >= CURDATE()))
           AND a.status <> 'Cancelled' AND f.is_active = 1 AND f.owner_user_id = :owner
         ORDER BY a.starts_at
         LIMIT 5"
    );
    $list->execute($owner);
    $appointments = $list->fetchAll();
} catch (PDOException $exception) {
    $databaseError = 'The dashboard could not load MySQL data. Start MySQL in XAMPP and import database/schema.sql.';

    if (getenv('APP_ENV') !== 'production') {
        $databaseError .= ' Details: ' . $exception->getMessage();
    }
}

$today = new DateTimeImmutable('now');
$greeting = match (true) {
    (int) $today->format('G') < 12 => 'Good morning',
    (int) $today->format('G') < 18 => 'Good afternoon',
    default => 'Good evening',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Dashboard | MediTrail Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar d-none d-lg-flex" aria-label="Main navigation">
        <a class="brand" href="index.php" aria-label="MediTrail Pro dashboard">
            <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            <span class="brand-name">MediTrail<span>Pro</span></span>
        </a>

        <div class="nav-caption">WORKSPACE</div>
        <nav class="side-nav">
            <a class="nav-item active" href="#dashboard" aria-current="page"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></a>
            <a class="nav-item" href="family_members.php"><i class="bi bi-people"></i><span>Family members</span></a>
            <a class="nav-item" href="medications.php"><i class="bi bi-capsule"></i><span>Medications</span><span class="nav-count"><?= escape($stats['active_medications']) ?></span></a>
            <a class="nav-item" href="dose_history.php"><i class="bi bi-graph-up"></i><span>Dose history</span></a>
            <a class="nav-item" href="appointments.php"><i class="bi bi-calendar2-week"></i><span>Appointments</span></a>
            <a class="nav-item" href="calendar.php"><i class="bi bi-calendar3"></i><span>Calendar</span></a>
            <a class="nav-item" href="reminders.php"><i class="bi bi-bell"></i><span>Reminders</span></a>
            <a class="nav-item" href="medical_profile.php"><i class="bi bi-clipboard2-pulse"></i><span>Medical profile</span></a>
            <a class="nav-item" href="documents.php"><i class="bi bi-file-earmark-medical"></i><span>Documents</span></a>
            <a class="nav-item" href="health.php"><i class="bi bi-activity"></i><span>Health tracking</span></a>
            <a class="nav-item" href="summary.php"><i class="bi bi-file-earmark-text"></i><span>Health summary</span></a>
        </nav>

        <div class="sidebar-bottom">
            <div class="privacy-card">
                <span class="privacy-icon"><i class="bi bi-shield-check"></i></span>
                <strong>Your family's data</strong>
                <p>Your records are visible only to your signed-in account. Use fictional demo data only.</p>
            </div>
            <a class="nav-item" href="activity.php"><i class="bi bi-clock-history"></i><span>Activity</span></a>
            <a class="nav-item" href="settings.php"><i class="bi bi-gear"></i><span>Settings</span></a>
            <div class="sidebar-user">
                <span class="avatar avatar-small"><?= escape($userInitial) ?></span>
                <span><strong><?= escape($currentUser['display_name']) ?></strong><small>Signed in</small></span>
                <i class="bi bi-three-dots ms-auto"></i>
            </div>
        </div>
    </aside>

    <div class="mobile-topbar d-lg-none">
        <a class="brand" href="index.php">
            <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            <span class="brand-name">MediTrail<span>Pro</span></span>
        </a>
        <button class="btn mobile-menu-button" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNavigation" aria-controls="mobileNavigation" aria-label="Open navigation">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <div class="offcanvas offcanvas-start mobile-drawer d-lg-none" tabindex="-1" id="mobileNavigation" aria-labelledby="mobileNavigationTitle">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title" id="mobileNavigationTitle">Navigation</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close navigation"></button>
        </div>
        <div class="offcanvas-body">
            <a class="brand drawer-brand" href="index.php">
                <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
                <span class="brand-name">MediTrail<span>Pro</span></span>
            </a>
            <nav class="side-nav">
                <a class="nav-item active" href="#dashboard" aria-current="page" data-bs-dismiss="offcanvas"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></a>
                <a class="nav-item" href="family_members.php"><i class="bi bi-people"></i><span>Family members</span></a>
                <a class="nav-item" href="medications.php"><i class="bi bi-capsule"></i><span>Medications</span></a>
                <a class="nav-item" href="appointments.php"><i class="bi bi-calendar2-week"></i><span>Appointments</span></a>
            <a class="nav-item" href="calendar.php"><i class="bi bi-calendar3"></i><span>Calendar</span></a>
                <a class="nav-item" href="reminders.php"><i class="bi bi-bell"></i><span>Reminders</span></a>
                <a class="nav-item" href="dose_history.php"><i class="bi bi-graph-up"></i><span>Dose history</span></a>
                <a class="nav-item" href="medical_profile.php"><i class="bi bi-clipboard2-pulse"></i><span>Medical profile</span></a>
                <a class="nav-item" href="documents.php"><i class="bi bi-file-earmark-medical"></i><span>Documents</span></a>
                <a class="nav-item" href="health.php"><i class="bi bi-activity"></i><span>Health tracking</span></a>
            <a class="nav-item" href="summary.php"><i class="bi bi-file-earmark-text"></i><span>Health summary</span></a>
            </nav>
        </div>
    </div>

    <main class="main-content" id="dashboard">
        <header class="topbar">
            <div class="breadcrumb-label"><span>Workspace</span><i class="bi bi-chevron-right"></i><strong>Dashboard</strong></div>
            <div class="topbar-actions">
                <span class="demo-pill"><span></span> DEMO DATA</span>
                <a class="icon-button" href="reminders.php" aria-label="Reminders<?= $doses['total'] - $doses['taken'] - $doses['skipped'] > 0 ? ': doses pending' : '' ?>"><i class="bi bi-bell"></i><?php if ($doses['total'] - $doses['taken'] - $doses['skipped'] > 0): ?><span class="notification-dot"></span><?php endif; ?></a>
                <span class="avatar"><?= escape($userInitial) ?></span>
                <div class="user-label"><strong><?= escape($currentUser['display_name']) ?></strong><small>Account owner</small></div>
                <?php include __DIR__ . '/includes/user_menu.php'; ?>
            </div>
        </header>

        <div class="page-container">
            <?php if ($databaseError !== null): ?>
                <div class="alert alert-warning database-alert" role="alert">
                    <i class="bi bi-database-exclamation"></i>
                    <div><strong>Database not connected</strong><br><?= escape($databaseError) ?></div>
                </div>
            <?php endif; ?>

            <section class="welcome-row">
                <div>
                    <div class="eyebrow"><span class="eyebrow-dot"></span> <?= escape(strtoupper($today->format('l, F j, Y'))) ?></div>
                    <h1><?= escape($greeting) ?>, <span><?= escape(explode(' ', (string) $currentUser['display_name'])[0]) ?>.</span></h1>
                    <p class="welcome-subtitle">Here's your family's health overview for today.</p>
                </div>
                <a class="btn btn-primary add-button" href="family_members.php">
                    <i class="bi bi-plus-lg"></i> Add family member
                </a>
            </section>

            <section class="profile-strip" id="family" aria-label="Selected family profile">
                <span class="profile-strip-icon"><i class="bi bi-people"></i></span>
                <span class="profile-strip-label">VIEWING FAMILY</span>
                <span class="profile-strip-name">All family members</span>
                <span class="profile-strip-divider"></span>
                <span class="profile-strip-note">Your information, organized in one place</span>
                <i class="bi bi-chevron-down ms-auto profile-chevron"></i>
            </section>

            <section class="stats-grid" aria-label="Family health summary">
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon teal"><i class="bi bi-people"></i></span><span class="stat-trend"><i class="bi bi-check-circle"></i> Profiles</span></div>
                    <div class="stat-number"><?= escape($stats['family_members']) ?></div>
                    <div class="stat-label">Family members</div>
                    <div class="stat-foot">Profiles in your family</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon violet"><i class="bi bi-capsule"></i></span><span class="stat-trend neutral"><i class="bi bi-calendar-check"></i> Today</span></div>
                    <div class="stat-number"><?= escape($stats['active_medications']) ?></div>
                    <div class="stat-label">Active medications</div>
                    <div class="stat-foot">Listed in your records</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon amber"><i class="bi bi-calendar2-event"></i></span><span class="stat-trend neutral"><i class="bi bi-clock"></i> Upcoming</span></div>
                    <div class="stat-number"><?= escape($stats['upcoming_appointments']) ?></div>
                    <div class="stat-label">Appointments</div>
                    <div class="stat-foot">On the family calendar</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon rose"><i class="bi bi-folder2-open"></i></span><span class="stat-trend neutral"><i class="bi bi-lock"></i> Private</span></div>
                    <div class="stat-number"><?= escape($recentRecords) ?></div>
                    <div class="stat-label">Recent records</div>
                    <div class="stat-foot">Documents and readings, last 30 days</div>
                </article>
            </section>

            <?php if ($lowSupply !== []): ?>
                <div class="alert alert-warning supply-alert" role="alert">
                    <i class="bi bi-box-seam"></i>
                    <div>
                        <strong>Time to refill</strong>
                        <ul class="mb-0">
                            <?php foreach ($lowSupply as $item): ?>
                                <li><a href="medications.php?edit=<?= escape((int) $item['id']) ?>"><?= escape($item['name']) ?></a> (<?= escape($item['first_name']) ?>): <?= (int) $item['supply_count'] === 0 ? 'out of supply' : escape((int) $item['supply_count']) . ' dose' . ((int) $item['supply_count'] === 1 ? '' : 's') . ' left' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php $handled = $doses['taken'] + $doses['skipped']; $pending = $doses['total'] - $handled; ?>
            <section class="panel today-panel" aria-label="Today's doses">
                <div class="panel-heading">
                    <div>
                        <div class="panel-title"><span class="heading-icon medication-heading-icon"><i class="bi bi-bell"></i></span>Today's doses</div>
                        <p class="panel-subtitle">
                            <?php if ($doses['total'] === 0): ?>
                                No active medications are scheduled.
                            <?php elseif ($pending === 0): ?>
                                All done for today. Nice work.
                            <?php else: ?>
                                <?= escape($pending) ?> still to record today.
                            <?php endif; ?>
                        </p>
                    </div>
                    <a class="text-link" href="reminders.php">Open reminders <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if ($doses['total'] > 0): ?>
                    <?php $percent = (int) round($handled / $doses['total'] * 100); ?>
                    <div class="dose-progress" role="progressbar" aria-valuenow="<?= escape($percent) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Doses recorded today">
                        <span class="dose-progress-bar" style="width: <?= escape($percent) ?>%"></span>
                    </div>
                    <p class="dose-progress-label"><?= escape($doses['taken']) ?> taken · <?= escape($doses['skipped']) ?> skipped · <?= escape($pending) ?> pending</p>
                <?php endif; ?>
            </section>

            <section class="content-grid">
                <article class="panel medication-panel" id="medications">
                    <div class="panel-heading">
                        <div>
                            <div class="panel-title"><span class="heading-icon medication-heading-icon"><i class="bi bi-capsule"></i></span>Medication schedule</div>
                            <p class="panel-subtitle">A quick look at the family's active medications</p>
                        </div>
                        <a class="text-link" href="medications.php">View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if ($medications === []): ?>
                        <div class="empty-state">
                            <span class="empty-icon"><i class="bi bi-capsule"></i></span>
                            <strong>No medication records yet</strong>
                            <p>When you add records, the family's schedule will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="record-list">
                            <?php foreach ($medications as $medication): ?>
                                <div class="record-row">
                                    <span class="record-icon medication-record-icon"><i class="bi bi-capsule"></i></span>
                                    <div class="record-main">
                                        <strong><?= escape($medication['name']) ?></strong>
                                        <span><?= escape($medication['first_name'] . ' ' . $medication['last_name']) ?> · <?= escape($medication['dose_instructions']) ?></span>
                                    </div>
                                    <span class="record-time"><?= escape(displayTime($medication['schedule_time'])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="medical-note"><i class="bi bi-info-circle"></i> Follow the instructions from your clinician or pharmacist. This app does not provide medical advice.</p>
                    <?php endif; ?>
                </article>

                <article class="panel appointments-panel" id="appointments">
                    <div class="panel-heading">
                        <div>
                            <div class="panel-title"><span class="heading-icon appointment-heading-icon"><i class="bi bi-calendar2-week"></i></span>Upcoming appointments</div>
                            <p class="panel-subtitle">What's coming up for your family</p>
                        </div>
                        <a class="text-link" href="calendar.php">Calendar <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if ($appointments === []): ?>
                        <div class="empty-state">
                            <span class="empty-icon"><i class="bi bi-calendar2-week"></i></span>
                            <strong>No upcoming appointments</strong>
                            <p>Add an appointment to keep your family plans together.</p>
                        </div>
                    <?php else: ?>
                        <div class="appointment-list">
                            <?php foreach ($appointments as $appointment): ?>
                                <?php $appointmentDate = new DateTimeImmutable($appointment['starts_at']); ?>
                                <div class="appointment-row">
                                    <div class="date-tile"><span><?= escape(strtoupper($appointmentDate->format('M'))) ?></span><strong><?= escape($appointmentDate->format('d')) ?></strong></div>
                                    <div class="appointment-main">
                                        <strong><?= escape($appointment['title']) ?>
                                            <?php $away = (int) $appointment['days_away']; ?>
                                            <?php if ($away <= 0): ?><span class="due-badge due-badge-today">Today</span>
                                            <?php elseif ($away === 1): ?><span class="due-badge due-badge-today">Tomorrow</span>
                                            <?php elseif ($away <= 7): ?><span class="due-badge">In <?= escape($away) ?> days</span><?php endif; ?>
                                        </strong>
                                        <span><?= escape($appointment['provider_name'] !== '' ? $appointment['provider_name'] : $appointment['first_name'] . ' ' . $appointment['last_name']) ?></span>
                                        <small><?= escape($appointment['first_name'] . ' ' . $appointment['last_name']) ?> · <?= (int) $appointment['has_time'] === 1 ? escape(displayTime($appointment['starts_at'])) : 'Time not set' ?></small>
                                    </div>
                                    <i class="bi bi-chevron-right appointment-arrow"></i>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="panel readings-panel" id="readings" aria-label="Latest health readings">
                <div class="panel-heading">
                    <div>
                        <div class="panel-title"><span class="heading-icon appointment-heading-icon"><i class="bi bi-activity"></i></span>Latest readings</div>
                        <p class="panel-subtitle">Most recent measurement for each family member</p>
                    </div>
                    <a class="text-link" href="health.php">Health tracking <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if ($readings === []): ?>
                    <div class="empty-state">
                        <span class="empty-icon"><i class="bi bi-activity"></i></span>
                        <strong>No readings yet</strong>
                        <p>Log weight, blood pressure and more to see them here.</p>
                    </div>
                <?php else: ?>
                    <div class="reading-tiles">
                        <?php foreach ($readings as $reading): ?>
                            <?php
                            $unit = ['Weight' => 'kg', 'Blood pressure' => 'mmHg', 'Blood glucose' => 'mmol/L', 'Heart rate' => 'bpm', 'Temperature' => '°C'][$reading['metric']] ?? '';
                            $value = rtrim(rtrim(number_format((float) $reading['value_primary'], 2, '.', ''), '0'), '.');
                            if ($reading['value_secondary'] !== null) {
                                $value .= '/' . rtrim(rtrim(number_format((float) $reading['value_secondary'], 2, '.', ''), '0'), '.');
                            }
                            ?>
                            <div class="reading-tile">
                                <span class="reading-tile-metric"><?= escape($reading['metric']) ?></span>
                                <strong><?= escape($value) ?> <small><?= escape($unit) ?></small></strong>
                                <span><?= escape($reading['first_name'] . ' ' . $reading['last_name']) ?> · <?= escape((new DateTimeImmutable($reading['measured_at']))->format('M j')) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <span><i class="bi bi-shield-check"></i> Built with privacy in mind</span>
                <span>For organization only · Not medical advice</span>
            </footer>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
