<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/documents.php';

$currentUser = requireLogin();
$attachmentId = positiveInt($_GET['id'] ?? null);

if ($attachmentId === false) {
    http_response_code(400);
    exit('Invalid attachment.');
}

try {
    $statement = db()->prepare(
        'SELECT aa.original_name, aa.stored_name, aa.mime_type
         FROM appointment_attachments AS aa
         INNER JOIN appointments AS a ON a.id = aa.appointment_id
         INNER JOIN family_members AS f ON f.id = a.family_member_id
         WHERE aa.id = :id AND f.owner_user_id = :owner LIMIT 1'
    );
    $statement->execute(['id' => $attachmentId, 'owner' => (int) $currentUser['id']]);
    $attachment = $statement->fetch();
} catch (PDOException $exception) {
    http_response_code(500);
    exit('The attachment could not be loaded.');
}

if (
    !$attachment
    || !isValidStoredName($attachment['stored_name'])
    || !isset(DOCUMENT_TYPES[$attachment['mime_type']])
    || !is_file(documentPath($attachment['stored_name']))
) {
    http_response_code(404);
    exit('Attachment not found.');
}

$extension = DOCUMENT_TYPES[$attachment['mime_type']];
$downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($attachment['original_name'], PATHINFO_FILENAME)) ?: 'attachment';

header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . (string) filesize(documentPath($attachment['stored_name'])));
header('Content-Disposition: attachment; filename="' . $downloadName . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile(documentPath($attachment['stored_name']));
exit;
