<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validCsrf($_POST['csrf_token'] ?? null)) {
    $loggedOutId = $_SESSION['user_id'] ?? null;
    if (is_int($loggedOutId)) {
        audit($loggedOutId, 'logout', 'user', $loggedOutId);
    }
    signOut();
    session_start();
    session_regenerate_id(true);
    redirect('login.php?signedout=1');
}

redirect('index.php');
