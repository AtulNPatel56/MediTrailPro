<?php
declare(strict_types=1);

const SESSION_IDLE_SECONDS = 1800;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('meditrail_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' https://cdn.jsdelivr.net; "
    . "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
    . "style-src-attr 'unsafe-inline'; "
    . "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; "
    . "img-src 'self' data:; "
    . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

function escape(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function positiveInt(mixed $value): int|false
{
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}

function db(): PDO
{
    static $pdo = null;

    return $pdo ??= require __DIR__ . '/../config/database.php';
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validCsrf(mixed $submitted): bool
{
    return is_string($submitted) && hash_equals(csrfToken(), $submitted);
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

/** Records who did what. Stores no health values or secrets, and never blocks the request if logging fails. */
function audit(int $userId, string $action, string $entity = '', ?int $entityId = null): void
{
    try {
        db()->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, ip_address)
             VALUES (:user, :action, :entity, :entity_id, :ip)'
        )->execute([
            'user' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (PDOException $exception) {
        error_log('Audit log write failed: ' . $exception->getMessage());
    }
}

function safeNextPage(mixed $next): string
{
    $allowed = ['index.php', 'family_members.php', 'appointments.php', 'medications.php', 'medical_profile.php', 'reminders.php', 'health.php', 'documents.php', 'settings.php', 'activity.php', 'summary.php', 'calendar.php', 'dose_history.php'];

    return is_string($next) && in_array($next, $allowed, true) ? $next : 'index.php';
}

function signIn(int $userId): void
{
    $version = db()->prepare('SELECT session_version FROM users WHERE id = :id');
    $version->execute(['id' => $userId]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = (int) $version->fetchColumn();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function signOut(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/** Returns the signed-in user, or sends the visitor to the sign-in page. */
function requireLogin(): array
{
    $userId = $_SESSION['user_id'] ?? null;
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    $isFresh = is_int($userId) && is_int($lastActivity) && (time() - $lastActivity) <= SESSION_IDLE_SECONDS;

    $user = null;
    if ($isFresh) {
        try {
            $statement = db()->prepare('SELECT id, email, display_name, session_version FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch() ?: null;
            // A password change bumps session_version, which ends every other signed-in session
            if ($user !== null && (int) $user['session_version'] !== ($_SESSION['session_version'] ?? -1)) {
                $user = null;
            }
        } catch (PDOException $exception) {
            http_response_code(500);
            exit('The database is unavailable. Start MySQL in XAMPP and import database/schema.sql.');
        }
    }

    if ($user === null) {
        $expired = isset($_SESSION['user_id']);
        signOut();
        $page = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        $query = $expired ? '&expired=1' : '';
        redirect('login.php?next=' . rawurlencode(safeNextPage($page)) . $query);
    }

    $_SESSION['last_activity'] = time();

    return $user;
}
