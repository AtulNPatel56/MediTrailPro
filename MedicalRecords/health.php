<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

// Plausibility ranges only catch typing mistakes; they are not medical thresholds.
$metrics = [
    'Weight' => ['unit' => 'kg', 'icon' => 'bi-speedometer2', 'min' => 1, 'max' => 500, 'dual' => false],
    'Blood pressure' => ['unit' => 'mmHg', 'icon' => 'bi-heart-pulse', 'min' => 20, 'max' => 320, 'dual' => true],
    'Blood glucose' => ['unit' => 'mmol/L', 'icon' => 'bi-droplet-half', 'min' => 0.5, 'max' => 60, 'dual' => false],
    'Heart rate' => ['unit' => 'bpm', 'icon' => 'bi-activity', 'min' => 15, 'max' => 260, 'dual' => false],
    'Temperature' => ['unit' => '°C', 'icon' => 'bi-thermometer-half', 'min' => 25, 'max' => 45, 'dual' => false],
];

$errors = [];
$pageError = null;
$successMessage = null;
$members = [];
$selected = null;
$readings = [];
$form = ['metric' => 'Weight', 'value_primary' => '', 'value_secondary' => '', 'measured_at' => '', 'note' => ''];
$csrfToken = csrfToken();

$statusMessages = ['created' => 'Reading added.', 'deleted' => 'Reading removed.'];
$status = $_GET['status'] ?? '';
if (is_string($status) && isset($statusMessages[$status])) {
    $successMessage = $statusMessages[$status];
}

$metricParam = $_POST['metric'] ?? $_GET['metric'] ?? 'Weight';
$activeMetric = is_string($metricParam) && isset($metrics[$metricParam]) ? $metricParam : 'Weight';
$form['metric'] = $activeMetric;

function redirectToHealth(int $memberId, string $metric, string $status): never
{
    redirect('health.php?member=' . $memberId . '&metric=' . rawurlencode($metric) . '&status=' . rawurlencode($status));
}

function parseNumber(mixed $value, float $min, float $max): float|false
{
    if (!is_string($value) || preg_match('/^\d{1,4}([.,]\d{1,2})?$/', trim($value)) !== 1) {
        return false;
    }
    $number = (float) str_replace(',', '.', trim($value));
    return $number >= $min && $number <= $max ? $number : false;
}

