<?php
declare(strict_types=1);

$devices = $db->all('device', ['removed_at' => null], 10000);
$incidentFilter = (string) ($_GET['incidents'] ?? 'open');
if (!in_array($incidentFilter, ['open', 'resolved', 'all'], true)) {
    $incidentFilter = 'open';
}
$incidentCounts = $db->incidentCounts();
$incidents = $db->incidentOverview($incidentFilter, 200);
$issues = $db->openIssues();
// Befehle nur bei angemeldeten Admins für die Check-UI laden.
$checkCredentialRows = isAdmin() ? $db->all('credential', [], 10000) : [];
$checkCommands = isAdmin()
    ? array_values(array_map(static fn(array $row): string => (string) $row['command'], $db->all('command', [], 50)))
    : [];
sort($checkCommands);
$checkNotice = isAdmin() ? ($_SESSION['rack_check_notice'] ?? null) : null;
unset($_SESSION['rack_check_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    checkCsrf();
    $rack = positiveId($_POST['rack'] ?? null);
    if ($rack < 1 || $rack > 3) {
        http_response_code(400);
        exit('Ungültiges Rack.');
    }
    $mode = (string) ($_POST['mode'] ?? '');
    try {
        $rackDevices = array_values(array_filter($devices, static fn(array $device): bool => rackScanPosition($device['rack_position'])[0] === $rack));

        if ($mode === 'toggle_exclusion') {
            $deviceId = positiveId($_POST['device_id'] ?? null);
            $exclude = (string) ($_POST['exclude'] ?? '');
            if ($deviceId < 1 || !in_array($exclude, ['0', '1'], true)) {
                throw new RuntimeException('Ungültige Geräte- oder Schloss-Auswahl.');
            }
            // Derselbe Lock wie beim Check verhindert Änderungen während eines Prüflaufs.
            $lock = fopen(ProjectPaths::rackLock(), 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Es läuft bereits ein Rack-Check. Bitte anschließend erneut versuchen.');
            }
            try {
                // Gerätezugehörigkeit unter dem Lock frisch prüfen (nicht aus altem HTML übernehmen).
                $currentDevice = $db->get('device', $deviceId);
                if ($currentDevice === null || $currentDevice['removed_at'] !== null
                    || rackScanPosition($currentDevice['rack_position'])[0] !== $rack) {
                    throw new RuntimeException('Dieses Gerät gehört nicht mehr zum ausgewählten Rack.');
                }
                if ((int) ($currentDevice['check_excluded'] ?? 0) !== (int) $exclude) {
                    $db->update('device', $deviceId, ['check_excluded' => (int) $exclude]);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            // Der neue Schlosszustand ist direkt nach dem Redirect sichtbar.
        } else {
            // Sämtliche Planung und Ausführung unter demselben Lock wie die
            // Ausschlussverwaltung: keine veralteten Flags aus parallelen Requests.
            $lock = fopen(ProjectPaths::rackLock(), 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Es läuft bereits ein Rack-Check. Bitte danach erneut starten.');
            }
            try {
                $devices = $db->all('device', ['removed_at' => null], 10000);
                $rackDevices = array_values(array_filter($devices, static fn(array $device): bool => rackScanPosition($device['rack_position'])[0] === $rack));
                $action = (string) ($_POST['action'] ?? '');
                if (!in_array($action, ['check', 'scan'], true)) {
                    throw new RuntimeException('Bitte „Prüfen“ oder „IP-Scan + Prüfen“ auswählen.');
                }
                $includeScan = $action === 'scan';
                if ($mode === 'default') {
                    $settings = rackEditableConfig($rack, $rackDevices, $checkCommands, $checkCredentialRows);
                    $settings['devices'] = (array) $settings['devices'];
                    foreach ($settings['devices'] as &$options) $options = [];
                    unset($options);
                } elseif ($mode === 'custom') {
                    $raw = (string) ($_POST['settings_json'] ?? '');
                    if (strlen($raw) > 64_000) {
                        throw new RuntimeException('JSON-Konfiguration darf maximal 64 KB groß sein.');
                    }
                    $settings = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } else {
                    throw new RuntimeException('Unbekannte Prüfaktion.');
                }
                [$defaults, $primary, $retries, $skipped, $scanOnlyIps, $scanSize, $skippedExcluded] = rackExecutionPlans(
                    $db, $rackDevices, $settings, $includeScan
                );
                foreach ($rackDevices as $device) {
                    if ((int) ($device['check_excluded'] ?? 0) !== 1 && !filter_var(trim((string) $device['management_ip']), FILTER_VALIDATE_IP)) {
                        ++$skipped;
                    }
                }
                set_time_limit(130);
                // Nur beim IP-Scan unbekannte IPs per TCP vortesten.
                [$primary, $retries, $scanResponsive] = $includeScan
                    ? rackFilterDiscoveryPlans($primary, $retries, $scanOnlyIps)
                    : [$primary, $retries, 0];
                $results = $primary ? runRackPlans($defaults, $primary, $retries) : [];
                [$counts, $failures, $registered, $withoutUnit] = saveRackCheckResults(
                    $db, $devices, $primary, $retries, $results, $rack, $scanOnlyIps
                );
                $ok = $counts['ok'] ?? 0;
                $total = array_sum($counts);
                $message = "Rack $rack: $ok von $total Geräten ohne Auffälligkeiten";
                if ($includeScan) {
                    $message .= "; IP-Scan: $scanSize Adressen, $scanResponsive bisher unbekannte IPs mit offenem Telnet-Port";
                    $message .= ', ' . count($registered) . ' neue Geräte im Rack registriert';
                    if ($withoutUnit) $message .= " ($withoutUnit ohne freie HE)";
                }
                if ($skippedExcluded) $message .= ", $skippedExcluded Gerät(e) dauerhaft ausgeschlossen";
                if ($skipped) $message .= ", $skipped ohne gültige Management-IP übersprungen";
                $message .= '.';
                $_SESSION['rack_check_notice'] = [
                    'rack' => $rack, 'success' => !$failures && !$skipped,
                    'message' => $message,
                    'failures' => array_slice($failures, 0, 20),
                    'registered' => array_slice($registered, 0, 40),
                    'registered_count' => count($registered),
                ];
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    } catch (Throwable $error) {
        $_SESSION['rack_check_notice'] = [
            'rack' => $rack, 'success' => false, 'message' => $error instanceof JsonException
                ? 'Ungültige JSON-Konfiguration: ' . $error->getMessage() : $error->getMessage(),
            'failures' => [], 'registered' => [], 'registered_count' => 0,
        ];
    }
    redirect('index.php#rack-' . $rack);
}
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

