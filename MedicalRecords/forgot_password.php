<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/mailer.php';

const RESET_MINUTES = 30;
const RESET_MAX_PER_HOUR = 3;

if (isset($_SESSION['user_id'])) {
    redirect('settings.php');
}

$email = '';
$errors = [];
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';

    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Your form session has expired. Reload the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    } else {
        try {
            $pdo = db();
            $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);
            $userId = $statement->fetchColumn();

            if ($userId !== false) {
                $userId = (int) $userId;
                $recent = $pdo->prepare(
                    'SELECT COUNT(*) FROM password_resets
                     WHERE user_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
                );
                $recent->execute(['id' => $userId]);

                if ((int) $recent->fetchColumn() < RESET_MAX_PER_HOUR) {
                    $token = bin2hex(random_bytes(32));
                    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :id AND used_at IS NULL')
                        ->execute(['id' => $userId]);
                    $pdo->prepare(
                        'INSERT INTO password_resets (user_id, token_hash, expires_at)
                         VALUES (:id, :hash, DATE_ADD(NOW(), INTERVAL ' . RESET_MINUTES . ' MINUTE))'
                    )->execute(['id' => $userId, 'hash' => hash('sha256', $token)]);

                    sendMail(
                        $email,
                        'Reset your MediTrail Pro password',
                        "Use this link to choose a new password. It works once and expires in " . RESET_MINUTES . " minutes.\n\n"
                        . appUrl() . '/reset_password.php?token=' . $token . "\n\n"
                        . "If you did not ask for this, ignore this message; your password has not changed."
                    );
                    audit($userId, 'password_reset_requested', 'user', $userId);
                }
            }
            // Same answer whether or not the account exists, so this page cannot be used to find accounts
            $sent = true;
        } catch (PDOException $exception) {
            http_response_code(500);
            $errors[] = 'Password reset is unavailable. Check that MySQL is running and the database is set up.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password | MediTrail Pro</title>
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
        <h1>Forgot your <span>password?</span></h1>
        <p class="welcome-subtitle">Enter your email and we'll send a link to choose a new one.</p>

        <?php if ($sent): ?>
            <div class="alert alert-success" role="status">If an account exists for that email, a reset link has been sent. It expires in <?= RESET_MINUTES ?> minutes.</div>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $error): ?><div><?= escape($error) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="forgot_password.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <div class="mb-4">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= escape($email) ?>" required autofocus>
            </div>
            <button class="btn btn-primary family-submit-button w-100" type="submit">Send reset link</button>
        </form>
        <p class="auth-switch"><a href="login.php">Back to sign in</a></p>
        <p class="auth-note"><i class="bi bi-info-circle"></i> Local build: no mail server, so the link is written to <code>storage/mail.log</code>.</p>
    </div>
</main>
</body>
</html>
