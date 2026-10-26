<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

const MIN_PASSWORD_LENGTH = 10;

$form = ['display_name' => '', 'email' => ''];
$errors = [];

if (isset($_SESSION['user_id'])) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['display_name'] = is_string($_POST['display_name'] ?? null) ? trim($_POST['display_name']) : '';
    $form['email'] = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';

    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Your form session has expired. Reload the page and try again.';
    } else {
        if ($form['display_name'] === '' || mb_strlen($form['display_name']) > 80) {
            $errors[] = 'Name is required and must be 80 characters or fewer.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 190) {
            $errors[] = 'Enter a valid email address.';
        }
        if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        } elseif (strlen($password) > 72) {
            $errors[] = 'Password must be 72 bytes or fewer.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The passwords do not match.';
        }

        if ($errors === []) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                $isFirstUser = (int) $pdo->query('SELECT COUNT(*) FROM users FOR UPDATE')->fetchColumn() === 0;

                $statement = $pdo->prepare(
                    'INSERT INTO users (email, display_name, password_hash) VALUES (:email, :name, :hash)'
                );
                $statement->execute([
                    'email' => $form['email'],
                    'name' => $form['display_name'],
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $newUserId = (int) $pdo->lastInsertId();

                // Records created before accounts existed are adopted by the first account only
                if ($isFirstUser) {
                    $pdo->prepare('UPDATE family_members SET owner_user_id = :id WHERE owner_user_id IS NULL')
                        ->execute(['id' => $newUserId]);
                }

                $pdo->commit();
                audit($newUserId, 'register', 'user', $newUserId);
                redirect('login.php?registered=1');
            } catch (PDOException $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception->getCode() === '23000') {
                    $errors[] = 'An account with that email already exists.';
                } else {
                    http_response_code(500);
                    $errors[] = 'Registration is unavailable. Check that MySQL is running and the database is set up.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | MediTrail Pro</title>
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
        <h1>Create your <span>account</span></h1>
        <p class="welcome-subtitle">Your family's records stay private to your account.</p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert" aria-live="assertive">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?><li><?= escape($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <div class="mb-3">
                <label class="form-label" for="display_name">Your name</label>
                <input class="form-control" id="display_name" name="display_name" type="text" maxlength="80" autocomplete="name" value="<?= escape($form['display_name']) ?>" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= escape($form['email']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password" aria-describedby="passwordHelp" required>
                <div class="form-text" id="passwordHelp">At least <?= MIN_PASSWORD_LENGTH ?> characters.</div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password_confirm">Confirm password</label>
                <input class="form-control" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
            </div>
            <button class="btn btn-primary family-submit-button w-100" type="submit">Create account</button>
        </form>
        <p class="auth-switch">Already registered? <a href="login.php">Sign in</a></p>
        <p class="auth-note"><i class="bi bi-info-circle"></i> Local development build. Email confirmation and password reset are not set up yet.</p>
    </div>
</main>
</body>
</html>
