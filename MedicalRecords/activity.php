<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

const ACTIVITY_PAGE_SIZE = 50;

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$actionLabels = [
    'login' => 'Signed in',
    'login_failed' => 'Failed sign-in attempt',
    'logout' => 'Signed out',
    'register' => 'Account created',
    'password_change' => 'Password changed',
    'password_reset_requested' => 'Requested a password reset link',
    'password_reset' => 'Password reset with email link',
    'export' => 'Exported account data',
    'refill' => 'Refilled supply for',
    'export_readings' => 'Exported health readings (CSV) for',
    'summary_view' => 'Viewed a printable health summary for',
    'create' => 'Added',
    'update' => 'Updated',
    'delete' => 'Deleted',
    'archive' => 'Archived',
    'restore' => 'Restored',
    'dose_taken' => 'Marked dose taken',
    'dose_skipped' => 'Skipped dose',
    'dose_undo' => 'Undid dose status',
];
$entityLabels = [
    'user' => 'account',
    'account' => 'account data',
    'family_member' => 'family member',
    'medication' => 'medication',
    'appointment' => 'appointment',
    'profile_entry' => 'medical profile entry',
    'health_reading' => 'health reading',
    'document' => 'document',
];

$page = max(1, positiveInt($_GET['page'] ?? null) ?? 1);
$rows = [];
$total = 0;
$pageError = null;

try {
    $pdo = db();
    $countQuery = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE user_id = :owner');
    $countQuery->execute(['owner' => $ownerId]);
    $total = (int) $countQuery->fetchColumn();

    $pages = max(1, (int) ceil($total / ACTIVITY_PAGE_SIZE));
    $page = min($page, $pages);

    $statement = $pdo->prepare(
        'SELECT action, entity, entity_id, ip_address, created_at
         FROM audit_log WHERE user_id = :owner
         ORDER BY created_at DESC, id DESC
         LIMIT ' . ACTIVITY_PAGE_SIZE . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE_SIZE)
    );
    $statement->execute(['owner' => $ownerId]);
    $rows = $statement->fetchAll();
} catch (PDOException $exception) {
    http_response_code(500);
    $pages = 1;
    $pageError = 'Activity could not be loaded because of a database problem.';
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
    <title>Activity | MediTrail Pro</title>
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
            <a class="family-back-link" href="settings.php"><i class="bi bi-gear"></i> Settings</a>
            <a class="family-back-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
            <?php include __DIR__ . '/includes/user_menu.php'; ?>
        </nav>
    </header>

    <div class="family-page-content">
        <section class="family-page-intro">
            <div>
                <div class="eyebrow"><span class="eyebrow-dot"></span> SECURITY</div>
                <h1>Account <span>activity</span></h1>
                <p class="welcome-subtitle">A record of sign-ins and changes on your account. Health details are never stored here. If you see something you don't recognise, change your password.</p>
            </div>
        </section>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert"><?= escape($pageError) ?></div>
        <?php endif; ?>

        <section class="family-list-card">
            <?php if ($rows === []): ?>
                <div class="empty-state"><i class="bi bi-clock-history"></i><p>No activity recorded yet.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr><th scope="col">When</th><th scope="col">What</th><th scope="col">IP address</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $label = $actionLabels[$row['action']] ?? $row['action'];
                            $entity = $entityLabels[$row['entity']] ?? $row['entity'];
                            $isEntityAction = in_array($row['action'], ['create', 'update', 'delete', 'archive', 'restore', 'summary_view', 'export_readings', 'refill'], true);
                            ?>
                            <tr>
                                <td><?= escape($row['created_at']) ?></td>
                                <td>
                                    <?= escape($label) ?><?= $isEntityAction ? ' ' . escape($entity) : '' ?>
                                    <?php if ($isEntityAction && $row['entity_id'] !== null): ?>
                                        <span class="text-muted">#<?= escape((int) $row['entity_id']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= escape($row['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="Activity pages">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="activity.php?page=<?= $page - 1 ?>">Newer</a>
                        <?php else: ?><span></span><?php endif; ?>
                        <span class="text-muted small">Page <?= $page ?> of <?= $pages ?></span>
                        <?php if ($page < $pages): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="activity.php?page=<?= $page + 1 ?>">Older</a>
                        <?php else: ?><span></span><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
