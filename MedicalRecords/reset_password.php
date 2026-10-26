<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

const RESET_MIN_PASSWORD = 10;

// Keep the token out of Referer headers sent to third-party CDNs
header('Referrer-Policy: no-referrer');

if (isset($_SESSION['user_id'])) {
    redirect('settings.php');
}

$raw = $_POST['token'] ?? $_GET['token'] ?? '';
$token = is_string($raw) && preg_match('/^[0-9a-f]{64}$/', $raw) === 1 ? $raw : '';
$errors = [];
$tokenValid = false;

try {
    $pdo = db();

    $lookup = static function (PDO $pdo, string $token): int|false {
        if ($token === '') {
            return false;
        }
        $statement = $pdo->prepare(
            'SELECT user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $statement->execute(['hash' => hash('sha256', $token)]);
        $found = $statement->fetchColumn();
        return $found === false ? false : (int) $found;
    };

    $tokenValid = $lookup($pdo, $token) !== false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

        if (!validCsrf($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            $errors[] = 'Your form session has expired. Reload the page and try again.';
        } elseif (!$tokenValid) {
            http_response_code(400);
        } elseif (mb_strlen($new) < RESET_MIN_PASSWORD) {
            $errors[] = 'New password must be at least ' . RESET_MIN_PASSWORD . ' characters.';
        } elseif (strlen($new) > 72) {
            $errors[] = 'New password must be 72 bytes or fewer.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The passwords do not match.';
        } else {
            $pdo->beginTransaction();
            // Consuming the token and changing the password happen together; a second use finds no row
            $consume = $pdo->prepare(
                'UPDATE password_resets SET used_at = NOW()
                 WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()'
            );
            $consume->execute(['hash' => hash('sha256', $token)]);

            if ($consume->rowCount() !== 1) {
                $pdo->rollBack();
                $tokenValid = false;
                http_response_code(400);
            } else {
                $userQuery = $pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = :hash');
                $userQuery->execute(['hash' => hash('sha256', $token)]);
                $userId = (int) $userQuery->fetchColumn();

                $pdo->prepare(
                    'UPDATE users SET password_hash = :hash, session_version = session_version + 1, failed_attempts = 0, locked_until = NULL WHERE id = :id'
                )->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :id AND used_at IS NULL')
                    ->execute(['id' => $userId]);
                $pdo->commit();

                audit($userId, 'password_reset', 'user', $userId);
                redirect('login.php?reset=1');
            }
        }
    }
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $errors[] = 'Password reset is unavailable. Check that MySQL is running and the database is set up.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Reset password | MediTrail Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
</head>
<body>
<main class="auth-page">
    <div class="auth-card">
        <a class="brand" href="login.php">
            <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            <span class="brand-name">MediTrail<span>Pro</span></span>
        </a>
        <h1>Choose a new <span>password</span></h1>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $error): ?><div><?= escape($error) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$tokenValid): ?>
            <div class="alert alert-danger" role="alert">This reset link is invalid, expired or already used.</div>
            <p class="auth-switch"><a href="forgot_password.php">Request a new link</a></p>
        <?php else: ?>
            <form method="post" action="reset_password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                <input type="hidden" name="token" value="<?= escape($token) ?>">
                <div class="mb-3">
                    <label class="form-label" for="new_password">New password</label>
                    <input class="form-control" id="new_password" name="new_password" type="password" minlength="<?= RESET_MIN_PASSWORD ?>" autocomplete="new-password" required autofocus>
                    <div class="form-text">At least <?= RESET_MIN_PASSWORD ?> characters.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="confirm_password">Confirm new password</label>
                    <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                </div>
                <button class="btn btn-primary family-submit-button w-100" type="submit">Change password</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
