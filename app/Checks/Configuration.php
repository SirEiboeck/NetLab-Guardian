<?php
declare(strict_types=1);

/** Im Browser niemals echte DB-Passwörter ausgeben. */
const RACK_DB_PASSWORD = '[aus Datenbank]';

/** Editierbares JSON: globale Credentials sichtbar, DB-Passwörter als Platzhalter. */
function rackEditableConfig(int $rack, array $rackDevices, array $commands, array $credentialRows): array
{
    $ips = [];
    foreach ($rackDevices as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $ips[$ip] = new stdClass();
        }
    }
    return [
        'credentials' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password' => RACK_DB_PASSWORD,
        ], $credentialRows),
        // Der IP-Scan verwendet standardmäßig das zum Rack gehörende /24.
        // Diese Adressbereiche können vor jedem Lauf im JSON geändert werden.
        'discover' => [
            'ranges' => [['start' => "10.78.$rack.1", 'end' => "10.78.$rack.254"]],
            'exclude' => [],
        ],
        'defaults' => [
            'port' => 23,
            'device_type' => 'cisco_ios_telnet',
            'commands' => $commands ?: ['show version'],
        ],
        'devices' => (object) $ips,
    ];
}

/** Nur die vom Check-Skript unterstützten JSON-Attribute erlauben. */
function validCheckOptions(mixed $options, bool $partial = false): array
{
    if (!is_array($options)) {
        throw new RuntimeException('Die Einstellungen müssen ein JSON-Objekt sein.');
    }
    foreach (array_keys($options) as $key) {
        if (!in_array($key, ['port', 'device_type', 'commands'], true)) {
            throw new RuntimeException('Unbekanntes JSON-Attribut: ' . (string) $key);
        }
    }
    if (!$partial && count($options) !== 3) {
        throw new RuntimeException('Unter defaults sind port, device_type und commands erforderlich.');
    }
    if (array_key_exists('port', $options) && (!is_int($options['port']) || $options['port'] < 1 || $options['port'] > 65535)) {
        throw new RuntimeException('port muss eine Zahl zwischen 1 und 65535 sein.');
    }
    if (array_key_exists('device_type', $options) && (!is_string($options['device_type']) || !preg_match('/^[a-zA-Z0-9_]{3,64}$/D', $options['device_type']))) {
        throw new RuntimeException('device_type enthält ungültige Zeichen.');
    }
    if (array_key_exists('commands', $options)) {
        $commands = $options['commands'];
        if (!is_array($commands) || !array_is_list($commands) || !$commands || count($commands) > 50) {
            throw new RuntimeException('commands muss eine Liste mit 1 bis 50 Befehlen sein.');
        }
        foreach ($commands as $command) {
            if (!is_string($command) || trim($command) === '' || strlen($command) > 256 || preg_match('/[\x00-\x1f\x7f]/', $command)) {
                throw new RuntimeException('Ein command ist leer, zu lang oder enthält Steuerzeichen.');
            }
        }
    }
    return $options;
}

/**
 * Wandelt discover.ranges/exclude aus dem vorhandenen JSON-Format in IPv4-Ziele um.
 * Die obere Grenze verhindert versehentliche riesige Netzwerkscans.
 * Ausschlüsse gelten ausschließlich für den IP-Scan, nicht für explizite devices.
 */
