<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

const MAX_FAILED_ATTEMPTS = 5;
const LOCK_MINUTES = 15;

$next = safeNextPage($_GET['next'] ?? $_POST['next'] ?? null);
$email = '';
$errors = [];
$notice = null;

if (isset($_GET['signedout'])) {
    $notice = 'You have been signed out.';
} elseif (isset($_GET['expired'])) {
    $notice = 'Your session expired. Please sign in again.';
} elseif (isset($_GET['registered'])) {
    $notice = 'Account created. Please sign in.';
} elseif (isset($_GET['deleted'])) {
    $notice = 'Your account and data were deleted.';
} elseif (isset($_GET['reset'])) {
    $notice = 'Your password was changed. Please sign in.';
}

if (isset($_SESSION['user_id'])) {
    redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Your form session has expired. Reload the page and try again.';
    } else {
        try {
            $pdo = db();
            $statement = $pdo->prepare(
                'SELECT id, password_hash, failed_attempts, (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
                 FROM users WHERE email = :email LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch() ?: null;

            // Verify against a dummy hash for unknown emails so timing does not reveal which accounts exist
            $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsalt$2eK1t2hFf2l7hQ1xk0m4pYb7bQeXfG0u';
            $passwordOk = password_verify($password, $hash);
            $isLocked = $user !== null && (bool) $user['is_locked'];

            if ($isLocked) {
                $errors[] = 'Too many failed attempts. Please try again in a few minutes.';
            } elseif ($user !== null && $passwordOk) {
                $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id')
                    ->execute(['id' => $user['id']]);
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                        ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
                }
                signIn((int) $user['id']);
                audit((int) $user['id'], 'login', 'user', (int) $user['id']);
                redirect($next);
            } else {
                if ($user !== null) {
                    $attempts = (int) $user['failed_attempts'] + 1;
                    $pdo->prepare(
                        'UPDATE users SET failed_attempts = :attempts,
                            locked_until = IF(:attempts_check >= :max, DATE_ADD(NOW(), INTERVAL :minutes MINUTE), locked_until)
                         WHERE id = :id'
                    )->execute([
                        'attempts' => $attempts,
                        'attempts_check' => $attempts,
                        'max' => MAX_FAILED_ATTEMPTS,
                        'minutes' => LOCK_MINUTES,
                        'id' => $user['id'],
                    ]);
                    audit((int) $user['id'], 'login_failed', 'user', (int) $user['id']);
                }
                $errors[] = 'The email or password is incorrect.';
            }
        } catch (PDOException $exception) {
            http_response_code(500);
            $errors[] = 'Sign-in is unavailable. Check that MySQL is running and the database is set up.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | MediTrail Pro</title>
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
        <h1>Welcome <span>back</span></h1>
        <p class="welcome-subtitle">Sign in to manage your family's records.</p>

        <?php if ($notice !== null): ?>
            <div class="alert alert-success" role="status"><?= escape($notice) ?></div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $error): ?><div><?= escape($error) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <input type="hidden" name="next" value="<?= escape($next) ?>">
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= escape($email) ?>" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                <div class="form-text"><a href="forgot_password.php">Forgot your password?</a></div>
            </div>
            <button class="btn btn-primary family-submit-button w-100" type="submit">Sign in</button>
        </form>
        <p class="auth-switch">New here? <a href="register.php">Create an account</a></p>
        <p class="auth-note"><i class="bi bi-info-circle"></i> Local development build. Use fictional data only.</p>
    </div>
</main>
</body>
</html>
