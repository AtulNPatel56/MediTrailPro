<?php
declare(strict_types=1);

/** Public base URL used in emailed links. Set APP_URL in production; never derive it from the request. */
function appUrl(): string
{
    $url = getenv('APP_URL');
    return rtrim(is_string($url) && $url !== '' ? $url : 'http://localhost:8080/MedicalRecords', '/');
}

/**
 * Local builds have no mail server, so messages go to storage/mail.log (web access denied).
 * Set APP_MAIL_MODE=smtp to use PHP's mail() once the server is configured.
 */
function sendMail(string $to, string $subject, string $body): void
{
    if (getenv('APP_MAIL_MODE') === 'smtp') {
        $headers = 'Content-Type: text/plain; charset=UTF-8';
        @mail($to, $subject, $body, $headers);
        return;
    }

    $entry = '[' . date('c') . "] To: $to\nSubject: $subject\n$body\n" . str_repeat('-', 60) . "\n";
    @file_put_contents(__DIR__ . '/../storage/mail.log', $entry, FILE_APPEND | LOCK_EX);
}
