<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$currentUser = requireLogin();
$ownerId = (int) $currentUser['id'];

$memberId = positiveInt($_GET['member'] ?? null);
if ($memberId === false) {
    http_response_code(400);
    exit('Choose a family member.');
}

/** Stops spreadsheet apps from running user-typed text as a formula. */
function csvSafe(string $value): string
{
    return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
}

try {
    $pdo = db();
    $find = $pdo->prepare('SELECT id, first_name, last_name FROM family_members WHERE id = :id AND owner_user_id = :owner LIMIT 1');
    $find->execute(['id' => $memberId, 'owner' => $ownerId]);
    $member = $find->fetch();
    if ($member === false) {
        http_response_code(404);
        exit('Family member not found.');
    }

    $query = $pdo->prepare(
        'SELECT metric, value_primary, value_secondary, measured_at, note FROM health_readings
         WHERE family_member_id = :id ORDER BY measured_at, id'
    );
    $query->execute(['id' => $memberId]);
    $rows = $query->fetchAll();
} catch (PDOException $exception) {
    http_response_code(500);
    exit('The export could not be created because of a database problem.');
}

audit($ownerId, 'export_readings', 'family_member', (int) $member['id']);

$units = ['Weight' => 'kg', 'Blood pressure' => 'mmHg', 'Blood glucose' => 'mmol/L', 'Heart rate' => 'bpm', 'Temperature' => 'C'];
$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($member['first_name'] . '-' . $member['last_name'])) ?: 'member';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="readings-' . trim($slug, '-') . '-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Metric', 'Value', 'Second value', 'Unit', 'Measured at', 'Note'], ',', '"', '');
foreach ($rows as $row) {
    fputcsv($out, [
        $row['metric'],
        $row['value_primary'],
        $row['value_secondary'] ?? '',
        $units[$row['metric']] ?? '',
        $row['measured_at'],
        csvSafe((string) $row['note']),
    ], ',', '"', '');
}
fclose($out);
