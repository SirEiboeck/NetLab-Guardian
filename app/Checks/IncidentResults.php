<?php
declare(strict_types=1);

function rackIssueText(string $text, array $passwords, int $limit = 1600): string
{
    foreach ($passwords as $password) {
        if ($password !== '') {
            $text = str_replace($password, '[Passwort verborgen]', $text);
        }
    }
    $text = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text) ?? 'Ungültige Diagnosemeldung';
    // mbstring ist nicht auf jedem PHP-Server installiert.
    return trim(function_exists('mb_substr')
        ? mb_substr($text, 0, $limit, 'UTF-8')
        : substr($text, 0, $limit));
}

/**
 * Normiert einen Python-Check in einzelne Geräte-Issues.
 * Die neue Python-Schnittstelle liefert status, issues und evaluated_commands.
 * evaluated_commands sagt PHP exakt, für welche Befehle frühere Fehler
 * tatsächlich als behoben gelten dürfen. Fehlende/ungültige Antworten
 * schließen aus Sicherheitsgründen keine bestehenden Diagnose-Issues.
 */
function rackCheckOutcome(mixed $response, array $passwords): array
{
    $issue = static function (string $command, string $code, string $title, string $details): array {
        return ['command' => $command, 'code' => $code, 'message' => $title, 'details' => $details];
    };
    $invalid = static function (string $code, string $details) use ($issue, $passwords): array {
        return [
            'status' => 'error',
            'issues' => [$issue('_transport', $code, 'Python-Prüfung fehlgeschlagen', rackIssueText($details, $passwords))],
            'evaluated' => [],
            'structured' => false,
            'valid_response' => false,
        ];
    };
    if (!is_array($response)) {
        return $invalid('missing_result', 'Für dieses Gerät wurde kein gültiges Prüfergebnis zurückgegeben.');
    }
    $status = $response['status'] ?? null;
    $statusDetail = null;
    if (is_array($status) && array_is_list($status)) {
        $statusDetail = $status[1] ?? null;
        $status = $status[0] ?? null;
    }
    if (in_array($status, ['unreachable', 'authentication_failed'], true)) {
        $title = $status === 'unreachable' ? 'Gerät nicht erreichbar' : 'Anmeldung fehlgeschlagen';
        $detail = $status === 'unreachable'
            ? 'Das Gerät war beim Netzwerkcheck nicht erreichbar.'
            : 'Die Anmeldung mit den verwendeten Zugangsdaten ist fehlgeschlagen.';
        return [
            'status' => $status,
            'issues' => [$issue('_transport', $status, $title, $detail)],
            'evaluated' => [],
            'structured' => false,
            'valid_response' => true,
        ];
    }
    if (!in_array($status, ['ok', 'error'], true)) {
        return $invalid('unknown_status', 'Python hat keinen bekannten Prüfstatus geliefert.');
    }

    // Eine strukturierte Rückgabe ist atomar gültig oder wird nicht zur
    // automatischen Auflösung vorhandener Issues verwendet.
    if (array_key_exists('issues', $response) || array_key_exists('evaluated_commands', $response)) {
        $rawIssues = $response['issues'] ?? null;
        $rawEvaluated = $response['evaluated_commands'] ?? null;
        if (!is_array($rawIssues) || !array_is_list($rawIssues) || count($rawIssues) > 2000 ||
            !is_array($rawEvaluated) || !array_is_list($rawEvaluated) || count($rawEvaluated) > 100) {
            return $invalid('invalid_issues', 'Python hat eine unvollständige Diagnoseliste geliefert.');
        }
        $evaluated = [];
        foreach ($rawEvaluated as $command) {
            if (!is_string($command) || strlen($command) > 256 || trim($command) === '') {
                return $invalid('invalid_issues', 'Ungültiger Befehl in evaluated_commands.');
            }
            $normalized = strtolower(implode(' ', preg_split('/\s+/', trim($command))));
            $evaluated[$normalized] = true;
        }
        $issues = [];
        foreach ($rawIssues as $row) {
            if (!is_array($row) || !isset($row['code'], $row['command'], $row['message']) ||
                !is_string($row['code']) || !is_string($row['command']) || !is_string($row['message']) ||
                !preg_match('/^[a-z][a-z0-9_.-]{0,100}$/D', $row['code']) ||
                strlen($row['command']) > 256 || trim($row['command']) === '' ||
                trim($row['message']) === '' || !is_string($row['details'] ?? '')) {
                return $invalid('invalid_issues', 'Python hat einen ungültigen Diagnoseeintrag geliefert.');
            }
            $command = strtolower(implode(' ', preg_split('/\s+/', trim($row['command']))));
            $issues[] = $issue(
                $command,
                $row['code'],
                rackIssueText($row['message'], $passwords, 180),
                rackIssueText($row['details'] ?? '', $passwords)
            );
        }
        if (($status === 'ok' && $issues) || ($status === 'error' && !$issues)) {
            return $invalid('invalid_issues', 'Status und Diagnoseliste widersprechen sich.');
        }
        return [
            'status' => $status,
            'issues' => $issues,
            'evaluated' => array_keys($evaluated),
            'structured' => true,
            'valid_response' => true,
        ];
    }

    // Übergang für alte device_checker.py-Versionen: eine gemeinsame Meldung.
    // Andere, separat gespeicherte Diagnosen ohne vollständige Prüfliste
    // niemals versehentlich schließen.
    if ($status === 'ok') {
        return ['status' => 'ok', 'issues' => [], 'evaluated' => [],
            'structured' => false, 'valid_response' => true];
    }
    $details = '';
    foreach ([$response['output'] ?? null, $statusDetail, $response['error'] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $details = $candidate;
            break;
        }
    }
    return [
        'status' => 'error',
        'issues' => [$issue('_transport', 'legacy_error', 'Allgemeiner Python-Fehler',
            rackIssueText($details ?: 'Python hat einen Fehler gemeldet.', $passwords))],
        'evaluated' => [],
        'structured' => false,
        'valid_response' => true,
    ];
}

