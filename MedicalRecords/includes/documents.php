<?php
declare(strict_types=1);

const DOCUMENT_STORAGE_DIR = __DIR__ . '/../storage/documents';
const DOCUMENT_MAX_BYTES = 5 * 1024 * 1024;
const DOCUMENT_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

function documentPath(string $storedName): string
{
    return DOCUMENT_STORAGE_DIR . '/' . $storedName;
}

function isValidStoredName(string $storedName): bool
{
    return preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $storedName) === 1;
}

function deleteStoredDocument(string $storedName): void
{
    if (isValidStoredName($storedName) && is_file(documentPath($storedName))) {
        @unlink(documentPath($storedName));
    }
}