try {
    $pdo = db();

    $memberQuery = $pdo->prepare(
        'SELECT id, first_name, last_name FROM family_members
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
        $action = $_POST['action'] ?? '';
        $selectedId = (int) $selected['id'];

        if (!validCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif (!is_string($_POST['metric'] ?? null) || !isset($metrics[$_POST['metric']])) {
            http_response_code(400);
            $errors[] = 'Choose a valid measurement type.';
        } elseif ($action === 'delete') {
            $readingId = positiveInt($_POST['id'] ?? null);
            if ($readingId === false) {
                http_response_code(400);
                $errors[] = 'The selected reading is invalid.';
            } else {
                $pdo->prepare(
                    'DELETE r FROM health_readings AS r
                     INNER JOIN family_members AS f ON f.id = r.family_member_id
                     WHERE r.id = :id AND r.family_member_id = :member AND f.owner_user_id = :owner'
                )->execute(['id' => $readingId, 'member' => $selectedId, 'owner' => $ownerId]);
                audit($ownerId, 'delete', 'health_reading', $readingId);
                redirectToHealth($selectedId, $activeMetric, 'deleted');
            }
        } elseif ($action === 'create') {
            $meta = $metrics[$activeMetric];
            foreach (['value_primary', 'value_secondary', 'measured_at', 'note'] as $field) {
                $value = $_POST[$field] ?? '';
                $form[$field] = is_string($value) ? trim($value) : '';
            }

            $primary = parseNumber($form['value_primary'], (float) $meta['min'], (float) $meta['max']);
            if ($primary === false) {
                $errors[] = ($meta['dual'] ? 'Top number' : 'Value') . ' must be a number between ' . $meta['min'] . ' and ' . $meta['max'] . '.';
            }
            $secondary = null;
            if ($meta['dual']) {
                $secondary = parseNumber($form['value_secondary'], (float) $meta['min'], (float) $meta['max']);
                if ($secondary === false) {
                    $errors[] = 'Bottom number must be a number between ' . $meta['min'] . ' and ' . $meta['max'] . '.';
                } elseif ($primary !== false && $secondary >= $primary) {
                    $errors[] = 'The top number must be higher than the bottom number.';
                }
            }

            $measured = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $form['measured_at']);
            if ($measured === false || $measured->format('Y-m-d\TH:i') !== $form['measured_at']) {
                $errors[] = 'Enter a valid date and time.';
            } elseif ($measured > new DateTimeImmutable('+1 day')) {
                $errors[] = 'The date cannot be in the future.';
            }
            if (mb_strlen($form['note']) > 255) {
                $errors[] = 'Notes must be 255 characters or fewer.';
            }

            if ($errors === []) {
                $pdo->prepare(
                    'INSERT INTO health_readings (family_member_id, metric, value_primary, value_secondary, measured_at, note)
                     VALUES (:member, :metric, :primary, :secondary, :measured, :note)'
                )->execute([
                    'member' => $selectedId,
                    'metric' => $activeMetric,
                    'primary' => $primary,
                    'secondary' => $secondary,
                    'measured' => $measured->format('Y-m-d H:i:00'),
                    'note' => $form['note'],
                ]);
                audit($ownerId, 'create', 'health_reading', (int) $pdo->lastInsertId());
                redirectToHealth($selectedId, $activeMetric, 'created');
            }
        } else {
            http_response_code(400);
            $errors[] = 'That action is not supported.';
        }
    }

    if ($selected !== null) {
        $listQuery = $pdo->prepare(
            'SELECT id, value_primary, value_secondary, measured_at, note FROM health_readings
             WHERE family_member_id = :member AND metric = :metric
             ORDER BY measured_at DESC, id DESC LIMIT 100'
        );
        $listQuery->execute(['member' => (int) $selected['id'], 'metric' => $activeMetric]);
        $readings = $listQuery->fetchAll();
    }
} catch (PDOException $exception) {
    http_response_code(500);
    $pageError = 'The health tracking page could not access the database. Check MySQL and that migration 005 has been applied.';
    if (getenv('APP_ENV') !== 'production') {
        $pageError .= ' Details: ' . $exception->getMessage();
    }
}

function formatNumber(mixed $value): string
{
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
}

$meta = $metrics[$activeMetric];

