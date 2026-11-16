<?php
declare(strict_types=1);

requireLogin();
$isNew = !isset($_GET['id']) || (string) $_GET['id'] === '';
$id = $isNew ? 0 : positiveId($_GET['id']);
if (!$isNew && $id < 1) {
    http_response_code(404);
    exit('Gerät nicht gefunden.');
}
if ($isNew) {
    requireAdmin();
}
$device = $isNew ? [] : $db->get('device', $id);
if (!$isNew && (!$device || $device['removed_at'] !== null)) {
    http_response_code(404);
    exit('Gerät nicht gefunden.');
}
$error = '';
$edit = $device;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    checkCsrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'save_device':
                $text = static fn(string $field): string => trim((string) ($_POST[$field] ?? ''));
                $nullable = static fn(string $field): ?string => (($value = trim((string) ($_POST[$field] ?? ''))) === '' ? null : $value);
                $hostname = $text('hostname');
                $type = $text('device_type');
                $ip = $text('management_ip');
                if ($hostname === '' || strlen($hostname) > 80 || $type === '' || strlen($type) > 80) {
                    throw new RuntimeException('Hostname und Gerätetyp sind erforderlich (maximal 80 Zeichen).');
                }
                if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
                    throw new RuntimeException('Ungültige Management-IP-Adresse.');
                }
                foreach (['model', 'serial_number', 'firmware', 'console_port', 'inventory_number'] as $field) {
                    if (strlen($text($field)) > 120) {
                        throw new RuntimeException('Ein Gerätefeld ist zu lang (maximal 120 Zeichen).');
                    }
                }
                if (strlen($text('notes')) > 4000) {
                    throw new RuntimeException('Notizen dürfen maximal 4000 Zeichen enthalten.');
                }
                $rackRaw = $text('rack');
                $unitRaw = $text('unit');
                $rack = $rackRaw === '' ? 0 : positiveId($rackRaw);
                $unit = $unitRaw === '' ? 0 : positiveId($unitRaw);
                if (($rackRaw !== '' && ($rack < 1 || $rack > 3)) ||
                    ($unitRaw !== '' && ($unit < 1 || $unit > 30)) ||
                    ($rack === 0 && $unit !== 0)) {
                    throw new RuntimeException('Rack muss 1–3 und HE 1–30 sein; für eine HE ist ein Rack erforderlich.');
                }
                $exclusion = $text('check_excluded');
                if (!in_array($exclusion, ['0', '1'], true)) {
                    throw new RuntimeException('Ungültiger Check-Status.');
                }
                $position = $rack === 0 ? null : ($unit === 0 ? "Rack $rack / Ohne HE" : "Rack $rack / HE $unit");
                $input = [
                    'hostname' => $hostname,
                    'device_type' => $type,
                    'model' => $nullable('model'),
                    'serial_number' => $nullable('serial_number'),
                    'firmware' => $nullable('firmware'),
                    'management_ip' => $ip === '' ? null : $ip,
                    'rack_position' => $position,
                    'console_port' => $nullable('console_port'),
                    'inventory_number' => $nullable('inventory_number'),
                    'notes' => $nullable('notes'),
                    'check_excluded' => (int) $exclusion,
                ];
                // Rack-Positionen und Ausschlüsse während paralleler Scans nicht verändern.
                $lock = fopen(ProjectPaths::rackLock(), 'c');
                if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                    if ($lock) fclose($lock);
                    throw new RuntimeException('Ein Rack-Check läuft. Bitte nach Abschluss speichern.');
                }
                try {
                    foreach ($db->all('device', ['removed_at' => null], 10000) as $existing) {
                        if ((int) $existing['id'] === $id) continue;
                        if (strcasecmp((string) $existing['hostname'], $hostname) === 0) {
                            throw new RuntimeException('Dieser Hostname ist bereits vergeben.');
                        }
                        if ($ip !== '' && (string) $existing['management_ip'] === $ip) {
                            throw new RuntimeException('Diese Management-IP ist bereits vergeben.');
                        }
                        if ($rack !== 0 && $unit !== 0 && rackScanPosition($existing['rack_position'])[0] === $rack &&
                            rackScanPosition($existing['rack_position'])[1] === $unit) {
                            throw new RuntimeException('Diese Rack-Position ist bereits belegt.');
                        }
                    }
                    if ($isNew) {
                        $id = (int) $db->insert('device', $input);
                    } else {
                        $db->update('device', $id, $input);
                    }
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                flash($isNew ? 'Gerät wurde angelegt.' : 'Gerät wurde gespeichert.');
                redirect('device.php?id=' . $id);

            case 'remove_device':
                if ($isNew) throw new RuntimeException('Gerät ist noch nicht gespeichert.');
                $lock = fopen(ProjectPaths::rackLock(), 'c');
                if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                    if ($lock) fclose($lock);
                    throw new RuntimeException('Ein Rack-Check läuft. Bitte nach Abschluss entfernen.');
                }
                try {
                    $db->update('device', $id, ['removed_at' => gmdate('Y-m-d H:i:s')]);
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                flash('Gerät wurde aus dem Inventar entfernt.');
                redirect('details.php#inventory');

            case 'add_issue':
                if ($isNew) throw new RuntimeException('Zuerst das Gerät speichern.');
                $type = trim((string) ($_POST['type'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                if ($type === '' || $description === '' || strlen($type) > 100 || strlen($description) > 2000) {
                    throw new RuntimeException('Bitte Typ und Meldung angeben (max. 100 bzw. 2000 Zeichen).');
                }
                $now = gmdate('Y-m-d H:i:s');
                $db->insert('issue', [
                    'device_id' => $id,
                    'source_key' => null,
                    'type' => $type,
                    'description' => $description,
                    'detected_at' => $now,
                    'last_seen_at' => $now,
                ]);
                flash('Incident erfasst.');
                redirect('device.php?id=' . $id . '#incidents');

            case 'resolve_issue':
            case 'delete_issue':
                if ($isNew) throw new RuntimeException('Gerät ist noch nicht gespeichert.');
                $issueId = positiveId($_POST['issue_id'] ?? null);
                $issue = $issueId ? $db->get('issue', $issueId) : null;
                if (!$issue || (int) $issue['device_id'] !== $id) {
                    throw new RuntimeException('Incident gehört nicht zu diesem Gerät.');
                }
                if ($action === 'delete_issue') {
                    if ($issue['source_key'] !== null || str_starts_with((string) $issue['type'], 'Python-Check: ')) {
                        throw new RuntimeException('Automatische Incidents bleiben für die Check-Historie erhalten.');
                    }
                    $db->delete('issue', $issueId);
                    flash('Manueller Incident gelöscht.');
                } else {
                    $db->resolveIssue($issueId);
                    flash('Incident als gelöst markiert.');
                }
                redirect('device.php?id=' . $id . '#incidents');

            default:
                throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('[NetLab Guardian] Gerät/Incident speichern: ' . $exception->getMessage());
        $error = 'Speichern fehlgeschlagen. Bitte eindeutige Seriennummer und Berechtigungen prüfen.';
    }
    if ($action === 'save_device') {
        $edit = array_merge($edit, $_POST);
        $edit['rack_position'] = isset($position) ? $position : ($edit['rack_position'] ?? null);
    }
}

if (!$isNew) {
    $device = $db->get('device', $id);
    $allIssues = $db->all('issue', ['device_id' => $id], 10000);
    usort($allIssues, static fn(array $a, array $b): int => strcmp((string) $b['detected_at'], (string) $a['detected_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
    $openIssues = array_values(array_filter($allIssues, static fn(array $issue): bool => $issue['resolved_at'] === null));
    $status = statusFor($id, $openIssues, $device['last_checked_at']);
} else {
    $allIssues = $openIssues = [];
    $status = 'unknown';
}
$flash = takeFlash();