/** Eine stabile, eindeutige Kennung pro Befehl und Fehler. */
function rackIssueSourceKey(array $issue): string
{
    return 'python|' . rawurlencode($issue['command']) . '|' . rawurlencode($issue['code']);
}

/** Anzeigename und Befehl aus dem stabilen Python-Schlüssel lesen. */
function rackIncidentCommand(?string $sourceKey): string
{
    if (!$sourceKey || !str_starts_with($sourceKey, 'python|')) {
        return 'Manuell';
    }
    $parts = explode('|', $sourceKey, 3);
    if (count($parts) !== 3) return 'Python';
    $command = rawurldecode($parts[1]);
    return str_starts_with($command, '_') ? 'Systemprüfung' : $command;
}

/** UTC-Zeiten aus SQLite für die lokale Anzeige (Österreich) formatieren. */
function rackIncidentDate(?string $value): string
{
    if (!$value) return '—';
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('Europe/Vienna'))->format('d.m.Y H:i');
    } catch (Exception) {
        return '—';
    }
}

/** Liefert auch für automatisch erfasste Rack-Geräte ohne HE die Rack-Nummer. */
function rackScanPosition(?string $position): array
{
    [$rack, $unit] = rackAndUnit($position);
    if ($rack !== null) return [$rack, $unit];
    if (preg_match('/^Rack ([1-3]) \/ Ohne HE$/D', trim((string) $position), $match)) {
        return [(int) $match[1], null];
    }
    return [null, null];
}

