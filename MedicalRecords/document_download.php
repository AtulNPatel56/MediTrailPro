<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/documents.php';

$currentUser = requireLogin();
$documentId = positiveInt($_GET['id'] ?? null);

if ($documentId === false) {
    http_response_code(400);
    exit('Invalid document.');
}

try {
    $statement = db()->prepare(
        'SELECT d.title, d.original_name, d.stored_name, d.mime_type, d.size_bytes
         FROM documents AS d
         INNER JOIN family_members AS f ON f.id = d.family_member_id
         WHERE d.id = :id AND f.owner_user_id = :owner LIMIT 1'
    );
    $statement->execute(['id' => $documentId, 'owner' => (int) $currentUser['id']]);
    $document = $statement->fetch();
} catch (PDOException $exception) {
    http_response_code(500);
    exit('The document could not be loaded.');
}

if (!$document || !isValidStoredName($document['stored_name']) || !is_file(documentPath($document['stored_name']))) {
    http_response_code(404);
    exit('Document not found.');
}

$extension = DOCUMENT_TYPES[$document['mime_type']] ?? 'bin';
$downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($document['original_name'], PATHINFO_FILENAME)) ?: 'document';

header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . (string) filesize(documentPath($document['stored_name'])));
header('Content-Disposition: attachment; filename="' . $downloadName . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile(documentPath($document['stored_name']));
exit;