function rackDiscoveryTargets(mixed $discover): array
{
    if ($discover === null) return [];
    if (!is_array($discover) || array_diff(array_keys($discover), ['ranges', 'exclude']) ||
        !isset($discover['ranges']) || !is_array($discover['ranges']) || !array_is_list($discover['ranges'])) {
        throw new RuntimeException('discover benötigt ranges als Liste; exclude ist optional.');
    }
    $parseRanges = static function (mixed $ranges, string $field): array {
        if (!is_array($ranges) || !array_is_list($ranges) || count($ranges) > 64) {
            throw new RuntimeException("discover.$field muss eine Liste mit höchstens 64 Bereichen sein.");
        }
        $parsed = [];
        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) !== 2 ||
                array_diff(array_keys($range), ['start', 'end']) ||
                !isset($range['start'], $range['end']) ||
                !is_string($range['start']) || !is_string($range['end']) ||
                !filter_var($range['start'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
                !filter_var($range['end'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RuntimeException("discover.$field: Jeder Bereich benötigt start und end als gültige IPv4-Adressen.");
            }
            $start = (int) sprintf('%u', ip2long($range['start']));
            $end = (int) sprintf('%u', ip2long($range['end']));
            if ($end < $start) {
                throw new RuntimeException("discover.$field: start darf nicht größer als end sein.");
            }
            $parsed[] = [$start, $end];
        }
        return $parsed;
    };
    $ranges = $parseRanges($discover['ranges'], 'ranges');
    $excluded = $parseRanges($discover['exclude'] ?? [], 'exclude');
    $targets = [];
    foreach ($ranges as [$start, $end]) {
        if ($end - $start + 1 > 2048) {
            throw new RuntimeException('Ein einzelner IP-Bereich darf höchstens 2048 Adressen enthalten.');
        }
        for ($ip = $start; $ip <= $end; ++$ip) {
            $ignored = false;
            foreach ($excluded as [$excludeStart, $excludeEnd]) {
                if ($ip >= $excludeStart && $ip <= $excludeEnd) {
                    $ignored = true;
                    break;
                }
            }
            if (!$ignored) {
                $targets[long2ip($ip)] = true;
                if (count($targets) > 2048) {
                    throw new RuntimeException('Der IP-Scan darf insgesamt höchstens 2048 Adressen umfassen.');
                }
            }
        }
    }
    return $targets;
}

/**
 * Erstellt Prüfpläne für Rack-Geräte und zusätzliche IPs aus discover.ranges.
 * Standardzugangsdaten stammen aus SQLite; im Browser erscheinen nur Platzhalter.
 * Alle Geräte verwenden denselben globalen Credential-Pool.
 * Das unveränderte Python-Config-Modul identifiziert Credentials am Benutzernamen.
 */
function rackExecutionPlans(DBInternalAPI $db, array $rackDevices, array $settings, bool $includeScan = true): array
{
    if (!is_array($settings) || array_diff(array_keys($settings), ['credentials', 'defaults', 'devices', 'discover']) ||
        !isset($settings['defaults'], $settings['devices']) || !is_array($settings['devices'])) {
        throw new RuntimeException('Das JSON benötigt defaults und devices; credentials ist optional.');
    }
    $defaults = validCheckOptions($settings['defaults']);
    $known = [];
    foreach ($rackDevices as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (isset($known[$ip])) {
                throw new RuntimeException('Doppelte Management-IP in diesem Rack: ' . $ip);
            }
            $known[$ip] = $device;
        }
    }

    // Globale DB-Credentials gelten für alle Geräte.
    $credentialRows = $db->all('credential', [], 10000);
    $credentialsById = [];
    foreach ($credentialRows as $row) {
        $credentialsById[(int) $row['id']] = $row;
    }
    if (array_key_exists('credentials', $settings)) {
        $entries = $settings['credentials'];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > 10000) {
            throw new RuntimeException('credentials muss eine JSON-Liste sein.');
        }
    } else {
        $entries = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password' => RACK_DB_PASSWORD,
        ], $credentialRows);
    }

    // IDs dienen zum Nachladen der gespeicherten Passwörter aus SQLite.
    // Ein Eintrag ohne ID ist ein temporäres JSON-Credential.
    $effectiveCredentials = [];
    foreach ($entries as $credential) {
        if (!is_array($credential) || array_diff(array_keys($credential), ['id', 'username', 'password']) ||
            !isset($credential['username'], $credential['password']) ||
            !is_string($credential['username']) || !is_string($credential['password'])) {
            throw new RuntimeException('Jedes Credential benötigt username und password (optional: id).');
        }
        $id = $credential['id'] ?? null;
        if ($id !== null && (!is_int($id) || !isset($credentialsById[$id]))) {
            throw new RuntimeException('Ungültige Credential-id in der JSON-Konfiguration.');
        }
        $username = trim($credential['username']);
        $password = $credential['password'];
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x1f\x7f]/', $username) ||
            strlen($password) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $password)) {
            throw new RuntimeException('Credential enthält einen ungültigen Benutzernamen oder ein ungültiges Passwort.');
        }
        if ($password === RACK_DB_PASSWORD) {
            if ($id === null) {
                throw new RuntimeException('Für [aus Datenbank] ist eine gültige Credential-id erforderlich.');
            }
            $password = (string) $credentialsById[$id]['pwd'];
        }
        $effectiveCredentials[] = ['id' => $id, 'username' => $username, 'password' => $password];
    }
    if (!$effectiveCredentials) {
        throw new RuntimeException('Keine Zugangsdaten ausgewählt. Bitte Credentials unter „Credentials verwalten“ anlegen oder im JSON ergänzen.');
    }

    // Der normale Prüf-Button berücksichtigt discover.ranges ausdrücklich NICHT.
    $scanTargets = $includeScan ? rackDiscoveryTargets($settings['discover'] ?? null) : [];
    // Auch ein Gerät in einem anderen Rack zählt als bereits inventarisiert,
    // falls ein Administrator dessen IP-Bereich explizit zum Scan hinzufügt.
    $knownGlobally = [];
    foreach ($db->all('device', ['removed_at' => null], 10000) as $device) {
        $ip = trim((string) $device['management_ip']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (isset($knownGlobally[$ip]) && (int) $knownGlobally[$ip]['id'] !== (int) $device['id']) {
                throw new RuntimeException('Mehrere aktive Geräte haben dieselbe Management-IP: ' . $ip);
            }
            $knownGlobally[$ip] = $device;
        }
    }
    // Dauerhafte Ausschlüsse sind verbindlich – auch bei manuell bearbeitetem JSON
    // und bei bekannten Geräten aus einem anderen Rack innerhalb eines Scanbereichs.
    $excludedDevices = [];
    foreach ($rackDevices as $device) {
        if ((int) ($device['check_excluded'] ?? 0) === 1) {
            $excludedDevices[(int) $device['id']] = true;
        }
    }
    $scanOnlyIps = [];
    $targetOptions = [];
    foreach ($settings['devices'] as $ip => $options) {
        if (!is_string($ip) || !isset($known[$ip])) {
            throw new RuntimeException('Unter devices dürfen nur Management-IPs des gewählten Racks stehen.');
        }
        $validated = validCheckOptions($options, true);
        if ((int) ($known[$ip]['check_excluded'] ?? 0) === 1) {
            continue;
        }
        $targetOptions[$ip] = $validated;
    }
    // Im Scanbereich auch andere bereits inventarisierte Geräte berücksichtigen,
    // ausgenommene Geräte jedoch niemals abfragen oder als neu registrieren.
    foreach ($scanTargets as $ip => $_) {
        if (isset($knownGlobally[$ip]) && (int) ($knownGlobally[$ip]['check_excluded'] ?? 0) === 1) {
            $excludedDevices[(int) $knownGlobally[$ip]['id']] = true;
            continue;
        }
        if (!isset($targetOptions[$ip])) {
            $targetOptions[$ip] = [];
        }
        if (!isset($knownGlobally[$ip])) {
            $scanOnlyIps[$ip] = true;
        }
    }

    $primary = [];
    $retries = [];
    $skipped = 0;
    foreach ($targetOptions as $ip => $options) {
        $byUsername = [];
        foreach ($effectiveCredentials as $credential) {
            $username = $credential['username'];
            $password = $credential['password'];
            if (!isset($byUsername[$username]) || !in_array($password, $byUsername[$username], true)) {
                $byUsername[$username][] = $password;
            }
        }
        if (!$byUsername) {
            throw new RuntimeException('Für ' . $ip . ' sind keine Credentials konfiguriert.');
        }
        // Gleiche Benutzernamen mit mehreren Passwörtern werden nacheinander probiert.
        $selected = [];
        foreach ($byUsername as $username => $passwords) {
            $selected[$username] = $passwords[0];
            for ($i = 1, $n = count($passwords); $i < $n; ++$i) {
                $retries[$ip][] = [
                    'ip' => $ip, 'options' => $options,
                    'credentials' => [$username => $passwords[$i]],
                ];
            }
        }
        $primary[] = ['ip' => $ip, 'options' => $options, 'credentials' => $selected];
    }
    if (!$primary && !$scanTargets && !$excludedDevices) {
        throw new RuntimeException('Keine Prüfziele gefunden. Bitte Management-IPs hinterlegen oder den IP-Scan starten.');
    }
    return [$defaults, $primary, $retries, $skipped, $scanOnlyIps, count($scanTargets), count($excludedDevices)];
}

/**
 * Schneller TCP-Vortest für noch nicht inventarisierte IPs aus discover.
 * Das bestehende Python-Programm verbindet sich ausschließlich über Port 23.
 * 96 parallele, nicht blockierende Verbindungen verhindern, dass ein /24 mit
 * überwiegend unbelegten Adressen minutenlange Netmiko-Timeouts verursacht.
 * Bekannte Geräte werden NICHT vorgefiltert: Dort sind Verbindungsfehler relevant.
 * Ein negativer Porttest bedeutet nur: derzeit kein erreichbarer Telnet-Dienst.
 */
