<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$members = [];
$member = null;
$allergies = $conditions = $contacts = $medications = $appointments = $readings = [];
$pageError = null;
$notFound = false;

try {
    $pdo = db();
    $list = $pdo->prepare(
        'SELECT id, first_name, last_name, relationship FROM family_members
         WHERE owner_user_id = :owner AND is_active = 1 ORDER BY first_name, last_name LIMIT 100'
    );
    $list->execute(['owner' => $ownerId]);
    $members = $list->fetchAll();

    $requested = positiveInt($_GET['member'] ?? null);
    if ($requested !== false) {
        $find = $pdo->prepare(
            'SELECT id, first_name, last_name, relationship FROM family_members
             WHERE id = :id AND owner_user_id = :owner LIMIT 1'
        );
        $find->execute(['id' => $requested, 'owner' => $ownerId]);
        $member = $find->fetch() ?: null;
        if ($member === null) {
            http_response_code(404);
            $notFound = true;
        }
    }

    if ($member !== null) {
        $memberId = (int) $member['id'];

        $entries = $pdo->prepare('SELECT kind, title, detail, phone FROM profile_entries WHERE family_member_id = :id ORDER BY title LIMIT 100');
        $entries->execute(['id' => $memberId]);
        foreach ($entries->fetchAll() as $entry) {
            match ($entry['kind']) {
                'Allergy' => $allergies[] = $entry,
                'Condition' => $conditions[] = $entry,
                default => $contacts[] = $entry,
            };
        }

        $query = $pdo->prepare(
            "SELECT name, dose_instructions, schedule_time FROM medications
             WHERE family_member_id = :id AND status = 'Active' ORDER BY schedule_time, name LIMIT 100"
        );
        $query->execute(['id' => $memberId]);
        $medications = $query->fetchAll();

        $query = $pdo->prepare(
            "SELECT category, title, provider_name, starts_at, has_time FROM appointments
             WHERE family_member_id = :id
               AND (starts_at >= NOW() OR (has_time = 0 AND DATE(starts_at) >= CURDATE()))
               AND status = 'Scheduled'
             ORDER BY starts_at LIMIT 5"
        );
        $query->execute(['id' => $memberId]);
        $appointments = $query->fetchAll();

        $query = $pdo->prepare(
            'SELECT r.metric, r.value_primary, r.value_secondary, r.measured_at FROM health_readings r
             WHERE r.family_member_id = :id
               AND r.id = (SELECT r2.id FROM health_readings r2
                           WHERE r2.family_member_id = r.family_member_id AND r2.metric = r.metric
                           ORDER BY r2.measured_at DESC, r2.id DESC LIMIT 1)
             ORDER BY r.metric'
        );
        $query->execute(['id' => $memberId]);
        $readings = $query->fetchAll();

        audit($ownerId, 'summary_view', 'family_member', $memberId);
    }
} catch (PDOException $exception) {
    http_response_code(500);
    $pageError = 'The summary could not be loaded because of a database problem.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

$units = ['Weight' => 'kg', 'Blood pressure' => 'mmHg', 'Blood glucose' => 'mmol/L', 'Heart rate' => 'bpm', 'Temperature' => '°C'];
$number = static fn(mixed $v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$fullName = $member !== null ? $member['first_name'] . ' ' . $member['last_name'] : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title><?= $member !== null ? escape($fullName) . ' summary' : 'Health summary' ?> | MediTrail Pro</title>
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
    <header class="family-page-header no-print">
        <a class="brand" href="index.php" aria-label="MediTrail Pro dashboard">
            <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            <span class="brand-name">MediTrail<span>Pro</span></span>
        </a>
        <nav class="d-flex align-items-center gap-3 flex-wrap">
            <a class="family-back-link" href="family_members.php"><i class="bi bi-people"></i> Family</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="summary-page">
        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
        <?php endif; ?>

        <?php if ($member === null): ?>
            <section class="family-page-intro no-print">
                <div>
                    <div class="eyebrow"><span class="eyebrow-dot"></span> SHARE WITH A CLINICIAN</div>
                    <h1>Health <span>summary</span></h1>
                    <p class="welcome-subtitle">A one-page printable summary of allergies, conditions, medications, appointments and latest readings.</p>
                </div>
            </section>
            <?php if ($notFound): ?>
                <div class="alert alert-danger" role="alert">That family member could not be found.</div>
            <?php endif; ?>
            <?php if ($members === []): ?>
                <div class="empty-state"><span class="empty-icon"><i class="bi bi-people"></i></span><strong>No family members yet</strong><p><a href="family_members.php">Add a family member</a> first.</p></div>
            <?php else: ?>
                <div class="profile-list">
                    <?php foreach ($members as $m): ?>
                        <a class="btn btn-outline-secondary d-block text-start mb-2" href="summary.php?member=<?= escape((int) $m['id']) ?>">
                            <i class="bi bi-file-earmark-text"></i> <?= escape($m['first_name'] . ' ' . $m['last_name']) ?>
                            <span class="text-muted">· <?= escape($m['relationship']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="summary-toolbar">
                <a class="btn btn-outline-secondary btn-sm" href="summary.php"><i class="bi bi-arrow-left"></i> All members</a>
                <button class="btn btn-primary btn-sm" type="button" data-print><i class="bi bi-printer"></i> Print or save as PDF</button>
            </div>

            <article class="summary-sheet">
                <h1><?= escape($fullName) ?></h1>
                <p class="summary-meta"><?= escape($member['relationship']) ?> · Prepared <?= escape((new DateTimeImmutable('now'))->format('F j, Y')) ?> from MediTrail Pro</p>

                <h2>Allergies</h2>
                <?php if ($allergies === []): ?><p class="summary-empty">None recorded.</p><?php else: ?>
                    <ul><?php foreach ($allergies as $a): ?>
                        <li><span class="summary-allergy"><?= escape($a['title']) ?></span><?= $a['detail'] !== '' ? ' – ' . escape($a['detail']) : '' ?></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>

                <h2>Conditions</h2>
                <?php if ($conditions === []): ?><p class="summary-empty">None recorded.</p><?php else: ?>
                    <ul><?php foreach ($conditions as $c): ?>
                        <li><strong><?= escape($c['title']) ?></strong><?= $c['detail'] !== '' ? ' – ' . escape($c['detail']) : '' ?></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>

                <h2>Current medications</h2>
                <?php if ($medications === []): ?><p class="summary-empty">No active medications.</p><?php else: ?>
                    <ul><?php foreach ($medications as $m): ?>
                        <li><strong><?= escape($m['name']) ?></strong> – <?= escape($m['dose_instructions']) ?><?= $m['schedule_time'] !== null ? ' (' . escape((new DateTimeImmutable($m['schedule_time']))->format('g:i A')) . ')' : '' ?></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>

                <h2>Latest readings</h2>
                <?php if ($readings === []): ?><p class="summary-empty">No readings recorded.</p><?php else: ?>
                    <ul><?php foreach ($readings as $r): ?>
                        <li><strong><?= escape($r['metric']) ?>:</strong> <?= escape($number($r['value_primary'])) ?><?= $r['value_secondary'] !== null ? '/' . escape($number($r['value_secondary'])) : '' ?> <?= escape($units[$r['metric']] ?? '') ?> <span class="summary-meta">(<?= escape((new DateTimeImmutable($r['measured_at']))->format('M j, Y')) ?>)</span></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>

                <h2>Upcoming appointments</h2>
                <?php if ($appointments === []): ?><p class="summary-empty">None scheduled.</p><?php else: ?>
                    <ul><?php foreach ($appointments as $a): ?>
                        <li><strong><?= escape($a['category']) ?>: <?= escape($a['title']) ?></strong><?= $a['provider_name'] !== '' ? ' – ' . escape($a['provider_name']) : '' ?>, <?= escape((new DateTimeImmutable($a['starts_at']))->format('D, M j, Y')) ?><?= (int) $a['has_time'] === 1 ? ' at ' . escape((new DateTimeImmutable($a['starts_at']))->format('g:i A')) : '' ?></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>

                <h2>Emergency contacts</h2>
                <?php if ($contacts === []): ?><p class="summary-empty">None recorded.</p><?php else: ?>
                    <ul><?php foreach ($contacts as $c): ?>
                        <li><strong><?= escape($c['title']) ?></strong><?= $c['detail'] !== '' ? ' (' . escape($c['detail']) . ')' : '' ?><?= $c['phone'] !== null && $c['phone'] !== '' ? ' – ' . escape($c['phone']) : '' ?></li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>

                <p class="summary-meta mt-4">Kept for organisation only. This summary is not medical advice and should be checked against official records.</p>
            </article>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