/** Speichert ausschließlich plausible, erfolgreich gelesene Inventarfelder. */
function rackSaveDiscoveredMetadata(DBInternalAPI $db, int $id, array $device, mixed $metadata, array $passwords): void
{
    if (!is_array($metadata)) return;
    $limits = [
        'hostname' => 80, 'model' => 120, 'firmware' => 120,
        'serial_number' => 120, 'os_family' => 100,
        'uptime' => 180, 'software_image' => 250,
    ];
    $updates = [];
    foreach ($limits as $key => $limit) {
        $value = $metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') continue;
        $value = rackIssueText($value, $passwords, $limit);
        if ($value === '' || str_contains($value, '[Passwort verborgen]')) continue;
        if ($key === 'hostname') {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}$/D', $value)) continue;
            $updates['discovered_hostname'] = $value;
            // Manuell gesetzte Gerätenamen nicht ungefragt überschreiben.
            $name = trim((string) ($device['hostname'] ?? ''));
            if ($name === '' || str_starts_with($name, 'Scan-')) {
                $updates['hostname'] = $value;
            }
        } elseif ($key === 'serial_number') {
            // UNIQUE-Constraint: Bei einem Konflikt nicht den ganzen Check verwerfen.
            $assigned = $db->all('device', ['serial_number' => $value], 1);
            if (!$assigned || (int) $assigned[0]['id'] === $id) {
                $updates[$key] = $value;
            }
        } else {
            $updates[$key] = $value;
        }
    }
    // Die Geräteklasse (Switch/Router/Sonstiges) wird bei einem eindeutig
    // erkannten Modell übernommen; bei unklarer Ausgabe vorhandenen Wert behalten.
    if (in_array($metadata['device_type'] ?? null, ['Switch', 'Router', 'Sonstiges'], true)
        && (($device['device_type'] ?? '') !== $metadata['device_type'])) {
        $updates['device_type'] = $metadata['device_type'];
    }
    if ($updates) {
        $updates['last_discovered_at'] = gmdate('Y-m-d H:i:s');
        $db->update('device', $id, $updates);
    }
}

/**
 * Speichert Check-Fehler der vorhandenen Geräte und legt durch den IP-Scan
 * neu erkannte Geräte automatisch im ausgewählten Rack an. Eine IP wird nur
 * nach einer tatsächlichen Antwort des Python-Checks übernommen (ok,
 * authentication_failed oder error), niemals bei unreachable/fehlender Antwort.
 * Bereits inventarisierte IPs werden weder verschoben noch doppelt angelegt.
 * Die HE ist mangels physischer Erkennung zunächst eine vorläufige Zuordnung.
 */