// Build a simple SVG line chart from the oldest to the newest reading
$chart = null;
if (count($readings) >= 2) {
    $points = array_reverse($readings);
    $series = [['key' => 'value_primary', 'class' => 'chart-line']];
    if ($meta['dual']) {
        $series[] = ['key' => 'value_secondary', 'class' => 'chart-line chart-line-alt'];
    }
    $all = [];
    foreach ($points as $point) {
        foreach ($series as $s) {
            $all[] = (float) $point[$s['key']];
        }
    }
    $low = min($all);
    $high = max($all);
    $pad = ($high - $low) * 0.15 ?: 1;
    $low -= $pad;
    $high += $pad;
    $width = 600;
    $height = 220;
    $left = 44;
    $right = 16;
    $top = 14;
    $bottom = 28;
    $count = count($points);
    $x = static fn (int $i): float => $left + ($width - $left - $right) * ($count > 1 ? $i / ($count - 1) : 0.5);
    $y = static fn (float $v): float => $top + ($height - $top - $bottom) * (1 - ($v - $low) / ($high - $low));

    $chart = ['width' => $width, 'height' => $height, 'lines' => [], 'ticks' => [], 'dots' => []];
    foreach ($series as $s) {
        $coords = [];
        foreach ($points as $i => $point) {
            $coords[] = round($x($i), 1) . ',' . round($y((float) $point[$s['key']]), 1);
            $chart['dots'][] = ['x' => round($x($i), 1), 'y' => round($y((float) $point[$s['key']]), 1), 'alt' => $s['key'] === 'value_secondary'];
        }
        $chart['lines'][] = ['points' => implode(' ', $coords), 'class' => $s['class']];
    }
    foreach ([0, 0.5, 1] as $fraction) {
        $value = $low + ($high - $low) * (1 - $fraction);
        $chart['ticks'][] = ['y' => round($top + ($height - $top - $bottom) * $fraction, 1), 'label' => formatNumber($value), 'left' => $left, 'right' => $width - $right];
    }
    $chart['from'] = (new DateTimeImmutable($points[0]['measured_at']))->format('M j');
    $chart['to'] = (new DateTimeImmutable($points[$count - 1]['measured_at']))->format('M j');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fa">
    <title>Health tracking | MediTrail Pro</title>
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
            <a class="family-back-link" href="medical_profile.php"><i class="bi bi-clipboard2-pulse"></i> Profile</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> TRENDS</div>
                <h1>Health <span>tracking</span></h1>
                <p class="welcome-subtitle">Log readings you measure and watch the trend. This app does not interpret results or give medical advice; talk to a clinician about your numbers.</p>
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

        <?php if ($members === [] && $pageError === null): ?>
            <div class="empty-state family-empty-state">
                <span class="empty-icon"><i class="bi bi-people"></i></span>
                <strong>No family members yet</strong>
                <p>Add a <a href="family_members.php">family member</a> to start tracking readings.</p>
            </div>
        <?php elseif ($members !== []): ?>
            <nav class="profile-tabs" aria-label="Choose family member">
                <?php foreach ($members as $member): ?>
                    <?php $isCurrent = $selected !== null && (int) $member['id'] === (int) $selected['id']; ?>
                    <a class="profile-tab<?= $isCurrent ? ' active' : '' ?>" href="health.php?member=<?= escape($member['id']) ?>&amp;metric=<?= escape(rawurlencode($activeMetric)) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                        <span class="avatar avatar-small"><?= escape(strtoupper(mb_substr($member['first_name'], 0, 1))) ?></span>
                        <?= escape($member['first_name'] . ' ' . $member['last_name']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($selected !== null): ?>
            <nav class="profile-tabs metric-tabs" aria-label="Choose measurement">
                <?php foreach ($metrics as $name => $info): ?>
                    <a class="profile-tab<?= $name === $activeMetric ? ' active' : '' ?>" href="health.php?member=<?= escape($selected['id']) ?>&amp;metric=<?= escape(rawurlencode($name)) ?>"<?= $name === $activeMetric ? ' aria-current="page"' : '' ?>>
                        <i class="bi <?= escape($info['icon']) ?>"></i> <?= escape($name) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="family-layout">
                <section class="family-form-card" aria-labelledby="readingFormTitle">
                    <div class="family-section-heading">
                        <span class="heading-icon"><i class="bi <?= escape($meta['icon']) ?>"></i></span>
                        <div><h2 id="readingFormTitle">Add <?= escape(strtolower($activeMetric)) ?></h2><p>Unit: <?= escape($meta['unit']) ?></p></div>
                    </div>
                    <form method="post" action="health.php?member=<?= escape($selected['id']) ?>&amp;metric=<?= escape(rawurlencode($activeMetric)) ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="member_id" value="<?= escape($selected['id']) ?>">
                        <input type="hidden" name="metric" value="<?= escape($activeMetric) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="value_primary"><?= $meta['dual'] ? 'Top number (systolic)' : 'Value (' . escape($meta['unit']) . ')' ?> <span class="required-mark">*</span></label>
                            <input class="form-control" id="value_primary" name="value_primary" type="text" inputmode="decimal" maxlength="7" value="<?= escape($form['value_primary']) ?>" required>
                        </div>
                        <?php if ($meta['dual']): ?>
                            <div class="mb-3">
                                <label class="form-label" for="value_secondary">Bottom number (diastolic) <span class="required-mark">*</span></label>
                                <input class="form-control" id="value_secondary" name="value_secondary" type="text" inputmode="decimal" maxlength="7" value="<?= escape($form['value_secondary']) ?>" required>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label" for="measured_at">Measured on <span class="required-mark">*</span></label>
                            <input class="form-control" id="measured_at" name="measured_at" type="datetime-local" value="<?= escape($form['measured_at']) ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="note">Note</label>
                            <input class="form-control" id="note" name="note" type="text" maxlength="255" value="<?= escape($form['note']) ?>">
                        </div>
                        <button class="btn btn-primary family-submit-button" type="submit"><i class="bi bi-plus-lg"></i> Add reading</button>
                    </form>
                </section>

                <section class="family-list-card" aria-labelledby="readingListTitle">
                    <div class="family-section-heading family-list-heading">
                        <span class="heading-icon family-list-icon"><i class="bi bi-graph-up"></i></span>
                        <div><h2 id="readingListTitle"><?= escape($activeMetric) ?> history</h2><p><?= escape(count($readings)) ?> readings · newest first</p></div>
                        <a class="btn btn-outline-secondary btn-sm ms-auto" href="export_readings.php?member=<?= escape((int) $selected['id']) ?>"><i class="bi bi-download"></i> Export all readings (CSV)</a>
                    </div>

                    <?php if ($chart !== null): ?>
                        <figure class="health-chart">
                            <svg viewBox="0 0 <?= escape($chart['width']) ?> <?= escape($chart['height']) ?>" role="img" aria-label="<?= escape($activeMetric) ?> trend from <?= escape($chart['from']) ?> to <?= escape($chart['to']) ?>">
                                <?php foreach ($chart['ticks'] as $tick): ?>
                                    <line class="chart-grid" x1="<?= escape($tick['left']) ?>" x2="<?= escape($tick['right']) ?>" y1="<?= escape($tick['y']) ?>" y2="<?= escape($tick['y']) ?>"/>
                                    <text class="chart-label" x="<?= escape($tick['left'] - 6) ?>" y="<?= escape($tick['y'] + 4) ?>" text-anchor="end"><?= escape($tick['label']) ?></text>
                                <?php endforeach; ?>
                                <?php foreach ($chart['lines'] as $line): ?>
                                    <polyline class="<?= escape($line['class']) ?>" points="<?= escape($line['points']) ?>"/>
                                <?php endforeach; ?>
                                <?php foreach ($chart['dots'] as $dot): ?>
                                    <circle class="chart-dot<?= $dot['alt'] ? ' chart-dot-alt' : '' ?>" cx="<?= escape($dot['x']) ?>" cy="<?= escape($dot['y']) ?>" r="3.5"/>
                                <?php endforeach; ?>
                                <text class="chart-label" x="44" y="216"><?= escape($chart['from']) ?></text>
                                <text class="chart-label" x="584" y="216" text-anchor="end"><?= escape($chart['to']) ?></text>
                            </svg>
                        </figure>
                    <?php elseif (count($readings) === 1): ?>
                        <p class="profile-empty">Add one more reading to see a trend chart.</p>
                    <?php endif; ?>

                    <?php if ($readings === []): ?>
                        <div class="empty-state family-empty-state">
                            <span class="empty-icon"><i class="bi <?= escape($meta['icon']) ?>"></i></span>
                            <strong>No readings yet</strong>
                            <p>Add a reading using the form to start your history.</p>
                        </div>
                    <?php else: ?>
                        <ul class="profile-list">
                            <?php foreach ($readings as $reading): ?>
                                <?php $when = new DateTimeImmutable($reading['measured_at']); ?>
                                <li>
                                    <div>
                                        <strong><?= escape(formatNumber($reading['value_primary'])) ?><?= $reading['value_secondary'] !== null ? '/' . escape(formatNumber($reading['value_secondary'])) : '' ?> <small><?= escape($meta['unit']) ?></small></strong>
                                        <span><?= escape($when->format('M j, Y · g:i A')) ?></span>
                                        <?php if ($reading['note'] !== ''): ?><span><?= escape($reading['note']) ?></span><?php endif; ?>
                                    </div>
                                    <form method="post" action="health.php?member=<?= escape($selected['id']) ?>&amp;metric=<?= escape(rawurlencode($activeMetric)) ?>" data-confirm="Remove this reading?">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="member_id" value="<?= escape($selected['id']) ?>">
                                        <input type="hidden" name="metric" value="<?= escape($activeMetric) ?>">
                                        <input type="hidden" name="id" value="<?= escape($reading['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger member-action-button" type="submit" aria-label="Remove this reading"><i class="bi bi-trash"></i></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
