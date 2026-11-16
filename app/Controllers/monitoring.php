<?php
declare(strict_types=1);

// Gemeinsame Datengrundlage für Start- und Detailansicht.
$devices = $db->all('device', ['removed_at' => null], 10000);
$incidentFilter = (string) ($_GET['incidents'] ?? 'open');
if (!in_array($incidentFilter, ['open', 'resolved', 'all'], true)) {
    $incidentFilter = 'open';
}
$incidentCounts = $db->incidentCounts();
$incidents = $db->incidentOverview($incidentFilter, 200);
$issues = $db->openIssues();

foreach ($devices as &$device) {
    [$device['rack'], $device['unit']] = rackScanPosition($device['rack_position']);
    $device['status'] = statusFor((int) $device['id'], $issues, $device['last_checked_at']);
}
unset($device);
$deviceById = array_column($devices, null, 'id');
$statuses = array_count_values(array_column($devices, 'status'));
$statusCount = static fn(string $status): int => $statuses[$status] ?? 0;
$problemRacks = [];
foreach ($devices as $d) {
    if ($d['rack'] !== null && in_array($d['status'], ['warning', 'critical'], true))
        $problemRacks[$d['rack']] = true;
}
$filter = ($_GET['filter'] ?? '') === 'issues' ? 'issues' : 'all';
$query = trim((string) ($_GET['q'] ?? ''));
$visible = array_values(array_filter($devices, static function (array $d) use ($query): bool {
    if ($query === '')
        return true;
    return str_contains(strtolower(implode(' ', [
        $d['hostname'],
        $d['management_ip'],
        $d['device_type'],
        $d['model'],
        $d['serial_number'],
        $d['rack_position'],
        statusLabel($d['status']),
    ])), strtolower($query));
}));
usort($visible, static fn(array $a, array $b): int => (($b['rack'] ?? 0) <=> ($a['rack'] ?? 0)) ?: (($b['unit'] ?? 0) <=> ($a['unit'] ?? 0)));