function saveRackCheckResults(
    DBInternalAPI $db,
    array $knownDevices,
    array $plans,
    array $retries,
    array $results,
    int $rack,
    array $scanOnlyIps
): array {
    $devicesByIp = [];
    $occupiedUnits = [];
    foreach ($knownDevices as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $devicesByIp[$ip] = $device;
        }
        [$deviceRack, $unit] = rackScanPosition($device['rack_position'] ?? null);
        if ($deviceRack === $rack && $unit !== null) {
            $occupiedUnits[$unit] = true;
        }
    }

    $passwords = [];
    foreach ($plans as $plan) {
        foreach ($plan['credentials'] as $password) {
            $passwords[$password] = $password;
        }
    }
    foreach ($retries as $options) {
        foreach ($options as $plan) {
            foreach ($plan['credentials'] as $password) {
                $passwords[$password] = $password;
            }
        }
    }
    // Längere Passwörter zuerst ersetzen, damit auch überlappende Werte maskiert werden.
    $passwords = array_values($passwords);
    usort($passwords, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    $counts = [];
    $failures = [];
    $registered = [];
    $withoutUnit = 0;
    foreach ($plans as $plan) {
        $ip = $plan['ip'];
        $outcome = rackCheckOutcome($results[$ip] ?? null, $passwords);
        $status = $outcome['status'];
        $device = $devicesByIp[$ip] ?? null;
        if ($device === null) {
            // Nur tatsächlich erreichte IP-Scan-Ziele registrieren. Die Verbindung
            // zu Port 23 wurde zuvor geprüft; Python muss einen Status liefern.
            // Inaktive, unbelegte oder nicht antwortende IPs bleiben ignoriert.
            if (!isset($scanOnlyIps[$ip]) ||
                !$outcome['valid_response'] ||
                !is_array($results[$ip] ?? null) ||
                !in_array($status, ['ok', 'authentication_failed', 'error'], true)) {
                continue;
            }
            $rawStatus = $results[$ip]['status'] ?? null;
            if (is_array($rawStatus) && array_is_list($rawStatus)) {
                $rawStatus = $rawStatus[0] ?? null;
            }
            if (!in_array($rawStatus, ['ok', 'authentication_failed', 'error'], true)) {
                continue;
            }

            $unit = null;
            for ($candidate = 1; $candidate <= 30; ++$candidate) {
                if (!isset($occupiedUnits[$candidate])) {
                    $unit = $candidate;
                    $occupiedUnits[$candidate] = true;
                    break;
                }
            }
            $position = $unit === null ? "Rack $rack / Ohne HE" : "Rack $rack / HE $unit";
            if ($unit === null) ++$withoutUnit;
            $hostname = 'Scan-' . str_replace('.', '-', $ip);
            $deviceId = (int) $db->insert('device', [
                'hostname' => $hostname,
                'device_type' => 'Unbekannt',
                'management_ip' => $ip,
                'rack_position' => $position,
                'notes' => $unit === null
                    ? 'Automatisch per IP-Scan erkannt. Keine freie HE; Position und Gerätetyp bitte manuell zuweisen.'
                    : 'Automatisch per IP-Scan erkannt. HE vorläufig zugeordnet; Position und Gerätetyp bitte prüfen.',
            ]);
            $device = ['id' => $deviceId, 'hostname' => $hostname, 'management_ip' => $ip];
            $devicesByIp[$ip] = $device;
            $registered[] = ['id' => $deviceId, 'ip' => $ip, 'position' => $position];
        }
        $deviceId = (int) $device['id'];
        // Auch bei Diagnosefehlern können verlässlich gelesene Gerätefelder
        // übernommen werden; nicht aber nach Authentifizierungs-/Transportfehlern.
        if (is_array($results[$ip] ?? null)
            && in_array($status, ['ok', 'error'], true)
            && $outcome['valid_response']
            && is_array($results[$ip]['metadata'] ?? null)) {
            rackSaveDiscoveredMetadata($db, $deviceId, $device, $results[$ip]['metadata'], $passwords);
            $device = $db->get('device', $deviceId) ?? $device;
            $devicesByIp[$ip] = $device;
        }
        $counts[$status] = ($counts[$status] ?? 0) + 1;

        // Alle aktuellen Diagnosen genau einmal pro Gerät und stabiler Kennung.
        $desired = [];
        foreach ($outcome['issues'] as $item) {
            $key = rackIssueSourceKey($item);
            $command = $item['command'];
            $description = $item['details'] !== '' ? $item['details'] : $item['message'];
            if ($command !== '_transport' && $command !== '_system') {
                $description = $command . ': ' . $description;
            }
            $desired[$key] = [
                'type' => 'Python-Check: ' . $item['message'],
                'description' => function_exists('mb_substr')
                    ? mb_substr($description, 0, 1800, 'UTF-8')
                    : substr($description, 0, 1800),
            ];
        }
        // Die DB übernimmt Duplikat-Erkennung, Aktualisierung und sichere
        // Auflösung atomar. Andere als Python-Incidents bleiben unverändert.
        $db->syncPythonIssues($deviceId, $desired, $outcome);
        foreach ($desired as $fields) {
            $deviceName = trim((string) $device['hostname']);
            $failures[] = ($deviceName !== '' ? $deviceName . ' (' . $ip . ')' : $ip)
                . ': ' . $fields['type'] . ' – ' . $fields['description'];
        }
    }
    return [$counts, $failures, $registered, $withoutUnit];
}

/** Kleines Schloss direkt am Gerätenamen: geschlossen = Prüfung und Scan aus. */
